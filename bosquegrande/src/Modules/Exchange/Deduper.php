<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Duplicate review: approximate record linkage over the
 * event corpus, the same edit-distance family LGL's own duplicate
 * finder uses, so the method is already familiar to the office.
 *
 * Candidate rule: two events are a candidate pair only when they
 * share a start date AND their normalized titles sit within the
 * similarity threshold. Blocking on the date first is what keeps a
 * weekly potluck (same title, many dates) out of the pile, and the
 * review table shows locations side by side because same title, same
 * date, different hall is a real thing the office must judge, not
 * the machine. "Not duplicates" verdicts are remembered permanently.
 *
 * Merge is a transplant, not a deletion: registrations move to the
 * kept event, the retired copy's source linkage is added to the kept
 * event (so future transfers update the survivor instead of
 * re-creating the duplicate), an image is carried over if the kept
 * copy lacks one, and the retired copy goes to the trash, reversibly.
 */
final class Deduper
{
    private const DISMISS_OPT = 'bg_dedup_dismissed';
    public const DEFAULT_THRESHOLD = 0.85;

    public function __construct(private Logger $logger)
    {
    }

    /** @return array<int, array<string, mixed>> candidate pairs, most similar first */
    public function scan(float $threshold = self::DEFAULT_THRESHOLD): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status, d.meta_value AS start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_bg_start_date'
             WHERE p.post_type = 'bg_event' AND p.post_status IN ('publish', 'draft')"
        ) ?: [];

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[(string) $r->start_date][] = $r;
        }

        $dismissed = $this->dismissed();
        $pairs = [];
        foreach ($byDate as $date => $group) {
            $n = count($group);
            if ($n < 2) continue;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $group[$i]; $b = $group[$j];
                    $key = $this->pairKey((int) $a->ID, (int) $b->ID);
                    if (isset($dismissed[$key])) continue;
                    $sim = self::similarity((string) $a->post_title, (string) $b->post_title);
                    if ($sim < $threshold) continue;
                    $pairs[] = [
                        'key' => $key,
                        'similarity' => $sim,
                        'date' => $date,
                        'a' => $this->describe((int) $a->ID, (string) $a->post_title, (string) $a->post_status),
                        'b' => $this->describe((int) $b->ID, (string) $b->post_title, (string) $b->post_status),
                    ];
                }
            }
        }
        usort($pairs, fn($x, $y) => $y['similarity'] <=> $x['similarity']);
        return array_slice($pairs, 0, 200);
    }

    /** Levenshtein similarity over normalized titles: 1.0 is identical. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' && $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $a = substr($a, 0, 250);
        $b = substr($b, 0, 250);
        $max = max(strlen($a), strlen($b));
        if ($max === 0) return 0.0;
        return 1.0 - (levenshtein($a, $b) / $max);
    }

    public static function normalize(string $t): string
    {
        $t = strtolower(trim($t));
        $t = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $t);
        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /**
     * Keep one, retire the other. Everything of value transplants.
     * @return array{ok: bool, registrations_moved: int, linkage_moved: bool}
     */
    public function merge(int $keepId, int $loseId): array
    {
        global $wpdb;
        $keep = get_post($keepId);
        $lose = get_post($loseId);
        if (!$keep || !$lose || $keepId === $loseId
            || $keep->post_type !== 'bg_event' || $lose->post_type !== 'bg_event'
        ) {
            return ['ok' => false, 'registrations_moved' => 0, 'linkage_moved' => false];
        }
        $regTable = $wpdb->prefix . 'bg_registrations';
        $registrationIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$regTable} WHERE event_post_id = %d",
            $loseId
        )) ?: []);

        $loseKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) get_post_meta($loseId, '_bg_src_key', false)
        ))));
        $keepKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) get_post_meta($keepId, '_bg_src_key', false)
        ))));
        $addedKeys = array_values(array_diff($loseKeys, $keepKeys));
        $keepSrc = (string) get_post_meta($keepId, '_bg_src', true);
        $keepSrcId = (string) get_post_meta($keepId, '_bg_src_id', true);
        $loseSrc = (string) get_post_meta($loseId, '_bg_src', true);
        $loseSrcId = (string) get_post_meta($loseId, '_bg_src_id', true);
        $keepImage = (string) get_post_meta($keepId, '_bg_image', true);
        $loseImage = (string) get_post_meta($loseId, '_bg_image', true);

        update_post_meta($loseId, '_bg_dedup_merge_snapshot', wp_json_encode([
            'keep' => $keepId,
            'registration_ids' => $registrationIds,
            'source_keys' => $loseKeys,
            'added_keys' => $addedKeys,
            'keep_src' => $keepSrc,
            'keep_src_id' => $keepSrcId,
            'copied_src' => $keepSrc === '' ? $loseSrc : '',
            'copied_src_id' => $keepSrc === '' ? $loseSrcId : '',
            'copied_image' => $keepImage === '' ? $loseImage : '',
        ]));

        $moved = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$regTable} SET event_post_id = %d WHERE event_post_id = %d",
            $keepId, $loseId
        ));

        $linkMoved = false;
        foreach ($addedKeys as $loseKey) {
            if ($loseKey !== '') {
                // Additional value, not a replacement: the mapper's lookup
                // matches any row, so future transfers of the retired
                // copy's source update the survivor instead of
                // re-creating the duplicate.
                add_post_meta($keepId, '_bg_src_key', $loseKey);
                $linkMoved = true;
            }
        }
        if ($keepSrc === '') {
            update_post_meta($keepId, '_bg_src', $loseSrc);
            update_post_meta($keepId, '_bg_src_id', $loseSrcId);
        }

        if ($keepImage === '' && $loseImage !== '') {
            update_post_meta($keepId, '_bg_image', $loseImage);
        }

        update_post_meta($loseId, '_bg_merged_into', $keepId);
        delete_post_meta($loseId, '_bg_src_key');
        if (!wp_trash_post($loseId)) {
            $this->restore($loseId);
            return ['ok' => false, 'registrations_moved' => 0, 'linkage_moved' => false];
        }

        $this->logger->info('exchange', 'Duplicate merged; the retired copy is in the trash, reversibly.', [
            'kept' => $keepId, 'retired' => $loseId,
            'registrations_moved' => $moved, 'linkage_moved' => $linkMoved,
        ]);
        return ['ok' => true, 'registrations_moved' => $moved, 'linkage_moved' => $linkMoved];
    }

    /**
     * Reverse a merge when the retired post is restored from Trash.
     * Only values still matching the merge snapshot are moved back, so
     * later staff edits on the survivor are never overwritten.
     */
    public function restore(int $loseId): bool
    {
        global $wpdb;
        $raw = (string) get_post_meta($loseId, '_bg_dedup_merge_snapshot', true);
        $snapshot = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($snapshot)) return false;
        $keepId = (int) ($snapshot['keep'] ?? 0);
        if (!$keepId) return false;

        foreach (array_map('intval', (array) ($snapshot['registration_ids'] ?? [])) as $registrationId) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bg_registrations SET event_post_id = %d
                 WHERE id = %d AND event_post_id = %d",
                $loseId,
                $registrationId,
                $keepId
            ));
        }
        foreach ((array) ($snapshot['added_keys'] ?? []) as $key) {
            delete_post_meta($keepId, '_bg_src_key', (string) $key);
        }
        delete_post_meta($loseId, '_bg_src_key');
        foreach ((array) ($snapshot['source_keys'] ?? []) as $key) {
            add_post_meta($loseId, '_bg_src_key', (string) $key);
        }
        $copiedSrc = (string) ($snapshot['copied_src'] ?? '');
        $copiedSrcId = (string) ($snapshot['copied_src_id'] ?? '');
        if ($copiedSrc !== ''
            && (string) get_post_meta($keepId, '_bg_src', true) === $copiedSrc
            && (string) get_post_meta($keepId, '_bg_src_id', true) === $copiedSrcId
        ) {
            update_post_meta($keepId, '_bg_src', (string) ($snapshot['keep_src'] ?? ''));
            update_post_meta($keepId, '_bg_src_id', (string) ($snapshot['keep_src_id'] ?? ''));
        }
        $copiedImage = (string) ($snapshot['copied_image'] ?? '');
        if ($copiedImage !== '' && (string) get_post_meta($keepId, '_bg_image', true) === $copiedImage) {
            delete_post_meta($keepId, '_bg_image');
        }
        delete_post_meta($loseId, '_bg_merged_into');
        delete_post_meta($loseId, '_bg_dedup_merge_snapshot');
        $this->logger->info('exchange', 'Duplicate merge reversed after the retired event was restored.', [
            'restored' => $loseId,
            'survivor' => $keepId,
        ]);
        return true;
    }

    public function dismiss(int $idA, int $idB): void
    {
        $d = $this->dismissed();
        $d[$this->pairKey($idA, $idB)] = current_time('mysql', true);
        update_option(self::DISMISS_OPT, $d, false);
    }

    /** @return array<string, string> */
    private function dismissed(): array
    {
        $d = get_option(self::DISMISS_OPT, []);
        return is_array($d) ? $d : [];
    }

    private function pairKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /** @return array<string, mixed> */
    private function describe(int $id, string $title, string $status): array
    {
        global $wpdb;
        $regTable = $wpdb->prefix . 'bg_registrations';
        return [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'location' => trim((string) get_post_meta($id, '_bg_location_text', true) . ' ' .
                (string) get_post_meta($id, '_bg_summary', true)),
            'source' => (string) get_post_meta($id, '_bg_src_key', true) ?: 'entered directly',
            'registrations' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$regTable} WHERE event_post_id = %d", $id
            )),
        ];
    }
}
