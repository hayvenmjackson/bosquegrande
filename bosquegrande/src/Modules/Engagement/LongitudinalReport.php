<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\SurveyFields;

if (!defined('ABSPATH')) exit;

/** Builds privacy-thresholded time series with no row-level identity. */
final class LongitudinalReport
{
    public function __construct(
        private ConstituentStore $store,
        private HistoricalParticipation $history,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<object> $registrations
     * @param array<int,int> $people registration id => constituent id
     * @param array<int,string> $regionMap constituent id => region
     * @return array<string,mixed>
     */
    public function build(array $events, array $registrations, array $people, array $regionMap, array $config): array
    {
        $start = (string) ($config['period_start'] ?? '');
        $end = (string) ($config['period_end'] ?? '');
        // Longitudinal slices are inherently more identifying than a
        // one-period total. Even when an administrator requests exact counts
        // elsewhere in a staff report, trend cells retain a hard minimum of
        // three and never persist the exact value of a smaller person cell.
        $requestedThreshold = (int) ($config['privacy_threshold'] ?? 3);
        $threshold = $requestedThreshold === 5 ? 5 : 3;
        $requested = sanitize_key((string) ($config['trend_granularity'] ?? 'auto'));
        $granularity = in_array($requested, ['month', 'quarter', 'year'], true)
            ? $requested : $this->automaticGranularity($start, $end);

        $eventsById = [];
        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            if ($id > 0) $eventsById[$id] = $event;
        }
        uasort($eventsById, static function (array $left, array $right): int {
            $byDate = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
            return $byDate !== 0 ? $byDate : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        });
        $regsByEvent = [];
        foreach ($registrations as $row) $regsByEvent[(int) ($row->event_post_id ?? 0)][] = $row;

        $profiles = [];
        $buckets = [];
        $seenPeople = [];
        foreach ($eventsById as $eventId => $event) {
            $date = (string) ($event['date'] ?? '');
            $bucketKey = $this->bucketKey($date, $granularity);
            if ($bucketKey === '') continue;
            if (!isset($buckets[$bucketKey])) $buckets[$bucketKey] = $this->emptyBucket($bucketKey, $granularity);
            $bucket =& $buckets[$bucketKey];
            $bucket['events']++;
            $regionSlug = (string) ($event['region_slug'] ?? '');
            if ($regionSlug === '') $regionSlug = 'unassigned';
            $bucket['event_regions'][$regionSlug]['events'] = (int) ($bucket['event_regions'][$regionSlug]['events'] ?? 0) + 1;
            $terms = wp_get_post_terms($eventId, 'bg_tag', ['fields' => 'names']);
            if (is_wp_error($terms) || !is_array($terms) || !$terms) $terms = ['Uncategorized'];
            foreach ($terms as $term) {
                $term = (string) $term;
                $bucket['programs'][$term]['events'] = (int) ($bucket['programs'][$term]['events'] ?? 0) + 1;
            }

            foreach ($regsByEvent[$eventId] ?? [] as $row) {
                $bucket['registrations']++;
                $personId = (int) ($people[(int) ($row->id ?? 0)] ?? 0);
                if ($personId > 0) {
                    $bucket['_participants'][$personId] = true;
                    // A person belongs to exactly one newcomer category in a
                    // time bucket. Classify their first selected occurrence in
                    // chronological order from full event history; later
                    // registrations in the same bucket cannot put them in both.
                    if (!isset($bucket['_new_people'][$personId])
                        && !isset($bucket['_returning_people'][$personId])
                        && !isset($bucket['_unknown_newcomer_people'][$personId])
                    ) {
                        if (isset($seenPeople[$personId])) {
                            $bucket['_returning_people'][$personId] = true;
                        } else {
                            $newcomerStatus = $this->history->newcomerStatus($personId, $date, $eventId);
                            if ($newcomerStatus === HistoricalParticipation::NEWCOMER) {
                                $bucket['_new_people'][$personId] = true;
                            } elseif ($newcomerStatus === HistoricalParticipation::RETURNING) {
                                $bucket['_returning_people'][$personId] = true;
                            } else {
                                $bucket['_unknown_newcomer_people'][$personId] = true;
                            }
                        }
                        $seenPeople[$personId] = true;
                    }
                    $homeRegion = (string) ($regionMap[$personId] ?? '');
                    if ($homeRegion === '') $homeRegion = 'unassigned';
                    $bucket['_participant_regions'][$homeRegion][$personId] = true;
                    if (!isset($profiles[$personId])) $profiles[$personId] = $this->store->personalAnswersFor($personId);
                } else {
                    $bucket['unlinked_registrations']++;
                }
                foreach ($terms as $term) {
                    $term = (string) $term;
                    $bucket['programs'][$term]['registrations'] = (int) ($bucket['programs'][$term]['registrations'] ?? 0) + 1;
                }
                $bucket['event_regions'][$regionSlug]['registrations'] = (int) ($bucket['event_regions'][$regionSlug]['registrations'] ?? 0) + 1;
            }
        }

        ksort($buckets, SORT_STRING);
        $previous = null;
        foreach ($buckets as &$bucket) {
            $participantIds = array_map('intval', array_keys($bucket['_participants']));
            $age = array_fill_keys(SurveyFields::ageRanges(), 0);
            $attributes = array_fill_keys(DemographicFields::options(), 0);
            foreach ($participantIds as $personId) {
                $answers = $profiles[$personId] ?? [];
                $ageValue = trim((string) ($answers['age_range'] ?? ''));
                if ($ageValue !== '') {
                    if (!isset($age[$ageValue])) $age[$ageValue] = 0;
                    $age[$ageValue]++;
                }
                foreach ((array) ($answers['demographics'] ?? []) as $value) {
                    $value = trim((string) $value);
                    if ($value === '') continue;
                    if (!isset($attributes[$value])) $attributes[$value] = 0;
                    $attributes[$value]++;
                }
            }

            $bucket['registrations'] = $this->safeCell((int) $bucket['registrations'], $threshold);
            $bucket['unlinked_registrations'] = $this->safeCell((int) $bucket['unlinked_registrations'], $threshold);
            $bucket['unique_participants'] = $this->safeCell(count($participantIds), $threshold);
            $bucket['new_participants'] = $this->safeCell(count($bucket['_new_people']), $threshold);
            $bucket['returning_participants'] = $this->safeCell(count($bucket['_returning_people']), $threshold);
            $bucket['unknown_newcomer_participants'] = $this->safeCell(
                count($bucket['_unknown_newcomer_people']),
                $threshold
            );
            $bucket['age_ranges'] = $this->safeCounts($age, $threshold);
            $bucket['demographics'] = $this->safeCounts($attributes, $threshold);
            foreach ($bucket['programs'] as &$program) {
                $program['registrations'] = $this->safeCell((int) ($program['registrations'] ?? 0), $threshold);
            }
            unset($program);
            foreach ($bucket['event_regions'] as &$eventRegion) {
                $eventRegion['registrations'] = $this->safeCell((int) ($eventRegion['registrations'] ?? 0), $threshold);
            }
            unset($eventRegion);
            $bucket['participant_regions'] = [];
            foreach ($bucket['_participant_regions'] as $slug => $ids) {
                $bucket['participant_regions'][$slug] = [
                    'label' => $slug === 'unassigned' ? 'Unassigned / needs review' : RegionalGroups::label($slug),
                    'participants' => $this->safeCell(count($ids), $threshold),
                ];
            }
            ksort($bucket['programs'], SORT_NATURAL | SORT_FLAG_CASE);
            ksort($bucket['event_regions'], SORT_NATURAL | SORT_FLAG_CASE);
            $bucket['changes'] = $this->changes($previous, $bucket);
            unset(
                $bucket['_participants'],
                $bucket['_new_people'],
                $bucket['_returning_people'],
                $bucket['_unknown_newcomer_people'],
                $bucket['_participant_regions']
            );
            $previous = $bucket;
        }
        unset($bucket);

        return [
            'granularity' => $granularity,
            'privacy_threshold' => $threshold,
            'privacy_note' => 'Longitudinal attendance and person-level cells below ' . $threshold . ' are shown as <' . $threshold . ' and their exact values are not stored in this snapshot. Trend reports always retain a minimum threshold of 3, even when the surrounding staff report uses exact counts.',
            'profile_note' => 'Age and demographic trends use each identified participant’s latest encrypted profile answers; BosqueGrande does not reconstruct an historical age category when only a current category is known. A title-only Past Events value with no definite date is reported as unknown, never guessed as new or returning.',
            'buckets' => array_values($buckets),
        ];
    }

    private function automaticGranularity(string $start, string $end): string
    {
        $a = strtotime($start . ' 00:00:00 UTC');
        $b = strtotime($end . ' 23:59:59 UTC');
        $months = ($a && $b && $b >= $a) ? (($b - $a) / (30.4375 * DAY_IN_SECONDS)) : 0;
        if ($months <= 24) return 'month';
        if ($months <= 60) return 'quarter';
        return 'year';
    }

    private function bucketKey(string $date, string $granularity): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return '';
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($granularity === 'year') return (string) $year;
        if ($granularity === 'quarter') return $year . '-Q' . (int) ceil($month / 3);
        return sprintf('%04d-%02d', $year, $month);
    }

    /** @return array<string,mixed> */
    private function emptyBucket(string $key, string $granularity): array
    {
        $label = $key;
        if ($granularity === 'month' && preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
            $label = wp_date('F Y', strtotime($m[1] . '-' . $m[2] . '-01 12:00:00 UTC'));
        }
        return [
            'key' => $key,
            'label' => $label,
            'events' => 0,
            'registrations' => 0,
            'unlinked_registrations' => 0,
            '_participants' => [],
            '_new_people' => [],
            '_returning_people' => [],
            '_unknown_newcomer_people' => [],
            '_participant_regions' => [],
            'programs' => [],
            'event_regions' => [],
        ];
    }

    /** @return array{display:string,value:int|null,suppressed:bool} */
    private function safeCell(int $count, int $threshold): array
    {
        if ($count === 0 || $threshold === 0 || $count >= $threshold) {
            return ['display' => (string) $count, 'value' => $count, 'suppressed' => false];
        }
        return ['display' => '<' . $threshold, 'value' => null, 'suppressed' => true];
    }

    /** @param array<string,int> $counts @return array<string,array{display:string,value:int|null,suppressed:bool}> */
    private function safeCounts(array $counts, int $threshold): array
    {
        $out = [];
        foreach ($counts as $label => $count) $out[(string) $label] = $this->safeCell((int) $count, $threshold);
        return $out;
    }

    /** @return array<string,int|null> */
    private function changes(?array $previous, array $current): array
    {
        if (!$previous) return ['events' => null, 'registrations' => null, 'unique_participants' => null];
        $priorUnique = $previous['unique_participants']['value'] ?? null;
        $currentUnique = $current['unique_participants']['value'] ?? null;
        $priorRegistrations = $previous['registrations']['value'] ?? null;
        $currentRegistrations = $current['registrations']['value'] ?? null;
        return [
            'events' => (int) $current['events'] - (int) $previous['events'],
            'registrations' => is_int($priorRegistrations) && is_int($currentRegistrations)
                ? $currentRegistrations - $priorRegistrations : null,
            'unique_participants' => is_int($priorUnique) && is_int($currentUnique) ? $currentUnique - $priorUnique : null,
        ];
    }
}
