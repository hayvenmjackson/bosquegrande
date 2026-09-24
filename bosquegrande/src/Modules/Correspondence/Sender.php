<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * The bulk sending pipeline. Queue rows double as the send ledger:
 * queued, sent, failed, or suppressed, one row per recipient per
 * newsletter, kept forever. Batches run through the Jobs facade
 * (BATCH_SIZE per run, then the worker re-queues itself), so a
 * thousand-recipient send is a chain of small cron jobs, never one
 * long request. Every bulk message carries the RFC 8058 one-click
 * unsubscribe headers; Gmail and Yahoo require them at volume.
 * Suppression is checked twice: at queue build and again at send.
 */
final class Sender
{
    public const BATCH_SIZE = 25;
    public const JOB = 'nl_batch';
    public const TEST_RECIPIENT_LIMIT = 25;
    public const AUTH_META = '_bg_nl_manual_release_authorized';
    private const MAX_ATTEMPTS = 3;
    private const SNAPSHOT_META = '_bg_send_snapshot';

    public function __construct(
        private ConstituentStore $store,
        private Groups $groups,
        private Logger $logger,
    ) {
    }

    /** @return array{queued: int, suppressed: int} */
    public function queueNewsletter(int $newsletterId, int $groupId): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'bg_send_queue';
        $now = current_time('mysql', true);
        $queued = 0;
        $suppressed = 0;
        $activeRows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE newsletter_id = %d AND status IN ('queued','sending')",
            $newsletterId
        ));
        if ($activeRows === 0) {
            $post = get_post($newsletterId);
            if ($post) {
                update_post_meta($newsletterId, self::SNAPSHOT_META, wp_json_encode([
                    'subject' => (string) $post->post_title,
                    'body' => Composer::body($post),
                    'template_html' => Composer::templateHtml(
                        (string) get_post_meta($newsletterId, Composer::TEMPLATE_META, true)
                    ),
                ]));
            }
        }
        foreach ($this->groups->activeConstituentIds($groupId) as $constituentId) {
            $record = $this->store->get($constituentId);
            $email = $record['email'] ?? '';
            $status = 'queued';
            if ($email === '' || $this->suppressedEmail($email)) {
                $status = 'suppressed';
            }
            $ok = $wpdb->insert($t, [
                'newsletter_id' => $newsletterId,
                'group_id' => $groupId,
                'constituent_id' => $constituentId,
                'queue_key' => hash('sha256', $newsletterId . '|' . $constituentId),
                'status' => $status,
                'attempts' => 0,
                'created_at' => $now,
            ]);
            if (!$ok) continue; // Existing unique queue key or a real DB refusal.
            if ($status === 'suppressed') $suppressed++;
            else $queued++;
        }
        $this->logger->info('correspondence', 'Newsletter queued.', [
            'newsletter' => $newsletterId, 'queued' => $queued, 'suppressed' => $suppressed,
        ]);
        return ['queued' => $queued, 'suppressed' => $suppressed];
    }

    /** Bulk recipients delivered today, for the daily cap. */
    public function sentToday(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_send_queue WHERE status = 'sent' AND sent_at >= %s",
            gmdate('Y-m-d') . ' 00:00:00'
        ));
    }

    /** The configured cap; 0 means uncapped. */
    public function dailyCap(): int
    {
        $settings = \BosqueGrande\Core\Plugin::instance()->container()->get('settings');
        return max(0, (int) apply_filters('bg_daily_send_cap', (int) $settings->get('daily_send_cap', 0)));
    }

    /**
     * One batch. Returns the number remaining so the worker knows
     * whether to chain another run.
     */
    public function runBatch(int $newsletterId): int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'bg_send_queue';
        if ((string) get_post_meta($newsletterId, self::AUTH_META, true) !== '1') {
            $this->logger->warn('correspondence', 'Newsletter worker stood down because this newsletter has no manual release authorization.', [
                'newsletter' => $newsletterId,
            ]);
            return 0;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET status = 'queued', claimed_at = NULL
             WHERE newsletter_id = %d AND status = 'sending' AND claimed_at < %s",
            $newsletterId,
            gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS)
        ));
        $post = get_post($newsletterId);
        if (!$post) {
            delete_post_meta($newsletterId, self::AUTH_META);
            $stranded = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$t} SET status = 'failed' WHERE newsletter_id = %d AND status = 'queued'",
                $newsletterId
            ));
            if ($stranded > 0) {
                $this->logger->warn('correspondence', 'Newsletter deleted mid-send; the remaining queue rows were marked failed.', ['newsletter' => $newsletterId, 'rows' => $stranded]);
            }
            return 0;
        }
        $limit = self::BATCH_SIZE;
        $cap = $this->dailyCap();
        if ($cap > 0) $limit = min($limit, max(0, $cap - $this->sentToday()));
        if ($limit === 0) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE newsletter_id = %d AND status IN ('queued','sending')",
                $newsletterId
            ));
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE newsletter_id = %d AND status = 'queued' ORDER BY id ASC LIMIT %d",
            $newsletterId, $limit
        )) ?: [];
        foreach ($rows as $row) {
            $claimed = $wpdb->update($t, [
                'status' => 'sending',
                'claimed_at' => current_time('mysql', true),
            ], ['id' => (int) $row->id, 'status' => 'queued']);
            if ($claimed === 1 && !$this->deliver($post, $row)) {
                delete_post_meta($newsletterId, self::AUTH_META);
                $this->logger->warn('correspondence', 'Newsletter delivery paused after the first mail transport refusal; another manual release is required.', [
                    'newsletter' => $newsletterId,
                    'row' => (int) $row->id,
                ]);
                return -1;
            }
        }
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE newsletter_id = %d AND status IN ('queued','sending')", $newsletterId
        ));
        if ($remaining === 0) {
            delete_post_meta($newsletterId, self::AUTH_META);
            if ($rows) {
                $this->logger->info('correspondence', 'Newsletter send finished.', ['newsletter' => $newsletterId]);
            }
        }
        return $remaining;
    }

    /** False means the mail transport refused this message and the batch must stop. */
    private function deliver(\WP_Post $post, object $row): bool
    {
        global $wpdb;
        $t = $wpdb->prefix . 'bg_send_queue';
        $record = $this->store->get((int) $row->constituent_id);
        $email = $record['email'] ?? '';
        if ($email === ''
            || $this->suppressedEmail($email)
            || $this->groups->subscriptionStatus((int) $row->constituent_id, (int) $row->group_id) !== 'active'
        ) {
            $wpdb->update($t, ['status' => 'suppressed'], ['id' => (int) $row->id]);
            return true;
        }
        $unsubToken = self::unsubscribeToken((int) $row->constituent_id, (int) $row->group_id);
        if ($unsubToken === '') {
            $wpdb->update($t, [
                'status' => 'failed',
                'attempts' => (int) $row->attempts + 1,
                'claimed_at' => null,
            ], ['id' => (int) $row->id]);
            $this->logger->error('correspondence', 'Newsletter delivery refused because its unsubscribe link could not be signed.', [
                'newsletter' => (int) $row->newsletter_id,
                'row' => (int) $row->id,
            ]);
            return true;
        }
        $unsubUrl = add_query_arg('bg_unsub', $unsubToken, home_url('/'));
        $oneClickUrl = rest_url('bosquegrande/v1/unsubscribe') . '?t=' . rawurlencode($unsubToken);
        $snapshot = json_decode((string) get_post_meta($post->ID, self::SNAPSHOT_META, true), true);
        if (is_array($snapshot)) {
            $html = Composer::renderSnapshot($snapshot, $unsubUrl);
            $subject = (string) ($snapshot['subject'] ?? $post->post_title);
        } else {
            $html = Composer::render($post, $unsubUrl);
            $subject = (string) $post->post_title;
        }
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'List-Unsubscribe: <' . $oneClickUrl . '>, <' . $unsubUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
        $sent = wp_mail($email, $subject, $html, $headers);
        $attempts = (int) $row->attempts + 1;
        $wpdb->update($t, [
            'status' => $sent ? 'sent' : ($attempts < self::MAX_ATTEMPTS ? 'queued' : 'failed'),
            'attempts' => $attempts,
            'sent_at' => $sent ? current_time('mysql', true) : null,
            'claimed_at' => null,
        ], ['id' => (int) $row->id]);
        if (!$sent) {
            $this->logger->warn('correspondence', 'Newsletter delivery refused by the mailer.', [
                'newsletter' => (int) $row->newsletter_id, 'row' => (int) $row->id,
            ]);
            return false;
        }
        return true;
    }

    public function testSend(int $newsletterId, string $to): bool
    {
        $post = get_post($newsletterId);
        if (!$post || !is_email($to)) return false;
        $html = Composer::render($post, home_url('/'));
        return wp_mail($to, '[Test] ' . $post->post_title, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Direct test-delivery path. Each address receives its own
     * message so test recipients are never disclosed to one another.
     * @param array<int, string> $recipients
     * @return array{sent: int, failed: int}
     */
    public function testSendMany(int $newsletterId, array $recipients): array
    {
        $sent = 0;
        $failed = 0;
        foreach (array_slice($recipients, 0, self::TEST_RECIPIENT_LIMIT) as $to) {
            if ($this->testSend($newsletterId, $to)) $sent++;
            else {
                $failed++;
                break;
            }
        }
        $this->logger->info('correspondence', 'Direct newsletter test completed.', [
            'newsletter' => $newsletterId,
            'sent' => $sent,
            'failed' => $failed,
        ]);
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return array{valid: array<int, string>, invalid: array<int, string>, overflow: int}
     */
    public static function parseTestRecipients(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw)) ?: [];
        $valid = [];
        $invalid = [];
        foreach ($parts as $part) {
            $candidate = sanitize_email(trim((string) $part));
            if ($candidate === '' || !is_email($candidate)) {
                if (trim((string) $part) !== '') $invalid[] = (string) $part;
                continue;
            }
            $valid[strtolower($candidate)] = $candidate;
        }
        $overflow = max(0, count($valid) - self::TEST_RECIPIENT_LIMIT);
        return [
            'valid' => array_values(array_slice($valid, 0, self::TEST_RECIPIENT_LIMIT, true)),
            'invalid' => $invalid,
            'overflow' => $overflow,
        ];
    }

    /** @return array<string, int> status => count for one newsletter. */
    public function ledger(int $newsletterId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}bg_send_queue
             WHERE newsletter_id = %d GROUP BY status",
            $newsletterId
        )) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->n;
        }
        return $out;
    }

    private function suppressedEmail(string $email): bool
    {
        global $wpdb;
        $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption');
        return $this->groups->suppressed($encryption->blindIndex(ConstituentStore::normEmail($email)));
    }

    /* ── Link builders ────────────────────────────────────────────── */

    public static function unsubscribeToken(int $constituentId, int $groupId): string
    {
        return Composer::sign(['a' => 'unsub', 'c' => $constituentId, 'g' => $groupId]);
    }

    public static function unsubscribeUrl(int $constituentId, int $groupId): string
    {
        return add_query_arg('bg_unsub', self::unsubscribeToken($constituentId, $groupId), home_url('/'));
    }

    public static function confirmToken(string $email, int $groupId, int $constituentId = 0): string
    {
        // Keep the address out of browser history, access logs, and copied
        // URLs. The random public token resolves to an encrypted, expiring
        // server-side payload; older signed links remain readable below.
        $payload = ['a' => 'confirm', 'e' => $email, 'g' => $groupId, 'i' => time()];
        if ($constituentId > 0) $payload['c'] = $constituentId;
        try {
            $token = 'bgc2_' . bin2hex(random_bytes(24));
        } catch (\Throwable) {
            return '';
        }
        $hash = hash('sha256', $token);
        $json = wp_json_encode($payload);
        $encryption = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption');
        $encrypted = is_string($json)
            ? $encryption->encrypt($json, 'subscription-confirm:' . $hash)
            : '';
        if ($encrypted === '' || $encrypted === $json) return '';
        return set_transient('bg_sub_confirm_' . $hash, $encrypted, 2 * DAY_IN_SECONDS)
            ? $token
            : '';
    }

    /** @return array<string,int|string>|null */
    public static function verifyConfirmToken(string $token): ?array
    {
        if (!preg_match('/^bgc2_[a-f0-9]{48}$/', $token)) return null;
        $hash = hash('sha256', $token);
        $stored = get_transient('bg_sub_confirm_' . $hash);
        if (!is_string($stored) || $stored === '') return null;
        $plain = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')
            ->decrypt($stored, 'subscription-confirm:' . $hash);
        $payload = json_decode($plain, true);
        return is_array($payload) && ($payload['a'] ?? '') === 'confirm'
            ? $payload
            : null;
    }

    public static function forgetConfirmToken(string $token): void
    {
        if (preg_match('/^bgc2_[a-f0-9]{48}$/', $token)) {
            delete_transient('bg_sub_confirm_' . hash('sha256', $token));
        }
    }
}
