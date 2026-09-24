<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerPortal;

if (!defined('ABSPATH')) exit;

/** Central, fail-closed organizer authorization. */
final class OrganizerAccess
{
    public const ROLE = 'bg_event_organizer';
    public const PORTAL = 'bg_access_organizer_portal';
    public const ROSTER = 'bg_view_assigned_rosters';
    public const CHECK_IN = 'bg_checkin_assigned_attendees';
    public const MESSAGE = 'bg_message_assigned_attendees';
    public const EXPORT = 'bg_export_assigned_rosters';
    public const PROPOSE = 'bg_propose_assigned_event_edits';
    public const CREATE = 'bg_create_event_proposals';
    public const WALK_INS = 'bg_register_assigned_event_walkins';
    public const TENDERS = 'bg_record_assigned_event_tenders';
    public const PRIVATE_ANSWERS = 'bg_collect_assigned_event_private_answers';
    public const CLOSE_OUT = 'bg_closeout_assigned_events';

    public function __construct(private OrganizerRepository $repository)
    {
    }

    /** Create or heal the role without modifying any existing role. */
    public static function ensureRole(): void
    {
        $caps = [
            'read' => true,
            self::PORTAL => true,
            self::ROSTER => true,
            self::CHECK_IN => true,
            self::MESSAGE => true,
            self::EXPORT => true,
            self::PROPOSE => true,
            self::CREATE => true,
            self::WALK_INS => true,
            self::TENDERS => true,
            self::PRIVATE_ANSWERS => true,
            self::CLOSE_OUT => true,
        ];
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, 'Event Organizer', $caps);
            $role = get_role(self::ROLE);
        }
        if (!$role) return;
        foreach ($caps as $cap => $granted) {
            if ($granted && !$role->has_cap($cap)) $role->add_cap($cap, true);
        }
    }

    public function canUsePortal(int $userId): bool
    {
        return $userId > 0 && (
            user_can($userId, 'manage_options')
            || user_can($userId, self::PORTAL)
        );
    }

    public function canCreate(int $userId): bool
    {
        return $this->canUsePortal($userId) && (
            user_can($userId, 'manage_options')
            || user_can($userId, self::CREATE)
        );
    }

    public function canEvent(int $userId, int $eventId, string $action = 'view'): bool
    {
        if ($userId < 1 || $eventId < 1 || !$this->canUsePortal($userId)) return false;
        // The front-end portal is intentionally limited to public events.
        // Apply this before the administrator shortcut so drafts, pending
        // proposals, scheduled posts, and cancelled/deprecated drafts cannot
        // be opened by guessing a portal URL or submitting an old form.
        if (!$this->repository->isPublishedEvent($eventId)) return false;
        if (user_can($userId, 'manage_options')) return true;

        $map = [
            'view' => [self::ROSTER, null],
            'roster' => [self::ROSTER, null],
            'check_in' => [self::CHECK_IN, 'can_check_in'],
            'message' => [self::MESSAGE, 'can_message'],
            'export' => [self::EXPORT, 'can_export'],
            'propose' => [self::PROPOSE, 'can_propose_edits'],
            'walk_in' => [self::WALK_INS, 'can_register_walkins'],
            'tender' => [self::TENDERS, 'can_record_tenders'],
            'private_answers' => [self::PRIVATE_ANSWERS, 'can_collect_private_answers'],
            'close_out' => [self::CLOSE_OUT, 'can_close_out'],
        ];
        if (!isset($map[$action])) return false;
        [$cap, $field] = $map[$action];
        if (!user_can($userId, $cap)) return false;
        $assignment = $this->repository->assignment($userId, $eventId);
        if (!$assignment || (string) $assignment->status !== 'active') return false;
        return $field === null || (int) ($assignment->{$field} ?? 0) === 1;
    }
}
