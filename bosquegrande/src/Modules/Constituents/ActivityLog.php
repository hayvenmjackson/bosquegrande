<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * The constituent activity timeline. Append-only by contract: nothing
 * here updates or deletes a row; anonymization scrubs content but
 * keeps the row, so counts and history stay accurate.
 *
 * Writes require a registered kind (register new kinds through the
 * bg_activity_kinds filter). Summaries are convention-bound to carry
 * no PII: event titles, tiers, amounts, and counts are fine; names,
 * emails, and demographic values are not, because summaries are
 * stored outside the encrypted envelope.
 */
final class ActivityLog
{
    private const KINDS = [
        'create', 'update', 'note', 'registration', 'payment',
        'subscription', 'import', 'merge', 'anonymization', 'tag', 'consent',
    ];

    public function __construct(private Logger $logger)
    {
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        $kinds = apply_filters('bg_activity_kinds', self::KINDS);
        return is_array($kinds) ? array_values($kinds) : self::KINDS;
    }

    /** @param array<string, mixed> $meta Non-PII context only. */
    public function append(int $constituentId, string $kind, string $summary, array $meta = []): bool
    {
        global $wpdb;
        if (!in_array($kind, self::kinds(), true)) {
            $this->logger->warn('constituents', 'Activity write refused: unregistered kind.', ['kind' => $kind]);
            return false;
        }
        $ok = $wpdb->insert($wpdb->prefix . 'bg_activity', [
            'constituent_id' => $constituentId,
            'kind' => $kind,
            'summary' => bg_substr($summary, 0, 255),
            'meta' => $meta ? wp_json_encode($meta) : null,
            'created_at' => current_time('mysql', true),
        ]);
        if (!$ok) {
            $this->logger->warn('constituents', 'Activity insert failed.', ['kind' => $kind]);
        }
        return (bool) $ok;
    }

    /** @return list<object> Newest first. */
    public function for(int $constituentId, int $limit = 200): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT kind, summary, meta, created_at FROM {$wpdb->prefix}bg_activity
             WHERE constituent_id = %d ORDER BY id DESC LIMIT %d",
            $constituentId, $limit
        )) ?: [];
    }

    /**
     * Anonymization scrub: rows stay (append-only), content goes.
     * Kind and timestamp survive so the timeline's shape remains.
     */
    public function scrub(int $constituentId): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bg_activity
             SET summary = '[removed]', meta = NULL WHERE constituent_id = %d",
            $constituentId
        )) !== false;
    }

    /** Move every row from one record to another (merge). */
    public function reassign(int $fromId, int $toId): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bg_activity SET constituent_id = %d WHERE constituent_id = %d",
            $toId, $fromId
        )) !== false;
    }
}
