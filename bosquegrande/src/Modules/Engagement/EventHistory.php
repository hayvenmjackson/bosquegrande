<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Modules\Constituents\ParticipationHistory;
use BosqueGrande\Modules\Exchange\Tagger;
use BosqueGrande\Modules\GatheringTree\Vocabulary;

if (!defined('ABSPATH')) exit;

/**
 * Read-only index of this site's events (current and imported historical)
 * plus the bulk retagging tools. Participation history uses the title index
 * to decide whether a title-only "past events" value names an event that
 * happened before a given date.
 */
final class EventHistory
{
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'bg_historical'];

    /** @var array<string, list<array{id:int,title:string,start_date:string}>>|null */
    private ?array $byTitle = null;

    /**
     * Events grouped by normalized title.
     *
     * @return array<string, list<array{id:int,title:string,start_date:string}>>
     */
    public function byTitleKey(): array
    {
        if ($this->byTitle !== null) return $this->byTitle;
        global $wpdb;
        $statuses = "'" . implode("','", array_map('esc_sql', self::STATUSES)) . "'";
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, d.meta_value AS start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_bg_start_date'
             WHERE p.post_type = 'bg_event' AND p.post_status IN ({$statuses})
             ORDER BY p.ID ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $key = ParticipationHistory::eventKey((string) $row->post_title);
            if ($key === '') continue;
            $out[$key][] = [
                'id' => (int) $row->ID,
                'title' => (string) $row->post_title,
                'start_date' => substr((string) $row->start_date, 0, 10),
            ];
        }
        return $this->byTitle = $out;
    }

    /**
     * Re-run the tagging rules over every event. The optional assistant is
     * never called here.
     *
     * @return array{scanned:int,tagged:int,kept:int,untagged:int}
     */
    public function retagAll(): array
    {
        $counts = ['scanned' => 0, 'tagged' => 0, 'kept' => 0, 'untagged' => 0];
        foreach ($this->eventIds() as $postId) {
            $res = Tagger::apply($postId, Tagger::documentFor($postId), false, false);
            $counts['scanned']++;
            $action = (string) ($res['action'] ?? 'kept');
            $counts[isset($counts[$action]) ? $action : 'kept']++;
        }
        update_option('bg_history_last_retag', current_time('mysql', true), false);
        return $counts;
    }

    /**
     * Ask the configured assistant to tag a bounded number of events that
     * still lack a region or interest after the rules ran. A content hash
     * keeps one unclassifiable event from consuming every later batch.
     *
     * @return array{scanned:int,attempted:int,tagged:int,still_unresolved:int}
     */
    public function assistantTagBatch(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $locationTags = array_keys(Vocabulary::regions());
        $interestTags = array_keys(Vocabulary::interests());
        $locationTax = \BosqueGrande\Modules\Locations\Taxonomy::taxonomy();
        $result = ['scanned' => 0, 'attempted' => 0, 'tagged' => 0, 'still_unresolved' => 0];
        foreach ($this->eventIds() as $postId) {
            $result['scanned']++;
            $doc = Tagger::documentFor($postId);
            $decision = Tagger::decide($doc);
            $interestExisting = wp_get_object_terms($postId, 'bg_tag', ['fields' => 'slugs']);
            $locationExisting = $locationTax === 'bg_tag'
                ? $interestExisting
                : wp_get_object_terms($postId, $locationTax, ['fields' => 'slugs']);
            $interestExisting = is_wp_error($interestExisting) ? [] : array_map('sanitize_key', (array) $interestExisting);
            $locationExisting = is_wp_error($locationExisting) ? [] : array_map('sanitize_key', (array) $locationExisting);
            $known = array_values(array_unique(array_merge(
                array_map('sanitize_key', (array) ($decision['tags'] ?? [])),
                $interestExisting,
                $locationExisting
            )));
            $needsLocation = $locationTags && !array_intersect($known, $locationTags);
            $needsInterest = $interestTags && !array_intersect($known, $interestTags);
            if (!$needsLocation && !$needsInterest) continue;
            $hash = sha1((string) wp_json_encode($doc));
            if (hash_equals((string) get_post_meta($postId, '_bg_ai_tag_attempt_hash', true), $hash)) continue;
            update_post_meta($postId, '_bg_ai_tag_attempt_hash', $hash);
            $before = (array) get_post_meta($postId, Tagger::AUTO_META, true);
            $tagged = Tagger::apply($postId, $doc, false, true);
            $after = (array) get_post_meta($postId, Tagger::AUTO_META, true);
            $result['attempted']++;
            if ($after !== $before || ($tagged['action'] ?? '') === 'tagged') $result['tagged']++;
            $combined = array_values(array_unique(array_merge($known, array_map('sanitize_key', $after))));
            if (($locationTags && !array_intersect($combined, $locationTags))
                || ($interestTags && !array_intersect($combined, $interestTags))
            ) $result['still_unresolved']++;
            if ($result['attempted'] >= $limit) break;
        }
        update_option('bg_history_last_assistant_retag', current_time('mysql', true), false);
        return $result;
    }

    public function clearAssistantAttempts(): int
    {
        global $wpdb;
        $deleted = $wpdb->delete($wpdb->postmeta, ['meta_key' => '_bg_ai_tag_attempt_hash']);
        return $deleted === false ? 0 : (int) $deleted;
    }

    /** @return list<int> */
    private function eventIds(): array
    {
        $posts = get_posts([
            'post_type' => 'bg_event',
            'post_status' => self::STATUSES,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        return array_map('intval', is_array($posts) ? $posts : []);
    }
}
