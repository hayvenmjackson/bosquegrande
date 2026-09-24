<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerPortal;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/** Database and encrypted-roster boundary for the organizer portal. */
final class OrganizerRepository
{
    public const ATTENDANCE = ['registered', 'checked_in', 'attended', 'no_show'];

    public function __construct(
        private Encryption $encryption,
        private ConstituentStore $constituents,
        private Logger $logger,
    ) {
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    public function available(): bool
    {
        global $wpdb;
        foreach (['bg_event_organizers', 'bg_event_attendance', 'bg_event_change_requests', 'bg_organizer_audit'] as $suffix) {
            $table = $this->table($suffix);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
        }
        return true;
    }

    public function assign(int $eventId, int $userId, array $permissions, int $assignedBy): bool
    {
        global $wpdb;
        if ($eventId < 1 || $userId < 1 || get_post_type($eventId) !== 'bg_event') return false;
        $now = current_time('mysql', true);
        $roleLabel = sanitize_key((string) ($permissions['role_label'] ?? 'organizer'));
        if (!in_array($roleLabel, ['organizer', 'host'], true)) $roleLabel = 'organizer';
        $constituentId = $this->constituentForUser($userId);
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table('bg_event_organizers')}
             (event_post_id, user_id, constituent_id, role_label, can_check_in, can_message, can_export,
              can_propose_edits, can_register_walkins, can_record_tenders,
              can_collect_private_answers, can_close_out, status, assigned_by, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d, %d, 'active', %d, %s, %s)
             ON DUPLICATE KEY UPDATE
              constituent_id = VALUES(constituent_id), role_label = VALUES(role_label),
              can_check_in = VALUES(can_check_in), can_message = VALUES(can_message),
              can_export = VALUES(can_export), can_propose_edits = VALUES(can_propose_edits),
              can_register_walkins = VALUES(can_register_walkins),
              can_record_tenders = VALUES(can_record_tenders),
              can_collect_private_answers = VALUES(can_collect_private_answers),
              can_close_out = VALUES(can_close_out),
              status = 'active', assigned_by = VALUES(assigned_by), updated_at = VALUES(updated_at)",
            $eventId,
            $userId,
            $constituentId,
            $roleLabel,
            !empty($permissions['check_in']) ? 1 : 0,
            !empty($permissions['message']) ? 1 : 0,
            !empty($permissions['export']) ? 1 : 0,
            !empty($permissions['propose']) ? 1 : 0,
            !empty($permissions['walk_in']) ? 1 : 0,
            !empty($permissions['tender']) ? 1 : 0,
            !empty($permissions['private_answers']) ? 1 : 0,
            !empty($permissions['close_out']) ? 1 : 0,
            $assignedBy,
            $now,
            $now
        );
        $ok = $wpdb->query($sql) !== false;
        if ($ok) {
            $this->syncEventRole($eventId, $constituentId, $userId, $roleLabel, $assignedBy, true);
            $this->audit($assignedBy, $eventId, 0, 'organizer_assigned', ucfirst($roleLabel) . ' user #' . $userId . ($constituentId > 0 ? ' / constituent #' . $constituentId : ''));
        }
        return $ok;
    }

    public function revoke(int $eventId, int $userId, int $revokedBy): bool
    {
        global $wpdb;
        $assignment = $this->assignment($userId, $eventId);
        $ok = $wpdb->update($this->table('bg_event_organizers'), [
            'status' => 'revoked',
            'updated_at' => current_time('mysql', true),
        ], ['event_post_id' => $eventId, 'user_id' => $userId]) !== false;
        if ($ok) {
            $constituentId = (int) ($assignment->constituent_id ?? 0);
            $roleLabel = sanitize_key((string) ($assignment->role_label ?? 'organizer'));
            $this->syncEventRole($eventId, $constituentId, $userId, $roleLabel, $revokedBy, false);
            $this->audit($revokedBy, $eventId, 0, 'organizer_revoked', ucfirst($roleLabel) . ' user #' . $userId);
        }
        return $ok;
    }

    private function constituentForUser(int $userId): int
    {
        $linked = \BosqueGrande\Core\UserLink::get($userId);
        if ($linked > 0 && $this->constituents->get($linked)) return $linked;
        $user = get_userdata($userId);
        $email = $user instanceof \WP_User ? sanitize_email((string) $user->user_email) : '';
        $ids = is_email($email) ? $this->constituents->idsByEmail($email) : [];
        if (count($ids) !== 1) return 0;
        $linked = (int) $ids[0];
        \BosqueGrande\Core\UserLink::set($userId, $linked);
        return $linked;
    }

    private function syncEventRole(
        int $eventId,
        int $constituentId,
        int $userId,
        string $roleLabel,
        int $assignedBy,
        bool $active
    ): void {
        global $wpdb;
        if ($constituentId < 1) return;
        $table = $this->table('bg_event_roles');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;
        $now = current_time('mysql', true);
        if (!$active) {
            $wpdb->update($table, [
                'status' => 'revoked',
                'ended_at' => $now,
                'updated_at' => $now,
            ], [
                'event_post_id' => $eventId,
                'constituent_id' => $constituentId,
                'role_label' => $roleLabel,
            ]);
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'ended', ended_at = %s, updated_at = %s
             WHERE event_post_id = %d AND constituent_id = %d AND source = 'assignment'
               AND role_label <> %s AND status = 'active'",
            $now,
            $now,
            $eventId,
            $constituentId,
            $roleLabel
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
             (event_post_id, constituent_id, user_id, role_label, status, source, assigned_by, assigned_at, ended_at, created_at, updated_at)
             VALUES (%d, %d, %d, %s, 'active', 'assignment', %d, %s, NULL, %s, %s)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), status = 'active', source = 'assignment',
              assigned_by = VALUES(assigned_by), assigned_at = VALUES(assigned_at), ended_at = NULL, updated_at = VALUES(updated_at)",
            $eventId,
            $constituentId,
            $userId,
            $roleLabel,
            $assignedBy,
            $now,
            $now,
            $now
        ));
    }

    public function assignment(int $userId, int $eventId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_organizers')}
             WHERE event_post_id = %d AND user_id = %d LIMIT 1",
            $eventId,
            $userId
        ));
        return $row ?: null;
    }

    public function activeAssignmentCount(int $userId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_event_organizers')}
             WHERE user_id = %d AND status = 'active'",
            $userId
        ));
    }

    /** @return list<object> */
    public function assignments(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT a.*, p.post_title, p.post_status, u.user_email, u.display_name
             FROM {$this->table('bg_event_organizers')} a
             JOIN {$wpdb->posts} p ON p.ID = a.event_post_id AND p.post_type = 'bg_event'
             JOIN {$wpdb->users} u ON u.ID = a.user_id
             ORDER BY a.status ASC, p.post_title ASC, u.display_name ASC"
        ) ?: [];
    }

    /** @return list<\WP_Post> */
    public function eventsForUser(int $userId, bool $allForAdmin = false): array
    {
        global $wpdb;
        if ($allForAdmin) {
            $events = get_posts([
                'post_type' => 'bg_event',
                'post_status' => 'publish',
                'posts_per_page' => 500,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            return $this->sortEvents($events);
        }
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT event_post_id FROM {$this->table('bg_event_organizers')}
             WHERE user_id = %d AND status = 'active' ORDER BY event_post_id DESC",
            $userId
        )) ?: []);
        if (!$ids) return [];
        $events = get_posts([
            'post_type' => 'bg_event',
            'post_status' => 'publish',
            'post__in' => $ids,
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        return $this->sortEvents($events);
    }

    public function isPublishedEvent(int $eventId): bool
    {
        return $eventId > 0
            && get_post_type($eventId) === 'bg_event'
            && get_post_status($eventId) === 'publish';
    }

    public function registrationCount(int $eventId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_registrations')}
             WHERE event_post_id = %d AND status IN ('confirmed','paid')",
            $eventId
        ));
    }

    /**
     * Event-scoped decrypted contacts. A profile opt-out is enforced here,
     * before a roster reaches the renderer, message workflow, or CSV export.
     * Allowed rows contain only the primary name, email, phone, and postal
     * address; demographic, donation, alternate-email, profile-note, LGL,
     * and Stripe fields never leave this method.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(int $eventId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.bg_constituent_id, r.lgl_constituent_id, r.tier,
                    r.status, r.created_at,
                    COALESCE(a.status, 'registered') AS attendance_status,
                    COALESCE(a.revision, '') AS attendance_revision
             FROM {$this->table('bg_registrations')} r
             LEFT JOIN {$this->table('bg_event_attendance')} a ON a.registration_id = r.id
             WHERE r.event_post_id = %d AND r.status IN ('confirmed','paid')
             ORDER BY r.created_at ASC, r.id ASC",
            $eventId
        )) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $localId = (int) ($row->bg_constituent_id ?? 0);
            if (!$localId && (int) ($row->lgl_constituent_id ?? 0) > 0) {
                $linked = $this->constituents->idsByLglId((int) $row->lgl_constituent_id);
                $localId = count($linked) === 1 ? (int) $linked[0] : 0;
            }
            $sharingAllowed = $localId > 0
                && $this->constituents->contactSharingAllowed($localId);
            // Do not decrypt the profile at all when the person opted out.
            // The anonymous registration row remains usable for aggregate
            // counts and attendance without disclosing identity or contact.
            $person = $sharingAllowed ? $this->constituents->get($localId) : null;
            $out[] = [
                'registration_id' => (int) $row->id,
                'name' => $person ? trim((string) $person['first_name'] . ' ' . (string) $person['last_name']) : '',
                'email' => $person ? (string) $person['email'] : '',
                'phone' => $person ? (string) $person['phone'] : '',
                'address1' => $person ? (string) $person['address1'] : '',
                'address2' => $person ? (string) $person['address2'] : '',
                'city' => $person ? (string) $person['city'] : '',
                'state' => $person ? (string) $person['state'] : '',
                'postal' => $person ? (string) $person['postal'] : '',
                'tier' => (string) $row->tier,
                'registration_status' => (string) $row->status,
                'attendance_status' => in_array((string) $row->attendance_status, self::ATTENDANCE, true)
                    ? (string) $row->attendance_status : 'registered',
                'created_at' => (string) $row->created_at,
                'attendance_revision' => (string) ($row->attendance_revision ?? ''),
                'contact_available' => (bool) $person,
                'contact_withheld' => $localId > 0 && !$sharingAllowed,
            ];
        }
        return $out;
    }

    public function setAttendance(int $eventId, int $registrationId, string $status, int $userId): bool
    {
        global $wpdb;
        if (!in_array($status, self::ATTENDANCE, true)) return false;
        $acquired = (bool) apply_filters('bg_organizer_event_lock_acquire', true, $eventId, 'attendance');
        if (!$acquired) return false;
        try {
            if ((bool) apply_filters('bg_organizer_event_writes_locked', false, $eventId, 'attendance')) return false;
            $belongs = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table('bg_registrations')}
                 WHERE id = %d AND event_post_id = %d AND status IN ('confirmed','paid')",
                $registrationId,
                $eventId
            ));
            if ($belongs !== 1) return false;
            $now = current_time('mysql', true);
            $existingChecked = $wpdb->get_var($wpdb->prepare(
                "SELECT checked_in_at FROM {$this->table('bg_event_attendance')} WHERE registration_id = %d",
                $registrationId
            ));
            $checked = in_array($status, ['checked_in', 'attended'], true)
                ? ((string) $existingChecked !== '' ? (string) $existingChecked : $now)
                : null;
            $ok = $wpdb->replace($this->table('bg_event_attendance'), [
                'registration_id' => $registrationId,
                'event_post_id' => $eventId,
                'status' => $status,
                'updated_by' => $userId,
                'checked_in_at' => $checked,
                'updated_at' => $now,
                'revision' => wp_generate_uuid4(),
            ]) !== false;
            if ($ok) $this->audit($userId, $eventId, $registrationId, 'attendance_changed', $status);
            return $ok;
        } finally {
            do_action('bg_organizer_event_lock_release', $eventId, 'attendance');
        }
    }

    public function audit(int $userId, int $eventId, int $registrationId, string $action, string $detail = ''): void
    {
        global $wpdb;
        $wpdb->insert($this->table('bg_organizer_audit'), [
            'user_id' => max(0, $userId),
            'event_post_id' => max(0, $eventId),
            'registration_id' => max(0, $registrationId),
            'action' => bg_substr(sanitize_key($action), 0, 48),
            'detail' => bg_substr(sanitize_text_field($detail), 0, 255),
            'created_at' => current_time('mysql', true),
        ]);
    }

    /** @return list<object> */
    public function audits(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name, p.post_title
             FROM {$this->table('bg_organizer_audit')} a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = a.event_post_id
             ORDER BY a.id DESC LIMIT %d",
            $limit
        )) ?: [];
    }

    /** Store an authenticated encrypted change payload. */
    public function createChangeRequest(int $eventId, int $userId, string $type, array $payload): int
    {
        global $wpdb;
        if (!in_array($type, ['create', 'edit'], true) || $eventId < 1 || $userId < 1) return 0;
        if ($this->hasPendingRequest($eventId, $userId)) return 0;
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert($this->table('bg_event_change_requests'), [
            'event_post_id' => $eventId,
            'user_id' => $userId,
            'request_type' => $type,
            'payload_enc' => '',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        $json = wp_json_encode($payload);
        $cipher = is_string($json) ? $this->encryption->encrypt($json, $this->changeContext($id)) : '';
        $saved = $cipher !== '' && $wpdb->update(
            $this->table('bg_event_change_requests'),
            ['payload_enc' => $cipher],
            ['id' => $id]
        ) !== false;
        $verified = $saved
            && $this->encryption->decryptOrNull($cipher, $this->changeContext($id)) === $json;
        if (!$verified) {
            $wpdb->query('ROLLBACK');
            $wpdb->delete($this->table('bg_event_change_requests'), ['id' => $id]);
            $this->logger->error('organizers', 'Event change request refused because its encrypted payload did not verify.', [
                'event' => $eventId,
                'user' => $userId,
            ]);
            return 0;
        }
        $wpdb->query('COMMIT');
        $this->audit($userId, $eventId, 0, 'change_requested', $type . ' request #' . $id);
        return $id;
    }

    public function hasPendingRequest(int $eventId, int $userId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('bg_event_change_requests')}
             WHERE event_post_id = %d AND user_id = %d AND status IN ('pending','processing')",
            $eventId,
            $userId
        )) > 0;
    }

    /** @return list<object> */
    public function pendingRequests(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT r.id, r.event_post_id, r.user_id, r.request_type, r.status,
                    r.created_at, p.post_title, u.display_name, u.user_email
             FROM {$this->table('bg_event_change_requests')} r
             JOIN {$wpdb->posts} p ON p.ID = r.event_post_id
             JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.status IN ('pending','processing') ORDER BY r.created_at ASC, r.id ASC"
        ) ?: [];
    }

    /** @return list<object> */
    public function requestsForEvent(int $eventId, int $userId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, request_type, status, created_at, reviewed_at
             FROM {$this->table('bg_event_change_requests')}
             WHERE event_post_id = %d AND user_id = %d ORDER BY id DESC LIMIT 20",
            $eventId,
            $userId
        )) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function changePayload(int $requestId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload_enc FROM {$this->table('bg_event_change_requests')} WHERE id = %d",
            $requestId
        ));
        if (!$row) return null;
        $json = $this->encryption->decryptOrNull((string) $row->payload_enc, $this->changeContext($requestId));
        if ($json === null) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function request(int $requestId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('bg_event_change_requests')} WHERE id = %d",
            $requestId
        ));
        return $row ?: null;
    }

    /** Atomically reserve a review so two staff requests cannot apply it twice. */
    public function claimRequest(int $requestId, int $reviewedBy): bool
    {
        global $wpdb;
        return $wpdb->update($this->table('bg_event_change_requests'), [
            'status' => 'processing',
            'reviewed_by' => $reviewedBy,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $requestId, 'status' => 'pending']) === 1;
    }

    public function releaseRequest(int $requestId): bool
    {
        global $wpdb;
        return $wpdb->update($this->table('bg_event_change_requests'), [
            'status' => 'pending',
            'reviewed_by' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $requestId, 'status' => 'processing']) !== false;
    }

    public function finishRequest(int $requestId, string $status, int $reviewedBy): bool
    {
        global $wpdb;
        if (!in_array($status, ['approved', 'rejected'], true)) return false;
        return $wpdb->update($this->table('bg_event_change_requests'), [
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $requestId, 'status' => 'processing']) === 1;
    }

    private function changeContext(int $id): string
    {
        return 'organizer-change:' . $id;
    }

    /** @param list<\WP_Post> $events @return list<\WP_Post> */
    private function sortEvents(array $events): array
    {
        $today = current_time('Y-m-d');
        usort($events, static function (\WP_Post $a, \WP_Post $b) use ($today): int {
            $ad = (string) get_post_meta($a->ID, '_bg_start_date', true);
            $bd = (string) get_post_meta($b->ID, '_bg_start_date', true);
            // An event remains current through its furnished end date, matching
            // the public calendar and registration-close semantics.
            $ae = (string) get_post_meta($a->ID, '_bg_end_date', true);
            $be = (string) get_post_meta($b->ID, '_bg_end_date', true);
            $alast = $ae !== '' ? $ae : $ad;
            $blast = $be !== '' ? $be : $bd;
            $ab = $ad === '' ? 2 : ($alast >= $today ? 0 : 1);
            $bb = $bd === '' ? 2 : ($blast >= $today ? 0 : 1);
            if ($ab !== $bb) return $ab <=> $bb;
            $asort = $ab === 1 ? $alast : $ad;
            $bsort = $bb === 1 ? $blast : $bd;
            if ($asort === $bsort) return $a->ID <=> $b->ID;
            // Today and future events run soonest-first. Past published
            // events remain available after them, newest effective end first,
            // so check-in and follow-up workflows are not broken by ordering.
            return $ab === 1 ? strcmp($bsort, $asort) : strcmp($asort, $bsort);
        });
        return $events;
    }
}
