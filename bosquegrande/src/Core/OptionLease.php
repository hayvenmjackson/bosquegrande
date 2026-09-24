<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Database-backed owner leases for request and worker idempotency.
 *
 * Stale takeover and release compare the serialized option value. An expired
 * worker cannot delete a replacement lease, and two reclaimers cannot both
 * believe they acquired the same key.
 */
final class OptionLease
{
    public static function acquire(string $key, int $ttl): string
    {
        if ($key === '' || strlen($key) > 190) return '';
        $owner = wp_generate_uuid4();
        $next = ['owner' => $owner, 'at' => time()];
        if (add_option($key, $next, '', false)) return $owner;

        $current = get_option($key, null);
        $lockedAt = is_array($current) ? (int) ($current['at'] ?? 0) : (int) $current;
        if ($lockedAt >= time() - max(1, $ttl)) return '';

        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s AND option_value = %s",
            maybe_serialize($next),
            $key,
            maybe_serialize($current)
        ));
        wp_cache_delete($key, 'options');
        return $changed === 1 ? $owner : '';
    }

    public static function release(string $key, string $owner): bool
    {
        if ($key === '' || $owner === '') return false;
        $current = get_option($key, null);
        if (!is_array($current)
            || !hash_equals((string) ($current['owner'] ?? ''), $owner)
        ) return false;

        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            maybe_serialize($current)
        ));
        wp_cache_delete($key, 'options');
        return $deleted === 1;
    }
}
