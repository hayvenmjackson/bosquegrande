<?php

declare(strict_types=1);

/**
 * EventAdopter
 *
 * Finds "stray" event posts left behind by earlier calendar plugins
 * (Modern Events Calendar's mec-events archives, The Events
 * Calendar's tribe_events, or anything added via the
 * bg_adoptable_post_types filter) and adopts them: the post is
 * converted IN PLACE into a bg_event so it becomes a first-class
 * citizen of the one pipeline (calendar render, photos, locations,
 * register seam). Adoption, not federation: merely reading foreign post
 * types would show events with no REGISTER button, no photos, and no
 * locations, so we convert instead.
 *
 * Invariants honored:
 * - Dateless-draft: a stray with no detectable start date is adopted as
 *   a draft, never published, so it can never break the calendar.
 * - Idempotent: adopted posts leave the candidate pool (their post type
 *   changes), and every adoption is stamped with its origin
 *   (_bg_adopted_from, _bg_adopted_at) so it is reversible.
 * - In-place conversion preserves post IDs, slugs, content, thumbnails,
 *   and attachments. Nothing is copied, nothing is duplicated.
 */

namespace BosqueGrande\Modules\Migrator;

defined('ABSPATH') || exit;

final class EventAdopter
{
    /** Hard cap per run; protects against pathological archives. */
    private const MAX_PER_RUN = 500;

    /** Statuses we will never adopt. */
    private const SKIP_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * Candidate post types. Filterable; bg_event is always excluded.
     *
     * @return string[]
     */
    public function candidateTypes(): array
    {
        $types = apply_filters('bg_adoptable_post_types', [
            'mec-events',    // Modern Events Calendar
            'tribe_events',  // The Events Calendar
            'event',         // generic single-type calendars
            'events',
        ]);
        $types = array_values(array_unique(array_map('sanitize_key', (array) $types)));
        return array_values(array_diff($types, ['bg_event']));
    }

    /**
     * Scan the database directly for strays. Direct SQL on purpose:
     * leftovers from deleted plugins are often UNREGISTERED post types,
     * which WP_Query cannot see reliably.
     *
     * @return array<int, array{id:int, type:string, status:string, title:string, date:string, time:string, source:string}>
     */
    public function discover(): array
    {
        global $wpdb;
        $types = $this->candidateTypes();
        if (!$types) return [];

        $inTypes  = implode(',', array_fill(0, count($types), '%s'));
        $inSkip   = implode(',', array_fill(0, count(self::SKIP_STATUSES), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID, post_type, post_status, post_title
               FROM {$wpdb->posts}
              WHERE post_type IN ($inTypes)
                AND post_status NOT IN ($inSkip)
              ORDER BY ID ASC
              LIMIT %d",
            array_merge($types, self::SKIP_STATUSES, [self::MAX_PER_RUN])
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $when = $this->detectStart((int) $r['ID'], (string) $r['post_type']);
            $out[] = [
                'id'     => (int) $r['ID'],
                'type'   => (string) $r['post_type'],
                'status' => (string) $r['post_status'],
                'title'  => (string) $r['post_title'],
                'date'   => $when['date'],
                'time'   => $when['time'],
                'source' => $when['source'],
            ];
        }
        return $out;
    }

    /**
     * Adopt a set of post IDs. Returns counts for the notice.
     *
     * @param int[] $ids
     * @return array{adopted:int, published:int, drafted:int, skipped:int}
     */
    public function adopt(array $ids): array
    {
        $candidates = $this->candidateTypes();
        $res = ['adopted' => 0, 'published' => 0, 'drafted' => 0, 'skipped' => 0];

        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, self::MAX_PER_RUN) as $id) {
            $post = get_post($id);
            if (!$post
                || !in_array($post->post_type, $candidates, true)
                || in_array($post->post_status, self::SKIP_STATUSES, true)
            ) {
                $res['skipped']++;
                continue;
            }

            $when   = $this->detectStart($id, $post->post_type);
            $status = $when['date'] !== '' ? 'publish' : 'draft'; // dateless-draft invariant

            $updated = wp_update_post([
                'ID'          => $id,
                'post_type'   => 'bg_event',
                'post_status' => $status,
            ], true);
            if (is_wp_error($updated)) {
                $res['skipped']++;
                continue;
            }

            if ($when['date'] !== '') update_post_meta($id, '_bg_start_date', $when['date']);
            if ($when['time'] !== '') update_post_meta($id, '_bg_start_time', $when['time']);

            // Card summary if none exists: trimmed plain text of the body.
            if (get_post_meta($id, '_bg_summary', true) === '') {
                $plain = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
                if ($plain !== '') {
                    update_post_meta($id, '_bg_summary', wp_trim_words($plain, 30, '...'));
                }
            }

            update_post_meta($id, '_bg_adopted_from', $post->post_type);
            update_post_meta($id, '_bg_adopted_at', current_time('mysql'));
            update_post_meta($id, '_bg_adopt_date_source', $when['source']);

            $res['adopted']++;
            $status === 'publish' ? $res['published']++ : $res['drafted']++;
        }
        return $res;
    }

    /**
     * Best-effort start date/time detection, most reliable source first.
     *
     * @return array{date:string, time:string, source:string}
     */
    private function detectStart(int $id, string $type): array
    {
        // 1. Modern Events Calendar meta.
        $d = $this->validDate((string) get_post_meta($id, 'mec_start_date', true));
        if ($d !== '') {
            return ['date' => $d, 'time' => $this->mecTimeFromMeta($id), 'source' => 'mec_meta'];
        }

        // 2. Modern Events Calendar table (survives meta cleanup).
        $row = $this->mecTableRow($id);
        if ($row && ($d = $this->validDate((string) $row['start'])) !== '') {
            $secs = (int) ($row['time_start'] ?? 0);
            $time = $secs > 0 ? date('g:i A', $secs) : '';
            return ['date' => $d, 'time' => $time, 'source' => 'mec_table'];
        }

        // 3. The Events Calendar meta ("Y-m-d H:i:s").
        $tec = (string) get_post_meta($id, '_EventStartDate', true);
        if ($tec !== '' && ($ts = strtotime($tec)) !== false) {
            $d = $this->validDate(date('Y-m-d', $ts));
            if ($d !== '') {
                $allDay = get_post_meta($id, '_EventAllDay', true);
                $time = ($allDay === 'yes' || $allDay === '1') ? '' : date('g:i A', $ts);
                return ['date' => $d, 'time' => $time, 'source' => 'tec_meta'];
            }
        }

        // 4. Generic keys some single-type calendars use.
        foreach (['_event_start_date', 'event_date', '_event_date'] as $key) {
            $raw = (string) get_post_meta($id, $key, true);
            if ($raw !== '' && ($ts = strtotime($raw)) !== false) {
                $d = $this->validDate(date('Y-m-d', $ts));
                if ($d !== '') return ['date' => $d, 'time' => '', 'source' => $key];
            }
        }

        return ['date' => '', 'time' => '', 'source' => 'none'];
    }

    /** MEC stores hour/minute/ampm as separate meta; mec_allday suppresses time. */
    private function mecTimeFromMeta(int $id): string
    {
        if ((string) get_post_meta($id, 'mec_allday', true) === '1') return '';
        $h  = (int) get_post_meta($id, 'mec_start_time_hour', true);
        $m  = (int) get_post_meta($id, 'mec_start_time_minutes', true);
        $ap = strtoupper((string) get_post_meta($id, 'mec_start_time_ampm', true));
        if ($h < 1 || $h > 12 || !in_array($ap, ['AM', 'PM'], true)) return '';
        return sprintf('%d:%02d %s', $h, max(0, min(59, $m)), $ap);
    }

    /** @return array<string,mixed>|null */
    private function mecTableRow(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mec_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT start, time_start FROM {$table} WHERE post_id = %d ORDER BY id ASC LIMIT 1", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** Returns Y-m-d if the value parses to a plausible date, else ''. */
    private function validDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $ts = strtotime($raw);
        if ($ts === false) return '';
        $y = (int) date('Y', $ts);
        if ($y < 1990 || $y > 2100) return '';
        return date('Y-m-d', $ts);
    }
}
