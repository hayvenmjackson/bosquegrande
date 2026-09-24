<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Identity;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\Settings;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * Tier 2 magic-link flow. Enumeration-safe: the response is identical
 * whether or not the email matches a constituent, and unknown emails
 * receive no message. Identity lookup uses BosqueGrande's encrypted
 * local store and never calls LGL from a public request.
 */
final class MagicLink
{
    private const RATE_LIMIT = 3;       // Sends per window
    private const RATE_WINDOW = 900;    // 15 minutes
    private const LINK_TTL = 900;       // 15 minutes

    /** Canonical public lifetime for copy which describes a sign-in link. */
    public static function lifetimeMinutes(): int
    {
        return max(1, (int) ceil(self::LINK_TTL / 60));
    }

    public function __construct(
        private TokenRepository $tokens,
        private Session $session,
        private ConstituentStore $store,
        private Settings $settings,
        private Logger $logger,
    ) {
    }

    public function handleRequestLink(
        string $email,
        string $redirect = '',
        string $firstName = '',
        string $lastName = ''
    ): \WP_REST_Response
    {
        $email = strtolower(trim($email));
        $neutral = new \WP_REST_Response([
            'ok' => true,
            'message' => bg_str('confirm_screen_notice', 'If we have this address on file, a confirmation email is on its way.'),
        ], 200);

        if (!is_email($email)) return $neutral;
        if ($this->rateLimited($email)) return $neutral;
        if ($this->suppressed($email)) {
            $this->logger->info('identity', 'Confirmation request for a suppressed address; nothing dispatched.');
            return $neutral;
        }

        $source = $this->lookupSource();
        $constituentId = $this->findLocalId($email, $firstName, $lastName);

        if (!$constituentId) {
            // Enumeration safety: same response, no email sent.
            $this->logger->info('identity', 'Magic link requested for unmatched email.', ['source' => $source]);
            return $neutral;
        }
        // The shared outbound-mail allowance is consumed only for a real,
        // eligible recipient. Arbitrary unmatched addresses cannot deny sign
        // in mail to known constituents.
        if (!$this->claimRateBucket('global', 60, self::RATE_WINDOW)) return $neutral;

        $raw = $this->tokens->createMagic($email, $constituentId, self::LINK_TTL, $source);
        if ($raw === '') {
            $this->logger->error('identity', 'Magic-link token could not be stored.', ['source' => $source]);
            return $neutral;
        }
        $redirect = wp_validate_redirect(wp_unslash($redirect), '');
        $link = add_query_arg(array_filter([
            'bg_verify' => $raw,
            'bg_to' => $redirect !== '' ? $redirect : null,
        ]), home_url('/'));

        $sent = wp_mail(
            $email,
            bg_str('confirm_email_subject', 'Confirm your email'),
            bg_str_format(
                'confirm_email_body',
                "Tap the link below to confirm your email. It works once and expires in {minutes} minutes.\n\n{link}",
                [
                    'link' => $link,
                    'minutes' => self::lifetimeMinutes(),
                ],
                ['link', 'minutes']
            )
        );
        $this->logger->info('identity', $sent ? 'Magic link sent.' : 'Magic link send failed (wp_mail).', [
            'source' => $source,
        ]);
        return $neutral;
    }

    public function handleVerify(string $raw, string $requestedTarget = ''): void
    {
        $row = $this->tokens->consumeMagic($raw);
        $constituentId = $row ? (int) ($row->lgl_constituent_id ?? 0) : 0;
        $source = $row && (string) ($row->kind ?? '') === 'magic_bg' ? 'bg' : 'lgl';

        $localRecord = $source === 'bg' && $this->store->available()
            ? $this->store->get($constituentId) : null;
        if (!$row || !$constituentId || ($source === 'bg' && (!$localRecord || ($localRecord['status'] ?? '') !== 'active'))) {
            wp_safe_redirect(add_query_arg('bg_identity', 'expired', home_url('/')));
            exit;
        }

        // An LGL magic token already proves the inbox and carries the
        // requested email. Bind that verified remote id to the matching
        // local encrypted constituent now, while both identifiers are
        // available. Future prefill reads use the stable numeric link
        // and do not depend on comparing randomized ciphertext, or on
        // LGL being reachable.
        if ($source === 'lgl' && $this->store->available()) {
            $linked = $this->store->idsByLglId($constituentId);
            if (!$linked) {
                $email = strtolower(trim((string) ($row->email ?? '')));
                $localId = is_email($email) ? $this->findLocalId($email) : 0;
                if ($localId) {
                    if ($this->store->linkLgl($localId, $constituentId)) {
                        $this->logger->info('identity', 'Verified LGL identity linked to its encrypted local constituent.', [
                            'constituent' => $localId,
                            'lgl_constituent' => $constituentId,
                        ]);
                    } else {
                        $this->logger->warn('identity', 'Verified LGL identity could not retain its local link.', [
                            'constituent' => $localId,
                            'lgl_constituent' => $constituentId,
                        ]);
                    }
                }
            }
        }
        if (!$this->session->start($constituentId, $source)) {
            $this->logger->error('identity', 'Verified identity could not start its device session.', [
                'constituent' => $constituentId,
                'source' => $source,
            ]);
            wp_safe_redirect(add_query_arg('bg_identity', 'failed', home_url('/')));
            exit;
        }
        $this->logger->info('identity', 'Device verified for constituent.', [
            'constituent' => $constituentId,
            'source' => $source,
        ]);

        $to = $requestedTarget;
        $target = $to !== '' ? wp_validate_redirect($to, home_url('/')) : home_url('/');
        // PRG: never leave the one-use token in the address bar and do
        // not drop the visitor straight into a form with no indication
        // that verification worked. The neutral intermediate screen
        // explains the device session and lets them continue deliberately.
        wp_safe_redirect(add_query_arg([
            'bg_identity' => 'confirmed',
            'bg_continue' => $target,
        ], home_url('/')));
        exit;
    }

    private function lookupSource(): string
    {
        return 'bg';
    }

    private function findLocalId(string $email, string $firstName = '', string $lastName = ''): int
    {
        if (!$this->store->available()) {
            $this->logger->warn('identity', 'Local magic-link lookup unavailable because encrypted constituent storage is unavailable.');
            return 0;
        }
        $ids = $this->store->idsByEmail($email);
        if (!$ids) return 0;
        $candidate = [
            'first_name' => sanitize_text_field($firstName),
            'last_name' => sanitize_text_field($lastName),
        ];
        if (count($ids) === 1) {
            $record = $this->store->get((int) $ids[0]);
            $completeCandidate = trim($candidate['first_name']) !== '' && trim($candidate['last_name']) !== '';
            $completeRecord = $record
                && trim((string) ($record['first_name'] ?? '')) !== ''
                && trim((string) ($record['last_name'] ?? '')) !== '';
            if ($completeCandidate && $completeRecord
                && ConstituentStore::identityScore((array) $record, $candidate) < 2) {
                $this->logger->warn('identity', 'Magic-link lookup refused because the supplied name conflicts with the person on the only matching email record.');
                return 0;
            }
            return (int) $ids[0];
        }
        $best = $this->store->disambiguateIds($ids, $candidate);
        if (!$best) {
            $this->logger->warn('identity', 'Multiple people share the requested email; no link was sent because the name did not identify exactly one record.', [
                'matches' => count($ids),
            ]);
        }
        return $best;
    }

    private function suppressed(string $email): bool
    {
        $list = (string) $this->settings->get('ml_suppression', '');
        if ($list === '') return false;
        $emails = array_filter(array_map(
            fn($line) => strtolower(trim($line)),
            preg_split('/[\r\n,;]+/', $list) ?: []
        ));
        return in_array(strtolower(trim($email)), $emails, true);
    }

    private function rateLimited(string $email): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $target = hash('sha256', $email);
        return !$this->claimRateBucket('repeat:' . $target, 1, MINUTE_IN_SECONDS)
            || !$this->claimRateBucket('email:' . $target, self::RATE_LIMIT, self::RATE_WINDOW)
            || !$this->claimRateBucket('ip:' . hash('sha256', $ip), self::RATE_LIMIT, self::RATE_WINDOW);
    }

    private function claimRateBucket(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_ml_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_ml_budget_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }
}
