<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Core\UserLink;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Identity\Session;
use BosqueGrande\Modules\OrganizerPortal\OrganizerRepository;

if (!defined('ABSPATH')) exit;

/**
 * Bridges My Profile's verified device session to an assigned organizer
 * account. Only accounts whose sole purpose is organizing are bridged: an
 * email-verified session must never become an editor or administrator login.
 */
final class OrganizerIdentityBridge
{
    public function __construct(
        private Session $session,
        private ConstituentStore $store,
        private OrganizerRepository $organizers,
    ) {
    }

    public function tryAuthenticate(): bool
    {
        if (is_user_logged_in()) {
            $this->ensureIdentitySessionForCurrentUser();
            return true;
        }
        $identity = $this->session->identity();
        if (!$identity) return false;
        $constituentId = $identity['source'] === 'bg'
            ? (int) $identity['constituent_id']
            : $this->localIdForLgl((int) $identity['constituent_id']);
        if ($constituentId < 1) return false;
        $userId = $this->userForConstituent($constituentId);
        if ($userId < 1 || !self::bridgeable($userId) || $this->organizers->activeAssignmentCount($userId) < 1) return false;
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, false, is_ssl());
        UserLink::set($userId, $constituentId);
        do_action('wp_login', get_userdata($userId)?->user_login ?? '', get_userdata($userId));
        return is_user_logged_in();
    }

    public function ensureIdentitySessionForCurrentUser(): void
    {
        $userId = get_current_user_id();
        // Only an actively assigned organizer/host may bridge a WordPress
        // login into the My Profile identity session. Ordinary site accounts
        // must continue through the normal magic-link verification route.
        if ($userId < 1 || $this->session->identity()
            || $this->organizers->activeAssignmentCount($userId) < 1
        ) return;
        $constituentId = UserLink::get($userId);
        if ($constituentId < 1) {
            $user = get_userdata($userId);
            $email = $user ? sanitize_email((string) $user->user_email) : '';
            $ids = $email !== '' ? $this->store->idsByEmail($email) : [];
            if (count($ids) === 1) {
                $constituentId = (int) $ids[0];
                UserLink::set($userId, $constituentId);
            }
        }
        if ($constituentId > 0) $this->session->start($constituentId, 'bg');
    }

    /** True for accounts that hold nothing beyond the organizer role. */
    public static function bridgeable(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) return false;
        if (is_multisite() && is_super_admin($userId)) return false;
        $extra = array_diff((array) $user->roles, [\BosqueGrande\Modules\OrganizerPortal\OrganizerAccess::ROLE, 'subscriber']);
        if ($extra !== []) return false;
        foreach (['edit_posts', 'upload_files', 'list_users', 'manage_options', 'bg_view_payment_details'] as $cap) {
            if (user_can($user, $cap)) return false;
        }
        return true;
    }

    private function localIdForLgl(int $lglId): int
    {
        $ids = $this->store->idsByLglId($lglId);
        return count($ids) === 1 ? (int) $ids[0] : 0;
    }

    private function userForConstituent(int $constituentId): int
    {
        $ids = UserLink::usersFor($constituentId);
        if (count($ids) === 1) return $ids[0];
        if ($ids) return 0;

        $record = $this->store->get($constituentId);
        $email = is_array($record) ? sanitize_email((string) ($record['email'] ?? '')) : '';
        if (!is_email($email)) return 0;
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || $this->organizers->activeAssignmentCount((int) $user->ID) < 1) return 0;
        UserLink::set((int) $user->ID, $constituentId);
        return (int) $user->ID;
    }
}
