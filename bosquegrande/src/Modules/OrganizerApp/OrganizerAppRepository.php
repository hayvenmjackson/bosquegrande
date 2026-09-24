<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Core\Encryption;

if (!defined('ABSPATH')) exit;

/**
 * Persistence boundary for the organizer PWA. PII remains in the existing
 * encrypted constituent store; these tables contain event workflow state,
 * opaque ids, integer cents, and authenticated encrypted free text only.
 */
final class OrganizerAppRepository
{
    public const CLOSEOUT_LOCKED = ['submitted', 'accepted'];
    public const TENDER_ACTIVE = ['recorded', 'reconciled'];

    private ?bool $availability = null;

    public function __construct(private Encryption $encryption)
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
        if ($this->availability !== null) return $this->availability;
        if (!$this->encryption->available()) return $this->availability = false;

        foreach (['bg_event_intakes', 'bg_event_tenders', 'bg_event_closeouts', 'bg_attendee_sessions'] as $suffix) {
            $table = $this->table($suffix);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return $this->availability = false;
            }
        }

        $required = [
            'bg_event_organizers' => ['can_register_walkins', 'can_record_tenders', 'can_collect_private_answers', 'can_close_out'],
            'bg_event_attendance' => ['revision'],
            'bg_event_intakes' => ['intake_type', 'review_reason', 'monthly_interest_cents', 'request_hash', 'idempotency_key'],
            'bg_event_tenders' => ['operation_id', 'request_hash', 'closeout_id', 'check_reference_enc', 'void_reason_enc', 'idempotency_key'],
            'bg_event_closeouts' => ['status', 'submission_key', 'snapshot_hash', 'check_count', 'explanation_enc'],
            'bg_attendee_sessions' => ['token_hash', 'expires_at', 'used_at'],
        ];
        foreach ($required as $suffix => $columns) {
            $table = $this->table($suffix);
            foreach ($columns as $column) {
                if (!$wpdb->get_var($wpdb->prepare(
                    "SHOW COLUMNS FROM {$table} LIKE %s",
                    $column
                ))) return $this->availability = false;
            }
        }
        return $this->availability = true;
    }

    public function writeLocked(int $eventId): bool
    {
        global $wpdb;
        if ($eventId < 1) return true;
        $status = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$this->table('bg_event_closeouts')} WHERE event_post_id = %d LIMIT 1",
            $eventId
        ));
        return in_array($status, self::CLOSEOUT_LOCKED, true);
    }

    /**
     * Serialize event mutations across organizer phones and the fallback
     * portal. MySQL/MariaDB named locks are connection-scoped and are always
     * released by the service in a finally block.
     */
    public function acquireEventLock(int $eventId, int $waitSeconds = 3): bool
    {
        global $wpdb;
        if ($eventId < 1) return false;
        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $this->eventLockName($eventId),
            max(0, min(10, $waitSeconds))
        ));
        return (int) $result === 1;
    }

    public function releaseEventLock(int $eventId): void
    {
        global $wpdb;
        if ($eventId < 1) return;
        $wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $this->eventLockName($eventId)
        ));
    }

    /** @return array<string, int|string|bool> */
    public function eventState(int $eventId): array
    {
        global $wpdb;
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(a.status, 'registered') = 'registered' THEN 1 ELSE 0 END) AS registered,
                    SUM(CASE WHEN COALESCE(a.status, 'registered') = 'checked_in' THEN 1 ELSE 0 END) AS checked_in,
                    SUM(CASE WHEN COALESCE(a.status, 'registered') = 'attended' THEN 1 ELSE 0 END) AS attended,
                    SUM(CASE WHEN COALESCE(a.status, 'registered') = 'no_show' THEN 1 ELSE 0 END) AS no_show,
                    SUM(CASE WHEN r.tier = 'scholarship' THEN 1 ELSE 0 END) AS scholarship
             FROM {$this->table('bg_registrations')} r
             LEFT JOIN {$this->table('bg_event_attendance')} a ON a.registration_id = r.id
             WHERE r.event_post_id = %d AND r.status IN ('confirmed','paid')",
            $eventId
        ));
        $walkIns = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_event_intakes')} WHERE event_post_id = %d AND intake_type = 'walk_in'",
            $eventId
        ));
        $unresolved = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_event_intakes')} WHERE event_post_id = %d AND review_status = 'pending'",
            $eventId
        ));
        $money = $this->moneyTotals($eventId);
        $closeout = $this->closeout($eventId);
        return [
            'total' => (int) ($counts->total ?? 0),
            'registered' => (int) ($counts->registered ?? 0),
            'checked_in' => (int) ($counts->checked_in ?? 0),
            'attended' => (int) ($counts->attended ?? 0),
            'no_show' => (int) ($counts->no_show ?? 0),
            'scholarship' => (int) ($counts->scholarship ?? 0),
            'walk_ins' => $walkIns,
            'unresolved_records' => $unresolved,
            'cash_cents' => $money['cash_cents'],
            'check_cents' => $money['check_cents'],
            'tender_count' => $money['tender_count'],
            'closeout_status' => $closeout ? (string) $closeout->status : 'open',
            'write_locked' => $this->writeLocked($eventId),
        ];
    }

    /** @return array{cash_cents:int,check_cents:int,tender_count:int,event_fee_cents:int,donation_cents:int} */
    public function moneyTotals(int $eventId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN method = 'cash' THEN amount_cents ELSE 0 END), 0) AS cash_cents,
                COALESCE(SUM(CASE WHEN method = 'check' THEN amount_cents ELSE 0 END), 0) AS check_cents,
                COALESCE(SUM(CASE WHEN purpose = 'event_fee' THEN amount_cents ELSE 0 END), 0) AS event_fee_cents,
                COALESCE(SUM(CASE WHEN purpose = 'donation' THEN amount_cents ELSE 0 END), 0) AS donation_cents,
                COUNT(*) AS tender_count
             FROM {$this->table('bg_event_tenders')}
             WHERE event_post_id = %d AND status IN ('recorded','reconciled')",
            $eventId
        ));
        return [
            'cash_cents' => (int) ($row->cash_cents ?? 0),
            'check_cents' => (int) ($row->check_cents ?? 0),
            'tender_count' => (int) ($row->tender_count ?? 0),
            'event_fee_cents' => (int) ($row->event_fee_cents ?? 0),
            'donation_cents' => (int) ($row->donation_cents ?? 0),
        ];
    }

    public function markEventTendersReconciled(int $eventId, int $closeoutId): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('bg_event_tenders')}
             SET status = 'reconciled', closeout_id = %d, reconciled_at = %s, updated_at = %s
             WHERE event_post_id = %d AND status = 'recorded'",
            $closeoutId,
            $now,
            $now,
            $eventId
        ));
        return $result !== false;
    }

    public function markEventTendersRecorded(int $eventId): bool
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('bg_event_tenders')}
             SET status = 'recorded', closeout_id = NULL, reconciled_at = NULL, updated_at = %s
             WHERE event_post_id = %d AND status = 'reconciled'",
            current_time('mysql', true),
            $eventId
        ));
        return $result !== false;
    }

    public function registration(int $eventId, int $registrationId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_registrations')}
             WHERE id = %d AND event_post_id = %d LIMIT 1",
            $registrationId,
            $eventId
        ));
        return $row ?: null;
    }

    public function activeRegistrationFor(int $eventId, int $constituentId): ?object
    {
        global $wpdb;
        if ($eventId < 1 || $constituentId < 1) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_registrations')}
             WHERE event_post_id = %d AND bg_constituent_id = %d
               AND status IN ('paid','confirmed','pending','processing','refund_pending')
             ORDER BY FIELD(status, 'paid','confirmed','pending','processing','refund_pending'), id DESC
             LIMIT 1",
            $eventId,
            $constituentId
        ));
        return $row ?: null;
    }

    public function confirmPendingRegistration(int $registrationId, int $constituentId, string $tier, int $amountCents): bool
    {
        global $wpdb;
        return $wpdb->update($this->table('bg_registrations'), [
            'bg_constituent_id' => $constituentId,
            'tier' => $tier,
            'amount_cents' => $amountCents,
            'status' => 'confirmed',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $registrationId, 'status' => 'pending']) === 1;
    }

    /**
     * Optimistic attendance mutation. The revision is a UUID rather than a
     * second-resolution timestamp, so two phones cannot silently overwrite
     * one another during a busy check-in line.
     *
     * @return array{ok:bool,conflict:bool,status:string,revision:string}
     */
    public function updateAttendance(
        int $eventId,
        int $registrationId,
        string $status,
        int $userId,
        string $expectedRevision
    ): array {
        global $wpdb;
        $allowed = ['registered', 'checked_in', 'attended', 'no_show'];
        $registration = $this->registration($eventId, $registrationId);
        if (!in_array($status, $allowed, true)
            || !$registration
            || !in_array((string) $registration->status, ['confirmed', 'paid'], true)
        ) {
            return ['ok' => false, 'conflict' => false, 'status' => '', 'revision' => ''];
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, revision, checked_in_at FROM {$this->table('bg_event_attendance')}
             WHERE registration_id = %d LIMIT 1",
            $registrationId
        ));
        $currentStatus = $row ? (string) $row->status : 'registered';
        $currentRevision = $row ? (string) ($row->revision ?? '') : '';
        if ($expectedRevision !== '' && !hash_equals($currentRevision, $expectedRevision)) {
            return ['ok' => false, 'conflict' => true, 'status' => $currentStatus, 'revision' => $currentRevision];
        }

        $now = current_time('mysql', true);
        $revision = wp_generate_uuid4();
        $checked = in_array($status, ['checked_in', 'attended'], true)
            ? (($row && (string) $row->checked_in_at !== '') ? (string) $row->checked_in_at : $now)
            : null;

        if ($row) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('bg_event_attendance')}
                 SET event_post_id = %d, status = %s, updated_by = %d,
                     checked_in_at = NULLIF(%s, ''), updated_at = %s, revision = %s
                 WHERE registration_id = %d AND revision = %s",
                $eventId,
                $status,
                $userId,
                $checked ?? '',
                $now,
                $revision,
                $registrationId,
                $currentRevision
            ));
            if ($updated !== 1) {
                $fresh = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, revision FROM {$this->table('bg_event_attendance')} WHERE registration_id = %d",
                    $registrationId
                ));
                return [
                    'ok' => false,
                    'conflict' => true,
                    'status' => (string) ($fresh->status ?? 'registered'),
                    'revision' => (string) ($fresh->revision ?? ''),
                ];
            }
        } else {
            $ok = $wpdb->insert($this->table('bg_event_attendance'), [
                'registration_id' => $registrationId,
                'event_post_id' => $eventId,
                'status' => $status,
                'updated_by' => $userId,
                'checked_in_at' => $checked,
                'updated_at' => $now,
                'revision' => $revision,
            ]);
            if (!$ok) {
                $fresh = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, revision FROM {$this->table('bg_event_attendance')} WHERE registration_id = %d",
                    $registrationId
                ));
                return [
                    'ok' => false,
                    'conflict' => (bool) $fresh,
                    'status' => (string) ($fresh->status ?? ''),
                    'revision' => (string) ($fresh->revision ?? ''),
                ];
            }
        }
        return ['ok' => true, 'conflict' => false, 'status' => $status, 'revision' => $revision];
    }

    public function intake(int $intakeId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_intakes')} WHERE id = %d LIMIT 1",
            $intakeId
        ));
        return $row ?: null;
    }

    public function intakeByKey(string $idempotencyKey): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_intakes')} WHERE idempotency_key = %s LIMIT 1",
            $idempotencyKey
        ));
        return $row ?: null;
    }

    public function intakeByRegistration(int $registrationId): ?object
    {
        global $wpdb;
        if ($registrationId < 1) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_intakes')} WHERE registration_id = %d LIMIT 1",
            $registrationId
        ));
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function insertIntake(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($this->table('bg_event_intakes'), [
            'event_post_id' => (int) $data['event_post_id'],
            'registration_id' => (int) $data['registration_id'],
            'constituent_id' => (int) $data['constituent_id'],
            'organizer_user_id' => (int) $data['organizer_user_id'],
            'intake_type' => (string) ($data['intake_type'] ?? 'walk_in'),
            'match_status' => (string) $data['match_status'],
            'review_status' => (string) $data['review_status'],
            'review_reason' => (string) ($data['review_reason'] ?? ''),
            'contact_consent_complete' => 0,
            'private_answers_complete' => 0,
            'monthly_interest_cents' => 0,
            'request_hash' => (string) ($data['request_hash'] ?? hash('sha256', (string) $data['idempotency_key'])),
            'idempotency_key' => (string) $data['idempotency_key'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Persist completion for both walk-ins and already-registered attendees.
     * A monthly amount is only a follow-up request; it never changes donor
     * status. The unique registration key keeps repeated privacy sessions from
     * creating parallel operational records.
     */
    public function recordPrivateCompletion(
        int $eventId,
        int $registrationId,
        int $constituentId,
        int $organizerUserId,
        int $monthlyInterestCents,
        bool $answersComplete,
        bool $consentComplete
    ): bool {
        global $wpdb;
        if ($eventId < 1 || $registrationId < 1 || $constituentId < 1) return false;
        $interest = max(0, $monthlyInterestCents);
        $existing = $this->intakeByRegistration($registrationId);
        if (!$existing) {
            $intakeId = $this->insertIntake([
                'event_post_id' => $eventId,
                'registration_id' => $registrationId,
                'constituent_id' => $constituentId,
                'organizer_user_id' => $organizerUserId,
                'intake_type' => $interest > 0 ? 'monthly_follow_up' : 'profile_update',
                'match_status' => 'existing_registration',
                'review_status' => $interest > 0 ? 'pending' : 'not_required',
                'review_reason' => $interest > 0 ? 'monthly_follow_up' : '',
                'idempotency_key' => hash('sha256', 'private|' . $eventId . '|' . $registrationId),
            ]);
            if ($intakeId < 1) {
                // A concurrent one-time session may have inserted the unique
                // registration row first. Re-read instead of reporting a false
                // failure or creating a duplicate.
                $existing = $this->intakeByRegistration($registrationId);
                if (!$existing) return false;
            } else {
                $existing = $this->intake($intakeId);
            }
        }
        if (!$existing) return false;

        $reviewStatus = (string) ($existing->review_status ?? 'not_required');
        $reviewReason = (string) ($existing->review_reason ?? '');
        $intakeType = (string) ($existing->intake_type ?? 'profile_update');
        $existingInterest = max(0, (int) ($existing->monthly_interest_cents ?? 0));

        // The private form intentionally never reveals a previous answer. A
        // later session therefore defaults to zero; do not let that default
        // erase an unresolved follow-up that staff has not reviewed yet.
        if ($interest === 0
            && $existingInterest > 0
            && $reviewStatus === 'pending'
            && $reviewReason === 'monthly_follow_up'
        ) $interest = $existingInterest;

        if ($interest > 0) {
            // Preserve an unresolved duplicate-match warning. Once that review
            // has been resolved or dismissed, a new monthly choice becomes the
            // active reason instead of reviving the old ambiguity reason.
            $preserveAmbiguity = $reviewStatus === 'pending' && $reviewReason === 'ambiguous_match';
            if (!$preserveAmbiguity) $reviewReason = 'monthly_follow_up';
            $reviewStatus = 'pending';
            if ($intakeType !== 'walk_in') $intakeType = 'monthly_follow_up';
        }

        return $wpdb->update($this->table('bg_event_intakes'), [
            'organizer_user_id' => $organizerUserId,
            'intake_type' => $intakeType,
            'review_status' => $reviewStatus,
            'review_reason' => $reviewReason,
            'private_answers_complete' => $answersComplete ? 1 : 0,
            'contact_consent_complete' => $consentComplete ? 1 : 0,
            'monthly_interest_cents' => $interest,
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $existing->id]) !== false;
    }

    public function tenderByKey(string $idempotencyKey): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_tenders')} WHERE idempotency_key = %s LIMIT 1",
            $idempotencyKey
        ));
        return $row ?: null;
    }

    /** @return list<object> */
    public function tendersByOperation(string $operationId): array
    {
        global $wpdb;
        if ($operationId === '') return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_tenders')} WHERE operation_id = %s ORDER BY id ASC",
            $operationId
        )) ?: [];
    }

    /** @param array<string,mixed> $data */
    public function createTender(array $data, string $checkReference): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($this->table('bg_event_tenders'), [
            'event_post_id' => (int) $data['event_post_id'],
            'registration_id' => (int) $data['registration_id'],
            'constituent_id' => (int) $data['constituent_id'],
            'organizer_user_id' => (int) $data['organizer_user_id'],
            'payment_id' => (int) $data['payment_id'],
            'operation_id' => (string) $data['operation_id'],
            'request_hash' => (string) $data['request_hash'],
            'purpose' => (string) $data['purpose'],
            'method' => (string) $data['method'],
            'amount_cents' => (int) $data['amount_cents'],
            'check_reference_enc' => '',
            'status' => 'recorded',
            'void_reason_enc' => '',
            'idempotency_key' => (string) $data['idempotency_key'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) return 0;
        $id = (int) $wpdb->insert_id;
        if ($checkReference === '') return $id;
        $cipher = $this->encryption->encrypt($checkReference, $this->tenderCheckContext($id));
        if ($cipher === '' || $cipher === $checkReference) {
            $wpdb->delete($this->table('bg_event_tenders'), ['id' => $id]);
            return 0;
        }
        $saved = $wpdb->update(
            $this->table('bg_event_tenders'),
            ['check_reference_enc' => $cipher],
            ['id' => $id]
        ) !== false;
        $verified = $saved
            && $this->encryption->decryptOrNull($cipher, $this->tenderCheckContext($id)) === $checkReference;
        if (!$verified) {
            $wpdb->delete($this->table('bg_event_tenders'), ['id' => $id]);
            return 0;
        }
        return $id;
    }

    public function tender(int $tenderId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_tenders')} WHERE id = %d LIMIT 1",
            $tenderId
        ));
        return $row ?: null;
    }

    /** Internal retry validation only; never expose this value through REST. */
    public function tenderCheckReference(object $tender): string
    {
        $cipher = (string) ($tender->check_reference_enc ?? '');
        if ($cipher === '') return '';
        return (string) ($this->encryption->decryptOrNull(
            $cipher,
            $this->tenderCheckContext((int) $tender->id)
        ) ?? '');
    }

    /** @return list<object> */
    public function tendersForEvent(int $eventId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, registration_id, constituent_id, organizer_user_id, payment_id,
                    purpose, method, amount_cents, status,
                    CASE WHEN check_reference_enc <> '' THEN 1 ELSE 0 END AS has_check_reference,
                    created_at, updated_at, voided_at
             FROM {$this->table('bg_event_tenders')}
             WHERE event_post_id = %d ORDER BY id DESC",
            $eventId
        )) ?: [];
    }

    /** @return list<object> */
    public function recentTenders(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, p.post_title, u.display_name
             FROM {$this->table('bg_event_tenders')} t
             LEFT JOIN {$wpdb->posts} p ON p.ID = t.event_post_id
             LEFT JOIN {$wpdb->users} u ON u.ID = t.organizer_user_id
             ORDER BY t.id DESC LIMIT %d",
            $limit
        )) ?: [];
    }

    /**
     * Staff operations export. This deliberately returns workflow metadata
     * only: no demographic answers, profile notes, mailing-list choices,
     * payment credentials, or decrypted check references.
     *
     * @return list<object>
     */
    public function eventOperationsRows(int $eventId): array
    {
        global $wpdb;
        if ($eventId < 1) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.id AS registration_id,
                r.bg_constituent_id AS constituent_id,
                r.status AS registration_status,
                r.tier AS registration_tier,
                r.amount_cents AS registration_amount_cents,
                COALESCE(a.status, 'registered') AS attendance_status,
                COALESCE(a.updated_at, '') AS attendance_updated_at,
                COALESCE(i.intake_type, '') AS intake_type,
                COALESCE(i.match_status, '') AS match_status,
                COALESCE(i.review_status, '') AS intake_review_status,
                COALESCE(i.private_answers_complete, 0) AS private_answers_complete,
                COALESCE(i.contact_consent_complete, 0) AS contact_consent_complete,
                COALESCE(i.monthly_interest_cents, 0) AS monthly_interest_cents,
                COALESCE(t.id, 0) AS tender_id,
                COALESCE(t.payment_id, 0) AS payment_id,
                COALESCE(t.operation_id, '') AS operation_id,
                COALESCE(t.purpose, '') AS tender_purpose,
                COALESCE(t.method, '') AS tender_method,
                COALESCE(t.amount_cents, 0) AS tender_amount_cents,
                COALESCE(t.status, '') AS tender_status,
                COALESCE(t.organizer_user_id, 0) AS tender_organizer_user_id,
                COALESCE(t.created_at, '') AS tender_created_at,
                CASE WHEN COALESCE(t.check_reference_enc, '') <> '' THEN 1 ELSE 0 END AS has_check_reference,
                COALESCE(t.closeout_id, 0) AS closeout_id,
                COALESCE(t.void_payment_id, 0) AS void_payment_id
             FROM {$this->table('bg_registrations')} r
             LEFT JOIN {$this->table('bg_event_attendance')} a ON a.registration_id = r.id
             LEFT JOIN {$this->table('bg_event_intakes')} i ON i.registration_id = r.id
             LEFT JOIN {$this->table('bg_event_tenders')} t ON t.registration_id = r.id AND t.event_post_id = r.event_post_id
             WHERE r.event_post_id = %d AND r.status IN ('confirmed','paid')
             ORDER BY r.id ASC, t.id ASC",
            $eventId
        )) ?: [];
    }

    public function markTenderVoided(int $tenderId, int $refundPaymentId, int $userId, string $reason): bool
    {
        global $wpdb;
        $cipher = $this->encryption->encrypt($reason, $this->tenderVoidContext($tenderId));
        if ($cipher === '' || $cipher === $reason) return false;
        $now = current_time('mysql', true);
        return $wpdb->update($this->table('bg_event_tenders'), [
            'status' => 'voided',
            'void_payment_id' => $refundPaymentId,
            'voided_by' => $userId,
            'void_reason_enc' => $cipher,
            'voided_at' => $now,
            'updated_at' => $now,
        ], ['id' => $tenderId, 'status' => 'recorded']) === 1;
    }

    public function issueSession(
        string $tokenHash,
        int $eventId,
        int $registrationId,
        int $constituentId,
        int $issuedBy,
        string $expiresAt
    ): int {
        global $wpdb;
        $this->expireSessions();
        // Revoke any older unused handoff for this registration.
        $wpdb->update($this->table('bg_attendee_sessions'), [
            'status' => 'revoked',
        ], [
            'registration_id' => $registrationId,
            'status' => 'active',
        ]);
        $ok = $wpdb->insert($this->table('bg_attendee_sessions'), [
            'token_hash' => $tokenHash,
            'event_post_id' => $eventId,
            'registration_id' => $registrationId,
            'constituent_id' => $constituentId,
            'issued_by' => $issuedBy,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public function sessionByToken(string $rawToken): ?object
    {
        global $wpdb;
        $hash = hash('sha256', $rawToken);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_attendee_sessions')}
             WHERE token_hash = %s AND status = 'active' AND expires_at >= %s LIMIT 1",
            $hash,
            current_time('mysql', true)
        ));
        return $row ?: null;
    }

    public function markSessionUsed(int $sessionId): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('bg_attendee_sessions')}
             SET status = 'used', used_at = %s
             WHERE id = %d AND status = 'active' AND expires_at >= %s",
            $now,
            $sessionId,
            $now
        )) === 1;
    }

    public function expireSessions(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('bg_attendee_sessions')} SET status = 'expired'
             WHERE status = 'active' AND expires_at < %s",
            current_time('mysql', true)
        ));
    }

    public function closeoutById(int $closeoutId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_closeouts')} WHERE id = %d LIMIT 1",
            $closeoutId
        ));
        return $row ?: null;
    }

    public function closeout(int $eventId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_closeouts')} WHERE event_post_id = %d LIMIT 1",
            $eventId
        ));
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveCloseout(array $data, string $explanation): int
    {
        global $wpdb;
        $eventId = (int) $data['event_post_id'];
        $existing = $this->closeout($eventId);
        $now = current_time('mysql', true);
        if ($existing && in_array((string) $existing->status, self::CLOSEOUT_LOCKED, true)) return 0;

        if (!$existing) {
            $ok = $wpdb->insert($this->table('bg_event_closeouts'), [
                'event_post_id' => $eventId,
                'organizer_user_id' => (int) $data['organizer_user_id'],
                'status' => 'draft',
                'expected_cash_cents' => 0,
                'expected_check_cents' => 0,
                'counted_cash_cents' => 0,
                'counted_check_cents' => 0,
                'check_count' => 0,
                'cash_variance_cents' => 0,
                'check_variance_cents' => 0,
                'explanation_enc' => '',
                'snapshot_hash' => '',
                'submission_key' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$ok) return 0;
            $id = (int) $wpdb->insert_id;
        } else {
            $id = (int) $existing->id;
        }

        $cipher = $explanation !== ''
            ? $this->encryption->encrypt($explanation, $this->closeoutContext($id))
            : '';
        if ($explanation !== '' && ($cipher === '' || $cipher === $explanation)) return 0;

        $fields = [
            'organizer_user_id' => (int) $data['organizer_user_id'],
            'status' => 'submitted',
            'expected_cash_cents' => (int) $data['expected_cash_cents'],
            'expected_check_cents' => (int) $data['expected_check_cents'],
            'counted_cash_cents' => (int) $data['counted_cash_cents'],
            'counted_check_cents' => (int) $data['counted_check_cents'],
            'check_count' => max(0, (int) ($data['check_count'] ?? 0)),
            'cash_variance_cents' => (int) $data['cash_variance_cents'],
            'check_variance_cents' => (int) $data['check_variance_cents'],
            'explanation_enc' => $cipher,
            'snapshot_hash' => (string) $data['snapshot_hash'],
            'submission_key' => (string) $data['submission_key'],
            'submitted_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'updated_at' => $now,
        ];
        $saved = $wpdb->update($this->table('bg_event_closeouts'), $fields, ['id' => $id]) !== false;
        if (!$saved) return 0;
        if ($explanation !== ''
            && $this->encryption->decryptOrNull($cipher, $this->closeoutContext($id)) !== $explanation
        ) return 0;
        return $id;
    }

    /** @return list<object> */
    public function closeoutsForReview(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT c.*, p.post_title, u.display_name
             FROM {$this->table('bg_event_closeouts')} c
             LEFT JOIN {$wpdb->posts} p ON p.ID = c.event_post_id
             LEFT JOIN {$wpdb->users} u ON u.ID = c.organizer_user_id
             WHERE c.status IN ('submitted','accepted','reopened')
             ORDER BY FIELD(c.status, 'submitted','reopened','accepted'), c.updated_at DESC"
        ) ?: [];
    }

    public function closeoutExplanation(object $closeout): string
    {
        $cipher = (string) ($closeout->explanation_enc ?? '');
        if ($cipher === '') return '';
        return (string) ($this->encryption->decryptOrNull(
            $cipher,
            $this->closeoutContext((int) $closeout->id)
        ) ?? '');
    }

    public function reviewCloseout(int $closeoutId, string $decision, int $reviewedBy): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);
        if ($decision === 'accept') {
            return $wpdb->update($this->table('bg_event_closeouts'), [
                'status' => 'accepted',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ], ['id' => $closeoutId, 'status' => 'submitted']) === 1;
        }
        if ($decision === 'reopen') {
            return $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('bg_event_closeouts')}
                 SET status = 'reopened', reviewed_by = %d, reviewed_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('submitted','accepted')",
                $reviewedBy,
                $now,
                $now,
                $closeoutId
            )) === 1;
        }
        return false;
    }

    /** @return list<object> */
    public function intakesForReview(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*, p.post_title, u.display_name
             FROM {$this->table('bg_event_intakes')} i
             LEFT JOIN {$wpdb->posts} p ON p.ID = i.event_post_id
             LEFT JOIN {$wpdb->users} u ON u.ID = i.organizer_user_id
             WHERE i.review_status = 'pending'
             ORDER BY i.created_at ASC, i.id ASC"
        ) ?: [];
    }

    public function reviewIntake(int $intakeId, string $decision, int $reviewedBy): bool
    {
        global $wpdb;
        if (!in_array($decision, ['resolved', 'dismissed'], true)) return false;
        $current = $this->intake($intakeId);
        if (!$current || (string) $current->review_status !== 'pending') return false;
        $now = current_time('mysql', true);

        // A single intake row can require identity review and then monthly-
        // giving follow-up. Completing either identity decision advances the
        // row to the second queue instead of silently discarding that request.
        if ((string) $current->review_reason === 'ambiguous_match'
            && (int) $current->monthly_interest_cents > 0
        ) {
            return $wpdb->update($this->table('bg_event_intakes'), [
                'review_status' => 'pending',
                'review_reason' => 'monthly_follow_up',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ], [
                'id' => $intakeId,
                'review_status' => 'pending',
                'review_reason' => 'ambiguous_match',
            ]) === 1;
        }

        return $wpdb->update($this->table('bg_event_intakes'), [
            'review_status' => $decision,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $intakeId, 'review_status' => 'pending']) === 1;
    }

    /** @return array<string,mixed> */
    public function snapshot(int $eventId): array
    {
        global $wpdb;
        $tenders = $wpdb->get_results($wpdb->prepare(
            "SELECT id, registration_id, payment_id, operation_id, purpose, method, amount_cents, created_at
             FROM {$this->table('bg_event_tenders')}
             WHERE event_post_id = %d AND status IN ('recorded','reconciled') ORDER BY id ASC",
            $eventId
        ), ARRAY_A) ?: [];
        $attendance = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id AS registration_id, COALESCE(a.status, 'registered') AS status,
                    COALESCE(a.revision, '') AS revision
             FROM {$this->table('bg_registrations')} r
             LEFT JOIN {$this->table('bg_event_attendance')} a ON a.registration_id = r.id
             WHERE r.event_post_id = %d AND r.status IN ('confirmed','paid') ORDER BY r.id ASC",
            $eventId
        ), ARRAY_A) ?: [];
        $intakes = $wpdb->get_results($wpdb->prepare(
            "SELECT id, registration_id, intake_type, match_status, review_status, review_reason,
                    private_answers_complete, contact_consent_complete, monthly_interest_cents, updated_at
             FROM {$this->table('bg_event_intakes')}
             WHERE event_post_id = %d ORDER BY id ASC",
            $eventId
        ), ARRAY_A) ?: [];
        return [
            'event_id' => $eventId,
            'attendance' => $attendance,
            'tenders' => $tenders,
            'intakes' => $intakes,
        ];
    }

    private function eventLockName(int $eventId): string
    {
        return 'bg-organizer-event-' . $eventId;
    }

    private function tenderCheckContext(int $id): string
    {
        return 'organizer-tender-check:' . $id;
    }

    private function tenderVoidContext(int $id): string
    {
        return 'organizer-tender-void:' . $id;
    }

    private function closeoutContext(int $id): string
    {
        return 'organizer-closeout:' . $id;
    }
}
