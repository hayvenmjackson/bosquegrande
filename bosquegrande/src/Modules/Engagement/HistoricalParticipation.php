<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\ParticipationHistory;

if (!defined('ABSPATH')) exit;

/**
 * Reconciles historical aggregate attendance (the attendance count recorded
 * on imported historical events) with encrypted constituent Past Events
 * values. It never invents a person match; anonymous rows fill only the
 * remainder between known attendees and the recorded count.
 */
final class HistoricalParticipation
{
    public const NEWCOMER_UNKNOWN = -1;
    public const RETURNING = 0;
    public const NEWCOMER = 1;

    /** @var array<int,array<string,bool>> */
    private array $keysByConstituent = [];
    private bool $historyLoaded = false;
    private bool $historySourceAvailable = false;

    public function __construct(
        private ConstituentStore $store,
        private EventHistory $catalog,
    ) {
    }

    /**
     * Classify one dated attendance without inventing chronology for a
     * title-only Past Events value.
     *
     * A repeated event title proves prior attendance only when every
     * possible dated occurrence is earlier than the attendance being tested.
     * Unknown titles, invalid dates, or occurrences on both sides of the
     * boundary remain unknown. Any separate, definite prior event still makes
     * the person returning.
     *
     * @return self::NEWCOMER_UNKNOWN|self::RETURNING|self::NEWCOMER
     */
    public function newcomerStatus(int $constituentId, string $eventDate, int $excludeEventId = 0): int
    {
        if ($constituentId < 1 || !self::validDate($eventDate)) return self::NEWCOMER_UNKNOWN;
        global $wpdb;
        $record = $this->store->get($constituentId);
        if (!is_array($record)) return self::NEWCOMER_UNKNOWN;
        $lglId = (int) ($record['lgl_constituent_id'] ?? 0);
        $wpdb->last_error = '';
        $nativeValue = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}bg_registrations r
             JOIN {$wpdb->postmeta} d ON d.post_id = r.event_post_id AND d.meta_key = '_bg_start_date'
             WHERE (r.bg_constituent_id = %d OR (%d > 0 AND r.lgl_constituent_id = %d))
               AND r.status IN ('confirmed','paid')
               AND r.event_post_id <> %d
               AND (
                    d.meta_value < %s
                    OR (d.meta_value = %s AND %d > 0 AND r.event_post_id < %d)
               )",
            $constituentId,
            $lglId,
            $lglId,
            $excludeEventId,
            $eventDate,
            $eventDate,
            $excludeEventId,
            $excludeEventId
        ));
        if ((string) $wpdb->last_error !== '') return self::NEWCOMER_UNKNOWN;
        $native = (int) $nativeValue;
        if ($native > 0) return self::RETURNING;

        $keys = $this->historyKeysFor($constituentId);
        if (!$this->historySourceAvailable) return self::NEWCOMER_UNKNOWN;
        if (!$keys) return self::NEWCOMER;
        $catalogByKey = $this->catalog->byTitleKey();
        $ambiguous = false;
        foreach (array_keys($keys) as $key) {
            $known = $catalogByKey[$key] ?? [];
            if (!$known) {
                $ambiguous = true;
                continue;
            }

            $before = 0;
            $sameDay = 0;
            $notBefore = 0;
            $undated = false;
            foreach ($known as $event) {
                $date = (string) ($event['start_date'] ?? '');
                if (!self::validDate($date)) {
                    $undated = true;
                    continue;
                }
                if ($date < $eventDate) $before++;
                else {
                    $notBefore++;
                    if ($date === $eventDate) $sameDay++;
                }
            }

            if (!$undated && $before > 0 && $notBefore === 0) return self::RETURNING;
            if ($undated
                || ($before > 0 && $notBefore > 0)
                || (count($known) > 1 && $sameDay > 0)
            ) $ambiguous = true;
        }
        return $ambiguous ? self::NEWCOMER_UNKNOWN : self::NEWCOMER;
    }

    public function hasPrior(int $constituentId, string $beforeDate, int $excludeEventId = 0): bool
    {
        return $this->newcomerStatus($constituentId, $beforeDate, $excludeEventId) === self::RETURNING;
    }

    public function isNewcomer(int $constituentId, string $eventDate, int $eventId = 0): bool
    {
        return $this->newcomerStatus($constituentId, $eventDate, $eventId) === self::NEWCOMER;
    }

    /**
     * Add privacy-safe historical attendance rows up to each event's recorded count.
     * Native registrations remain authoritative and are never duplicated.
     *
     * @param list<array<string,mixed>> $events
     * @param list<object> $native
     * @return array{registrations:list<object>,people:array<int,int>,quality:array<string,int>}
     */
    public function augment(array $events, array $native, array $nativePeople): array
    {
        $this->loadHistory();
        $nativeByEvent = [];
        $nativePersonEvent = [];
        foreach ($native as $row) {
            $eventId = (int) ($row->event_post_id ?? 0);
            $nativeByEvent[$eventId][] = $row;
            $person = (int) ($nativePeople[(int) ($row->id ?? 0)] ?? 0);
            if ($person > 0) $nativePersonEvent[$eventId][$person] = true;
        }

        $rows = $native;
        $people = $nativePeople;
        $nextId = -1;
        $quality = [
            'catalog_events' => 0,
            'catalog_attendance' => 0,
            'native_attendance' => count($native),
            'linked_historical_attendance' => 0,
            'anonymous_historical_attendance' => 0,
            'ambiguous_title_events' => 0,
            'native_above_catalog' => 0,
        ];
        $catalogByTitle = $this->catalog->byTitleKey();

        foreach ($events as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            $target = max(0, (int) get_post_meta($eventId, '_bg_historical_attendance_count', true));
            if ($target < 1) continue;
            $quality['catalog_events']++;
            $quality['catalog_attendance'] += $target;
            $nativeCount = count($nativeByEvent[$eventId] ?? []);
            if ($nativeCount >= $target) {
                if ($nativeCount > $target) $quality['native_above_catalog'] += $nativeCount - $target;
                continue;
            }
            $needed = $target - $nativeCount;
            $titleKey = ParticipationHistory::eventKey((string) ($event['title'] ?? ''));
            $linked = [];
            // Repeated event-series titles cannot safely identify which dated
            // occurrence a Past Events value means. In that case retain the
            // attendance only as an anonymous aggregate instead of assigning
            // one constituent to every occurrence of the series.
            $titleIsUnique = $titleKey !== '' && count($catalogByTitle[$titleKey] ?? []) === 1;
            if ($titleKey !== '' && !$titleIsUnique) $quality['ambiguous_title_events']++;
            if ($titleIsUnique) {
                foreach ($this->keysByConstituent as $constituentId => $keys) {
                    if (!isset($keys[$titleKey]) || isset($nativePersonEvent[$eventId][$constituentId])) continue;
                    $linked[] = $constituentId;
                }
            }
            sort($linked);
            foreach (array_slice($linked, 0, $needed) as $constituentId) {
                $row = $this->syntheticRow($nextId, $eventId, $constituentId, false);
                $rows[] = $row;
                $people[$nextId] = $constituentId;
                $nextId--;
                $needed--;
                $quality['linked_historical_attendance']++;
            }
            while ($needed-- > 0) {
                $rows[] = $this->syntheticRow($nextId, $eventId, 0, true);
                $nextId--;
                $quality['anonymous_historical_attendance']++;
            }
        }
        return ['registrations' => $rows, 'people' => $people, 'quality' => $quality];
    }

    /** @return array<string,bool> */
    private function historyKeysFor(int $constituentId): array
    {
        $this->loadHistory();
        return $this->keysByConstituent[$constituentId] ?? [];
    }

    private static function validDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function loadHistory(): void
    {
        if ($this->historyLoaded) return;
        $this->historyLoaded = true;
        $fieldIds = ParticipationHistory::fieldIds($this->store);
        if (!$fieldIds) return;
        $this->historySourceAvailable = true;
        foreach ($this->store->allDecrypted() as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1) continue;
            $values = $this->store->valuesFor($id);
            foreach ($fieldIds as $fieldId) {
                $value = (string) ($values[$fieldId] ?? '');
                foreach (ParticipationHistory::events($value) as $eventTitle) {
                    $key = ParticipationHistory::eventKey($eventTitle);
                    if ($key !== '') $this->keysByConstituent[$id][$key] = true;
                }
            }
        }
    }

    private function syntheticRow(int $id, int $eventId, int $constituentId, bool $anonymous): object
    {
        return (object) [
            'id' => $id,
            'event_post_id' => $eventId,
            'bg_constituent_id' => $constituentId,
            'lgl_constituent_id' => 0,
            'status' => 'confirmed',
            'tier' => 'historical',
            'amount_cents' => 0,
            // A title-only historical value does not prove whether this was
            // the person's first event. Keep that dimension unknown rather
            // than distorting longitudinal newcomer/returning comparisons.
            'is_new_constituent' => -1,
            'volunteer_interest' => 0,
            'historical_synthetic' => 1,
            'historical_anonymous' => $anonymous ? 1 : 0,
            'created_at' => (string) get_post_meta($eventId, '_bg_start_date', true) . ' 00:00:00',
        ];
    }
}
