<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Deterministic mapper: staged payloads to bg_event posts.
 *
 * Mapping rules preserve source data while preventing destructive updates:
 *
 *  - firstNonEmpty(), not null coalescing. LGL returns empty strings
 *    alongside populated sibling keys; ?? chains let the empty string
 *    shadow a populated sibling value.
 *  - Tolerant date parsing with the raw value logged on failure.
 *    A garbage date must never fatal a run or silently zero a field.
 *  - A record without a parseable date is created as a draft, held
 *    for review, and a transfer never re-drafts or blanks a post that
 *    already has a date.
 *  - Per-field ownership: a field marked admin-entered is never
 *    overwritten by source data. Marked provisional heals to source.
 *  - Dry mode runs this exact pipeline against the same staged bytes
 *    with writes suppressed; the report is the run.
 */
final class Mapper
{
    public const OWNED_FIELDS = [
        'post_title', 'post_content',
        '_bg_start_date', '_bg_start_time', '_bg_end_date', '_bg_end_time',
        '_bg_reg_url', '_bg_summary', '_bg_image',
    ];

    public function __construct(private Logger $logger)
    {
    }

    /**
     * @param object{source: string, external_id: string, payload: string} $row
     * @return array<string, mixed> report entry
     */
    public function apply(object $row, bool $dry): array
    {
        $decoded = json_decode($row->payload, true);
        if (!is_array($decoded)) {
            return $this->entry($row, 'error', 0, ['note' => 'payload not decodable']);
        }

        $salesState = $row->source === 'eventbrite'
            ? EventbriteSales::state($decoded)
            : EventbriteSales::UNKNOWN;
        if ($salesState === EventbriteSales::CLOSED) {
            $postId = $this->findByExternal($row->source, $row->external_id);
            if ($postId < 0) {
                return $this->entry($row, 'held', 0, ['note' => 'more than one site event carries this Eventbrite ID']);
            }
            $existing = $postId ? get_post($postId) : null;
            if ($existing instanceof \WP_Post && !$this->eventbriteOwnsCalendarRecord($existing)) {
                if (!$dry) {
                    update_post_meta($existing->ID, '_bg_eventbrite_sales_state', EventbriteSales::CLOSED);
                }
                return $this->entry($row, 'skipped', $existing->ID, [
                    'note' => 'BosqueGrande owns this linked event; Eventbrite ticket availability did not change its calendar status',
                ]);
            }
            return $this->delistEventbrite($row, $existing, $dry);
        }

        $norm = $row->source === 'eventbrite'
            ? $this->normalizeEventbrite($decoded)
            : $this->normalizeLgl($decoded);

        if ($norm['title'] === '') {
            return $this->entry($row, 'skipped', 0, ['note' => 'record carries no name']);
        }

        $dateNote = '';
        $start = $this->parseDate($norm['start_raw']);
        if ($start === null && $norm['start_raw'] !== '') {
            $this->logger->warn('exchange', 'Unparseable start value; record held.', [
                'source' => $row->source, 'external_id' => $row->external_id,
                'raw' => $this->cut($norm['start_raw'], 0, 120),
            ]);
            $dateNote = 'start value not parseable, raw preserved in log';
        } elseif ($norm['start_raw'] === '') {
            $dateNote = 'no start value provided by the source';
        }
        $end = $this->parseDate($norm['end_raw']);

        $postId = $this->findByExternal($row->source, $row->external_id);
        if ($postId < 0) {
            return $this->entry($row, 'held', 0, ['note' => 'more than one site event carries this Eventbrite ID']);
        }
        $existing = $postId ? get_post($postId) : null;

        if ($existing === null) {
            return $this->create($row, $norm, $start, $end, $dateNote, $dry, $salesState);
        }
        if ($row->source === 'eventbrite' && !$this->eventbriteOwnsCalendarRecord($existing)) {
            if (!$dry && $salesState !== EventbriteSales::UNKNOWN) {
                update_post_meta($existing->ID, '_bg_eventbrite_sales_state', $salesState);
            }
            return $this->entry($row, 'skipped', $existing->ID, [
                'note' => 'BosqueGrande owns this linked event; inbound Eventbrite event fields were preserved',
            ]);
        }
        return $this->update($row, $existing, $norm, $start, $end, $dateNote, $dry, $salesState);
    }

    /** Only Eventbrite-origin calendar records inherit remote listing state and fields. */
    private function eventbriteOwnsCalendarRecord(\WP_Post $post): bool
    {
        return (string) get_post_meta($post->ID, '_bg_src', true) === 'eventbrite';
    }

    /* ── Create ─────────────────────────────────────────────────── */

    /** @param array<string, string> $norm @param ?array{date: string, time: string} $start @param ?array{date: string, time: string} $end */
    private function create(object $row, array $norm, ?array $start, ?array $end, string $dateNote, bool $dry, string $salesState): array
    {
        $held = $start === null;
        $fields = $this->fieldValues($norm, $start, $end);

        if (!$dry) {
            $postId = wp_insert_post([
                'post_type'    => 'bg_event',
                'post_status'  => $held ? 'draft' : 'publish',
                'post_title'   => $fields['post_title'],
                'post_content' => $fields['post_content'],
            ], true);
            if (is_wp_error($postId)) {
                return $this->entry($row, 'error', 0, ['note' => $postId->get_error_message()]);
            }
            update_post_meta($postId, '_bg_src', $row->source);
            update_post_meta($postId, '_bg_src_id', $row->external_id);
            update_post_meta($postId, '_bg_src_key', $row->source . ':' . $row->external_id);
            if (($norm['code'] ?? '') !== '') update_post_meta($postId, '_bg_src_code', $norm['code']);
            if ($row->source === 'eventbrite' && $salesState !== EventbriteSales::UNKNOWN) {
                update_post_meta($postId, '_bg_eventbrite_sales_state', $salesState);
            }
            $own = [];
            $snap = [];
            foreach (self::OWNED_FIELDS as $f) {
                $this->writeField($postId, $f, $fields[$f] ?? '');
                $own[$f] = 'source';
                $snap[$f] = (string) ($fields[$f] ?? '');
            }
            update_post_meta($postId, '_gt_ownership', $own);
            update_post_meta($postId, '_gt_src_snapshot', $snap);
        } else {
            $postId = 0;
        }

        $tagNote = '';
        if (!$dry && $postId) {
            $tagRes = Tagger::apply((int) $postId, Tagger::documentFor((int) $postId), false);
            if ($tagRes['action'] === 'tagged') $tagNote = implode(', ', $tagRes['tags']);
        }
        return $this->entry($row, $held ? 'held' : 'created', (int) $postId, array_filter([
            'title' => $fields['post_title'],
            'start' => $start ? trim($start['date'] . ' ' . $start['time']) : '',
            'note'  => $dateNote,
            'tags'  => $tagNote,
            'status' => $held ? 'draft, held for review' : 'publish',
        ]));
    }

    /* ── Update ─────────────────────────────────────────────────── */

    /** @param array<string, string> $norm @param ?array{date: string, time: string} $start @param ?array{date: string, time: string} $end */
    private function update(object $row, \WP_Post $post, array $norm, ?array $start, ?array $end, string $dateNote, bool $dry, string $salesState): array
    {
        $own = get_post_meta($post->ID, '_gt_ownership', true);
        $own = is_array($own) ? $own : [];
        $snap = get_post_meta($post->ID, '_gt_src_snapshot', true);
        $snap = is_array($snap) ? $snap : [];
        $fields = $this->fieldValues($norm, $start, $end);

        $existingDate = (string) get_post_meta($post->ID, '_bg_start_date', true);
        if ($start === null && $existingDate !== '') {
            // The invariant: a source that stops furnishing a date never
            // blanks or drafts a post that has one.
            unset($fields['_bg_start_date'], $fields['_bg_start_time']);
            $dateNote = $dateNote !== '' ? $dateNote . '; date of record retained' : 'date of record retained';
        }

        $changes = [];
        $autoDelisted = $row->source === 'eventbrite'
            && $salesState === EventbriteSales::SELLING
            && (string) get_post_meta($post->ID, '_bg_eventbrite_auto_delisted', true) === '1'
            && $post->post_status === 'draft';
        if ($autoDelisted) {
            $changes['post_status'] = ['from' => 'draft', 'to' => 'publish'];
            if (!$dry) {
                $result = wp_update_post(['ID' => $post->ID, 'post_status' => 'publish'], true);
                if (is_wp_error($result)) {
                    return $this->entry($row, 'error', $post->ID, ['note' => $result->get_error_message()]);
                }
                delete_post_meta($post->ID, '_bg_eventbrite_auto_delisted');
                delete_post_meta($post->ID, '_bg_eventbrite_auto_delisted_at');
            }
        }
        foreach ($fields as $f => $value) {
            $owner = $own[$f] ?? 'source';
            if ($owner === 'admin') continue;
            $current = $this->readField($post, $f);
            if ((string) $value === $current) continue;
            if ($value === '' && in_array($f, ['post_title', '_bg_start_date'], true)) continue;
            $changes[$f] = ['from' => $this->cut($current, 0, 80), 'to' => $this->cut((string) $value, 0, 80)];
            if (!$dry) {
                $this->writeField($post->ID, $f, (string) $value);
                $own[$f] = 'source';
                $snap[$f] = (string) $value;
            }
        }

        if (!$dry && $changes) {
            update_post_meta($post->ID, '_gt_ownership', $own);
            update_post_meta($post->ID, '_gt_src_snapshot', $snap);
        }

        if (!$dry) {
            if (($norm['code'] ?? '') !== '') update_post_meta($post->ID, '_bg_src_code', $norm['code']);
            if ($row->source === 'eventbrite' && $salesState !== EventbriteSales::UNKNOWN) {
                update_post_meta($post->ID, '_bg_eventbrite_sales_state', $salesState);
            }
            Tagger::apply($post->ID, Tagger::documentFor($post->ID), false);
        }

        if (!$changes) {
            return $this->entry($row, 'skipped', $post->ID, array_filter(['note' => $dateNote !== '' ? $dateNote : 'no differences']));
        }
        return $this->entry($row, 'updated', $post->ID, array_filter([
            'changes' => $changes,
            'note'    => $dateNote,
        ]));
    }

    /* ── Eventbrite availability ─────────────────────────────────── */

    private function delistEventbrite(object $row, ?\WP_Post $post, bool $dry): array
    {
        if ($post === null) {
            return $this->entry($row, 'skipped', 0, [
                'note' => 'Eventbrite is no longer selling tickets; event not imported',
            ]);
        }

        if ((string) get_post_meta($post->ID, '_bg_calendar_force_listed', true) === '1') {
            $needsPublish = !in_array($post->post_status, ['publish', 'future'], true);
            if (!$dry) {
                if ($needsPublish) {
                    $result = wp_update_post(['ID' => $post->ID, 'post_status' => 'publish'], true);
                    if (is_wp_error($result)) {
                        return $this->entry($row, 'error', $post->ID, ['note' => $result->get_error_message()]);
                    }
                }
                delete_post_meta($post->ID, '_bg_eventbrite_auto_delisted');
                delete_post_meta($post->ID, '_bg_eventbrite_auto_delisted_at');
                update_post_meta($post->ID, '_bg_eventbrite_sales_state', EventbriteSales::CLOSED);
            }
            return $this->entry($row, $needsPublish ? 'updated' : 'skipped', $post->ID, [
                'note' => 'calendar listing retained by an explicit office override',
            ]);
        }

        $wasListed = in_array($post->post_status, ['publish', 'future'], true);
        if (!$dry) {
            if ($wasListed) {
                $result = wp_update_post(['ID' => $post->ID, 'post_status' => 'draft'], true);
                if (is_wp_error($result)) {
                    return $this->entry($row, 'error', $post->ID, ['note' => $result->get_error_message()]);
                }
                update_post_meta($post->ID, '_bg_eventbrite_auto_delisted', '1');
                update_post_meta($post->ID, '_bg_eventbrite_auto_delisted_at', gmdate('c'));
            }
            update_post_meta($post->ID, '_bg_eventbrite_sales_state', EventbriteSales::CLOSED);
        }

        if (!$wasListed) {
            return $this->entry($row, 'skipped', $post->ID, [
                'note' => 'Eventbrite is no longer selling tickets; event is already delisted',
            ]);
        }
        return $this->entry($row, 'updated', $post->ID, [
            'changes' => ['post_status' => ['from' => $post->post_status, 'to' => 'draft']],
            'note' => 'automatically delisted because Eventbrite is no longer selling tickets',
        ]);
    }

    /* ── Normalizers ────────────────────────────────────────────── */

    /** @param array<string, mixed> $e @return array<string, string> */
    private function normalizeLgl(array $e): array
    {
        return [
            'title'     => $this->firstNonEmpty($e, ['name', 'event_name', 'title']),
            'desc'      => $this->firstNonEmpty($e, ['description', 'note', 'notes']),
            'start_raw' => $this->firstNonEmpty($e, ['start_date', 'starts_at', 'start_at', 'date']),
            'end_raw'   => $this->firstNonEmpty($e, ['end_date', 'ends_at', 'end_at']),
            'reg_url'   => $this->firstNonEmpty($e, ['registration_url', 'url']),
            'image'     => '',
            'code'      => $this->firstNonEmpty($e, ['code', 'short_code']),
        ];
    }

    /** @param array<string, mixed> $e @return array<string, string> */
    private function normalizeEventbrite(array $e): array
    {
        $ev = isset($e['event']) && is_array($e['event']) ? $e['event'] : $e;
        return [
            'title'     => trim((string) ($ev['name']['text'] ?? $ev['name'] ?? '')),
            'desc'      => (string) ($ev['description']['html'] ?? $ev['description']['text'] ?? $ev['summary'] ?? ''),
            'start_raw' => (string) ($ev['start']['local'] ?? $ev['start']['utc'] ?? ''),
            'end_raw'   => (string) ($ev['end']['local'] ?? $ev['end']['utc'] ?? ''),
            'reg_url'   => (string) ($ev['url'] ?? ''),
            'image'     => (string) ($ev['logo']['original']['url'] ?? $ev['logo']['url'] ?? ''),
            'code'      => '',
        ];
    }

    /** @param array<string, mixed> $e @param string[] $keys */
    private function firstNonEmpty(array $e, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $e[$k] ?? null;
            if (is_string($v) && trim($v) !== '') return trim($v);
            if (is_numeric($v)) return (string) $v;
        }
        return '';
    }

    /* ── Dates ──────────────────────────────────────────────────── */

    /** @return ?array{date: string, time: string} */
    public function parseDate(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sO', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'm/d/Y H:i', 'm/d/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTime) {
                $hasTime = strpos($fmt, 'H') !== false;
                return ['date' => $dt->format('Y-m-d'), 'time' => $hasTime ? $dt->format('g:i A') : ''];
            }
        }
        try {
            $dt = new \DateTime($raw);
            $hasTime = (bool) preg_match('/\d[:h]\d|T\d{2}/', $raw);
            return ['date' => $dt->format('Y-m-d'), 'time' => $hasTime ? $dt->format('g:i A') : ''];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ── Field plumbing ─────────────────────────────────────────── */

    /** @param array<string, string> $norm @param ?array{date: string, time: string} $start @param ?array{date: string, time: string} $end @return array<string, string> */
    private function fieldValues(array $norm, ?array $start, ?array $end): array
    {
        $startDate = (string) ($start['date'] ?? '');
        $startTime = (string) ($start['time'] ?? '');
        $endDate = (string) ($end['date'] ?? '');
        $endTime = (string) ($end['time'] ?? '');

        // Imported events always acquire a useful ending clock time.
        // If Eventbrite/LGL omits it, treat the event as one hour long. This
        // matches the editor and public calendar fallback rather than leaving
        // transferred records with a second, conflicting time convention.
        if ($endTime === '' && $startTime !== '') {
            $clock = \DateTimeImmutable::createFromFormat('!g:i A', strtoupper($startTime));
            if ($clock instanceof \DateTimeImmutable) {
                $finish = $clock->modify('+1 hour');
                $endTime = $finish->format('g:i A');
                $endDate = $startDate;
                if ($startDate !== '' && $finish->format('Y-m-d') !== $clock->format('Y-m-d')) {
                    $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
                    if ($day instanceof \DateTimeImmutable) $endDate = $day->modify('+1 day')->format('Y-m-d');
                }
            }
        }
        if ($endDate === '' && $endTime !== '' && $startDate !== '') $endDate = $startDate;

        return [
            'post_title'       => $norm['title'],
            'post_content'     => $norm['desc'],
            '_bg_start_date' => $startDate,
            '_bg_start_time' => $startTime,
            '_bg_end_date'   => $endDate,
            '_bg_end_time'   => $endTime,
            '_bg_reg_url'    => $norm['reg_url'],
            '_bg_summary'    => wp_trim_words(wp_strip_all_tags($norm['desc']), 22, ''),
            '_bg_image'      => $norm['image'],
        ];
    }

    private function readField(\WP_Post $post, string $f): string
    {
        if ($f === 'post_title') return (string) $post->post_title;
        if ($f === 'post_content') return (string) $post->post_content;
        return (string) get_post_meta($post->ID, $f, true);
    }

    private function writeField(int $postId, string $f, string $value): void
    {
        if ($f === 'post_title' || $f === 'post_content') {
            wp_update_post([
                'ID' => $postId,
                $f === 'post_title' ? 'post_title' : 'post_content' => $value,
            ]);
            return;
        }
        update_post_meta($postId, $f, $value);
    }


    /** mbstring-agnostic truncation for log and report strings. */
    private function cut(string $s, int $a, int $b): string
    {
        return function_exists('mb_substr') ? mb_substr($s, $a, $b) : substr($s, $a, $b);
    }

    private function findByExternal(string $source, string $externalId): int
    {
        global $wpdb;
        $wpdb->last_error = '';
        if ($source === 'eventbrite') {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'bg_event' AND p.post_status != 'trash'
                   AND (
                        EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                WHERE x.post_id = p.ID AND x.meta_key = '_bg_src_key' AND x.meta_value = %s)
                        OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                   WHERE x.post_id = p.ID AND x.meta_key = '_bg_eb_event_id' AND x.meta_value = %s)
                        OR (EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                    WHERE x.post_id = p.ID AND x.meta_key = '_bg_src' AND x.meta_value = 'eventbrite')
                            AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                        WHERE x.post_id = p.ID AND x.meta_key = '_bg_src_id' AND x.meta_value = %s))
                   )
                 ORDER BY p.ID ASC LIMIT 2",
                'eventbrite:' . $externalId,
                $externalId,
                $externalId
            )) ?: [];
        } else {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_bg_src_key' AND pm.meta_value = %s
                   AND p.post_type = 'bg_event' AND p.post_status != 'trash'
                 ORDER BY pm.post_id ASC LIMIT 2",
                $source . ':' . $externalId
            )) ?: [];
        }
        if ($wpdb->last_error !== '') {
            $this->logger->error('exchange', 'An external event link lookup failed; transfer held.', [
                'source' => $source,
            ]);
            return -1;
        }
        if (count($ids) > 1) {
            $this->logger->error('exchange', 'An external Eventbrite ID is linked to more than one site event; transfer held.', [
                'source' => $source,
                'external_id' => $externalId,
                'matches' => array_map('intval', $ids),
            ]);
            return -1;
        }
        return (int) ($ids[0] ?? 0);
    }

    /** @return array<string, mixed> */
    private function entry(object $row, string $action, int $postId, array $extra = []): array
    {
        return array_merge([
            'source'      => $row->source,
            'external_id' => $row->external_id,
            'sha256'      => substr((string) ($row->sha256 ?? ''), 0, 12),
            'action'      => $action,
            'post_id'     => $postId,
        ], $extra);
    }
}
