<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/** Encrypted persistence boundary for import jobs, source rows, and lineage. */
final class ImportStore
{
    public function __construct(private Encryption $encryption, private Logger $logger)
    {
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    public function available(): bool
    {
        global $wpdb;
        if (!$this->encryption->available()) return false;
        foreach (['bg_import_jobs', 'bg_import_rows', 'bg_import_mutations', 'bg_external_records', 'bg_registration_answers'] as $suffix) {
            $table = $this->table($suffix);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }
        return true;
    }

    /** @param array<string,mixed> $inventory */
    public function createJob(string $sourceName, string $type, string $hash, string $path, array $inventory, int $userId): int
    {
        global $wpdb;
        if (!$this->available() || $path === '') return 0;
        $token = wp_generate_uuid4();
        $pathEnc = $this->encryption->encrypt($path, self::fileContext($token));
        if ($pathEnc === '' || $this->encryption->decrypt($pathEnc, self::fileContext($token)) !== $path) return 0;
        $summary = ['inventory' => $inventory, 'conflict_policy' => 'drop', 'counts' => []];
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($this->table('bg_import_jobs'), [
            'token' => $token,
            'source_name' => bg_substr(sanitize_file_name($sourceName), 0, 191),
            'source_type' => sanitize_key($type),
            'source_hash' => preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '',
            'source_sheet' => '',
            'file_path_enc' => $pathEnc,
            'mapping_enc' => '',
            'status' => 'uploaded',
            'conflict_policy' => 'drop',
            'total_rows' => 0,
            'processed_rows' => 0,
            'created_count' => 0,
            'matched_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'summary_json' => wp_json_encode($summary),
            'created_by' => max(0, $userId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public function job(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_import_jobs')} WHERE id = %d", $id
        ));
        return $row ?: null;
    }

    /** @return list<object> */
    public function jobs(int $limit = 30): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_import_jobs')} ORDER BY id DESC LIMIT %d",
            max(1, min(100, $limit))
        )) ?: [];
    }

    public function filePath(object $job): string
    {
        return $this->encryption->decrypt((string) ($job->file_path_enc ?? ''), self::fileContext((string) $job->token));
    }

    /** @param array<string,mixed> $mapping */
    public function setMapping(int $jobId, string $sheet, array $mapping): bool
    {
        global $wpdb;
        $job = $this->job($jobId);
        if (!$job) return false;
        $json = wp_json_encode($mapping);
        $enc = is_string($json) ? $this->encryption->encrypt($json, self::mappingContext((string) $job->token)) : '';
        if ($enc === '' || $this->encryption->decrypt($enc, self::mappingContext((string) $job->token)) !== $json) return false;
        return $wpdb->update($this->table('bg_import_jobs'), [
            'source_sheet' => bg_substr(sanitize_text_field($sheet), 0, 191),
            'mapping_enc' => $enc,
            'status' => 'mapped',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId]) !== false;
    }

    /** @return array<string,mixed> */
    public function mapping(object $job): array
    {
        $plain = $this->encryption->decrypt((string) ($job->mapping_enc ?? ''), self::mappingContext((string) $job->token));
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    public function summary(object $job): array
    {
        $decoded = json_decode((string) ($job->summary_json ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $summary */
    public function updateJob(int $jobId, array $fields, ?array $summary = null): bool
    {
        global $wpdb;
        $allowed = [
            'status', 'total_rows', 'processed_rows', 'created_count', 'matched_count',
            'skipped_count', 'failed_count', 'completed_at', 'file_path_enc',
        ];
        $clean = [];
        foreach ($fields as $key => $value) if (in_array($key, $allowed, true)) $clean[$key] = $value;
        if ($summary !== null) $clean['summary_json'] = wp_json_encode($summary);
        $clean['updated_at'] = current_time('mysql', true);
        return $wpdb->update($this->table('bg_import_jobs'), $clean, ['id' => $jobId]) !== false;
    }

    public function clearRows(int $jobId): bool
    {
        global $wpdb;
        if ($jobId < 1) return false;
        return $wpdb->delete($this->table('bg_import_rows'), ['job_id' => $jobId], ['%d']) !== false;
    }

    /** @param array<string,mixed> $payload @param list<string> $issues */
    public function stageRow(
        int $jobId,
        int $sourceRow,
        string $identityKey,
        string $registrationKey,
        string $state,
        array $payload,
        array $issues
    ): int {
        global $wpdb;
        $json = wp_json_encode($payload);
        $context = self::rowContext($jobId, $sourceRow);
        $enc = is_string($json) ? $this->encryption->encrypt($json, $context) : '';
        if ($enc === '' || $this->encryption->decrypt($enc, $context) !== $json) return 0;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($this->table('bg_import_rows'), [
            'job_id' => $jobId,
            'source_row' => $sourceRow,
            'identity_key' => $identityKey,
            'registration_key' => $registrationKey,
            'row_hash' => hash_hmac('sha256', $json, wp_salt('auth')),
            'state' => sanitize_key($state),
            'issues' => self::issueString($issues),
            'payload_enc' => $enc,
            'constituent_id' => 0,
            'event_post_id' => 0,
            'registration_id' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** @return array{row:object,payload:array<string,mixed>}|null */
    public function stagedRow(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_import_rows')} WHERE id = %d", $id
        ));
        if (!$row) return null;
        $plain = $this->encryption->decrypt((string) $row->payload_enc, self::rowContext((int) $row->job_id, (int) $row->source_row));
        $payload = json_decode($plain, true);
        if (!is_array($payload)) return null;
        return ['row' => $row, 'payload' => $payload];
    }

    /** @return list<array{row:object,payload:array<string,mixed>}> */
    public function rowsByState(int $jobId, array $states, int $limit = 50, int $afterId = 0): array
    {
        global $wpdb;
        $states = array_values(array_filter(array_map('sanitize_key', $states)));
        if (!$states) return [];
        $quoted = implode(',', array_fill(0, count($states), '%s'));
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table('bg_import_rows')}
             WHERE job_id = %d AND id > %d AND state IN ({$quoted})
             ORDER BY id ASC LIMIT %d",
            array_merge([$jobId, $afterId], $states, [max(1, min(500, $limit))])
        );
        $rows = $wpdb->get_results($sql) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $plain = $this->encryption->decrypt((string) $row->payload_enc, self::rowContext((int) $row->job_id, (int) $row->source_row));
            $payload = json_decode($plain, true);
            if (is_array($payload)) $out[] = ['row' => $row, 'payload' => $payload];
        }
        return $out;
    }

    /** @param array<string,mixed> $payload @param list<string> $issues */
    public function updateStagedRow(int $id, array $payload, array $issues, ?string $state = null, array $ids = []): bool
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT job_id, source_row FROM {$this->table('bg_import_rows')} WHERE id = %d", $id
        ));
        if (!$existing) return false;
        $json = wp_json_encode($payload);
        $enc = is_string($json) ? $this->encryption->encrypt($json, self::rowContext((int) $existing->job_id, (int) $existing->source_row)) : '';
        if ($enc === '') return false;
        $fields = ['payload_enc' => $enc, 'issues' => self::issueString($issues), 'updated_at' => current_time('mysql', true)];
        if ($state !== null) $fields['state'] = sanitize_key($state);
        foreach (['constituent_id', 'event_post_id', 'registration_id'] as $key) {
            if (array_key_exists($key, $ids)) $fields[$key] = max(0, (int) $ids[$key]);
        }
        return $wpdb->update($this->table('bg_import_rows'), $fields, ['id' => $id]) !== false;
    }

    public function setRowState(int $id, string $state, array $ids = [], array $extraIssues = []): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT issues FROM {$this->table('bg_import_rows')} WHERE id = %d", $id
        ));
        if (!$row) return false;
        $issues = array_filter(explode(',', (string) $row->issues));
        $issues = array_values(array_unique(array_merge($issues, $extraIssues)));
        $fields = ['state' => sanitize_key($state), 'issues' => self::issueString($issues), 'updated_at' => current_time('mysql', true)];
        foreach (['constituent_id', 'event_post_id', 'registration_id'] as $key) {
            if (array_key_exists($key, $ids)) $fields[$key] = max(0, (int) $ids[$key]);
        }
        return $wpdb->update($this->table('bg_import_rows'), $fields, ['id' => $id]) !== false;
    }

    /** @return array<string,int> */
    public function stateCounts(int $jobId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT state, COUNT(*) n FROM {$this->table('bg_import_rows')} WHERE job_id = %d GROUP BY state",
            $jobId
        )) ?: [];
        $out = [];
        foreach ($rows as $row) $out[(string) $row->state] = (int) $row->n;
        return $out;
    }

    /** @return array<string,int> */
    public function issueCounts(int $jobId): array
    {
        global $wpdb;
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT issues FROM {$this->table('bg_import_rows')} WHERE job_id = %d AND issues <> ''",
            $jobId
        )) ?: [];
        $out = [];
        foreach ($values as $list) foreach (array_filter(explode(',', (string) $list)) as $issue) {
            $out[$issue] = ($out[$issue] ?? 0) + 1;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public function purgeStaging(int $jobId, object $job): bool
    {
        global $wpdb;
        $path = $this->filePath($job);
        if ($path !== '' && is_file($path) && (!@unlink($path) || is_file($path))) {
            $this->logger->error('imports', 'A committed import source file could not be purged.', ['job' => $jobId]);
            return false;
        }
        $cleared = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('bg_import_rows')} SET payload_enc = '' WHERE job_id = %d",
            $jobId
        ));
        if ($cleared === false || !$this->updateJob($jobId, ['file_path_enc' => ''])) {
            $this->logger->error('imports', 'Committed import staging could not be purged.', ['job' => $jobId]);
            return false;
        }
        return true;
    }

    public function external(string $source, string $entityType, string $externalId): ?object
    {
        global $wpdb;
        $hash = $this->externalHash($source, $entityType, $externalId);
        if ($hash === '') return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_external_records')}
             WHERE source = %s AND entity_type = %s AND external_id_hash = %s LIMIT 1",
            sanitize_key($source), sanitize_key($entityType), $hash
        ));
        return $row ?: null;
    }

    /** @param array<string,mixed> $metadata */
    public function linkExternal(
        string $source,
        string $entityType,
        int $entityId,
        string $externalId,
        int $jobId,
        string $sourceCreatedAt = '',
        array $metadata = []
    ): bool {
        global $wpdb;
        $source = sanitize_key($source);
        $entityType = sanitize_key($entityType);
        $hash = $this->externalHash($source, $entityType, $externalId);
        if ($entityId < 1 || $hash === '') return false;
        $idEnc = $this->encryption->encrypt($externalId, self::externalIdContext($source, $entityType, $hash));
        $metaJson = wp_json_encode($metadata);
        $metaEnc = $metadata && is_string($metaJson)
            ? $this->encryption->encrypt($metaJson, self::externalMetaContext($source, $entityType, $hash)) : '';
        if ($idEnc === '' || ($metadata && $metaEnc === '')) return false;
        $now = current_time('mysql', true);
        $hasSourceDate = $sourceCreatedAt !== '';
        $sourceDateSql = $hasSourceDate ? '%s' : 'NULL';
        $args = [$source, $entityType, $entityId, $hash, $idEnc, $jobId];
        if ($hasSourceDate) $args[] = $sourceCreatedAt;
        array_push($args, $metaEnc, $now, $now);
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table('bg_external_records')}
             (source, entity_type, entity_id, external_id_hash, external_id_enc,
              import_job_id, source_created_at, metadata_enc, created_at, updated_at)
             VALUES (%s,%s,%d,%s,%s,%d,{$sourceDateSql},%s,%s,%s)
             ON DUPLICATE KEY UPDATE
              import_job_id=IF(entity_id=VALUES(entity_id),VALUES(import_job_id),import_job_id),
              source_created_at=IF(entity_id=VALUES(entity_id),VALUES(source_created_at),source_created_at),
              metadata_enc=IF(entity_id=VALUES(entity_id),VALUES(metadata_enc),metadata_enc),
              updated_at=IF(entity_id=VALUES(entity_id),VALUES(updated_at),updated_at)",
            ...$args
        );
        if ($wpdb->query($sql) === false) return false;
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT entity_id FROM {$this->table('bg_external_records')}
             WHERE source = %s AND entity_type = %s AND external_id_hash = %s LIMIT 1",
            $source,
            $entityType,
            $hash
        ));
        if ($owner !== $entityId) {
            $this->logger->warn('imports', 'An external record was already linked to a different local entity; the link was preserved.', [
                'source' => $source,
                'entity_type' => $entityType,
                'external_hash' => substr($hash, 0, 12),
                'existing_entity' => $owner,
                'requested_entity' => $entityId,
            ]);
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $answers */
    public function saveRegistrationAnswers(int $registrationId, array $answers, int $jobId, string $source = 'spreadsheet'): bool
    {
        global $wpdb;
        if ($registrationId < 1) return false;
        if ($wpdb->query('START TRANSACTION') === false) return false;
        try {
            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT payload_enc, source FROM {$this->table('bg_registration_answers')}
                 WHERE registration_id = %d FOR UPDATE",
                $registrationId
            ));
            if ($wpdb->last_error !== '') {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $storedSource = '';
            if ($row) {
                $plain = $this->encryption->decrypt(
                    (string) $row->payload_enc,
                    self::registrationAnswersContext($registrationId)
                );
                $existing = json_decode($plain, true);
                if (!is_array($existing)) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
                // Spreadsheet/historical refreshes own their ordinary answer
                // snapshot, but may not erase encrypted connector namespaces.
                foreach ($existing as $key => $value) {
                    if (str_starts_with((string) $key, 'eventbrite_') && !array_key_exists($key, $answers)) {
                        $answers[$key] = $value;
                    }
                }
                $storedSource = sanitize_key((string) $row->source);
            }
            $source = sanitize_key($source);
            $mergedSource = $storedSource === '' || $storedSource === $source ? $source : 'mixed';
            if (!$this->writeRegistrationAnswers($registrationId, $answers, $jobId, $mergedSource)) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /** @param array<string,mixed> $answers Caller owns the surrounding transaction/row lock. */
    private function writeRegistrationAnswers(int $registrationId, array $answers, int $jobId, string $source): bool
    {
        global $wpdb;
        $json = wp_json_encode($answers);
        $enc = is_string($json) ? $this->encryption->encrypt($json, self::registrationAnswersContext($registrationId)) : '';
        if ($enc === '') return false;
        $now = current_time('mysql', true);
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table('bg_registration_answers')}
             (registration_id,payload_enc,source,import_job_id,created_at,updated_at)
             VALUES (%d,%s,%s,%d,%s,%s)
             ON DUPLICATE KEY UPDATE payload_enc=VALUES(payload_enc), source=VALUES(source),
              import_job_id=VALUES(import_job_id), updated_at=VALUES(updated_at)",
            $registrationId, $enc, sanitize_key($source), $jobId, $now, $now
        );
        return $wpdb->query($sql) !== false;
    }

    /**
     * Replace one source-owned namespace without discarding another source's
     * encrypted registration answers. An unreadable existing payload is a
     * hard stop; it is never overwritten with a partial replacement.
     *
     * @param mixed $value
     */
    public function mergeRegistrationAnswerNamespace(
        int $registrationId,
        string $namespace,
        mixed $value,
        string $source
    ): bool {
        global $wpdb;
        $namespace = sanitize_key($namespace);
        $source = sanitize_key($source);
        if ($registrationId < 1 || $namespace === '' || $source === '') return false;

        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload_enc, source, import_job_id
             FROM {$this->table('bg_registration_answers')} WHERE registration_id = %d FOR UPDATE",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            $this->logger->error('imports', 'Registration answers could not be locked for a safe source merge.', [
                'registration' => $registrationId,
            ]);
            return false;
        }
        $answers = [];
        $storedSource = '';
        $jobId = 0;
        if ($row) {
            $plain = $this->encryption->decrypt(
                (string) $row->payload_enc,
                self::registrationAnswersContext($registrationId)
            );
            $decoded = json_decode($plain, true);
            if (!is_array($decoded)) {
                $this->logger->error('imports', 'An existing encrypted registration-answer payload could not be merged.', [
                    'registration' => $registrationId,
                ]);
                return false;
            }
            $answers = $decoded;
            $storedSource = sanitize_key((string) $row->source);
            $jobId = max(0, (int) $row->import_job_id);
        }
        $answers[$namespace] = $value;
        $mergedSource = $storedSource === '' || $storedSource === $source ? $source : 'mixed';
        return $this->writeRegistrationAnswers($registrationId, $answers, $jobId, $mergedSource);
    }

    /** @return array<string,mixed> */
    public function registrationAnswers(int $registrationId): array
    {
        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT payload_enc FROM {$this->table('bg_registration_answers')} WHERE registration_id = %d",
            $registrationId
        ));
        $plain = $this->encryption->decrypt($stored, self::registrationAnswersContext($registrationId));
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<int> $registrationIds @return array<int,array<string,mixed>> */
    public function registrationAnswersMany(array $registrationIds): array
    {
        global $wpdb;
        $registrationIds = array_values(array_unique(array_filter(array_map('intval', $registrationIds))));
        $out = [];
        foreach (array_chunk($registrationIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $wpdb->get_results(
                "SELECT registration_id, payload_enc FROM {$this->table('bg_registration_answers')}
                 WHERE registration_id IN ({$in})"
            ) ?: [];
            foreach ($rows as $row) {
                $registrationId = (int) $row->registration_id;
                $plain = $this->encryption->decrypt(
                    (string) $row->payload_enc,
                    self::registrationAnswersContext($registrationId)
                );
                $decoded = json_decode($plain, true);
                if (is_array($decoded)) $out[$registrationId] = $decoded;
            }
        }
        return $out;
    }

    /** @param list<int> $registrationIds @return array<string,int> */
    public function registrationIssueCountsMany(array $registrationIds): array
    {
        global $wpdb;
        $registrationIds = array_values(array_unique(array_filter(array_map('intval', $registrationIds))));
        $out = [];
        foreach (array_chunk($registrationIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $lists = $wpdb->get_col(
                "SELECT issues FROM {$this->table('bg_import_rows')}
                 WHERE registration_id IN ({$in}) AND issues <> ''"
            ) ?: [];
            foreach ($lists as $list) foreach (array_filter(explode(',', (string) $list)) as $issue) {
                $out[$issue] = ($out[$issue] ?? 0) + 1;
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @param array<string,mixed> $before */
    public function mutation(
        int $jobId,
        int $rowId,
        string $entityType,
        int $entityId,
        string $action,
        array $before,
        string $beforeHash,
        string $afterHash
    ): bool {
        global $wpdb;
        $entityType = sanitize_key($entityType);
        $beforeEnc = '';
        if ($before) {
            $json = wp_json_encode($before);
            $beforeEnc = is_string($json) ? $this->encryption->encrypt(
                $json, self::mutationContext($jobId, $rowId, $entityType, $entityId)
            ) : '';
            if ($beforeEnc === '') return false;
        }
        return (bool) $wpdb->insert($this->table('bg_import_mutations'), [
            'job_id' => $jobId,
            'import_row_id' => $rowId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => sanitize_key($action),
            'before_enc' => $beforeEnc,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
            'created_at' => current_time('mysql', true),
            'reverted_at' => null,
        ]);
    }

    /** @return list<object> */
    public function pendingMutations(int $jobId, int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_import_mutations')}
             WHERE job_id = %d AND reverted_at IS NULL ORDER BY id DESC LIMIT %d",
            $jobId, max(1, min(200, $limit))
        )) ?: [];
    }

    /** @return array<string,mixed> */
    public function mutationBefore(object $mutation): array
    {
        $plain = $this->encryption->decrypt(
            (string) $mutation->before_enc,
            self::mutationContext(
                (int) $mutation->job_id, (int) $mutation->import_row_id,
                (string) $mutation->entity_type, (int) $mutation->entity_id
            )
        );
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function markMutationReverted(int $id): bool
    {
        global $wpdb;
        return $wpdb->update($this->table('bg_import_mutations'), [
            'reverted_at' => current_time('mysql', true),
        ], ['id' => $id]) !== false;
    }

    public function remainingMutations(int $jobId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_import_mutations')} WHERE job_id = %d AND reverted_at IS NULL",
            $jobId
        ));
    }

    public function removeExternalForEntity(string $entityType, int $entityId): void
    {
        global $wpdb;
        $wpdb->delete($this->table('bg_external_records'), [
            'entity_type' => sanitize_key($entityType), 'entity_id' => $entityId,
        ], ['%s', '%d']);
    }

    private function externalHash(string $source, string $entityType, string $externalId): string
    {
        $normalized = strtolower(trim($source) . '|' . trim($entityType) . '|' . trim($externalId));
        return $normalized === '||' ? '' : $this->encryption->blindIndex($normalized);
    }

    /** @param list<string> $issues */
    private static function issueString(array $issues): string
    {
        $safe = [];
        foreach ($issues as $issue) {
            $issue = preg_replace('/[^a-z0-9:_-]+/', '_', strtolower((string) $issue)) ?: '';
            if ($issue !== '') $safe[$issue] = true;
        }
        return bg_substr(implode(',', array_keys($safe)), 0, 2000);
    }

    public static function fileContext(string $token): string { return 'import-file:' . $token; }
    public static function mappingContext(string $token): string { return 'import-mapping:' . $token; }
    public static function rowContext(int $jobId, int $row): string { return "import-row:{$jobId}:{$row}"; }
    public static function mutationContext(int $jobId, int $rowId, string $type, int $entityId): string { return "import-mutation:{$jobId}:{$rowId}:{$type}:{$entityId}"; }
    public static function externalIdContext(string $source, string $type, string $hash): string { return "external-id:{$source}:{$type}:{$hash}"; }
    public static function externalMetaContext(string $source, string $type, string $hash): string { return "external-meta:{$source}:{$type}:{$hash}"; }
    public static function registrationAnswersContext(int $registrationId): string { return "registration-answers:{$registrationId}"; }
}
