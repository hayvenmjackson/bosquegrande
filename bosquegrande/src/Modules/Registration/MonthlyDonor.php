<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\CoreFields;
use BosqueGrande\Modules\Payments\StripeClient;

if (!defined('ABSPATH')) exit;

/**
 * Who counts as a monthly donor.
 *
 * A donor CRM's "giving status" label tends to drift, so recurring giving
 * is taken from the payment processor that charges it. The resolver asks
 * Stripe when available, keeps a small register for cash and check
 * exceptions, and accepts the strict Yes/No value on one unambiguous local
 * record (pulled from a mapped LGL field or set by staff).
 *
 * Signals, first hit wins:
 *   1. the manual register (settings, one address per line)
 *   2. an active Stripe subscription for that email
 *   3. the Monthly Donor Yes/No value on the linked local record
 *
 * Every answer carries a reason, and the resolved value is mirrored into
 * the Monthly Donor field so it is visible on the record.
 *
 * The gate is a setting. While it is open, nothing is rejected and gated
 * registration options are offered on the honor system.
 */
final class MonthlyDonor
{
    public const GATE_OPTION = 'monthly_donor_gate';
    public const REGISTER_OPTION = 'monthly_donor_register';

    /** Verdict cache window. Short: a cancelled card should bite quickly. */
    private const TTL = 900;

    public const REASON_REGISTER = 'manual_register';
    public const REASON_STRIPE = 'stripe_subscription';
    public const REASON_LGL = 'crm_giving_status';
    public const REASON_LOCAL = 'bg_record';
    public const REASON_NONE = 'no_evidence';

    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private ?StripeClient $stripe = null,
        private ?ConstituentStore $store = null,
    ) {
    }

    /** Container-friendly construction; every collaborator is optional. */
    public static function fromContainer(Container $c): self
    {
        return new self(
            $c->get('settings'),
            $c->get('logger'),
            $c->has('stripe') ? $c->get('stripe') : null,
            $c->has('constituents.store') ? $c->get('constituents.store') : null,
        );
    }

    /**
     * Is the free tier gated at all right now? False means the tier is
     * offered to everyone (honour system) and never rejected.
     */
    public function enforced(): bool
    {
        return (string) $this->settings->get(self::GATE_OPTION, 'open') === 'enforced';
    }

    /**
     * @return array{yes: bool, reason: string}
     */
    public function status(string $email, int $lglId = 0, ?callable $lglCheck = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['yes' => false, 'reason' => self::REASON_NONE];
        }

        $cacheKey = $this->cacheKey($email, $lglId);
        $cached = get_transient($cacheKey);
        if ($lglCheck === null && is_array($cached) && isset($cached['yes'], $cached['reason'])) {
            return ['yes' => (bool) $cached['yes'], 'reason' => (string) $cached['reason']];
        }

        $verdict = $this->evaluate($email, $lglId, $lglCheck);
        set_transient($cacheKey, $verdict, self::TTL);
        $this->mirror($email, $verdict);
        return $verdict;
    }

    /** Convenience for callers that only need the boolean. */
    public function isDonor(string $email, int $lglId = 0, ?callable $lglCheck = null): bool
    {
        return $this->status($email, $lglId, $lglCheck)['yes'];
    }

    /** @return array{yes: bool, reason: string} */
    private function evaluate(string $email, int $lglId, ?callable $lglCheck = null): array
    {
        if (in_array($email, $this->register(), true)) {
            return ['yes' => true, 'reason' => self::REASON_REGISTER];
        }
        if ($this->stripe && $this->stripe->canUseApi()
            && $this->stripe->hasActiveSubscription($email)
        ) {
            return ['yes' => true, 'reason' => self::REASON_STRIPE];
        }
        $local = $this->localStatus($email, $lglId);
        if ($local !== null) {
            return ['yes' => $local, 'reason' => self::REASON_LOCAL];
        }
        if ($lglId > 0 && $lglCheck !== null) {
            try {
                if ((bool) $lglCheck($lglId, $email)) {
                    return ['yes' => true, 'reason' => self::REASON_LGL];
                }
            } catch (\Throwable $e) {
                $this->logger->warn('registration', 'Legacy donor-status callback failed.', [
                    'lgl_constituent' => $lglId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
        return ['yes' => false, 'reason' => self::REASON_NONE];
    }

    /** A manually selected or LGL-pulled Yes/No value on one unambiguous local record. */
    private function localStatus(string $email, int $lglId): ?bool
    {
        if (!$this->store || !$this->store->available()) return null;
        $ids = $lglId > 0 ? $this->store->idsByLglId($lglId) : [];
        if (count($ids) !== 1) $ids = $this->store->idsByEmail($email);
        if (count($ids) !== 1) return null;

        $fieldId = $this->store->ensureField(
            CoreFields::MONTHLY_DONOR,
            'Monthly Donor',
            false
        );
        if ($fieldId < 1) return null;
        $value = CoreFields::normalizeMonthlyDonor(
            $this->store->valuesFor((int) $ids[0])[$fieldId] ?? ''
        );
        return $value === '' ? null : $value === 'Yes';
    }

    /**
     * The manual register: members who pay by cash or cheque and so
     * have no subscription to find. One address per line; commas and
     * semicolons are tolerated because people paste from anywhere.
     * @return array<int, string>
     */
    public function register(): array
    {
        $raw = (string) $this->settings->get(self::REGISTER_OPTION, '');
        if (trim($raw) === '') return [];
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && is_email($line)) $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * Remove a verified address from the cash/cheque exception list.
     * A Stripe donor can also appear here after a mistaken manual entry;
     * cancellation removes both signals so the benefit cannot remain
     * active after billing has stopped.
     */
    public function removeFromRegister(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;
        $register = $this->register();
        $remaining = array_values(array_filter(
            $register,
            static fn(string $candidate): bool => !hash_equals($email, $candidate)
        ));
        if ($remaining === $register) return true;
        return $this->settings->set(self::REGISTER_OPTION, implode("\n", $remaining));
    }

    /**
     * Commit the local side of a verified donor cancellation and clear
     * every cached verdict that could keep the button or free tier live.
     */
    public function recordCancellation(string $email, int $localId, int $lglId = 0): bool
    {
        $email = strtolower(trim($email));
        $registerOk = $this->removeFromRegister($email);
        $fieldOk = false;

        if ($this->store && $this->store->available()) {
            if ($localId < 1) {
                $linked = $lglId > 0 ? $this->store->idsByLglId($lglId) : [];
                if (count($linked) === 1) {
                    $localId = (int) $linked[0];
                } else {
                    $ids = $this->store->idsByEmail($email);
                    $localId = count($ids) === 1 ? (int) $ids[0] : 0;
                }
            }
            $fieldId = $this->store->ensureField(
                CoreFields::MONTHLY_DONOR,
                'Monthly Donor',
                false
            );
            $fieldOk = $localId > 0
                && $fieldId > 0
                && $this->store->setValue($localId, $fieldId, 'No');
        }

        $this->clearCache($email, $lglId);
        return $registerOk && $fieldOk;
    }

    public function clearCache(string $email, int $lglId = 0): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;
        delete_transient($this->cacheKey($email, $lglId));
        if ($lglId !== 0) delete_transient($this->cacheKey($email, 0));
        if ($this->stripe) $this->stripe->clearSubscriptionCache($email);
    }

    /**
     * Mirror a positive external verdict onto the constituent record.
     * Best effort and silent: an absent external signal never overwrites
     * an LGL-pulled or staff-selected fallback.
     */
    private function mirror(string $email, array $verdict): void
    {
        // An absence of evidence is not a verified "No", and a local
        // selection is already stored. Only mirror a positive external
        // signal so a staff-entered fallback is never erased by silence.
        if (empty($verdict['yes']) || ($verdict['reason'] ?? '') === self::REASON_LOCAL) return;
        if (!$this->store || !$this->store->available()) return;
        $ids = $this->store->idsByEmail($email);
        if (!$ids) return;
        $fieldId = $this->store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        if ($fieldId < 1) return;
        if (count($ids) !== 1) return;
        $localId = (int) $ids[0];
        $this->store->setValue($localId, $fieldId, 'Yes');
    }

    private function cacheKey(string $email, int $lglId): string
    {
        return 'bg_md2_' . md5(strtolower(trim($email)) . '|' . $lglId);
    }

    /** Human-readable reason, for admin screens and logs. */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::REASON_REGISTER => 'on the manual monthly donor register',
            self::REASON_STRIPE => 'active monthly subscription in Stripe',
            self::REASON_LGL => 'giving status in the donor CRM',
            self::REASON_LOCAL => 'Monthly Donor selection on the local record',
            default => 'no monthly giving found',
        };
    }
}
