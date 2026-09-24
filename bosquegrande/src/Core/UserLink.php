<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * The link from a WordPress account to this site's contact record.
 *
 * Contact ids belong to one site's tables, but user meta is shared by every
 * site in a multisite network. On a network the key carries the site's table
 * prefix, so an account linked to contact #5 on one organization's site is
 * never mistaken for contact #5 on another's. Single sites keep the plain
 * key, so upgraded sites need no migration.
 */
final class UserLink
{
    public static function key(): string
    {
        global $wpdb;
        return is_multisite() ? $wpdb->get_blog_prefix() . 'bg_constituent_id' : 'bg_constituent_id';
    }

    public static function get(int $userId): int
    {
        return $userId > 0 ? (int) get_user_meta($userId, self::key(), true) : 0;
    }

    public static function set(int $userId, int $constituentId): void
    {
        if ($userId > 0 && $constituentId > 0) update_user_meta($userId, self::key(), $constituentId);
    }

    /** @return list<int> Accounts on this site linked to the contact. */
    public static function usersFor(int $constituentId, int $limit = 3): array
    {
        if ($constituentId < 1) return [];
        $users = get_users([
            'meta_key' => self::key(),
            'meta_value' => (string) $constituentId,
            'fields' => 'ids',
            'number' => $limit,
        ]);
        return array_values(array_unique(array_map('intval', is_array($users) ? $users : [])));
    }
}
