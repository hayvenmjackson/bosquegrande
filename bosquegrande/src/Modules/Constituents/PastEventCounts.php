<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

if (!defined('ABSPATH')) exit;

/**
 * Distinct past participation from built-in registrations plus imported LGL
 * history, keyed by permanent constituent id.
 *
 * BosqueGrande does not yet store a person-level door check-in, so callers must not
 * present the result as verified attendance.
 */
final class PastEventCounts
{
    /**
     * @param list<int> $constituentIds
     * @return array<int, int> constituent id => distinct past-event count
     */
    public static function forConstituents(array $constituentIds, ConstituentStore $store): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $constituentIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) return [];

        $requested = array_fill_keys($ids, true);
        $counts = array_fill_keys($ids, 0);
        global $wpdb;

        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT r.event_post_id, r.bg_constituent_id, r.lgl_constituent_id, p.post_title
             FROM {$wpdb->prefix}bg_registrations r
             INNER JOIN {$wpdb->posts} p
                ON p.ID = r.event_post_id
               AND p.post_type = 'bg_event'
               AND p.post_status <> 'trash'
             INNER JOIN {$wpdb->postmeta} d
                ON d.post_id = r.event_post_id
               AND d.meta_key = '_bg_start_date'
             LEFT JOIN {$wpdb->postmeta} e
                ON e.post_id = r.event_post_id
               AND e.meta_key = '_bg_end_date'
             WHERE r.status IN ('confirmed','paid')
               AND d.meta_value <> ''
               /* Ongoing multi-day participation is not past until its end day. */
               AND COALESCE(NULLIF(e.meta_value, ''), d.meta_value) < %s",
            $today
        )) ?: [];
        // Migration 15 added the direct constituent id. Older rows may carry only an
        // LGL id; use that legacy link only when it identifies one active BG
        // record. A household/shared link is never guessed.
        $legacyLglIds = [];
        foreach ($rows as $row) {
            if ((int) ($row->bg_constituent_id ?? 0) <= 0
                && (int) ($row->lgl_constituent_id ?? 0) > 0
            ) {
                $legacyLglIds[] = (int) $row->lgl_constituent_id;
            }
        }
        $legacyLglIds = array_values(array_unique($legacyLglIds));
        $lglToBg = [];
        foreach (array_chunk($legacyLglIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $linked = $wpdb->get_results(
                "SELECT id, lgl_constituent_id
                 FROM {$wpdb->prefix}bg_constituents
                 WHERE status = 'active' AND lgl_constituent_id IN ({$in})
                 ORDER BY id ASC"
            ) ?: [];
            foreach ($linked as $record) {
                $lglId = (int) $record->lgl_constituent_id;
                if (!array_key_exists($lglId, $lglToBg)) {
                    $lglToBg[$lglId] = (int) $record->id;
                } else {
                    $lglToBg[$lglId] = 0;
                }
            }
        }

        /** @var array<int, array<int, true>> $eventsByPerson */
        $eventsByPerson = [];
        /** @var array<int, array<string, true>> $nativeTitlesByPerson */
        $nativeTitlesByPerson = [];
        foreach ($rows as $row) {
            $bgId = (int) ($row->bg_constituent_id ?? 0);
            if ($bgId <= 0) {
                $bgId = (int) ($lglToBg[(int) ($row->lgl_constituent_id ?? 0)] ?? 0);
            }
            $eventId = (int) ($row->event_post_id ?? 0);
            if ($bgId <= 0 || $eventId <= 0 || !isset($requested[$bgId])) continue;
            $eventsByPerson[$bgId][$eventId] = true;
            $titleKey = ParticipationHistory::eventKey((string) ($row->post_title ?? ''));
            if ($titleKey !== '') $nativeTitlesByPerson[$bgId][$titleKey] = true;
        }

        $historyFieldIds = ParticipationHistory::fieldIds($store);
        foreach ($ids as $bgId) {
            $historyEvents = [];
            if ($historyFieldIds) {
                $values = $store->valuesFor($bgId);
                foreach ($historyFieldIds as $fieldId) {
                    foreach (ParticipationHistory::events((string) ($values[$fieldId] ?? '')) as $event) {
                        $key = ParticipationHistory::eventKey($event);
                        if ($key === '' || isset($nativeTitlesByPerson[$bgId][$key])) continue;
                        $historyEvents[$key] = true;
                    }
                }
            }
            $counts[$bgId] = count($eventsByPerson[$bgId] ?? []) + count($historyEvents);
        }
        return $counts;
    }
}
