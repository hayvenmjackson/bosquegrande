<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Imports\ImportStore;

if (!defined('ABSPATH')) exit;

/**
 * Resumable re-encryption after a key rotation, plus the daily health check.
 *
 * Small batches run through WP-Cron and opportunistically on administrator
 * requests, so sites with disabled or unreliable cron still converge. A value
 * is replaced only after its new ciphertext is verified. A failure is
 * isolated, logged without plaintext, and surfaced on System Status.
 */
final class CryptoMigrator
{
    public const JOB = 'crypto_migrate';
    public const HEALTH_HOOK = 'bg_crypto_daily_health';

    private const STATE_OPTION = 'bg_crypto_migration_state';
    private const COMPLETE_OPTION = 'bg_crypto_migration_complete_key';
    private const FAILURE_OPTION = 'bg_crypto_migration_failures';
    private const SCHEDULED_OPTION = 'bg_crypto_migration_scheduled';
    private const LOCK = 'bg_crypto_migration_lock';
    private const BATCH = 50;

    public function __construct(
        private Encryption $encryption,
        private KeyStore $keys,
        private Settings $settings,
        private Jobs $jobs,
        private Logger $logger,
    ) {
    }

    public function boot(): void
    {
        $this->jobs->register(self::JOB, [$this, 'handle']);
        add_action(self::HEALTH_HOOK, [$this, 'dailyHealth']);
        add_action('admin_init', function (): void {
            if (!current_user_can('manage_options')) return;
            $this->startIfNeeded();
            // A bounded synchronous batch is the fallback for sites whose
            // pseudo-cron never fires. It keeps setup maintenance-free.
            if ($this->pending()) $this->runBatch();
        }, 25);
        $this->scheduleHealth();
        $this->startIfNeeded();
    }

    public function activate(): void
    {
        $this->keys->ensure();
        $this->scheduleHealth();
        $this->startIfNeeded(true);
        if ($this->pending()) $this->runBatch();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HEALTH_HOOK);
        delete_option(self::SCHEDULED_OPTION);
        // A currently running worker owns its lease. Deactivation stops new
        // schedules but must not erase that ownership; a later activation can
        // take over only after the bounded lease expires.
    }

    public function startIfNeeded(bool $force = false): void
    {
        if (!$this->encryption->available()) return;
        $target = $this->encryption->activeKeyId();
        if ($target === '') return;
        $state = $this->state();
        $complete = (string) get_option(self::COMPLETE_OPTION, '');
        $required = (int) get_option('bg_crypto_migration_required', 0) === 1;

        if (!$force && $complete === $target && !$required) return;
        if (!$force
            && (string) ($state['target'] ?? '') === $target
            && in_array((string) ($state['status'] ?? ''), ['running', 'blocked'], true)
        ) {
            if (($state['status'] ?? '') === 'running') $this->schedule();
            return;
        }

        update_option(self::STATE_OPTION, [
            'target' => $target,
            'status' => 'running',
            'stage' => 'settings',
            'cursor' => 0,
            'processed' => 0,
            'migrated' => 0,
            'failed' => 0,
            'started_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], false);
        delete_option(self::FAILURE_OPTION);
        $this->schedule();
    }

    /** @param array<string, mixed> $args */
    public function handle(array $args = []): void
    {
        delete_option(self::SCHEDULED_OPTION);
        $target = sanitize_text_field((string) ($args['target'] ?? ''));
        if ($target !== '' && $target !== $this->encryption->activeKeyId()) {
            $this->startIfNeeded(true);
            return;
        }
        $this->runBatch();
        if ($this->pending()) $this->schedule();
    }

    public function runBatch(): void
    {
        if (!$this->encryption->available()) return;
        $lockOwner = OptionLease::acquire(self::LOCK, 15 * MINUTE_IN_SECONDS);
        if ($lockOwner === '') return;
        try {
            $state = $this->state();
            if (($state['status'] ?? '') !== 'running') return;
            if ((string) ($state['target'] ?? '') !== $this->encryption->activeKeyId()) {
                $this->startIfNeeded(true);
                return;
            }

            $stage = (string) ($state['stage'] ?? 'settings');
            $done = match ($stage) {
                'settings' => $this->migrateSettings($state),
                'constituents' => $this->migrateConstituents($state),
                'emails' => $this->migrateEmails($state),
                'cvalues' => $this->migrateValues($state),
                'diffs' => $this->migrateDiffs($state),
                'imports' => $this->migrateImports($state),
                'writebacks' => $this->migrateWritebacks($state),
                'postmeta' => $this->migratePostMeta($state),
                'organizer_app' => $this->migrateOrganizerApp($state),
                'qbo_exports' => $this->migrateQboExports($state),
                'captures' => $this->migrateCaptures($state),
                default => true,
            };
            if ($done) $this->advance($state);
            $state['updated_at'] = current_time('mysql', true);
            update_option(self::STATE_OPTION, $state, false);
        } catch (\Throwable $e) {
            $state = $this->state();
            $this->failure($state, 'worker', (string) ($state['stage'] ?? ''), $e->getMessage());
            $state['status'] = 'blocked';
            $state['updated_at'] = current_time('mysql', true);
            update_option(self::STATE_OPTION, $state, false);
        } finally {
            OptionLease::release(self::LOCK, $lockOwner);
        }
    }

    public function retry(): void
    {
        delete_option(self::FAILURE_OPTION);
        $this->startIfNeeded(true);
    }

    public function rotate(): ?string
    {
        $id = $this->keys->rotate();
        if ($id !== null) $this->startIfNeeded(true);
        return $id;
    }

    public function pending(): bool
    {
        return ($this->state()['status'] ?? '') === 'running';
    }

    /**
     * @return array{
     *   health: array{ok: bool, key_id: string, status: string},
     *   migration: array<string, mixed>,
     *   failures: list<array<string, string>>
     * }
     */
    public function report(): array
    {
        $failures = get_option(self::FAILURE_OPTION, []);
        return [
            'health' => $this->encryption->healthCheck(),
            'migration' => $this->state(),
            'failures' => is_array($failures) ? array_values($failures) : [],
        ];
    }

    public function dailyHealth(): void
    {
        $health = $this->encryption->healthCheck();
        update_option('bg_crypto_health', [
            'checked_at' => current_time('mysql', true),
            'ok' => $health['ok'] ? 1 : 0,
            'status' => $health['status'],
            'key_id' => $health['key_id'],
        ], false);
        if (!$health['ok']) {
            $this->logger->error('encryption', 'The automated encryption self-test failed.', [
                'status' => $health['status'],
            ]);
            return;
        }
        $this->startIfNeeded();
    }

    /** @param array<string, mixed> $state */
    private function migrateSettings(array &$state): bool
    {
        $result = $this->settings->rewrapSecrets();
        $state['processed'] = (int) $state['processed'] + $result['migrated'] + $result['failed'];
        $state['migrated'] = (int) $state['migrated'] + $result['migrated'];
        if ($result['failed'] > 0) {
            $this->failure($state, 'settings', 'credentials', 'One or more stored credentials were unreadable.');
        }
        return true;
    }

    /** @param array<string, mixed> $state */
    private function migrateConstituents(array &$state): bool
    {
        global $wpdb;
        if (!$this->tableExists('bg_constituents')) return true;
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, envelope FROM {$wpdb->prefix}bg_constituents
             WHERE id > %d AND envelope != '' ORDER BY id ASC LIMIT %d",
            $cursor,
            self::BATCH
        )) ?: [];
        if (!$rows) return true;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $state['cursor'] = $id;
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) $row->envelope;
            $context = 'constituent:' . $id;
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'constituent', (string) $id, 'Envelope authentication failed.');
                continue;
            }
            $decoded = json_decode($plain, true);
            if (!is_array($decoded)) {
                $this->failure($state, 'constituent', (string) $id, 'Envelope JSON was invalid.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            if ($cipher === '') {
                $this->failure($state, 'constituent', (string) $id, 'A new envelope could not be produced.');
                continue;
            }
            $email = ConstituentStore::normEmail((string) ($decoded['email'] ?? ''));
            $name = ConstituentStore::normName(
                (string) ($decoded['first_name'] ?? ''),
                (string) ($decoded['last_name'] ?? '')
            );
            $phone = ConstituentStore::normPhone((string) ($decoded['phone'] ?? ''));
            $ok = $wpdb->update($wpdb->prefix . 'bg_constituents', [
                'envelope' => $cipher,
                'email_bidx' => $email === '' ? null : $this->encryption->blindIndex($email),
                'name_bidx' => $name === '' ? null : $this->encryption->blindIndex($name),
                'phone_bidx' => $phone === '' ? null : $this->encryption->blindIndex($phone),
                'updated_at' => current_time('mysql', true),
            ], ['id' => $id]);
            if ($ok === false) {
                $this->failure($state, 'constituent', (string) $id, (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    /** @param array<string, mixed> $state */
    private function migrateEmails(array &$state): bool
    {
        global $wpdb;
        if (!$this->tableExists('bg_constituent_emails')) return true;
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, constituent_id, email_enc, email_bidx
             FROM {$wpdb->prefix}bg_constituent_emails
             WHERE id > %d ORDER BY id ASC LIMIT %d",
            $cursor,
            self::BATCH
        )) ?: [];
        if (!$rows) return true;
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $state['cursor'] = $id;
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) $row->email_enc;
            $context = 'constituent-email:' . (int) $row->constituent_id
                . ':' . (string) $row->email_bidx;
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null || !is_email(ConstituentStore::normEmail($plain))) {
                $this->failure($state, 'constituent-email', (string) $id, 'Email identity authentication failed.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt(ConstituentStore::normEmail($plain), $context);
            $ok = $cipher !== '' && $wpdb->update(
                $wpdb->prefix . 'bg_constituent_emails',
                ['email_enc' => $cipher, 'updated_at' => current_time('mysql', true)],
                ['id' => $id]
            ) !== false;
            if (!$ok) {
                $this->failure($state, 'constituent-email', (string) $id, (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    /** @param array<string, mixed> $state */
    private function migrateValues(array &$state): bool
    {
        global $wpdb;
        if (!$this->tableExists('bg_cvalues')) return true;
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [0, 0];
        $cid = max(0, (int) ($cursor[0] ?? 0));
        $fid = max(0, (int) ($cursor[1] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT constituent_id, field_id, value_enc
             FROM {$wpdb->prefix}bg_cvalues
             WHERE constituent_id > %d
                OR (constituent_id = %d AND field_id > %d)
             ORDER BY constituent_id ASC, field_id ASC LIMIT %d",
            $cid,
            $cid,
            $fid,
            self::BATCH
        )) ?: [];
        if (!$rows) return true;
        foreach ($rows as $row) {
            $cid = (int) $row->constituent_id;
            $fid = (int) $row->field_id;
            $state['cursor'] = [$cid, $fid];
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) ($row->value_enc ?? '');
            if ($stored === '') continue;
            $context = "cvalue:{$cid}:{$fid}";
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'custom-value', "{$cid}:{$fid}", 'Value authentication failed.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            $ok = $cipher !== '' && $wpdb->update(
                $wpdb->prefix . 'bg_cvalues',
                ['value_enc' => $cipher],
                ['constituent_id' => $cid, 'field_id' => $fid]
            ) !== false;
            if (!$ok) {
                $this->failure($state, 'custom-value', "{$cid}:{$fid}", (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    /** @param array<string, mixed> $state */
    private function migrateDiffs(array &$state): bool
    {
        global $wpdb;
        if (!$this->tableExists('bg_lgl_diffs')) return true;
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, constituent_id, lgl_id, field_key, payload_enc
             FROM {$wpdb->prefix}bg_lgl_diffs
             WHERE id > %d ORDER BY id ASC LIMIT %d",
            $cursor,
            self::BATCH
        )) ?: [];
        if (!$rows) return true;
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $state['cursor'] = $id;
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) ($row->payload_enc ?? '');
            if ($stored === '') continue;
            $context = 'lgl-diff:' . (int) $row->constituent_id
                . ':' . (int) $row->lgl_id
                . ':' . sanitize_key((string) $row->field_key);
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'lgl-diff', (string) $id, 'Difference authentication failed.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            $ok = $cipher !== '' && $wpdb->update(
                $wpdb->prefix . 'bg_lgl_diffs',
                ['payload_enc' => $cipher],
                ['id' => $id]
            ) !== false;
            if (!$ok) {
                $this->failure($state, 'lgl-diff', (string) $id, (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    /** Rewrap every encrypted Import Studio field without exposing its value. */
    private function migrateImports(array &$state): bool
    {
        global $wpdb;
        $definitions = [
            [
                'table' => 'bg_import_jobs', 'id' => 'id',
                'select' => 'id, token, file_path_enc, mapping_enc',
                'fields' => [
                    'file_path_enc' => static fn(object $row): string => ImportStore::fileContext((string) $row->token),
                    'mapping_enc' => static fn(object $row): string => ImportStore::mappingContext((string) $row->token),
                ],
            ],
            [
                'table' => 'bg_import_rows', 'id' => 'id',
                'select' => 'id, job_id, source_row, payload_enc',
                'fields' => [
                    'payload_enc' => static fn(object $row): string => ImportStore::rowContext((int) $row->job_id, (int) $row->source_row),
                ],
            ],
            [
                'table' => 'bg_import_mutations', 'id' => 'id',
                'select' => 'id, job_id, import_row_id, entity_type, entity_id, before_enc',
                'fields' => [
                    'before_enc' => static fn(object $row): string => ImportStore::mutationContext(
                        (int) $row->job_id, (int) $row->import_row_id,
                        (string) $row->entity_type, (int) $row->entity_id
                    ),
                ],
            ],
            [
                'table' => 'bg_external_records', 'id' => 'id',
                'select' => 'id, source, entity_type, external_id_hash, external_id_enc, metadata_enc',
                'fields' => [
                    'external_id_enc' => static fn(object $row): string => ImportStore::externalIdContext(
                        (string) $row->source, (string) $row->entity_type, (string) $row->external_id_hash
                    ),
                    'metadata_enc' => static fn(object $row): string => ImportStore::externalMetaContext(
                        (string) $row->source, (string) $row->entity_type, (string) $row->external_id_hash
                    ),
                ],
            ],
            [
                'table' => 'bg_registration_answers', 'id' => 'registration_id',
                'select' => 'registration_id, payload_enc',
                'fields' => [
                    'payload_enc' => static fn(object $row): string => ImportStore::registrationAnswersContext((int) $row->registration_id),
                ],
            ],
        ];

        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [0, 0];
        $definitionIndex = max(0, (int) ($cursor[0] ?? 0));
        $rowCursor = max(0, (int) ($cursor[1] ?? 0));
        if (!isset($definitions[$definitionIndex])) return true;
        $definition = $definitions[$definitionIndex];
        if (!$this->tableExists($definition['table'])) {
            $state['cursor'] = [$definitionIndex + 1, 0];
            return !isset($definitions[$definitionIndex + 1]);
        }
        $table = $wpdb->prefix . $definition['table'];
        $idField = $definition['id'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$definition['select']} FROM {$table}
             WHERE {$idField} > %d ORDER BY {$idField} ASC LIMIT %d",
            $rowCursor,
            self::BATCH
        )) ?: [];

        foreach ($rows as $row) {
            $rowId = (int) $row->{$idField};
            $state['cursor'] = [$definitionIndex, $rowId];
            $updates = [];
            foreach ($definition['fields'] as $field => $contextFactory) {
                $stored = (string) ($row->{$field} ?? '');
                if ($stored === '') continue;
                $state['processed'] = (int) $state['processed'] + 1;
                $context = $contextFactory($row);
                $plain = $this->encryption->decryptOrNull($stored, $context);
                if ($plain === null) {
                    $this->failure($state, 'import-' . $field, (string) $rowId, 'Import value authentication failed.');
                    continue;
                }
                if (!$this->encryption->needsReencrypt($stored)) continue;
                $cipher = $this->encryption->encrypt($plain, $context);
                if ($cipher === '') {
                    $this->failure($state, 'import-' . $field, (string) $rowId, 'Import value could not be re-encrypted.');
                    continue;
                }
                $updates[$field] = $cipher;
                $state['migrated'] = (int) $state['migrated'] + 1;
            }
            if ($updates && $wpdb->update($table, $updates, [$idField => $rowId]) === false) {
                $state['migrated'] = max(0, (int) $state['migrated'] - count($updates));
                $this->failure($state, 'import-row', (string) $rowId, (string) $wpdb->last_error);
            }
        }

        if (count($rows) >= self::BATCH) return false;
        $state['cursor'] = [$definitionIndex + 1, 0];
        return !isset($definitions[$definitionIndex + 1]);
    }

    /** @param array<string, mixed> $state */
    private function migrateWritebacks(array &$state): bool
    {
        $queue = get_option('bg_writeback_queue', []);
        if (!is_array($queue) || $queue === []) return true;
        $changed = false;
        foreach ($queue as $idx => $entry) {
            if (!is_array($entry)) continue;
            $state['processed'] = (int) $state['processed'] + 1;
            $cid = max(0, (int) ($entry['constituent_id'] ?? 0));
            $context = 'writeback:' . $cid;
            $stored = (string) ($entry['payload_enc'] ?? '');
            if ($stored === '' || !$this->encryption->needsReencrypt($stored)) continue;
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'writeback', (string) $idx, 'Queue entry authentication failed.');
                continue;
            }
            if ($plain === '') continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            if ($cipher === '') {
                $this->failure($state, 'writeback', (string) $idx, 'Queue entry could not be encrypted.');
                continue;
            }
            $queue[$idx]['payload_enc'] = $cipher;
            $changed = true;
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        if ($changed) update_option('bg_writeback_queue', array_values($queue), false);
        return true;
    }

    /** @param array<string, mixed> $state */
    private function migratePostMeta(array &$state): bool
    {
        global $wpdb;
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $keys = ['_bg_submitter_name', '_bg_submitter_email', '_bg_submitter_ip'];
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_id > %d AND meta_key IN ({$placeholders})
             ORDER BY meta_id ASC LIMIT %d",
            array_merge([$cursor], $keys, [self::BATCH])
        );
        $rows = $wpdb->get_results($sql) ?: [];
        if (!$rows) return true;
        foreach ($rows as $row) {
            $metaId = (int) $row->meta_id;
            $state['cursor'] = $metaId;
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) $row->meta_value;
            if ($stored === '') continue;
            $context = 'event-meta:' . (int) $row->post_id . ':' . (string) $row->meta_key;
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'event-meta', (string) $metaId, 'Metadata authentication failed.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            if ($cipher === ''
                || $wpdb->update($wpdb->postmeta, ['meta_value' => $cipher], ['meta_id' => $metaId]) === false
            ) {
                $this->failure($state, 'event-meta', (string) $metaId, (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    /**
     * Rewrap organizer-app check references, void reasons, and closeout
     * explanations. The cursor is [table_index, row_id], allowing the worker
     * to resume without exposing or logging plaintext.
     *
     * @param array<string, mixed> $state
     */
    private function migrateOrganizerApp(array &$state): bool
    {
        global $wpdb;
        $definitions = [
            [
                'table' => 'bg_event_tenders',
                'fields' => [
                    'check_reference_enc' => 'organizer-tender-check:',
                    'void_reason_enc' => 'organizer-tender-void:',
                ],
            ],
            [
                'table' => 'bg_event_closeouts',
                'fields' => [
                    'explanation_enc' => 'organizer-closeout:',
                ],
            ],
        ];
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [0, 0];
        $tableIndex = max(0, (int) ($cursor[0] ?? 0));
        $rowId = max(0, (int) ($cursor[1] ?? 0));

        while (isset($definitions[$tableIndex])) {
            $definition = $definitions[$tableIndex];
            $suffix = (string) $definition['table'];
            if (!$this->tableExists($suffix)) {
                $tableIndex++;
                $rowId = 0;
                $state['cursor'] = [$tableIndex, $rowId];
                continue;
            }
            $columns = ['id'];
            foreach (array_keys($definition['fields']) as $field) $columns[] = $field;
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT ' . implode(', ', $columns) . " FROM {$wpdb->prefix}{$suffix} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $rowId,
                self::BATCH
            )) ?: [];
            if (!$rows) {
                $tableIndex++;
                $rowId = 0;
                $state['cursor'] = [$tableIndex, $rowId];
                continue;
            }

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $rowId = $id;
                $state['cursor'] = [$tableIndex, $rowId];
                foreach ($definition['fields'] as $field => $contextPrefix) {
                    $stored = (string) ($row->{$field} ?? '');
                    if ($stored === '') continue;
                    $state['processed'] = (int) $state['processed'] + 1;
                    $context = (string) $contextPrefix . $id;
                    $plain = $this->encryption->decryptOrNull($stored, $context);
                    if ($plain === null) {
                        $this->failure($state, 'organizer-app-' . $field, (string) $id, 'Encrypted organizer-app value could not be authenticated.');
                        continue;
                    }
                    if (!$this->encryption->needsReencrypt($stored)) continue;
                    $cipher = $this->encryption->encrypt($plain, $context);
                    if ($cipher === '' || $cipher === $plain) {
                        $this->failure($state, 'organizer-app-' . $field, (string) $id, 'Encrypted organizer-app value could not be re-encrypted.');
                        continue;
                    }
                    $ok = $wpdb->update(
                        $wpdb->prefix . $suffix,
                        [$field => $cipher, 'updated_at' => current_time('mysql', true)],
                        ['id' => $id]
                    ) !== false;
                    if (!$ok || $this->encryption->decryptOrNull($cipher, $context) !== $plain) {
                        $this->failure($state, 'organizer-app-' . $field, (string) $id, (string) $wpdb->last_error);
                        continue;
                    }
                    $state['migrated'] = (int) $state['migrated'] + 1;
                }
            }
            if (count($rows) >= self::BATCH) return false;
            $tableIndex++;
            $rowId = 0;
            $state['cursor'] = [$tableIndex, $rowId];
        }
        return true;
    }

    /** Rewrap retained QBO CSVs after a data-key rotation. */
    private function migrateQboExports(array &$state): bool
    {
        global $wpdb;
        if (!$this->tableExists('bg_qbo_exports')
            || !$this->columnExists('bg_qbo_exports', 'payload_enc')
            || !$this->columnExists('bg_qbo_exports', 'payload_sha256')) {
            return true;
        }
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payload_enc, payload_sha256 FROM {$wpdb->prefix}bg_qbo_exports
             WHERE id > %d AND payload_enc IS NOT NULL AND payload_enc != ''
             ORDER BY id ASC LIMIT %d",
            $cursor,
            self::BATCH
        ));
        if (!is_array($rows)) {
            // Do not report a key rotation complete when the retained-export
            // scan itself failed. Record a bounded failure, advance through the
            // remaining stages, and let the worker finish in its blocked state.
            $scanError = bg_substr(sanitize_text_field((string) $wpdb->last_error), 0, 180);
            $this->failure(
                $state,
                'qbo-export',
                'scan',
                $scanError !== '' ? $scanError : 'Retained export scan failed.'
            );
            return true;
        }
        if (!$rows) return true;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $state['cursor'] = $id;
            $state['processed'] = (int) $state['processed'] + 1;
            $stored = (string) $row->payload_enc;
            $context = 'qbo-export|' . $id;
            // QBO payloads have existed only as context-bound bg2 values.
            if (!str_starts_with($stored, 'bg2:')) {
                $this->failure($state, 'qbo-export', (string) $id, 'Export payload was not authenticated bg2 ciphertext.');
                continue;
            }
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if (!is_string($plain) || $plain === ''
                || !hash_equals((string) $row->payload_sha256, hash('sha256', $plain))) {
                $this->failure($state, 'qbo-export', (string) $id, 'Export payload authentication or digest verification failed.');
                continue;
            }
            if (!$this->encryption->needsReencrypt($stored)) continue;
            $cipher = $this->encryption->encrypt($plain, $context);
            if (!str_starts_with($cipher, 'bg2:')
                || $this->encryption->decryptOrNull($cipher, $context) !== $plain
                || $wpdb->update(
                    $wpdb->prefix . 'bg_qbo_exports',
                    ['payload_enc' => $cipher, 'payload_sha256' => hash('sha256', $plain)],
                    ['id' => $id]
                ) === false) {
                $this->failure($state, 'qbo-export', (string) $id, (string) $wpdb->last_error);
                continue;
            }
            $state['migrated'] = (int) $state['migrated'] + 1;
        }
        return count($rows) < self::BATCH;
    }

    private function migrateCaptures(array &$state): bool
    {
        $uploads = wp_get_upload_dir();
        $dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'bg-fixtures';
        if (!is_dir($dir)) return true;
        $paths = glob(trailingslashit($dir) . '*.bgcap') ?: [];
        sort($paths, SORT_STRING);
        $rewritten = 0;
        foreach ($paths as $path) {
            $name = basename($path);
            $stored = file_get_contents($path);
            if (!is_string($stored) || $stored === '' || !$this->encryption->needsReencrypt($stored)) continue;
            $context = 'capture:' . $name;
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) {
                $this->failure($state, 'capture', $name, 'Capture authentication failed.');
                continue;
            }
            $cipher = $this->encryption->encrypt($plain, $context);
            if ($cipher === '' || !$this->atomicWrite($path, $cipher)) {
                $this->failure($state, 'capture', $name, 'Capture could not be rewritten.');
                continue;
            }
            $written = file_get_contents($path);
            if (!is_string($written)
                || !hash_equals($cipher, $written)
                || $this->encryption->decryptOrNull($written, $context) !== $plain
            ) {
                $this->failure($state, 'capture', $name, 'Capture could not be rewritten.');
                continue;
            }
            $state['processed'] = (int) $state['processed'] + 1;
            $state['migrated'] = (int) $state['migrated'] + 1;
            $rewritten++;
            if ($rewritten >= self::BATCH) return false;
        }
        return true;
    }

    private function atomicWrite(string $path, string $contents): bool
    {
        try {
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return false;
        }
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    /** @param array<string, mixed> $state */
    private function advance(array &$state): void
    {
        $order = ['settings', 'constituents', 'emails', 'cvalues', 'diffs', 'imports', 'writebacks', 'postmeta', 'organizer_app', 'qbo_exports', 'captures'];
        $index = array_search((string) ($state['stage'] ?? ''), $order, true);
        if ($index === false || !isset($order[$index + 1])) {
            if ((int) ($state['failed'] ?? 0) > 0) {
                $state['status'] = 'blocked';
                $this->logger->error('encryption', 'Encryption migration completed with isolated failures.', [
                    'failed' => (int) $state['failed'],
                    'migrated' => (int) $state['migrated'],
                ]);
                return;
            }
            $state['status'] = 'complete';
            $state['completed_at'] = current_time('mysql', true);
            update_option(self::COMPLETE_OPTION, (string) $state['target'], false);
            delete_option('bg_crypto_migration_required');
            delete_option(self::SCHEDULED_OPTION);
            $this->logger->info('encryption', 'Encryption migration completed automatically.', [
                'migrated' => (int) $state['migrated'],
                'key_id' => (string) $state['target'],
            ]);
            return;
        }
        $state['stage'] = $order[$index + 1];
        $state['cursor'] = in_array($state['stage'], ['cvalues', 'imports'], true) ? [0, 0] : 0;
    }

    /** @param array<string, mixed> $state */
    private function failure(array &$state, string $type, string $id, string $message): void
    {
        $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
        $failures = get_option(self::FAILURE_OPTION, []);
        if (!is_array($failures)) $failures = [];
        if (count($failures) < 50) {
            $failures[] = [
                'type' => sanitize_key($type),
                'id' => sanitize_text_field($id),
                'message' => bg_substr(sanitize_text_field($message ?: 'Unknown failure'), 0, 180),
                'at' => current_time('mysql', true),
            ];
            update_option(self::FAILURE_OPTION, $failures, false);
        }
        $this->logger->error('encryption', 'A stored value could not be migrated.', [
            'type' => $type,
            'id' => $id,
            'reason' => bg_substr($message, 0, 180),
        ]);
    }

    private function schedule(): void
    {
        if (get_option(self::SCHEDULED_OPTION, '')) return;
        $target = $this->encryption->activeKeyId();
        if ($target === '') return;
        if ($this->jobs->enqueue(self::JOB, ['target' => $target], 30)) {
            update_option(self::SCHEDULED_OPTION, current_time('mysql', true), false);
        }
    }

    private function scheduleHealth(): void
    {
        if (!wp_next_scheduled(self::HEALTH_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HEALTH_HOOK);
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private function tableExists(string $suffix): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function columnExists(string $suffix, string $column): bool
    {
        global $wpdb;
        if (!$this->tableExists($suffix)) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$wpdb->prefix}{$suffix} LIKE %s",
            $column
        ));
    }
}
