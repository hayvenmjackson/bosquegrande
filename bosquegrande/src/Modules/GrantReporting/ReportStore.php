<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GrantReporting;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/** Saved, reproducible report snapshots. Aggregate and staff-entered payloads are encrypted at rest. */
final class ReportStore
{
    public function __construct(private Encryption $encryption, private Logger $logger)
    {
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $snapshot */
    public function save(array $config, array $snapshot, string $narrative, int $reportId = 0): int
    {
        global $wpdb;
        if (!$this->encryption->available()) {
            $this->logger->error('grant-reporting', 'Report snapshot was not saved because encryption is unavailable.');
            return 0;
        }
        $table = $wpdb->prefix . 'bg_grant_reports';
        $payload = wp_json_encode([
            'config' => $config,
            'snapshot' => $snapshot,
            'narrative' => $narrative,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') return 0;
        $now = current_time('mysql', true);
        $meta = [
            'title' => bg_substr((string) ($config['title'] ?? 'Grant activity report'), 0, 191),
            'funder_name' => bg_substr((string) ($config['funder_name'] ?? ''), 0, 191),
            'grant_reference' => bg_substr((string) ($config['grant_reference'] ?? ''), 0, 191),
            'period_start' => (string) ($config['period_start'] ?? ''),
            'period_end' => (string) ($config['period_end'] ?? ''),
            'region_slug' => bg_substr((string) ($config['participant_region_slug'] ?? $config['region_slug'] ?? ''), 0, 48),
            'updated_at' => $now,
        ];

        if ($reportId > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $reportId));
            if ($exists !== $reportId) return 0;
            $encrypted = $this->encryption->encrypt($payload, self::context($reportId));
            if ($encrypted === '' || $encrypted === $payload) return 0;
            $ok = $wpdb->update($table, $meta + ['payload_enc' => $encrypted], ['id' => $reportId]);
            if ($ok === false) {
                $this->logger->error('grant-reporting', 'Saved report could not be updated.', ['report' => $reportId]);
                return 0;
            }
            return $reportId;
        }

        $ok = $wpdb->insert($table, $meta + [
            'payload_enc' => 'pending',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
        ]);
        if (!$ok) return 0;
        $reportId = (int) $wpdb->insert_id;
        $encrypted = $this->encryption->encrypt($payload, self::context($reportId));
        if ($encrypted === '' || $encrypted === $payload
            || $wpdb->update($table, ['payload_enc' => $encrypted], ['id' => $reportId]) === false
        ) {
            $wpdb->delete($table, ['id' => $reportId]);
            $this->logger->error('grant-reporting', 'New report snapshot could not be encrypted and was not retained.');
            return 0;
        }
        return $reportId;
    }

    /** @return array<string, mixed>|null */
    public function find(int $reportId): ?array
    {
        global $wpdb;
        if ($reportId < 1 || !$this->encryption->available()) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_grant_reports WHERE id = %d",
            $reportId
        ));
        if (!$row) return null;
        $plain = $this->encryption->decryptOrNull((string) $row->payload_enc, self::context($reportId));
        $payload = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($payload)) {
            $this->logger->error('grant-reporting', 'Saved report snapshot could not be decrypted.', ['report' => $reportId]);
            return null;
        }
        return [
            'id' => $reportId,
            'report_id' => $reportId,
            'title' => (string) $row->title,
            'funder_name' => (string) $row->funder_name,
            'grant_reference' => (string) $row->grant_reference,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'config' => is_array($payload['config'] ?? null) ? $payload['config'] : [],
            'snapshot' => is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [],
            'narrative' => (string) ($payload['narrative'] ?? ''),
        ];
    }

    /** @return list<object> */
    public function recent(int $limit = 25): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, funder_name, grant_reference, period_start, period_end,
                    region_slug, created_at, updated_at
             FROM {$wpdb->prefix}bg_grant_reports ORDER BY updated_at DESC LIMIT %d",
            max(1, min(100, $limit))
        )) ?: [];
    }

    private static function context(int $reportId): string
    {
        return 'grant-report|' . $reportId;
    }
}
