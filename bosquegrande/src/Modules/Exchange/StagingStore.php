<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

if (!defined('ABSPATH')) exit;

/**
 * Verbatim payload store. Source records are preserved with their hashes
 * before interpretation, so dry and live mapping use identical input and
 * every transfer retains an auditable source snapshot.
 */
final class StagingStore
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bg_staging';
    }

    public function stage(int $runId, string $source, string $externalId, string $payload): int
    {
        global $wpdb;
        $ok = $wpdb->insert($this->table, [
            'run_id'      => $runId,
            'source'      => $source,
            'external_id' => $externalId,
            'payload'     => $payload,
            'sha256'      => hash('sha256', $payload),
            'captured_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%s', '%s']);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** @return array<int, object{id: int, source: string, external_id: string, payload: string, sha256: string}> */
    public function rowsForRun(int $runId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, source, external_id, payload, sha256 FROM {$this->table} WHERE run_id = %d ORDER BY id ASC",
            $runId
        )) ?: [];
    }

    public function countForRun(int $runId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE run_id = %d", $runId
        ));
    }

    /** Retention: staged bytes older than the window go; ledger rows stay forever. */
    public function prune(int $days = 90): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE captured_at < %s",
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }
}
