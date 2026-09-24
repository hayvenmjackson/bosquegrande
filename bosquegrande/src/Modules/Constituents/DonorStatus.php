<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Mutually exclusive constituent donor status for the people screen.
 *
 * Monthly Donor wins first. Past donation history then comes from an
 * actual donation ledger row, an imported giving-history field, or a
 * completed LGL gift lookup. An absence of evidence is never silently
 * presented as "Never donated": linked records remain Unknown until LGL
 * has explicitly been checked.
 */
final class DonorStatus
{
    public const MONTHLY = 'monthly';
    public const PAST = 'past';
    public const NEVER = 'never';
    public const UNKNOWN = 'unknown';

    public function __construct(
        private ConstituentStore $store,
        private Logger $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<int, string> constituent id => one of the status constants.
     */
    public function statusesFor(array $records, int $monthlyFieldId): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map(
            static fn(array $record): int => (int) ($record['id'] ?? 0),
            $records
        )));
        if (!$ids) return [];

        $localHistory = [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $paymentIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT constituent_id FROM {$wpdb->prefix}bg_payments
             WHERE kind = 'donation' AND constituent_id IN ({$placeholders})",
            ...$ids
        )) ?: [];
        foreach ($paymentIds as $id) $localHistory[(int) $id] = true;

        $remoteFacts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT constituent_id, has_history FROM {$wpdb->prefix}bg_constituent_donor_facts
             WHERE constituent_id IN ({$placeholders})",
            ...$ids
        )) ?: [];
        foreach ($rows as $row) $remoteFacts[(int) $row->constituent_id] = (bool) $row->has_history;

        $historyFieldIds = $this->historyFieldIds();
        $out = [];
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1) continue;
            $values = $this->store->valuesFor($id);
            $monthly = $monthlyFieldId > 0
                ? CoreFields::normalizeMonthlyDonor($values[$monthlyFieldId] ?? '')
                : '';
            if ($monthly === 'Yes') {
                $out[$id] = self::MONTHLY;
                continue;
            }
            if (isset($localHistory[$id]) || ($remoteFacts[$id] ?? false)
                || $this->storedHistory($values, $historyFieldIds)
            ) {
                $out[$id] = self::PAST;
                continue;
            }
            if (array_key_exists($id, $remoteFacts) && $remoteFacts[$id] === false) {
                $out[$id] = self::NEVER;
                continue;
            }
            // A record that has never been linked to LGL can still be known
            // locally when BosqueGrande itself created it after launch.
            $out[$id] = self::UNKNOWN;
        }
        return $out;
    }

    /** Learn a zero-call donor fact from LGL's expanded Giving Status data. */
    public function learnFromRemote(int $constituentId, array $remote): void
    {
        $history = self::historyFromRemote($remote);
        if ($constituentId > 0 && $history !== null) {
            $this->storeFact($constituentId, $history, 'lgl-status');
        }
    }

    /**
     * Query one linked LGL record for at most one gift. Returns null on a
     * transport failure, so an outage cannot turn a donor into a non-donor.
     */
    public function refreshOne(int $constituentId, object $lgl): ?bool
    {
        $record = $this->store->get($constituentId);
        $lglId = (int) ($record['lgl_constituent_id'] ?? 0);
        if (!$record || $lglId < 1 || !method_exists($lgl, 'listGifts')) return null;
        $gifts = (array) $lgl->listGifts($lglId, 1);
        if (method_exists($lgl, 'lastRequestSucceeded') && !$lgl->lastRequestSucceeded()) {
            $this->logger->warn('constituents', 'LGL donation-history refresh left the prior status unchanged after a failed request.', [
                'constituent' => $constituentId,
                'lgl_constituent' => $lglId,
            ]);
            return null;
        }
        $history = $gifts !== [];
        $this->storeFact($constituentId, $history, 'lgl-gifts');
        return $history;
    }

    /** @return list<int> Active linked constituent ids, paged for the background refresh. */
    public function linkedIds(int $offset, int $limit): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE status = 'active' AND lgl_constituent_id IS NOT NULL
             ORDER BY id ASC LIMIT %d OFFSET %d",
            max(1, min(25, $limit)),
            max(0, $offset)
        ));
        return array_map('intval', $ids ?: []);
    }

    public function checkedCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bg_constituent_donor_facts");
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::MONTHLY => 'Monthly donor',
            self::PAST => 'Past donation history',
            self::NEVER => 'Never donated',
            default => 'Not checked yet',
        };
    }

    /** A semicolon/comma-tolerant reading of LGL Giving Status categories. */
    public static function historyFromRemote(array $remote): ?bool
    {
        $positive = false;
        $negative = false;
        $direct = trim((string) ($remote['giving_status'] ?? ''));
        $sets = $direct !== '' ? [$direct] : [];
        foreach ((array) ($remote['categories'] ?? []) as $category) {
            if (!is_array($category)) continue;
            $name = self::key((string) ($category['name'] ?? $category['category_name'] ?? ''));
            if (!str_contains($name, 'givingstatus') && !str_contains($name, 'donorstatus')) continue;
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $sets[] = (string) (is_array($keyword) ? ($keyword['name'] ?? '') : $keyword);
            }
        }
        if (!$sets) return null;
        foreach (ConstituentStore::splitList(implode(';', $sets)) as $value) {
            $key = self::key($value);
            if ($key === '') continue;
            if (str_contains($key, 'neverdon') || str_contains($key, 'nondon')
                || str_contains($key, 'nodonation') || str_contains($key, 'prospectonly')
            ) {
                $negative = true;
                continue;
            }
            if (str_contains($key, 'donor') || str_contains($key, 'lapsed')
                || str_contains($key, 'former') || str_contains($key, 'gift')
            ) {
                $positive = true;
            }
        }
        return $positive ? true : ($negative ? false : null);
    }

    /** @return array<int, string> field id => key */
    private function historyFieldIds(): array
    {
        $keys = [
            'giving_summary_all_time', 'tg_amt_this_fiscal', 'tg_amt_last_fiscal',
            'tg_amount', '2026_tg_amount', '2025_tg_amount', '2024_tg_amount',
            '2023_tg_amount', '2022_tg_amount', '2021_tg_amount',
            'consecutive_years_of_giving', 'gift_date', 'gift_amount',
            'first_gift_date', 'first_gift_amount', 'last_gift_date',
            'last_gift_amount', 'largest_gift_date', 'largest_gift_amount',
        ];
        $wanted = array_fill_keys($keys, true);
        $out = [];
        foreach ($this->store->fieldDefinitions() as $def) {
            $key = (string) $def->field_key;
            if (isset($wanted[$key])) $out[(int) $def->id] = $key;
        }
        return $out;
    }

    /** @param array<int, string> $values @param array<int, string> $historyFieldIds */
    private function storedHistory(array $values, array $historyFieldIds): bool
    {
        foreach ($historyFieldIds as $fieldId => $key) {
            $value = trim((string) ($values[$fieldId] ?? ''));
            if ($value === '') continue;
            if (str_contains($key, 'date')) return true;
            if ($key === 'giving_summary_all_time') {
                $numbers = preg_replace('/[^0-9.\-]+/', '', $value);
                if ($numbers === '' || (float) $numbers > 0) return true;
                continue;
            }
            $number = preg_replace('/[^0-9.\-]+/', '', $value);
            if ($number !== '' && (float) $number > 0) return true;
        }
        return false;
    }

    private function storeFact(int $constituentId, bool $history, string $source): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_constituent_donor_facts';
        $now = current_time('mysql', true);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT constituent_id FROM {$table} WHERE constituent_id = %d",
            $constituentId
        ));
        $data = [
            'has_history' => $history ? 1 : 0,
            'source' => bg_substr($source, 0, 24),
            'checked_at' => $now,
        ];
        $ok = $exists !== null
            ? $wpdb->update($table, $data, ['constituent_id' => $constituentId]) !== false
            : (bool) $wpdb->insert($table, ['constituent_id' => $constituentId] + $data);
        if (!$ok) {
            $this->logger->warn('constituents', 'Derived donor status could not be stored.', [
                'constituent' => $constituentId,
                'database_error' => (string) $wpdb->last_error,
            ]);
        }
    }

    private static function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}
