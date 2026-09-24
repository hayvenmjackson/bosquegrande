<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GrantReporting;

use BosqueGrande\Core\Container;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\DonorStatus;
use BosqueGrande\Modules\Constituents\CoreFields;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\Imports\ImportStore;
use BosqueGrande\Modules\Notifications\NotificationsModule;
use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\SurveyFields;
use BosqueGrande\Modules\Engagement\HistoricalParticipation;

if (!defined('ABSPATH')) exit;

/**
 * Deterministic grant-report aggregates over BosqueGrande's local source of
 * truth. Individual records are decrypted only long enough to count their
 * answers; the returned snapshot contains aggregate cells and no identity,
 * contact, address, note, or free-text demographic detail.
 */
final class ReportBuilder
{
    public function __construct(private Container $container)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $year = wp_date('Y');
        return [
            'title' => 'Grant activity report',
            'funder_name' => '',
            'grant_reference' => '',
            'period_start' => $year . '-01-01',
            'period_end' => wp_date('Y-m-d'),
            // region_slug remains as a backward-compatible alias for saved
            // reports created before participant/event geography was split.
            'region_slug' => '',
            'participant_region_slug' => '',
            'event_region_slug' => '',
            'event_mode' => 'all',
            'event_ids' => [],
            'program_tags' => [],
            'grant_tags' => [],
            'privacy_threshold' => 3,
            'trend_granularity' => 'auto',
            'grant_award_cents' => 0,
            'grant_received_cents' => 0,
            'grant_expenses_cents' => 0,
            'other_income_cents' => 0,
            'in_kind_cents' => 0,
            'volunteer_hours' => 0.0,
            'verified_attendance' => 0,
            'target_people' => 0,
            'target_events' => 0,
            'objective' => '',
            'activities' => '',
            'outcomes' => '',
            'challenges' => '',
            'next_steps' => '',
            'notes' => '',
        ];
    }

    /** Sanitize the report form without trusting hidden fields. @return array<string, mixed> */
    public static function fromRequest(array $input): array
    {
        $input = wp_unslash($input);
        $config = self::defaults();
        foreach (['title', 'funder_name', 'grant_reference'] as $key) {
            $config[$key] = bg_substr(sanitize_text_field((string) ($input[$key] ?? '')), 0, 191);
        }
        foreach (['objective', 'activities', 'outcomes', 'challenges', 'next_steps', 'notes'] as $key) {
            $config[$key] = bg_substr(sanitize_textarea_field((string) ($input[$key] ?? '')), 0, 12000);
        }

        $start = self::date((string) ($input['period_start'] ?? '')) ?: $config['period_start'];
        $end = self::date((string) ($input['period_end'] ?? '')) ?: $config['period_end'];
        if ($start > $end) [$start, $end] = [$end, $start];
        $config['period_start'] = $start;
        $config['period_end'] = $end;

        $legacyRegion = sanitize_key((string) ($input['region_slug'] ?? ''));
        $participantRegion = sanitize_key((string) ($input['participant_region_slug'] ?? $legacyRegion));
        $eventRegion = sanitize_key((string) ($input['event_region_slug'] ?? ''));
        $config['participant_region_slug'] = isset(RegionalGroups::regions()[$participantRegion]) ? $participantRegion : '';
        $config['event_region_slug'] = isset(RegionalGroups::regions()[$eventRegion]) ? $eventRegion : '';
        $config['region_slug'] = $config['participant_region_slug'];
        $config['event_mode'] = (string) ($input['event_mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
        $config['event_ids'] = self::ids((array) ($input['event_ids'] ?? []));
        $config['program_tags'] = array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            (array) ($input['program_tags'] ?? [])
        ))));
        $config['grant_tags'] = array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            (array) ($input['grant_tags'] ?? [])
        ))));
        $threshold = (int) ($input['privacy_threshold'] ?? 3);
        $config['privacy_threshold'] = in_array($threshold, [0, 3, 5], true) ? $threshold : 3;
        $granularity = sanitize_key((string) ($input['trend_granularity'] ?? 'auto'));
        $config['trend_granularity'] = in_array($granularity, ['auto', 'month', 'quarter', 'year'], true) ? $granularity : 'auto';

        foreach (['grant_award', 'grant_received', 'grant_expenses', 'other_income', 'in_kind'] as $field) {
            $config[$field . '_cents'] = self::moneyToCents($input[$field] ?? 0);
        }
        $config['volunteer_hours'] = max(0.0, round((float) ($input['volunteer_hours'] ?? 0), 2));
        foreach (['verified_attendance', 'target_people', 'target_events'] as $key) {
            $config[$key] = max(0, (int) ($input[$key] ?? 0));
        }
        if ($config['title'] === '') $config['title'] = 'Grant activity report';
        return $config;
    }

    /** @return list<array{id:int,title:string,date:string,status:string,region_slug:string,region_label:string}> */
    public function eventOptions(): array
    {
        $posts = get_posts([
            'post_type' => 'bg_event',
            // Auto-delisted Eventbrite records are drafts by design, but may
            // be deliberately selected for historical grant reporting.
            'post_status' => ['publish', 'bg_historical', 'draft'],
            'posts_per_page' => -1,
            'meta_key' => '_bg_start_date',
            'orderby' => 'meta_value',
            'order' => 'DESC',
        ]);
        $out = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || !$this->reportableEventPost($post, true)) continue;
            $title = (string) $post->post_title;
            $region = RegionalGroups::eventRegion(
                $title,
                (string) get_post_meta($post->ID, '_bg_location_text', true)
            );
            $out[] = [
                'id' => (int) $post->ID,
                'title' => $title,
                'date' => (string) get_post_meta($post->ID, '_bg_start_date', true),
                'status' => (string) $post->post_status,
                'region_slug' => $region,
                'region_label' => $region !== '' ? RegionalGroups::label($region) : 'Unassigned / needs review',
            ];
        }
        return $out;
    }

    /** @return array<string, string> slug => name */
    public function programOptions(): array
    {
        $terms = get_terms(['taxonomy' => 'bg_tag', 'hide_empty' => false]);
        if (is_wp_error($terms) || !is_array($terms)) return [];
        $out = [];
        foreach ($terms as $term) $out[(string) $term->slug] = (string) $term->name;
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return array<string, string> slug => name */
    public function grantOptions(): array
    {
        $terms = get_terms(['taxonomy' => 'bg_grant_tag', 'hide_empty' => false]);
        if (is_wp_error($terms) || !is_array($terms)) return [];
        $out = [];
        foreach ($terms as $term) $out[(string) $term->slug] = (string) $term->name;
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function build(array $config): array
    {
        global $wpdb;
        // Saved reports from older releases may lack newer fields.
        $config = array_merge(self::defaults(), $config);
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        /** @var RegionalGroups $regional */
        $regional = $this->container->get('constituents.regions');

        $events = $this->events($config);
        $eventIds = array_map(static fn(array $event): int => $event['id'], $events);
        $registrations = $this->registrations($eventIds);
        $registrationPeople = $this->registrationPeople($registrations);
        $historicalQuality = [
            'catalog_events' => 0,
            'catalog_attendance' => 0,
            'native_attendance' => count($registrations),
            'linked_historical_attendance' => 0,
            'anonymous_historical_attendance' => 0,
            'ambiguous_title_events' => 0,
            'native_above_catalog' => 0,
        ];
        if ($this->container->has('engagement.history')) {
            $augmented = $this->container->get('engagement.history')->augment($events, $registrations, $registrationPeople);
            $registrations = (array) ($augmented['registrations'] ?? $registrations);
            $registrationPeople = (array) ($augmented['people'] ?? $registrationPeople);
            $historicalQuality = array_merge($historicalQuality, (array) ($augmented['quality'] ?? []));
        }
        /** @var HistoricalParticipation|null $history */
        $history = $this->container->has('engagement.history')
            ? $this->container->get('engagement.history')
            : null;
        $eventDates = [];
        foreach ($events as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId > 0) $eventDates[$eventId] = (string) ($event['date'] ?? '');
        }
        $newcomerStatuses = self::newcomerStatuses(
            $history,
            $registrations,
            $registrationPeople,
            $eventDates
        );
        $allParticipantIds = array_values(array_unique(array_filter(array_values($registrationPeople))));
        $regionMap = $regional->mapFor($allParticipantIds);
        $participantRegionFilter = self::participantRegionFilter($config);
        $eventRegionFilter = self::eventRegionFilter($config);
        $excludedByRegion = 0;
        if ($participantRegionFilter !== '') {
            $registrations = array_values(array_filter($registrations, function (object $row) use (
                $registrationPeople, $regionMap, $participantRegionFilter, &$excludedByRegion
            ): bool {
                $personId = (int) ($registrationPeople[(int) $row->id] ?? 0);
                $keep = $personId > 0 && (string) ($regionMap[$personId] ?? '') === $participantRegionFilter;
                if (!$keep) $excludedByRegion++;
                return $keep;
            }));
        }

        $participantIds = [];
        $unlinked = 0;
        $newRegistrations = 0;
        $returningRegistrations = 0;
        $unknownNewcomerRegistrations = 0;
        $volunteerPeople = [];
        $tierCounts = [];
        $registrationIds = [];
        foreach ($registrations as $row) {
            $id = (int) ($registrationPeople[(int) $row->id] ?? 0);
            if ($id > 0) $participantIds[$id] = true;
            else $unlinked++;
            $newStatus = (int) ($newcomerStatuses[(int) ($row->id ?? 0)] ?? -1);
            if ($newStatus === 1) $newRegistrations++;
            elseif ($newStatus === 0) $returningRegistrations++;
            else $unknownNewcomerRegistrations++;
            if ((int) ($row->volunteer_interest ?? 0) === 1) {
                $volunteerPeople[$id > 0 ? 'c:' . $id : 'r:' . (int) $row->id] = true;
            }
            $tier = trim((string) ($row->tier ?? '')) ?: 'Unspecified';
            $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;
            $registrationIds[] = (int) $row->id;
        }
        $participantIds = array_map('intval', array_keys($participantIds));
        sort($participantIds);

        $participantRegionMap = $regional->mapFor($participantIds);
        $regionBreakdown = $this->regionBreakdown($participantIds, $participantRegionMap, $registrations, $registrationPeople);
        $longitudinal = $this->container->has('engagement.longitudinal')
            ? $this->container->get('engagement.longitudinal')->build($events, $registrations, $registrationPeople, $participantRegionMap, $config)
            : [];
        $demographics = $this->demographics($store, $participantIds, $registrations, $registrationPeople);
        $surveys = $this->surveys($registrationIds, count($registrations));
        $eventRows = $this->eventRows($events, $registrations, $registrationPeople, $surveys['by_event']);
        $programs = $this->programBreakdown($events, $eventRows);
        $grants = $this->grantBreakdown($events, $eventRows);
        $eventRegionBreakdown = $this->eventRegionBreakdown($events, $eventRows);
        $financials = $this->financials($config, $eventIds, $registrationPeople, $regional);
        $community = $this->communitySnapshot($config, $store, $regional);
        ksort($tierCounts, SORT_NATURAL | SORT_FLAG_CASE);

        $quality = [];
        if ($grants) {
            $quality[] = 'Grant categories overlap: one event can appear under more than one grant. Do not add grant rows together as though they were mutually exclusive.';
        }
        if (!$events) $quality[] = 'No reportable events matched the selected date and scope.';
        if ($unlinked > 0) $quality[] = $unlinked . ' registration(s) could not be tied to a permanent record ID, so they are not included in unique-person or demographic counts.';
        $unassigned = (int) ($regionBreakdown['unassigned']['participants'] ?? 0);
        if ($unassigned > 0) $quality[] = $unassigned . ' identified participant(s) have no regional assignment.';
        $unassignedEvents = (int) ($eventRegionBreakdown['unassigned']['events'] ?? 0);
        if ($unassignedEvents > 0) $quality[] = $unassignedEvents . ' event(s) could not be assigned from the Event Location or the legacy town/state title fallback.';
        if ($excludedByRegion > 0) $quality[] = $excludedByRegion . ' registration(s) were outside the selected participant home region or lacked a usable regional assignment.';
        if ((int) ($demographics['profile_fallback_conflicts'] ?? 0) > 0) {
            $quality[] = (int) $demographics['profile_fallback_conflicts'] . ' blank current age or demographic field(s) could not use a historical fallback because the selected historical records contradicted one another.';
        }
        if ((int) ($demographics['scope_conflicts'] ?? 0) > 0) {
            $quality[] = (int) $demographics['scope_conflicts'] . ' relationship or outreach field(s) were omitted because records in the selected scope contradicted one another.';
        }
        if ((int) ($demographics['age_missing'] ?? 0) > 0) {
            $quality[] = (int) $demographics['age_missing'] . ' identified participant(s) had no usable age-range answer.';
        }
        if ((int) ($demographics['attributes_missing'] ?? 0) > 0) {
            $quality[] = (int) $demographics['attributes_missing'] . ' identified participant(s) had no usable demographic selection.';
        }
        $ageFallbacks = (int) ($demographics['age_historical_sources'] ?? 0);
        $attributeFallbacks = (int) ($demographics['attributes_historical_sources'] ?? 0);
        if ($ageFallbacks > 0 || $attributeFallbacks > 0) {
            $quality[] = $ageFallbacks . ' age-range response(s) and ' . $attributeFallbacks . ' demographic response(s) used one unambiguous historical snapshot because the current profile field was blank.';
        }
        if ((int) ($demographics['source_conflict_rows'] ?? 0) > 0) {
            $quality[] = (int) $demographics['source_conflict_rows'] . ' historical source row-field value(s) in this scope were omitted during import because the source contradicted itself.';
        }
        if ((int) ($config['verified_attendance'] ?? 0) < 1 && $registrations) {
            $quality[] = 'BosqueGrande records confirmed registrations, not door check-ins. Enter verified attendance when staff have an actual attendance count.';
        }
        if (!GrantTags::backfillComplete()) {
            $quality[] = 'Grant tag rules were still being applied when this report was built. Grant-filtered totals can be incomplete; regenerate the report once the processing notice disappears.';
        }
        if ((int) $financials['unattributed_cents'] !== 0) {
            $quality[] = 'Some ledger activity could not be attributed to the selected regional or program scope; it is disclosed separately.';
        }
        $catalogEvents = (int) ($historicalQuality['catalog_events'] ?? 0);
        $linkedHistorical = (int) ($historicalQuality['linked_historical_attendance'] ?? 0);
        $anonymousHistorical = (int) ($historicalQuality['anonymous_historical_attendance'] ?? 0);
        if ($catalogEvents > 0) {
            $quality[] = $catalogEvents . ' imported historical event(s) contributed their recorded attendance count: '
                . $linkedHistorical . ' attendance record(s) were tied to a person through an encrypted Past Events value and '
                . $anonymousHistorical . ' were kept only as anonymous totals.';
        }
        if ((int) ($historicalQuality['native_above_catalog'] ?? 0) > 0) {
            $quality[] = (int) $historicalQuality['native_above_catalog'] . ' registration(s) exceeded an event\'s recorded historical count; the registrations were used as the more complete source.';
        }
        if ((int) ($historicalQuality['ambiguous_title_events'] ?? 0) > 0) {
            $quality[] = (int) $historicalQuality['ambiguous_title_events'] . ' historical event(s) share a title with other occurrences; their attendance stayed anonymous rather than being assigned to a possibly wrong person.';
        }
        if ($unknownNewcomerRegistrations > 0) {
            $quality[] = $unknownNewcomerRegistrations . ' registration or historical attendance record(s) did not contain enough evidence to classify the attendee as new or returning.';
        }
        $quality[] = 'Gifts processed outside BosqueGrande are not in its ledger unless they were imported or entered here; use the staff-entered financial fields for them.';

        return [
            'schema' => 5,
            'generated_at' => current_time('mysql', true),
            'period' => ['start' => $config['period_start'], 'end' => $config['period_end']],
            'scope' => [
                // Legacy keys continue to mean participant home region.
                'region_slug' => $participantRegionFilter,
                'region_label' => $participantRegionFilter !== '' ? RegionalGroups::label($participantRegionFilter) : 'All participant regions',
                'participant_region_slug' => $participantRegionFilter,
                'participant_region_label' => $participantRegionFilter !== '' ? RegionalGroups::label($participantRegionFilter) : 'All participant regions',
                'event_region_slug' => $eventRegionFilter,
                'event_region_label' => $eventRegionFilter !== '' ? RegionalGroups::label($eventRegionFilter) : 'All event regions',
                'scope_label' => self::scopeLabel($participantRegionFilter, $eventRegionFilter),
                'event_mode' => (string) ($config['event_mode'] ?? 'all'),
                'event_ids' => $eventIds,
                'program_tags' => array_values((array) ($config['program_tags'] ?? [])),
                'grant_tags' => array_values((array) ($config['grant_tags'] ?? [])),
                'grant_tag_names' => $this->selectedTermNames(
                    'bg_grant_tag',
                    array_values((array) ($config['grant_tags'] ?? []))
                ),
            ],
            'summary' => [
                'events' => count($events),
                'confirmed_registrations' => count($registrations),
                'identified_unique_participants' => count($participantIds),
                'unlinked_registrations' => $unlinked,
                'new_participant_registrations' => $newRegistrations,
                'returning_participant_registrations' => $returningRegistrations,
                'unknown_newcomer_status_registrations' => $unknownNewcomerRegistrations,
                'volunteer_interest_people' => count($volunteerPeople),
                'verified_attendance' => (int) $config['verified_attendance'],
                'target_people' => (int) $config['target_people'],
                'target_events' => (int) $config['target_events'],
                'volunteer_hours' => (float) $config['volunteer_hours'],
            ],
            'registration_tiers' => $tierCounts,
            'events' => $eventRows,
            'programs' => $programs,
            'grants' => $grants,
            'event_regions' => $eventRegionBreakdown,
            'regions' => $regionBreakdown,
            'demographics' => $demographics,
            'longitudinal' => $longitudinal,
            'historical_coverage' => $historicalQuality,
            'outcomes' => $surveys,
            'financials' => $financials,
            'manual_financials' => [
                'grant_award_cents' => (int) $config['grant_award_cents'],
                'grant_received_cents' => (int) $config['grant_received_cents'],
                'grant_expenses_cents' => (int) $config['grant_expenses_cents'],
                'other_income_cents' => (int) $config['other_income_cents'],
                'in_kind_cents' => (int) $config['in_kind_cents'],
                'grant_balance_cents' => (int) $config['grant_received_cents'] - (int) $config['grant_expenses_cents'],
            ],
            'community' => $community,
            'quality' => array_values(array_unique($quality)),
            'sources' => [
                'BosqueGrande event posts and confirmed/paid registrations',
                'Encrypted constituent Past Events values used only for conservative historical identity linkage',
                'Current encrypted contact profiles for age and demographic selections (latest saved answers)',
                'Encrypted registration-level historical answers used only when a current age or demographic field is blank',
                'Contact regional assignments and deterministic event-title regionalization',
                'Survey responses, subscriber groups, and payment ledger',
                'Clearly labeled staff-entered report fields',
            ],
        ];
    }

    /** A provider-safe digest: no identity and no sensitive demographic cells. */
    public static function narrativeDigest(array $snapshot, array $config): string
    {
        $safe = [
            'report' => [
                'title' => (string) ($config['title'] ?? ''),
                'funder' => (string) ($config['funder_name'] ?? ''),
                'period' => $snapshot['period'] ?? [],
                'scope' => $snapshot['scope'] ?? [],
            ],
            'results' => $snapshot['summary'] ?? [],
            'programs' => $snapshot['programs'] ?? [],
            'grants' => $snapshot['grants'] ?? [],
            'event_regions' => $snapshot['event_regions'] ?? [],
            'participant_regions' => $snapshot['regions'] ?? [],
            // Retain the pre-3.5.5 key for drafting-provider compatibility.
            'regions' => $snapshot['regions'] ?? [],
            'longitudinal' => $snapshot['longitudinal'] ?? [],
            'survey_outcomes' => [
                'respondents' => (int) ($snapshot['outcomes']['respondents'] ?? 0),
                'response_rate' => (float) ($snapshot['outcomes']['response_rate'] ?? 0),
                'overall_average' => (float) ($snapshot['outcomes']['overall_average'] ?? 0),
            ],
            'financials' => $snapshot['financials'] ?? [],
            'staff_financials' => $snapshot['manual_financials'] ?? [],
            'community' => $snapshot['community'] ?? [],
            'staff_context' => [
                'objective' => (string) ($config['objective'] ?? ''),
                'activities' => (string) ($config['activities'] ?? ''),
                'outcomes' => (string) ($config['outcomes'] ?? ''),
                'challenges' => (string) ($config['challenges'] ?? ''),
                'next_steps' => (string) ($config['next_steps'] ?? ''),
            ],
            'limitations' => $snapshot['quality'] ?? [],
        ];
        return (string) wp_json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{id:int,title:string,date:string,status:string,region_slug:string,region_label:string}> */
    private function events(array $config): array
    {
        $selectedMode = (string) ($config['event_mode'] ?? 'all') === 'selected';
        $args = [
            'post_type' => 'bg_event',
            'post_status' => $selectedMode
                ? ['publish', 'bg_historical', 'draft']
                : ['publish', 'bg_historical'],
            'posts_per_page' => -1,
            'meta_key' => '_bg_start_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => '_bg_start_date',
                'value' => [(string) $config['period_start'], (string) $config['period_end']],
                'compare' => 'BETWEEN',
                'type' => 'DATE',
            ]],
        ];
        if (!$selectedMode) {
            $args['meta_query'][] = [
                'relation' => 'OR',
                ['key' => '_bg_eventbrite_auto_delisted', 'compare' => 'NOT EXISTS'],
                ['key' => '_bg_eventbrite_auto_delisted', 'value' => '1', 'compare' => '!='],
            ];
        }
        if ($selectedMode) {
            $ids = self::ids((array) $config['event_ids']);
            if (!$ids) return [];
            $args['post__in'] = $ids;
        }
        $programTags = array_values((array) ($config['program_tags'] ?? []));
        $grantTags = array_values((array) ($config['grant_tags'] ?? []));
        $taxQuery = [];
        if ($programTags) {
            $taxQuery[] = [
                'taxonomy' => 'bg_tag',
                'field' => 'slug',
                'terms' => $programTags,
                // WordPress's IN operator means any selected program tag.
                'operator' => 'IN',
            ];
        }
        if ($grantTags) {
            $taxQuery[] = [
                'taxonomy' => 'bg_grant_tag',
                'field' => 'slug',
                'terms' => $grantTags,
                // Grants overlap by design: match any selected grant tag.
                'operator' => 'IN',
            ];
        }
        if ($taxQuery) {
            // Different filter families narrow one another, while selections
            // inside each family remain inclusive (OR/IN).
            if (count($taxQuery) > 1) $taxQuery['relation'] = 'AND';
            $args['tax_query'] = $taxQuery;
        }
        $posts = get_posts($args);
        $eventRegionFilter = self::eventRegionFilter($config);
        $out = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || !$this->reportableEventPost($post, $selectedMode)) continue;
            $title = (string) $post->post_title;
            $region = RegionalGroups::eventRegion(
                $title,
                (string) get_post_meta($post->ID, '_bg_location_text', true)
            );
            if ($eventRegionFilter !== '' && $region !== $eventRegionFilter) continue;
            $out[] = [
                'id' => (int) $post->ID,
                'title' => $title,
                'date' => (string) get_post_meta($post->ID, '_bg_start_date', true),
                'status' => (string) $post->post_status,
                'region_slug' => $region,
                'region_label' => $region !== '' ? RegionalGroups::label($region) : 'Unassigned / needs review',
            ];
        }
        return $out;
    }

    /**
     * Published and historical records are normally reportable. A record
     * marked as Eventbrite-auto-delisted is opt-in only, and a draft is never
     * eligible unless it carries that exact marker and selected mode is active.
     */
    private function reportableEventPost(\WP_Post $post, bool $selectedMode): bool
    {
        $status = (string) $post->post_status;
        $autoDelisted = (string) get_post_meta($post->ID, '_bg_eventbrite_auto_delisted', true) === '1';

        if ($status === 'draft') return $selectedMode && $autoDelisted;
        if (!in_array($status, ['publish', 'bg_historical'], true)) return false;
        return !$autoDelisted || $selectedMode;
    }

    /** @param list<int> $eventIds @return list<object> */
    private function registrations(array $eventIds): array
    {
        if (!$eventIds) return [];
        global $wpdb;
        $in = implode(',', array_map('intval', $eventIds));
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}bg_registrations
             WHERE event_post_id IN ({$in}) AND status IN ('confirmed','paid')
             ORDER BY event_post_id ASC, id ASC"
        ) ?: [];
    }

    /** @param list<object> $registrations @return array<int, int> registration id => BG id */
    private function registrationPeople(array $registrations): array
    {
        global $wpdb;
        $out = [];
        $remoteIds = [];
        foreach ($registrations as $row) {
            $local = (int) ($row->bg_constituent_id ?? 0);
            if ($local > 0) $out[(int) $row->id] = $local;
            elseif ((int) ($row->lgl_constituent_id ?? 0) > 0) $remoteIds[] = (int) $row->lgl_constituent_id;
        }
        $remoteIds = array_values(array_unique($remoteIds));
        if (!$remoteIds) return $out;
        $in = implode(',', array_map('intval', $remoteIds));
        $rows = $wpdb->get_results(
            "SELECT id, lgl_constituent_id FROM {$wpdb->prefix}bg_constituents
             WHERE status = 'active' AND lgl_constituent_id IN ({$in}) ORDER BY id ASC"
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $remote = (int) $row->lgl_constituent_id;
            if (!array_key_exists($remote, $map)) $map[$remote] = (int) $row->id;
            else $map[$remote] = 0; // Ambiguous links are never guessed.
        }
        foreach ($registrations as $row) {
            if (isset($out[(int) $row->id])) continue;
            $remote = (int) ($row->lgl_constituent_id ?? 0);
            if (($map[$remote] ?? 0) > 0) $out[(int) $row->id] = (int) $map[$remote];
        }
        return $out;
    }

    /** @return array<string, array{label:string,participants:int,registrations:int}> */
    private function regionBreakdown(array $participantIds, array $regionMap, array $registrations, array $people): array
    {
        $out = [];
        foreach (RegionalGroups::regions() as $slug => $label) {
            $out[$slug] = ['label' => $label, 'participants' => 0, 'registrations' => 0];
        }
        $out['unassigned'] = ['label' => 'Unassigned / needs review', 'participants' => 0, 'registrations' => 0];
        foreach ($participantIds as $id) {
            $slug = (string) ($regionMap[$id] ?? '');
            if (!isset($out[$slug])) $slug = 'unassigned';
            $out[$slug]['participants']++;
        }
        foreach ($registrations as $row) {
            $id = (int) ($people[(int) $row->id] ?? 0);
            $slug = $id > 0 ? (string) ($regionMap[$id] ?? '') : '';
            if (!isset($out[$slug])) $slug = 'unassigned';
            $out[$slug]['registrations']++;
        }
        return array_filter($out, static fn(array $row): bool => $row['participants'] > 0 || $row['registrations'] > 0);
    }

    /** @return array<string, mixed> */
    private function demographics(
        ConstituentStore $store,
        array $participantIds,
        array $registrations,
        array $registrationPeople
    ): array
    {
        // Keep the standardized grant-report order, including zeroes.
        $age = array_fill_keys(SurveyFields::ageRanges(), 0);
        $attributes = array_fill_keys(DemographicFields::options(), 0);
        $relationships = [];
        $heard = [];
        $answeredAge = 0;
        $answeredAttributes = 0;
        $ageProfileSources = 0;
        $ageHistoricalSources = 0;
        $attributeProfileSources = 0;
        $attributeHistoricalSources = 0;
        $profileFallbackConflicts = 0;
        $scopeConflicts = 0;

        /** @var ImportStore $imports */
        $imports = $this->container->get('imports.store');
        $registrationIds = array_map(static fn(object $row): int => (int) $row->id, $registrations);
        $historicalAnswers = $imports->available() ? $imports->registrationAnswersMany($registrationIds) : [];
        $sourceIssues = $imports->available() ? $imports->registrationIssueCountsMany($registrationIds) : [];
        $sourceConflictRows = 0;
        foreach ($sourceIssues as $code => $count) {
            if ((str_starts_with((string) $code, 'conflict_dropped:')
                    || str_starts_with((string) $code, 'cross_row_conflict_dropped:'))
                && preg_match('/(demographics|age_range|heard|promote|relationship)/', (string) $code)
            ) $sourceConflictRows += (int) $count;
        }

        $personRegistrations = [];
        foreach ($registrations as $row) {
            $personId = (int) ($registrationPeople[(int) $row->id] ?? 0);
            if ($personId > 0) $personRegistrations[$personId][] = (int) $row->id;
        }

        foreach ($participantIds as $id) {
            $current = $store->personalAnswersFor((int) $id);
            $historicalValues = [];
            $nativeRegistrationPresent = false;
            foreach ((array) ($personRegistrations[(int) $id] ?? []) as $registrationId) {
                if (!array_key_exists($registrationId, $historicalAnswers)) {
                    $nativeRegistrationPresent = true;
                    continue;
                }
                foreach (['age_range', 'demographics', 'relationship', 'heard'] as $field) {
                    $value = self::normalizeAnswer(
                        $historicalAnswers[$registrationId][$field] ?? ($field === 'age_range' ? '' : [])
                    );
                    if (!self::answerPresent($value)) continue;
                    $historicalValues[$field][self::answerKey($value)] = $value;
                }
            }

            // Age and demographic selections are person-level grant-report facts.
            // The latest encrypted constituent profile is authoritative regardless
            // of whether the selected event was native or imported. A single,
            // unambiguous historical snapshot fills only a blank current field.
            $ageResolution = self::profileFirstAnswer(
                $current['age_range'] ?? '',
                array_values((array) ($historicalValues['age_range'] ?? [])),
                ''
            );
            if ($ageResolution['conflict']) {
                $profileFallbackConflicts++;
            } else {
                $ageValue = trim((string) $ageResolution['value']);
                if ($ageValue !== '') {
                    if (!array_key_exists($ageValue, $age)) $age[$ageValue] = 0;
                    $age[$ageValue]++;
                    $answeredAge++;
                    if ($ageResolution['source'] === 'profile') $ageProfileSources++;
                    elseif ($ageResolution['source'] === 'historical') $ageHistoricalSources++;
                }
            }

            $attributeResolution = self::profileFirstAnswer(
                $current['demographics'] ?? [],
                array_values((array) ($historicalValues['demographics'] ?? [])),
                []
            );
            if ($attributeResolution['conflict']) {
                $profileFallbackConflicts++;
            } else {
                $selected = is_array($attributeResolution['value'])
                    ? array_values($attributeResolution['value'])
                    : [];
                if ($selected) {
                    $answeredAttributes++;
                    if ($attributeResolution['source'] === 'profile') $attributeProfileSources++;
                    elseif ($attributeResolution['source'] === 'historical') $attributeHistoricalSources++;
                }
                foreach ($selected as $value) {
                    $value = trim((string) $value);
                    if ($value === '') continue;
                    // Free-text details are intentionally never copied into the snapshot.
                    if (!array_key_exists($value, $attributes)) $attributes[$value] = 0;
                    $attributes[$value]++;
                }
            }

            // Relationship and outreach answers retain their existing report-scope
            // behavior because outreach is event-specific rather than a current
            // constituent demographic fact.
            foreach (['relationship', 'heard'] as $field) {
                $candidates = (array) ($historicalValues[$field] ?? []);
                if ($nativeRegistrationPresent) {
                    $currentValue = self::normalizeAnswer($current[$field] ?? []);
                    if (self::answerPresent($currentValue)) {
                        $candidates[self::answerKey($currentValue)] = $currentValue;
                    }
                }
                $candidates = array_values($candidates);
                if (count($candidates) === 1) {
                    foreach ((array) $candidates[0] as $value) {
                        $value = trim((string) $value);
                        if ($value === '') continue;
                        if ($field === 'relationship') {
                            $relationships[$value] = ($relationships[$value] ?? 0) + 1;
                        } else {
                            $heard[$value] = ($heard[$value] ?? 0) + 1;
                        }
                    }
                } elseif (count($candidates) > 1) {
                    $scopeConflicts++;
                }
            }
        }

        arsort($relationships, SORT_NUMERIC);
        arsort($heard, SORT_NUMERIC);
        $base = count($participantIds);
        return [
            'base_participants' => $base,
            'age_answered' => $answeredAge,
            'age_missing' => max(0, $base - $answeredAge),
            'age_profile_sources' => $ageProfileSources,
            'age_historical_sources' => $ageHistoricalSources,
            'attributes_answered' => $answeredAttributes,
            'attributes_missing' => max(0, $base - $answeredAttributes),
            'attributes_profile_sources' => $attributeProfileSources,
            'attributes_historical_sources' => $attributeHistoricalSources,
            'age_ranges' => $age,
            'attributes' => $attributes,
            'relationships' => $relationships,
            'heard_about_events' => $heard,
            'conflicts_dropped' => $profileFallbackConflicts + $scopeConflicts,
            'profile_fallback_conflicts' => $profileFallbackConflicts,
            'scope_conflicts' => $scopeConflicts,
            'source_conflict_rows' => $sourceConflictRows,
        ];
    }

    /**
     * Resolve a person-level answer without allowing historical contradictions
     * to replace a current encrypted profile value.
     *
     * @return array{value:mixed,source:string,conflict:bool}
     */
    private static function profileFirstAnswer(mixed $current, array $historical, mixed $empty): array
    {
        $current = self::normalizeAnswer($current);
        if (self::answerPresent($current)) {
            return ['value' => $current, 'source' => 'profile', 'conflict' => false];
        }

        $candidates = [];
        foreach ($historical as $value) {
            $value = self::normalizeAnswer($value);
            if (!self::answerPresent($value)) continue;
            $candidates[self::answerKey($value)] = $value;
        }
        $candidates = array_values($candidates);
        if (count($candidates) === 1) {
            return ['value' => $candidates[0], 'source' => 'historical', 'conflict' => false];
        }
        if (count($candidates) > 1) {
            return ['value' => $empty, 'source' => 'missing', 'conflict' => true];
        }
        return ['value' => $empty, 'source' => 'missing', 'conflict' => false];
    }

    private static function normalizeAnswer(mixed $value): mixed
    {
        if (!is_array($value)) return trim((string) $value);
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) continue;
            $item = trim((string) $item);
            if ($item !== '') $out[$item] = true;
        }
        $out = array_keys($out);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private static function answerPresent(mixed $value): bool
    {
        return is_array($value) ? $value !== [] : trim((string) $value) !== '';
    }

    private static function answerKey(mixed $value): string
    {
        return (string) wp_json_encode($value);
    }

    /** @return array<string, mixed> */
    private function surveys(array $registrationIds, int $registrations): array
    {
        if (!$registrationIds) return [
            'respondents' => 0, 'response_rate' => 0.0, 'overall_average' => 0.0,
            'questions' => [], 'by_event' => [],
        ];
        global $wpdb;
        $in = implode(',', array_map('intval', $registrationIds));
        $rows = $wpdb->get_results(
            "SELECT event_post_id, question_key, COUNT(*) AS responses, AVG(score) AS average
             FROM {$wpdb->prefix}bg_survey_responses WHERE registration_id IN ({$in})
             GROUP BY event_post_id, question_key"
        ) ?: [];
        $respondents = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT registration_id) FROM {$wpdb->prefix}bg_survey_responses
             WHERE registration_id IN ({$in})"
        );
        $questionDefs = NotificationsModule::questions();
        $questions = [];
        $byEvent = [];
        $weighted = 0.0;
        $scores = 0;
        foreach ($rows as $row) {
            $key = (string) $row->question_key;
            $n = (int) $row->responses;
            $avg = round((float) $row->average, 2);
            if (!isset($questions[$key])) {
                $questions[$key] = [
                    'label' => (string) ($questionDefs[$key] ?? $key),
                    'responses' => 0,
                    'weighted' => 0.0,
                    'average' => 0.0,
                ];
            }
            $questions[$key]['responses'] += $n;
            $questions[$key]['weighted'] += $avg * $n;
            $questions[$key]['average'] = round($questions[$key]['weighted'] / max(1, $questions[$key]['responses']), 2);
            $eventId = (int) $row->event_post_id;
            if (!isset($byEvent[$eventId])) $byEvent[$eventId] = ['responses' => 0, 'weighted' => 0.0, 'average' => 0.0];
            $byEvent[$eventId]['responses'] += $n;
            $byEvent[$eventId]['weighted'] += $avg * $n;
            $byEvent[$eventId]['average'] = round($byEvent[$eventId]['weighted'] / max(1, $byEvent[$eventId]['responses']), 2);
            $weighted += $avg * $n;
            $scores += $n;
        }
        foreach ($questions as &$question) unset($question['weighted']);
        unset($question);
        foreach ($byEvent as &$event) unset($event['weighted']);
        unset($event);
        return [
            'respondents' => $respondents,
            'response_rate' => $registrations > 0 ? round($respondents / $registrations * 100, 1) : 0.0,
            'overall_average' => $scores > 0 ? round($weighted / $scores, 2) : 0.0,
            'questions' => $questions,
            'by_event' => $byEvent,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function eventRows(array $events, array $registrations, array $people, array $surveyByEvent): array
    {
        $regsByEvent = [];
        foreach ($registrations as $row) $regsByEvent[(int) $row->event_post_id][] = $row;
        $out = [];
        foreach ($events as $event) {
            $rows = $regsByEvent[$event['id']] ?? [];
            $unique = [];
            $unlinked = 0;
            $volunteers = [];
            $fees = 0;
            foreach ($rows as $row) {
                $person = (int) ($people[(int) $row->id] ?? 0);
                if ($person > 0) $unique[$person] = true;
                else $unlinked++;
                if ((int) ($row->volunteer_interest ?? 0) === 1) {
                    $volunteers[$person > 0 ? 'c:' . $person : 'r:' . (int) $row->id] = true;
                }
                $fees += (int) ($row->amount_cents ?? 0);
            }
            $tags = wp_get_post_terms($event['id'], 'bg_tag', ['fields' => 'names']);
            if (is_wp_error($tags) || !is_array($tags)) $tags = [];
            $grantTags = wp_get_post_terms($event['id'], 'bg_grant_tag', ['fields' => 'names']);
            if (is_wp_error($grantTags) || !is_array($grantTags)) $grantTags = [];
            $survey = $surveyByEvent[$event['id']] ?? ['responses' => 0, 'average' => 0.0];
            $out[] = $event + [
                'registrations' => count($rows),
                'identified_unique_participants' => count($unique),
                'unlinked_registrations' => $unlinked,
                'volunteer_interest_people' => count($volunteers),
                'registration_fee_cents' => $fees,
                'survey_scores' => (int) $survey['responses'],
                'survey_average' => (float) $survey['average'],
                'program_tags' => array_values(array_map('strval', $tags)),
                'grant_tags' => array_values(array_map('strval', $grantTags)),
            ];
        }
        return $out;
    }

    /** @return array<string, array{events:int,registrations:int}> */
    private function programBreakdown(array $events, array $eventRows): array
    {
        $rowsById = [];
        foreach ($eventRows as $row) $rowsById[(int) $row['id']] = $row;
        $out = [];
        foreach ($events as $event) {
            $terms = wp_get_post_terms($event['id'], 'bg_tag', ['fields' => 'names']);
            if (is_wp_error($terms) || !is_array($terms) || !$terms) $terms = ['Uncategorized'];
            foreach ($terms as $name) {
                $name = (string) $name;
                if (!isset($out[$name])) $out[$name] = ['events' => 0, 'registrations' => 0];
                $out[$name]['events']++;
                $out[$name]['registrations'] += (int) ($rowsById[$event['id']]['registrations'] ?? 0);
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return array<string, array{events:int,registrations:int}> */
    private function grantBreakdown(array $events, array $eventRows): array
    {
        $rowsById = [];
        foreach ($eventRows as $row) $rowsById[(int) $row['id']] = $row;
        $out = [];
        foreach ($events as $event) {
            $terms = wp_get_post_terms($event['id'], 'bg_grant_tag', ['fields' => 'names']);
            if (is_wp_error($terms) || !is_array($terms) || !$terms) continue;
            foreach ($terms as $name) {
                $name = (string) $name;
                if (!isset($out[$name])) $out[$name] = ['events' => 0, 'registrations' => 0];
                $out[$name]['events']++;
                $out[$name]['registrations'] += (int) ($rowsById[$event['id']]['registrations'] ?? 0);
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return array<string, array{label:string,events:int,registrations:int}> */
    private function eventRegionBreakdown(array $events, array $eventRows): array
    {
        $rowsById = [];
        foreach ($eventRows as $row) $rowsById[(int) $row['id']] = $row;
        $out = [];
        foreach (RegionalGroups::regions() as $slug => $label) {
            $out[$slug] = ['label' => $label, 'events' => 0, 'registrations' => 0];
        }
        $out['unassigned'] = ['label' => 'Unassigned / needs review', 'events' => 0, 'registrations' => 0];
        foreach ($events as $event) {
            $slug = (string) ($event['region_slug'] ?? '');
            if (!isset($out[$slug])) $slug = 'unassigned';
            $out[$slug]['events']++;
            $out[$slug]['registrations'] += (int) ($rowsById[(int) $event['id']]['registrations'] ?? 0);
        }
        return array_filter($out, static fn(array $row): bool => $row['events'] > 0 || $row['registrations'] > 0);
    }

    /** @return array<string, int|string> */
    private function financials(array $config, array $eventIds, array $registrationPeople, RegionalGroups $regional): array
    {
        global $wpdb;
        $bounds = self::localPeriodUtcBounds($config);
        $rows = $bounds === null ? [] : ($wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, constituent_id, registration_id, parent_id, amount_cents
             FROM {$wpdb->prefix}bg_payments
             WHERE created_at >= %s AND created_at < %s ORDER BY id ASC",
            $bounds[0],
            $bounds[1]
        )) ?: []);
        $selectedRegistrationIds = array_fill_keys(array_map('intval', array_keys($registrationPeople)), true);
        $eventScoped = (string) ($config['event_mode'] ?? 'all') === 'selected'
            || (array) ($config['program_tags'] ?? []) !== []
            || (array) ($config['grant_tags'] ?? []) !== []
            || self::eventRegionFilter($config) !== '';
        $regionFilter = self::participantRegionFilter($config);
        $paymentPeople = [];
        foreach ($rows as $row) {
            $person = (int) ($row->constituent_id ?? 0);
            if ($person < 1 && (int) ($row->registration_id ?? 0) > 0) {
                $person = (int) ($registrationPeople[(int) $row->registration_id] ?? 0);
            }
            if ($person > 0) $paymentPeople[$person] = true;
        }
        $paymentRegionMap = $regional->mapFor(array_map('intval', array_keys($paymentPeople)));
        $totals = ['donation' => 0, 'event_fee' => 0, 'sale' => 0, 'refund' => 0];
        $unattributed = 0;
        foreach ($rows as $row) {
            $kind = (string) $row->kind;
            if (!array_key_exists($kind, $totals)) continue;
            $amount = (int) $row->amount_cents;
            $registrationId = (int) ($row->registration_id ?? 0);
            // Once a report is narrowed by event, program, grant, or event location,
            // every ledger row must trace to a registration in the selected event
            // set. A registration id from some other event is not enough.
            if ($eventScoped && !isset($selectedRegistrationIds[$registrationId])) {
                $unattributed += $amount;
                continue;
            }
            $person = (int) ($row->constituent_id ?? 0);
            if ($person < 1 && $registrationId > 0) {
                $person = (int) ($registrationPeople[$registrationId] ?? 0);
            }
            if ($regionFilter !== '' && ($person < 1 || (string) ($paymentRegionMap[$person] ?? '') !== $regionFilter)) {
                $unattributed += $amount;
                continue;
            }
            $totals[$kind] += $amount;
        }
        return [
            'currency' => \BosqueGrande\Core\Money::code(),
            'donations_cents' => $totals['donation'],
            'event_fees_cents' => $totals['event_fee'],
            'sales_cents' => $totals['sale'],
            'refunds_cents' => $totals['refund'],
            'net_cents' => $totals['donation'] + $totals['event_fee'] + $totals['sale'] - $totals['refund'],
            'unattributed_cents' => $unattributed,
        ];
    }

    /** @return array<string, mixed> */
    private function communitySnapshot(array $config, ConstituentStore $store, RegionalGroups $regional): array
    {
        global $wpdb;
        $records = $store->allDecrypted();
        $regionFilter = self::participantRegionFilter($config);
        if ($regionFilter !== '' && $records) {
            $ids = array_map(static fn(array $record): int => (int) $record['id'], $records);
            $map = $regional->mapFor($ids);
            $records = array_values(array_filter($records, static function (array $record) use ($map, $regionFilter): bool {
                return (string) ($map[(int) $record['id']] ?? '') === $regionFilter;
            }));
        }
        $new = 0;
        $bounds = self::localPeriodUtcBounds($config);
        foreach ($records as $record) {
            $created = substr((string) ($record['created_at'] ?? ''), 0, 19);
            if ($bounds !== null && $created >= $bounds[0] && $created < $bounds[1]) $new++;
        }
        $monthlyFieldId = 0;
        foreach ($store->fieldDefinitions() as $definition) {
            if ((string) $definition->field_key === CoreFields::MONTHLY_DONOR) {
                $monthlyFieldId = (int) $definition->id;
                break;
            }
        }
        /** @var DonorStatus $donors */
        $donors = $this->container->get('constituents.donor_status');
        $statuses = $donors->statusesFor($records, $monthlyFieldId);
        $donorCounts = [DonorStatus::MONTHLY => 0, DonorStatus::PAST => 0, DonorStatus::NEVER => 0, DonorStatus::UNKNOWN => 0];
        foreach ($statuses as $status) $donorCounts[$status] = ($donorCounts[$status] ?? 0) + 1;

        $where = "s.status = 'active'";
        if ($regionFilter !== '') {
            $where .= $wpdb->prepare(' AND r.region_slug = %s', $regionFilter);
        }
        $join = $regionFilter !== ''
            ? " JOIN {$wpdb->prefix}bg_constituent_regions r ON r.constituent_id = s.constituent_id"
            : '';
        $groups = $wpdb->get_results(
            "SELECT g.name, COUNT(*) AS subscribers FROM {$wpdb->prefix}bg_subscriptions s
             JOIN {$wpdb->prefix}bg_groups g ON g.id = s.group_id {$join}
             WHERE {$where} GROUP BY g.id, g.name ORDER BY g.name ASC"
        ) ?: [];
        $subscriptions = [];
        foreach ($groups as $group) $subscriptions[(string) $group->name] = (int) $group->subscribers;
        return [
            'active_constituents_now' => count($records),
            'new_constituents_in_period' => $new,
            'monthly_donors_now' => $donorCounts[DonorStatus::MONTHLY],
            'past_donors_now' => $donorCounts[DonorStatus::PAST],
            'never_donated_now' => $donorCounts[DonorStatus::NEVER],
            'donor_status_not_checked' => $donorCounts[DonorStatus::UNKNOWN],
            'newsletter_subscribers_now' => $subscriptions,
        ];
    }

    /** Participant home-region filter, including pre-3.5.5 saved configs. */
    private static function participantRegionFilter(array $config): string
    {
        $region = sanitize_key((string) ($config['participant_region_slug'] ?? $config['region_slug'] ?? ''));
        return isset(RegionalGroups::regions()[$region]) ? $region : '';
    }

    /** Event location-region filter applied to location-first event classifications. */
    private static function eventRegionFilter(array $config): string
    {
        $region = sanitize_key((string) ($config['event_region_slug'] ?? ''));
        return isset(RegionalGroups::regions()[$region]) ? $region : '';
    }

    /**
     * Reporting classifies attendance from chronological event history. The
     * legacy registration flag reflects CRM/import state and is not evidence
     * that an event was a person's first BosqueGrande event.
     *
     * @param list<object> $registrations
     * @param array<int,int> $registrationPeople registration id => constituent id
     * @param array<int,string> $eventDates event id => local event date
     * @return array<int,int> registration id => 1 new, 0 returning, -1 unknown
     */
    private static function newcomerStatuses(
        ?HistoricalParticipation $history,
        array $registrations,
        array $registrationPeople,
        array $eventDates
    ): array {
        $ordered = $registrations;
        usort($ordered, static function (object $left, object $right) use ($eventDates): int {
            $leftEvent = (int) ($left->event_post_id ?? 0);
            $rightEvent = (int) ($right->event_post_id ?? 0);
            $byDate = strcmp(
                (string) ($eventDates[$leftEvent] ?? ''),
                (string) ($eventDates[$rightEvent] ?? '')
            );
            if ($byDate !== 0) return $byDate;
            $byEvent = $leftEvent <=> $rightEvent;
            return $byEvent !== 0 ? $byEvent : ((int) ($left->id ?? 0) <=> (int) ($right->id ?? 0));
        });

        $statuses = [];
        $firstByPerson = [];
        foreach ($ordered as $registration) {
            $registrationId = (int) ($registration->id ?? 0);
            $eventId = (int) ($registration->event_post_id ?? 0);
            $constituentId = (int) ($registrationPeople[$registrationId] ?? 0);
            $eventDate = self::date((string) ($eventDates[$eventId] ?? ''));
            if (!$history || $registrationId === 0 || $constituentId < 1 || $eventId < 1 || $eventDate === '') {
                $statuses[$registrationId] = -1;
                continue;
            }

            if (!isset($firstByPerson[$constituentId])) {
                $status = $history->newcomerStatus($constituentId, $eventDate, $eventId);
                $firstByPerson[$constituentId] = ['event_id' => $eventId, 'status' => $status];
                $statuses[$registrationId] = $status;
                continue;
            }

            // A duplicate active row for the same person/event receives the
            // same classification; any later event is necessarily returning.
            $first = $firstByPerson[$constituentId];
            $statuses[$registrationId] = (int) $first['event_id'] === $eventId
                ? (int) $first['status']
                : 0;
        }
        return $statuses;
    }

    /** @return array{0:string,1:string}|null Half-open UTC bounds for local report dates. */
    private static function localPeriodUtcBounds(array $config): ?array
    {
        $startDate = self::date((string) ($config['period_start'] ?? ''));
        $endDate = self::date((string) ($config['period_end'] ?? ''));
        if ($startDate === '' || $endDate === '' || $endDate < $startDate) return null;

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        if (!$start || !$end) return null;

        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private static function scopeLabel(string $participantRegion, string $eventRegion): string
    {
        if ($participantRegion === '' && $eventRegion === '') return 'All participant and event regions';
        $parts = [];
        if ($participantRegion !== '') $parts[] = 'Participants: ' . RegionalGroups::label($participantRegion);
        if ($eventRegion !== '') $parts[] = 'Events: ' . RegionalGroups::label($eventRegion);
        return implode('; ', $parts);
    }

    /** @param list<string> $slugs @return list<string> */
    private function selectedTermNames(string $taxonomy, array $slugs): array
    {
        if (!$slugs) return [];
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'slug' => $slugs,
        ]);
        if (is_wp_error($terms) || !is_array($terms)) return [];
        $names = array_values(array_map(static fn($term): string => (string) $term->name, $terms));
        natcasesort($names);
        return array_values($names);
    }

    /** @return list<int> */
    private static function ids(array $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    private static function date(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value : '';
    }

    private static function moneyToCents(mixed $value): int
    {
        $plain = preg_replace('/[^0-9.\-]+/', '', (string) $value);
        return max(0, (int) round((float) ($plain ?: 0) * 100));
    }
}
