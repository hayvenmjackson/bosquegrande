<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Revenue;

use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ActivityLog;

if (!defined('ABSPATH')) exit;

/**
 * The money ledger. Typed rows (donation, event_fee, sale, refund),
 * integer cents always, append-only: a refund
 * is a new row parented to the original, never an edit. Card data
 * never exists here in any form; Stripe identifiers only. exported_at
 * is the QuickBooks discipline: a row exports once, ever.
 */
final class Ledger
{
    public const KINDS = ['donation', 'event_fee', 'sale', 'refund'];

    public function __construct(private Logger $logger, private ActivityLog $activity)
    {
    }

    /**
     * @param array{kind: string, amount_cents: int, constituent_id?: int, registration_id?: int,
     *              stripe_payment_intent?: string, external_key?: string, parent_id?: int, source?: string,
     *              note?: string, created_at_utc?: string} $args
     * @return int Row id, or 0 on refusal.
     */
    public function record(array $args): int
    {
        global $wpdb;
        $kind = (string) ($args['kind'] ?? '');
        $amount = (int) ($args['amount_cents'] ?? 0);
        if (!in_array($kind, self::KINDS, true) || $amount <= 0) {
            $this->logger->warn('revenue', 'Ledger write refused: unknown kind or non-positive amount.', ['kind' => $kind]);
            return 0;
        }
        $intent = (string) ($args['stripe_payment_intent'] ?? '');
        $parentId = (int) ($args['parent_id'] ?? 0);
        $externalKey = (string) ($args['external_key'] ?? '');
        if ($externalKey === '' && $intent !== '') {
            $externalKey = $kind === 'refund'
                ? 'refund:' . $parentId
                : 'stripe:' . $intent;
        }
        $createdAt = $this->normalizeCreatedAtUtc((string) ($args['created_at_utc'] ?? ''));
        if ($createdAt === null) {
            $this->logger->warn('revenue', 'Ledger write refused: invalid UTC creation timestamp.', [
                'kind' => $kind,
            ]);
            return 0;
        }
        $ok = $wpdb->insert($wpdb->prefix . 'bg_payments', [
            'kind' => $kind,
            'constituent_id' => (int) ($args['constituent_id'] ?? 0) ?: null,
            'registration_id' => (int) ($args['registration_id'] ?? 0) ?: null,
            'amount_cents' => $amount,
            'currency' => \BosqueGrande\Core\Money::currency(),
            'stripe_payment_intent' => $intent !== '' ? $intent : null,
            'external_key' => $externalKey !== '' ? bg_substr($externalKey, 0, 191) : null,
            'parent_id' => $parentId ?: null,
            'source' => bg_substr((string) ($args['source'] ?? 'manual'), 0, 24),
            'note' => bg_substr((string) ($args['note'] ?? ''), 0, 255),
            'created_at' => $createdAt,
        ]);
        if (!$ok) {
            if ($externalKey !== '' && $this->idByExternalKey($externalKey) > 0) {
                return 0; // Idempotent duplicate: the original row is authoritative.
            }
            $this->logger->warn('revenue', 'Ledger insert failed.', ['kind' => $kind]);
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        $constituentId = (int) ($args['constituent_id'] ?? 0);
        if ($constituentId) {
            $labels = ['donation' => 'Donation', 'event_fee' => 'Event fee', 'sale' => 'Sale', 'refund' => 'Refund'];
            $this->activity->append($constituentId, 'payment', sprintf('%s: %s%s',
                $labels[$kind], \BosqueGrande\Core\Money::format((int) $amount),
                ($args['note'] ?? '') !== '' ? ' (' . $args['note'] . ')' : ''));
        }
        return $id;
    }

    /**
     * Ensure one successfully paid Stripe invoice is represented once in the
     * append-only ledger. A replay may add identity/reference information that
     * was absent from the first event, but it may never change money or replace
     * conflicting ownership/reference data.
     */
    public function ensureMonthlyDonation(
        string $invoiceId,
        int $amountCents,
        int $constituentId = 0,
        string $paymentIntent = '',
        string $createdAtUtc = ''
    ): int {
        $invoiceId = trim($invoiceId);
        $paymentIntent = trim($paymentIntent);
        if (!preg_match('/^in_[A-Za-z0-9]+$/', $invoiceId)
            || $amountCents < 1
            || $amountCents > 10000000
            || $constituentId < 0
            || ($paymentIntent !== '' && !preg_match('/^pi_[A-Za-z0-9]+$/', $paymentIntent))
        ) {
            $this->logger->warn('revenue', 'Monthly-donation ledger write refused: invalid Stripe or amount data.', [
                'invoice' => $invoiceId !== '' ? $invoiceId : 'missing',
            ]);
            return 0;
        }
        $createdAt = $this->normalizeCreatedAtUtc($createdAtUtc);
        if ($createdAt === null) {
            $this->logger->warn('revenue', 'Monthly-donation ledger write refused: invalid paid timestamp.', [
                'invoice' => $invoiceId,
            ]);
            return 0;
        }

        $externalKey = 'stripe-invoice:' . $invoiceId;
        $existingId = $this->idByExternalKey($externalKey);
        if ($existingId > 0) {
            return $this->verifyAndEnrichMonthlyDonation(
                $existingId,
                $amountCents,
                $constituentId,
                $paymentIntent
            );
        }

        $createdId = $this->record([
            'kind' => 'donation',
            'amount_cents' => $amountCents,
            'constituent_id' => $constituentId,
            'stripe_payment_intent' => $paymentIntent,
            'external_key' => $externalKey,
            'source' => 'stripe_monthly',
            'note' => 'Monthly donation',
            'created_at_utc' => $createdAt,
        ]);
        if ($createdId > 0) return $createdId;

        // A Checkout confirmation and invoice webhook can race. The unique
        // external key chooses the authoritative row; validate it rather than
        // reporting a false failure or creating a second receipt.
        $existingId = $this->idByExternalKey($externalKey);
        return $existingId > 0
            ? $this->verifyAndEnrichMonthlyDonation(
                $existingId,
                $amountCents,
                $constituentId,
                $paymentIntent
            )
            : 0;
    }

    /** A refund row parented to the original. Stripe is the caller's job. */
    public function refund(int $paymentId, string $source): int
    {
        $row = $this->row($paymentId);
        if (!$row || $row->kind === 'refund' || $this->refundOf($paymentId)) return 0;
        return $this->record([
            'kind' => 'refund',
            'amount_cents' => (int) $row->amount_cents,
            'constituent_id' => (int) ($row->constituent_id ?? 0),
            'registration_id' => (int) ($row->registration_id ?? 0),
            'stripe_payment_intent' => (string) ($row->stripe_payment_intent ?? ''),
            'parent_id' => $paymentId,
            'source' => $source,
            'note' => 'Refund of #' . $paymentId,
        ]);
    }

    /** Return the existing or newly-created refund row, never a false duplicate failure. */
    public function ensureRefund(int $paymentId, string $source): int
    {
        $existing = $this->refundOf($paymentId);
        if ($existing > 0) return $existing;
        $created = $this->refund($paymentId, $source);
        return $created > 0 ? $created : $this->refundOf($paymentId);
    }

    public function row(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_payments WHERE id = %d", $id
        ));
        return $row ?: null;
    }

    public function idByIntent(string $intent): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_payments
             WHERE stripe_payment_intent = %s AND kind != 'refund' ORDER BY id ASC LIMIT 1",
            $intent
        ));
    }

    public function idByExternalKey(string $key): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_payments WHERE external_key = %s LIMIT 1",
            $key
        ));
    }

    public function refundOf(int $paymentId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_payments WHERE parent_id = %d AND kind = 'refund' LIMIT 1",
            $paymentId
        ));
    }

    /** @return list<object> Newest first. */
    public function recent(int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_payments ORDER BY id DESC LIMIT %d", $limit
        )) ?: [];
    }

    /**
     * Business-timezone months containing monthly Stripe receipts.
     *
     * @return list<string> YYYY-MM values.
     */
    public function monthlyMonths(string $order = 'desc'): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT created_at FROM {$wpdb->prefix}bg_payments
             WHERE kind = 'donation' AND source = 'stripe_monthly'
             ORDER BY created_at ASC, id ASC"
        ) ?: [];
        $months = [];
        foreach ($rows as $createdAt) {
            $month = get_date_from_gmt((string) $createdAt, 'Y-m');
            if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) $months[$month] = true;
        }
        $values = array_keys($months);
        if (strtolower($order) === 'asc') sort($values, SORT_STRING);
        else rsort($values, SORT_STRING);
        return $values;
    }

    /**
     * Monthly receipts with any parented refund and latest QBO review state.
     * The month is interpreted in the configured WordPress/business timezone.
     *
     * @return list<object>
     */
    public function monthlyRows(string $month = '', string $order = 'desc', int $limit = 1000): array
    {
        global $wpdb;
        $bounds = $this->businessMonthUtcBounds($month);
        if ($month !== '' && $bounds === null) return [];
        $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = min(1000, max(1, $limit));
        $where = "p.kind = 'donation' AND p.source = 'stripe_monthly'";
        $args = [];
        if ($bounds !== null) {
            $where .= ' AND p.created_at >= %s AND p.created_at < %s';
            $args[] = $bounds[0];
            $args[] = $bounds[1];
        }
        $args[] = $limit;
        $sql = "SELECT p.*,
                    (SELECT MIN(r.id) FROM {$wpdb->prefix}bg_payments r
                     WHERE r.kind = 'refund' AND r.parent_id = p.id) AS refund_id,
                    COALESCE((SELECT SUM(r.amount_cents) FROM {$wpdb->prefix}bg_payments r
                     WHERE r.kind = 'refund' AND r.parent_id = p.id), 0) AS refunded_cents,
                    (SELECT MAX(r.created_at) FROM {$wpdb->prefix}bg_payments r
                     WHERE r.kind = 'refund' AND r.parent_id = p.id) AS refunded_at,
                    (SELECT q.resolved FROM {$wpdb->prefix}bg_qbo_review q
                     WHERE q.payment_id = p.id ORDER BY q.id DESC LIMIT 1) AS qbo_review_resolved,
                    (SELECT q.disposition FROM {$wpdb->prefix}bg_qbo_review q
                     WHERE q.payment_id = p.id ORDER BY q.id DESC LIMIT 1) AS qbo_review_disposition,
                    (SELECT q.reason FROM {$wpdb->prefix}bg_qbo_review q
                     WHERE q.payment_id = p.id ORDER BY q.id DESC LIMIT 1) AS qbo_review_reason
                    ,(SELECT q.resolved FROM {$wpdb->prefix}bg_qbo_review q
                      INNER JOIN {$wpdb->prefix}bg_payments r ON r.id = q.payment_id
                      WHERE r.kind = 'refund' AND r.parent_id = p.id
                      ORDER BY q.id DESC LIMIT 1) AS refund_qbo_review_resolved
                    ,(SELECT q.disposition FROM {$wpdb->prefix}bg_qbo_review q
                      INNER JOIN {$wpdb->prefix}bg_payments r ON r.id = q.payment_id
                      WHERE r.kind = 'refund' AND r.parent_id = p.id
                      ORDER BY q.id DESC LIMIT 1) AS refund_qbo_review_disposition
                    ,(SELECT q.reason FROM {$wpdb->prefix}bg_qbo_review q
                      INNER JOIN {$wpdb->prefix}bg_payments r ON r.id = q.payment_id
                      WHERE r.kind = 'refund' AND r.parent_id = p.id
                      ORDER BY q.id DESC LIMIT 1) AS refund_qbo_review_reason
                FROM {$wpdb->prefix}bg_payments p
                WHERE {$where}
                ORDER BY p.created_at {$direction}, p.id {$direction}
                LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: [];
    }

    /**
     * @return array{count:int,gross:int,refunded:int,net:int}
     */
    public function monthlyTotals(string $month = ''): array
    {
        global $wpdb;
        $bounds = $this->businessMonthUtcBounds($month);
        if ($month !== '' && $bounds === null) {
            return ['count' => 0, 'gross' => 0, 'refunded' => 0, 'net' => 0];
        }
        $where = "p.kind = 'donation' AND p.source = 'stripe_monthly'";
        $args = [];
        if ($bounds !== null) {
            $where .= ' AND p.created_at >= %s AND p.created_at < %s';
            $args[] = $bounds[0];
            $args[] = $bounds[1];
        }
        $sql = "SELECT COUNT(*) AS payment_count,
                       COALESCE(SUM(p.amount_cents), 0) AS gross_cents,
                       COALESCE(SUM(COALESCE(r.refunded_cents, 0)), 0) AS refunded_cents
                FROM {$wpdb->prefix}bg_payments p
                LEFT JOIN (
                    SELECT parent_id, SUM(amount_cents) AS refunded_cents
                    FROM {$wpdb->prefix}bg_payments
                    WHERE kind = 'refund' AND parent_id IS NOT NULL
                    GROUP BY parent_id
                ) r ON r.parent_id = p.id
                WHERE {$where}";
        $row = $args
            ? $wpdb->get_row($wpdb->prepare($sql, ...$args))
            : $wpdb->get_row($sql);
        $count = (int) ($row->payment_count ?? 0);
        $gross = (int) ($row->gross_cents ?? 0);
        $refunded = (int) ($row->refunded_cents ?? 0);
        return [
            'count' => $count,
            'gross' => $gross,
            'refunded' => $refunded,
            'net' => $gross - $refunded,
        ];
    }

    /** @return array{gross: int, refunded: int, net: int} All-time cents. */
    public function totals(): array
    {
        global $wpdb;
        $gross = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM {$wpdb->prefix}bg_payments WHERE kind != 'refund'"
        );
        $refunded = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM {$wpdb->prefix}bg_payments WHERE kind = 'refund'"
        );
        return ['gross' => $gross, 'refunded' => $refunded, 'net' => $gross - $refunded];
    }

    /** @return list<object> Ledger rows never exported to QuickBooks, oldest first, capped. */
    public function unexported(int $limit): array
    {
        return $this->unexportedAfter(0, $limit);
    }

    /** @return list<object> Keyset page of rows still eligible for QBO review/export. */
    public function unexportedAfter(int $afterId, int $limit): array
    {
        global $wpdb;
        $afterId = max(0, $afterId);
        $limit = max(1, min(2000, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}bg_payments p
             WHERE p.id > %d
               AND p.exported_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->prefix}bg_qbo_review r
                   WHERE r.payment_id = p.id
                     AND r.resolved = 1
                     AND r.disposition = 'handled_in_qbo'
             )
             ORDER BY p.id ASC LIMIT %d",
            $afterId,
            $limit
        )) ?: [];
    }

    /** @param list<int> $ids */
    public function markExported(array $ids, int $exportId): bool
    {
        global $wpdb;
        if (!$ids || $exportId < 1) return false;
        $now = current_time('mysql', true);
        foreach ($ids as $id) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bg_payments
                 SET exported_at = %s, export_id = %d
                 WHERE id = %d AND exported_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}bg_qbo_review r
                       WHERE r.payment_id = {$wpdb->prefix}bg_payments.id
                         AND r.resolved = 1
                         AND r.disposition = 'handled_in_qbo'
                   )",
                $now,
                $exportId,
                (int) $id
            ));
            if ($updated !== 1) return false;
        }
        return true;
    }

    private function verifyAndEnrichMonthlyDonation(
        int $paymentId,
        int $amountCents,
        int $constituentId,
        string $paymentIntent
    ): int {
        global $wpdb;
        $row = $this->row($paymentId);
        if (!$row
            || (string) $row->kind !== 'donation'
            || (string) $row->source !== 'stripe_monthly'
            || !\BosqueGrande\Core\Money::matches((string) $row->currency)
            || (int) $row->amount_cents !== $amountCents
        ) {
            $this->logger->error('revenue', 'Monthly-donation replay conflicts with the authoritative ledger row.', [
                'payment' => $paymentId,
            ]);
            return 0;
        }
        $storedConstituent = (int) ($row->constituent_id ?? 0);
        $storedIntent = (string) ($row->stripe_payment_intent ?? '');
        if (($storedConstituent > 0 && $constituentId > 0 && $storedConstituent !== $constituentId)
            || ($storedIntent !== '' && $paymentIntent !== '' && !hash_equals($storedIntent, $paymentIntent))
        ) {
            $this->logger->error('revenue', 'Monthly-donation replay has conflicting identity or Stripe reference.', [
                'payment' => $paymentId,
            ]);
            return 0;
        }

        if ($storedConstituent < 1 && $constituentId > 0) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bg_payments SET constituent_id = %d
                 WHERE id = %d AND (constituent_id IS NULL OR constituent_id = 0)",
                $constituentId,
                $paymentId
            ));
            if ($updated === false) return 0;
        }
        if ($storedIntent === '' && $paymentIntent !== '') {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bg_payments SET stripe_payment_intent = %s
                 WHERE id = %d AND (stripe_payment_intent IS NULL OR stripe_payment_intent = '')",
                $paymentIntent,
                $paymentId
            ));
            if ($updated === false) return 0;
        }

        // Re-read after guarded updates. A concurrent replay may have filled a
        // blank first; accept only the same values and refuse every conflict.
        $row = $this->row($paymentId);
        if (!$row
            || (string) $row->kind !== 'donation'
            || (string) $row->source !== 'stripe_monthly'
            || !\BosqueGrande\Core\Money::matches((string) $row->currency)
            || (int) $row->amount_cents !== $amountCents
        ) return 0;
        $storedConstituent = (int) ($row->constituent_id ?? 0);
        $storedIntent = (string) ($row->stripe_payment_intent ?? '');
        if (($constituentId > 0 && $storedConstituent !== $constituentId)
            || ($paymentIntent !== '' && !hash_equals($storedIntent, $paymentIntent))
        ) {
            $this->logger->error('revenue', 'Concurrent monthly-donation replay resolved to conflicting data.', [
                'payment' => $paymentId,
            ]);
            return 0;
        }
        return $paymentId;
    }

    /** Return a normalized MySQL UTC timestamp, or null on invalid input. */
    private function normalizeCreatedAtUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return current_time('mysql', true);
        $utc = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, $utc);
            if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) return null;
        }
        $timestamp = $parsed->getTimestamp();
        if ($timestamp < 946684800 || $timestamp > time() + DAY_IN_SECONDS) return null;
        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * Convert one local business month into a half-open UTC SQL range.
     * Empty means all months; null means an invalid nonempty month.
     *
     * @return array{0:string,1:string}|null
     */
    private function businessMonthUtcBounds(string $month): ?array
    {
        $month = trim($month);
        if ($month === '') return null;
        if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) return null;
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
        if (!$start || $start->format('Y-m') !== $month) return null;
        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
