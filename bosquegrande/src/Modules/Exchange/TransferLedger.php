<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

if (!defined('ABSPATH')) exit;

/**
 * Transfer run ledger. One row per run, live or dry, manual or
 * scheduled, with counts and the full per-record report as JSON.
 * Ledger rows are never deleted; this is the CYA spine of the whole
 * Exchange design.
 */
final class TransferLedger
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bg_transfer_runs';
    }

    public function open(string $mode, string $trigger, string $sources): int
    {
        global $wpdb;
        $wpdb->insert($this->table, [
            'mode'       => $mode,
            'trig'       => $trigger,
            'sources'    => $sources,
            'started_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    /** @param array<string, int> $counts @param array<int, array<string, mixed>> $report */
    public function close(int $runId, array $counts, array $report): bool
    {
        global $wpdb;
        return $wpdb->update($this->table, [
            'finished_at' => current_time('mysql', true),
            'staged'      => (int) ($counts['staged'] ?? 0),
            'created'     => (int) ($counts['created'] ?? 0),
            'updated'     => (int) ($counts['updated'] ?? 0),
            'skipped'     => (int) ($counts['skipped'] ?? 0),
            'held'        => (int) ($counts['held'] ?? 0),
            'errors'      => (int) ($counts['errors'] ?? 0),
            'report'      => wp_json_encode($report),
        ], ['id' => $runId], ['%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s'], ['%d']) !== false;
    }

    /** @return array<int, object> */
    public function recent(int $n = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $n
        )) ?: [];
    }

    public function find(int $runId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $runId));
        return $row ?: null;
    }
}
