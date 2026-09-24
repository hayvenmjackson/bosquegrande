<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * The database schema, installed and repaired with dbDelta.
 *
 * VERSION is stored in bg_db_version. A fresh site gets the whole schema in
 * one pass. A site coming from BosqueGrande 4.1.x (schema 30) is brought to
 * the v5 shape by upgradeFrom4(); anything older must first be upgraded to
 * 4.1.x, because v5 no longer carries the earlier data conversions.
 */
final class Schema
{
    public const VERSION = 31;
    private const LEGACY_4X = 30;
    private const LEGACY_DEMOGRAPHIC_LABEL = 'DIVERSITY: Do you identify as any of the following?';

    /** Seeded answer fields shared by registration, profile, and imports. */
    private const ANSWER_FIELDS = [
        ['demographic', 'Demographics', 1],
        ['age_range', 'Age Range', 1],
        ['relationship_status', 'Relationship Status', 1],
        ['how_did_you_hear_about_this_event', 'How did you hear about this event?', 1],
        ['promotion_consent', 'Promotion consent', 1],
    ];

    /** Why the last run() stopped, for the admin notice; empty when fine. */
    private string $blocked = '';

    /**
     * Bring the database to VERSION. Returns true when the schema is current
     * and verified.
     */
    public function run(): bool
    {
        global $wpdb;
        $from = (int) get_option('bg_db_version', 0);
        $this->blocked = '';
        if ($from > 0 && $from < self::LEGACY_4X) {
            $this->blocked = 'This database was created by a BosqueGrande release older than 4.1. Upgrade it to 4.1.x first, then install 5.0.';
            return false;
        }
        if ($from === self::LEGACY_4X && !$this->upgradeFrom4()) {
            return false;
        }
        $wpdb->last_error = '';
        $this->install();
        if (!$this->verify()) {
            (new Logger())->error('schema', 'The database schema did not verify.', [
                'database_error' => (string) $wpdb->last_error,
            ]);
            if ($this->blocked === '') {
                $this->blocked = 'The database tables could not be created or verified. Check the database error log; setup retries on the next request.';
            }
            return false;
        }
        if ($from !== self::VERSION) update_option('bg_db_version', self::VERSION, false);
        return true;
    }

    public function blockedReason(): string
    {
        return $this->blocked;
    }

    /** Create or complete every table. Safe to repeat. */
    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (self::tables() as $suffix => $body) {
            dbDelta("CREATE TABLE {$wpdb->prefix}{$suffix} ({$body}\n) {$charset};");
        }
        $this->seedAnswerFields();
        $this->seedDefaultGroup();
    }

    /** Every table and column exists, and the answer fields are seeded. */
    public function verify(): bool
    {
        global $wpdb;
        if ((string) $wpdb->last_error !== '') return false;
        foreach (self::tables() as $suffix => $body) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
            $present = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0));
            foreach (self::columns($body) as $column) {
                if (!in_array($column, $present, true)) return false;
            }
        }
        return $this->answerStorageHealthy();
    }

    /**
     * Repair the custom-field tables on demand. A form save can reach this
     * after a host restored only part of the database.
     */
    public function repairAnswerStorage(): bool
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $tables = self::tables();
        foreach (['bg_cfields', 'bg_cvalues'] as $suffix) {
            dbDelta("CREATE TABLE {$wpdb->prefix}{$suffix} ({$tables[$suffix]}\n) {$charset};");
        }
        $this->seedAnswerFields();
        return $this->answerStorageHealthy();
    }

    public function answerStorageHealthy(): bool
    {
        global $wpdb;
        $fields = $wpdb->prefix . 'bg_cfields';
        $values = $wpdb->prefix . 'bg_cvalues';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fields)) !== $fields) return false;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $values)) !== $values) return false;
        $keys = array_column(self::ANSWER_FIELDS, 0);
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $seeded = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT field_key) FROM {$fields} WHERE field_key IN ({$placeholders})",
            $keys
        ));
        return $seeded === count($keys);
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::tables());
    }

    private function seedAnswerFields(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_cfields';
        foreach (self::ANSWER_FIELDS as [$key, $label, $sensitive]) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE field_key = %s", $key));
            if ($exists > 0) continue;
            $inserted = $wpdb->insert($table, [
                'field_key' => $key,
                'label' => $label,
                'sensitive' => $sensitive,
                'created_at' => current_time('mysql', true),
            ]);
            if ($inserted === false) {
                (new Logger())->error('schema', 'An answer field could not be seeded.', [
                    'field_key' => $key,
                    'database_error' => (string) $wpdb->last_error,
                ]);
            }
        }
    }

    /**
     * The default subscriber group. It cannot be archived, and newsletter
     * opt-ins pulled from LGL land in it.
     */
    private function seedDefaultGroup(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_groups';
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE slug = 'general'") > 0) return;
        $wpdb->insert($table, [
            'slug' => 'general',
            'name' => 'Newsletter',
            'description' => '',
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * 4.1.x to 5.0. The 4.1 encryption worker must have finished, because v5
     * reads only current ciphertext. Plaintext compatibility columns and two
     * never-used tables are dropped once they are confirmed empty.
     */
    private function upgradeFrom4(): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $required = (int) get_option('bg_crypto_migration_required', 0) === 1;
        $state = get_option('bg_crypto_migration_state', []);
        $status = is_array($state) ? (string) ($state['status'] ?? '') : '';
        if ($required || in_array($status, ['running', 'blocked'], true)) {
            $this->blocked = 'The 4.1 encryption update has not finished on this site. Reinstall 4.1.x, let System Status report the encryption update as complete, then install 5.0.';
            return false;
        }
        foreach ([['bg_cvalues', 'value_text'], ['bg_lgl_diffs', 'payload_json']] as [$suffix, $column]) {
            $table = $p . $suffix;
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column))) continue;
            $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> ''");
            if ($left > 0) {
                $this->blocked = 'Some stored answers are still unencrypted. Reinstall 4.1.x and let its encryption update finish, then install 5.0.';
                return false;
            }
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
        foreach (['bg_volunteer_shifts', 'bg_qbo_cache'] as $suffix) {
            $table = $p . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === 0) {
                $wpdb->query("DROP TABLE {$table}");
            }
        }
        $wpdb->update($p . 'bg_cfields', ['label' => 'Demographics'], [
            'field_key' => 'demographic',
            'label' => self::LEGACY_DEMOGRAPHIC_LABEL,
        ]);
        $wpdb->update($p . 'bg_groups', ['name' => 'Newsletter'], ['slug' => 'general', 'name' => 'Monthly Newsletter']);
        $settings = get_option('bosquegrande_settings', []);
        if (is_array($settings)) {
            unset($settings['monthly_donor_signup_url'], $settings['identity_lookup_source']);
            update_option('bosquegrande_settings', $settings, false);
        }
        foreach (['bg_schema_verified_release', 'bg_schema_incomplete', 'bg_link_pepper'] as $option) {
            delete_option($option);
        }
        return true;
    }

    /** @return list<string> */
    private static function columns(string $body): array
    {
        $out = [];
        foreach (preg_split('/,\s*\n/', trim($body)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY)\b/', $line)) continue;
            if (preg_match('/^`?([a-z_0-9]+)`?\s/', $line, $m)) $out[] = $m[1];
        }
        return $out;
    }

    /** @return array<string, string> table suffix => column and key definitions */
    private static function tables(): array
    {
        return [
            'bg_activity' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                constituent_id BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(32) NOT NULL,
                summary VARCHAR(255) NOT NULL DEFAULT '',
                meta LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY constituent_id (constituent_id),
                KEY kind (kind)",
            'bg_attendee_sessions' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                event_post_id BIGINT UNSIGNED NOT NULL,
                registration_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                issued_by BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY event_status (event_post_id, status),
                KEY registration_status (registration_id, status),
                KEY expires_at (expires_at)",
            'bg_cfields' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                field_key VARCHAR(48) NOT NULL,
                label VARCHAR(190) NOT NULL,
                `sensitive` TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY field_key (field_key)",
            'bg_consents' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                constituent_id BIGINT UNSIGNED NOT NULL,
                purpose VARCHAR(48) NOT NULL,
                granted TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(48) NOT NULL DEFAULT '',
                noted_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY constituent_id (constituent_id)",
            'bg_constituent_donor_facts' => "
                constituent_id BIGINT UNSIGNED NOT NULL,
                has_history TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(24) NOT NULL DEFAULT '',
                checked_at DATETIME NOT NULL,
                PRIMARY KEY  (constituent_id),
                KEY has_history (has_history)",
            'bg_constituent_emails' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                constituent_id BIGINT UNSIGNED NOT NULL,
                email_enc LONGTEXT NOT NULL,
                email_bidx CHAR(64) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                login_enabled TINYINT(1) NOT NULL DEFAULT 1,
                source VARCHAR(32) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY constituent_email (constituent_id, email_bidx),
                KEY email_bidx (email_bidx),
                KEY constituent_id (constituent_id)",
            'bg_constituent_regions' => "
                constituent_id BIGINT UNSIGNED NOT NULL,
                region_slug VARCHAR(48) NOT NULL DEFAULT '',
                assignment_source VARCHAR(24) NOT NULL DEFAULT '',
                location_hash CHAR(64) NOT NULL DEFAULT '',
                confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                evidence VARCHAR(255) NOT NULL DEFAULT '',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (constituent_id),
                KEY region_slug (region_slug),
                KEY assignment_source (assignment_source)",
            'bg_constituents' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                envelope LONGTEXT NOT NULL,
                email_bidx CHAR(64) NULL,
                name_bidx CHAR(64) NULL,
                phone_bidx CHAR(64) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                lgl_constituent_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY email_bidx (email_bidx),
                KEY status (status),
                KEY lgl_constituent_id (lgl_constituent_id)",
            'bg_ctag_links' => "
                tag_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (tag_id, constituent_id),
                KEY constituent_id (constituent_id)",
            'bg_ctags' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(64) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY name (name)",
            'bg_cvalues' => "
                constituent_id BIGINT UNSIGNED NOT NULL,
                field_id BIGINT UNSIGNED NOT NULL,
                value_enc LONGTEXT NULL,
                PRIMARY KEY  (constituent_id, field_id)",
            'bg_event_attendance' => "
                registration_id BIGINT UNSIGNED NOT NULL,
                event_post_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'registered',
                updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                checked_in_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                revision CHAR(36) NOT NULL DEFAULT '',
                PRIMARY KEY  (registration_id),
                KEY event_status (event_post_id, status),
                KEY updated_by (updated_by)",
            'bg_event_change_requests' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                request_type VARCHAR(16) NOT NULL DEFAULT 'edit',
                payload_enc LONGTEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY event_status (event_post_id, status),
                KEY user_status (user_id, status),
                KEY created_at (created_at)",
            'bg_event_closeouts' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                organizer_user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                expected_cash_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                expected_check_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                counted_cash_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                counted_check_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                check_count INT UNSIGNED NOT NULL DEFAULT 0,
                cash_variance_cents BIGINT NOT NULL DEFAULT 0,
                check_variance_cents BIGINT NOT NULL DEFAULT 0,
                explanation_enc LONGTEXT NOT NULL,
                snapshot_hash CHAR(64) NOT NULL DEFAULT '',
                submission_key CHAR(64) NOT NULL DEFAULT '',
                submitted_at DATETIME NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_post_id (event_post_id),
                KEY status_updated (status, updated_at),
                KEY organizer_user_id (organizer_user_id)",
            'bg_event_intakes' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                registration_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                organizer_user_id BIGINT UNSIGNED NOT NULL,
                intake_type VARCHAR(24) NOT NULL DEFAULT 'walk_in',
                match_status VARCHAR(32) NOT NULL DEFAULT 'new',
                review_status VARCHAR(24) NOT NULL DEFAULT 'not_required',
                review_reason VARCHAR(32) NOT NULL DEFAULT '',
                contact_consent_complete TINYINT(1) NOT NULL DEFAULT 0,
                private_answers_complete TINYINT(1) NOT NULL DEFAULT 0,
                monthly_interest_cents INT UNSIGNED NOT NULL DEFAULT 0,
                request_hash CHAR(64) NOT NULL DEFAULT '',
                idempotency_key CHAR(64) NOT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idempotency_key (idempotency_key),
                UNIQUE KEY registration_id (registration_id),
                KEY event_created (event_post_id, created_at),
                KEY event_type (event_post_id, intake_type),
                KEY constituent_id (constituent_id),
                KEY review_status (review_status, created_at)",
            'bg_event_organizers' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                role_label VARCHAR(16) NOT NULL DEFAULT 'organizer',
                can_check_in TINYINT(1) NOT NULL DEFAULT 1,
                can_message TINYINT(1) NOT NULL DEFAULT 1,
                can_export TINYINT(1) NOT NULL DEFAULT 0,
                can_propose_edits TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                can_register_walkins TINYINT(1) NOT NULL DEFAULT 0,
                can_record_tenders TINYINT(1) NOT NULL DEFAULT 0,
                can_collect_private_answers TINYINT(1) NOT NULL DEFAULT 0,
                can_close_out TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY event_user (event_post_id, user_id),
                KEY user_status (user_id, status),
                KEY event_status (event_post_id, status),
                KEY constituent_status (constituent_id, status)",
            'bg_event_roles' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                role_label VARCHAR(16) NOT NULL DEFAULT 'organizer',
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                source VARCHAR(32) NOT NULL DEFAULT 'assignment',
                assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                assigned_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_constituent_role (event_post_id, constituent_id, role_label),
                KEY constituent_status (constituent_id, status),
                KEY event_status (event_post_id, status),
                KEY user_id (user_id)",
            'bg_event_tenders' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                registration_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                organizer_user_id BIGINT UNSIGNED NOT NULL,
                payment_id BIGINT UNSIGNED NOT NULL,
                operation_id CHAR(64) NOT NULL,
                request_hash CHAR(64) NOT NULL DEFAULT '',
                purpose VARCHAR(16) NOT NULL,
                method VARCHAR(8) NOT NULL,
                amount_cents BIGINT UNSIGNED NOT NULL,
                check_reference_enc LONGTEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'recorded',
                idempotency_key CHAR(64) NOT NULL,
                closeout_id BIGINT UNSIGNED NULL,
                void_payment_id BIGINT UNSIGNED NULL,
                voided_by BIGINT UNSIGNED NULL,
                void_reason_enc LONGTEXT NOT NULL,
                voided_at DATETIME NULL,
                reconciled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idempotency_key (idempotency_key),
                KEY operation_id (operation_id),
                KEY event_status (event_post_id, status),
                KEY registration_id (registration_id),
                KEY payment_id (payment_id),
                KEY closeout_id (closeout_id)",
            'bg_external_records' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(32) NOT NULL,
                entity_type VARCHAR(24) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                external_id_hash CHAR(64) NOT NULL,
                external_id_enc LONGTEXT NOT NULL,
                import_job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_created_at DATETIME NULL,
                metadata_enc LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_entity_external (source, entity_type, external_id_hash),
                KEY entity (entity_type, entity_id),
                KEY import_job_id (import_job_id)",
            'bg_grant_reports' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(191) NOT NULL,
                funder_name VARCHAR(191) NOT NULL DEFAULT '',
                grant_reference VARCHAR(191) NOT NULL DEFAULT '',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                region_slug VARCHAR(48) NOT NULL DEFAULT '',
                payload_enc LONGTEXT NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY report_period (period_start, period_end),
                KEY updated_at (updated_at)",
            'bg_groups' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(48) NOT NULL,
                name VARCHAR(190) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)",
            'bg_identity_tokens' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(8) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                email VARCHAR(190) NULL,
                lgl_constituent_id BIGINT UNSIGNED NULL,
                expires_at DATETIME NULL,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY kind_constituent (kind, lgl_constituent_id)",
            'bg_import_jobs' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(36) NOT NULL,
                source_name VARCHAR(191) NOT NULL DEFAULT '',
                source_type VARCHAR(12) NOT NULL DEFAULT '',
                source_hash CHAR(64) NOT NULL DEFAULT '',
                source_sheet VARCHAR(191) NOT NULL DEFAULT '',
                file_path_enc LONGTEXT NOT NULL,
                mapping_enc LONGTEXT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'uploaded',
                conflict_policy VARCHAR(24) NOT NULL DEFAULT 'drop',
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
                created_count INT UNSIGNED NOT NULL DEFAULT 0,
                matched_count INT UNSIGNED NOT NULL DEFAULT 0,
                skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
                failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                summary_json LONGTEXT NULL,
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token (token),
                KEY status_updated (status, updated_at),
                KEY source_hash (source_hash)",
            'bg_import_mutations' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                import_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                entity_type VARCHAR(24) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(16) NOT NULL,
                before_enc LONGTEXT NOT NULL,
                before_hash CHAR(64) NOT NULL DEFAULT '',
                after_hash CHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                reverted_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY job_reverted (job_id, reverted_at, id),
                KEY entity (entity_type, entity_id)",
            'bg_import_rows' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                source_row INT UNSIGNED NOT NULL,
                identity_key CHAR(64) NOT NULL DEFAULT '',
                registration_key CHAR(64) NOT NULL DEFAULT '',
                row_hash CHAR(64) NOT NULL DEFAULT '',
                state VARCHAR(32) NOT NULL DEFAULT 'ready',
                issues TEXT NULL,
                payload_enc LONGTEXT NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                event_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                registration_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_row (job_id, source_row),
                KEY job_state (job_id, state, id),
                KEY job_identity (job_id, identity_key),
                KEY job_registration (job_id, registration_key)",
            'bg_jobs_log' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                level VARCHAR(8) NOT NULL,
                channel VARCHAR(32) NOT NULL,
                message TEXT NOT NULL,
                context LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY level (level),
                KEY channel (channel),
                KEY created_at (created_at)",
            'bg_lgl_diffs' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                constituent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                lgl_id BIGINT UNSIGNED NOT NULL,
                field_key VARCHAR(96) NOT NULL,
                payload_enc LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY record_field (constituent_id, lgl_id, field_key),
                KEY lgl_id (lgl_id)",
            'bg_organizer_audit' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                event_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                registration_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                action VARCHAR(48) NOT NULL,
                detail VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY event_created (event_post_id, created_at),
                KEY user_created (user_id, created_at),
                KEY action_created (action, created_at)",
            'bg_payments' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(24) NOT NULL,
                constituent_id BIGINT UNSIGNED NULL,
                registration_id BIGINT UNSIGNED NULL,
                amount_cents INT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'usd',
                stripe_payment_intent VARCHAR(64) NULL,
                parent_id BIGINT UNSIGNED NULL,
                source VARCHAR(24) NOT NULL DEFAULT '',
                note VARCHAR(255) NOT NULL DEFAULT '',
                exported_at DATETIME NULL,
                export_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                external_key VARCHAR(191) NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY external_key (external_key),
                KEY kind (kind),
                KEY constituent_id (constituent_id),
                KEY stripe_payment_intent (stripe_payment_intent),
                KEY exported_at (exported_at),
                KEY registration_id (registration_id)",
            'bg_qbo_exports' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(190) NOT NULL,
                invoices INT UNSIGNED NOT NULL DEFAULT 0,
                payload_enc LONGTEXT NULL,
                payload_sha256 CHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id)",
            'bg_qbo_mappings' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                match_kind VARCHAR(24) NOT NULL,
                match_value VARCHAR(190) NOT NULL,
                qbo_account VARCHAR(190) NOT NULL DEFAULT '',
                qbo_item VARCHAR(190) NOT NULL DEFAULT '',
                qbo_class VARCHAR(190) NOT NULL DEFAULT '',
                qbo_class_allocations LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY match_pair (match_kind, match_value)",
            'bg_qbo_review' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(190) NOT NULL DEFAULT '',
                resolved TINYINT(1) NOT NULL DEFAULT 0,
                disposition VARCHAR(24) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY resolved (resolved),
                KEY payment_disposition (payment_id, resolved, disposition)",
            'bg_registration_answers' => "
                registration_id BIGINT UNSIGNED NOT NULL,
                payload_enc LONGTEXT NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT '',
                import_job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (registration_id),
                KEY import_job_id (import_job_id)",
            'bg_registrations' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_post_id BIGINT UNSIGNED NOT NULL,
                lgl_event_id BIGINT UNSIGNED NULL,
                lgl_constituent_id BIGINT UNSIGNED NULL,
                tier VARCHAR(32) NOT NULL,
                amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
                stripe_payment_intent VARCHAR(64) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                volunteer_interest TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                lgl_invitation_id BIGINT UNSIGNED NULL,
                lgl_gift_id BIGINT UNSIGNED NULL,
                is_new_constituent TINYINT(1) NOT NULL DEFAULT 0,
                newcomer_eligible TINYINT(1) NOT NULL DEFAULT 0,
                reminder_sent_at DATETIME NULL,
                survey_sent_at DATETIME NULL,
                newcomer_sent_at DATETIME NULL,
                reminder_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                survey_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                newcomer_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                bg_constituent_id BIGINT UNSIGNED NULL,
                settlement_token CHAR(36) NULL,
                settlement_started_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY event_post_id (event_post_id),
                KEY lgl_constituent_id (lgl_constituent_id),
                KEY status (status),
                KEY bg_constituent_id (bg_constituent_id),
                KEY settlement_token (settlement_token)",
            'bg_send_queue' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT UNSIGNED NOT NULL,
                group_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                queue_key CHAR(64) NULL,
                claimed_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY queue_key (queue_key),
                KEY newsletter_status (newsletter_id, status),
                KEY constituent_id (constituent_id)",
            'bg_staging' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(24) NOT NULL,
                external_id VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                sha256 CHAR(64) NOT NULL,
                captured_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY run_id (run_id),
                KEY source_external (source, external_id)",
            'bg_subscriptions' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id BIGINT UNSIGNED NOT NULL,
                constituent_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                source VARCHAR(48) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY group_constituent (group_id, constituent_id),
                KEY constituent_id (constituent_id)",
            'bg_suppression' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email_bidx CHAR(64) NOT NULL,
                reason VARCHAR(48) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email_bidx (email_bidx)",
            'bg_survey_responses' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                registration_id BIGINT UNSIGNED NOT NULL,
                event_post_id BIGINT UNSIGNED NOT NULL,
                question_key VARCHAR(64) NOT NULL,
                score TINYINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                response_key CHAR(64) NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY response_key (response_key),
                KEY registration_id (registration_id),
                KEY event_question (event_post_id, question_key)",
            'bg_transfer_runs' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                mode VARCHAR(8) NOT NULL,
                trig VARCHAR(16) NOT NULL,
                sources VARCHAR(64) NOT NULL DEFAULT '',
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                staged INT UNSIGNED NOT NULL DEFAULT 0,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                updated INT UNSIGNED NOT NULL DEFAULT 0,
                skipped INT UNSIGNED NOT NULL DEFAULT 0,
                held INT UNSIGNED NOT NULL DEFAULT 0,
                errors INT UNSIGNED NOT NULL DEFAULT 0,
                report LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY started_at (started_at)",
        ];
    }
}
