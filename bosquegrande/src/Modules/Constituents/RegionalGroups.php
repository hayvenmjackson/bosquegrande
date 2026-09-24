<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;
use BosqueGrande\Modules\Intelligence\IntelligenceModule;
use BosqueGrande\Modules\Intelligence\ProviderInterface;

if (!defined('ABSPATH')) exit;

/**
 * One regional group per constituent, derived from their address. This is
 * separate from newsletter groups. The regions are the site's event
 * vocabulary regions plus "Outside our area"; the matching rules (postal
 * codes and place names) are edited on the Tagging Rules screen.
 *
 * Postal rules run first, then exact and close place-name matches. Optional
 * assisted matching sees only city, state, and postal code and can return
 * only one of the site's regions.
 */
final class RegionalGroups
{
    public const AUTO = '__auto__';
    public const NONE = '__none__';
    public const OUTSIDE = 'out-of-area';

    private const STATES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
        'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
        'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
        'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME',
        'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
        'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
        'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
        'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI',
        'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX',
        'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
        'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
        'puerto rico' => 'PR', 'guam' => 'GU', 'american samoa' => 'AS',
        'northern mariana islands' => 'MP', 'us virgin islands' => 'VI',
        // Canadian provinces.
        'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB',
        'new brunswick' => 'NB', 'newfoundland and labrador' => 'NL',
        'nova scotia' => 'NS', 'ontario' => 'ON', 'prince edward island' => 'PE',
        'quebec' => 'QC', 'saskatchewan' => 'SK', 'northwest territories' => 'NT',
        'nunavut' => 'NU', 'yukon' => 'YT',
        // Common spreadsheet variants.
        'mass' => 'MA', 'commonwealth of massachusetts' => 'MA',
        'new york state' => 'NY', 'conn' => 'CT', 'washington dc' => 'DC',
        'u s virgin islands' => 'VI', 'newfoundland' => 'NL',
    ];

    public function __construct(
        private ConstituentStore $store,
        private Settings $settings,
        private ProviderInterface $assistant,
        private Logger $logger,
    ) {
    }

    /** @return array<string, string> slug => label */
    public static function regions(): array
    {
        $regions = \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
        if ($regions) $regions[self::OUTSIDE] = 'Outside our area';
        return $regions;
    }

    public static function label(string $slug): string
    {
        return self::regions()[$slug] ?? '';
    }

    /**
     * Classify an event from its canonical location text, falling back to the
     * town and state carried in its title for legacy records.
     *
     * Events use the same regions and rules as constituent records. Explicit
     * location text is considered first, then parenthetical and trailing
     * fragments of the title, then the whole title. No assisted matching is
     * used, so grant reports stay reproducible.
     */
    public static function eventRegion(string $title, string $locationText = ''): string
    {
        $title = trim(ConstituentStore::stripExcelGuard($title));
        $locationText = trim(ConstituentStore::stripExcelGuard($locationText));
        if ($title === '' && $locationText === '') return '';

        $fragments = [];
        if ($locationText !== '') $fragments[] = $locationText;
        if (preg_match_all('/\(([^()]*)\)/u', $title, $matches)) {
            foreach (array_reverse((array) ($matches[1] ?? [])) as $fragment) {
                $fragment = trim((string) $fragment);
                if ($fragment !== '') $fragments[] = $fragment;
            }
        }

        $parts = preg_split('/\s+(?:@|[-\x{2013}\x{2014}|])\s+/u', $title) ?: [];
        foreach (array_reverse($parts) as $fragment) {
            $fragment = trim((string) $fragment);
            if ($fragment !== '') $fragments[] = $fragment;
        }
        $fragments[] = $title;

        $seen = [];
        foreach ($fragments as $fragment) {
            $key = self::normal($fragment);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $region = self::regionFromLocationText($fragment);
            if ($region !== '') return $region;
        }
        return '';
    }

    /** @return array{region_slug:string,assignment_source:string,location_hash:string,confidence:float,evidence:string}|null */
    public function for(int $constituentId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT region_slug, assignment_source, location_hash, confidence, evidence
             FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id = %d",
            $constituentId
        ), ARRAY_A);
        if (!is_array($row)) return null;
        return [
            'region_slug' => (string) ($row['region_slug'] ?? ''),
            'assignment_source' => (string) ($row['assignment_source'] ?? ''),
            'location_hash' => (string) ($row['location_hash'] ?? ''),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'evidence' => (string) ($row['evidence'] ?? ''),
        ];
    }

    /** @return array<int, string> constituent id => region slug */
    public function mapFor(array $constituentIds): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $constituentIds))));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT constituent_id, region_slug FROM {$wpdb->prefix}bg_constituent_regions
             WHERE constituent_id IN ({$placeholders})",
            ...$ids
        )) ?: [];
        $out = [];
        foreach ($rows as $row) $out[(int) $row->constituent_id] = (string) $row->region_slug;
        return $out;
    }

    /** Save a staff override, or return the record to address-driven mode. */
    public function saveChoice(int $constituentId, string $choice): bool
    {
        if ($choice === self::AUTO) return $this->assign($constituentId, true);
        if ($choice === self::NONE) {
            return $this->write($constituentId, '', 'manual', '', 1.0, 'Staff selected no regional group.');
        }
        $choice = sanitize_title($choice);
        if (!isset(self::regions()[$choice])) return false;
        return $this->write($constituentId, $choice, 'manual', '', 1.0, 'Staff-selected regional group.');
    }

    /** Assign from current contact fields unless a staff override exists. */
    public function assign(int $constituentId, bool $force = false): bool
    {
        $record = $this->store->get($constituentId);
        if (!$record || ($record['status'] ?? '') !== 'active') return false;
        $current = $this->for($constituentId);
        if (!$force && ($current['assignment_source'] ?? '') === 'manual') return true;
        $hash = self::locationHash($record);
        if (!$force && $current && hash_equals((string) $current['location_hash'], $hash)) return true;
        $decision = $this->decide($record);
        return $this->write(
            $constituentId,
            $decision['region'],
            $decision['source'],
            $hash,
            $decision['confidence'],
            $decision['evidence']
        );
    }

    /**
     * IDs needing the upgrade/recalculation pass. Manual choices are never
     * candidates. Migration 23 and the admin refresh deliberately blank the
     * fingerprint so this query does not need to decrypt every person first.
     * @return list<int>
     */
    public function pendingIds(int $limit = 50): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT c.id FROM {$wpdb->prefix}bg_constituents c
             LEFT JOIN {$wpdb->prefix}bg_constituent_regions r ON r.constituent_id = c.id
             WHERE c.status = 'active'
               AND (r.constituent_id IS NULL
                    OR (r.assignment_source <> 'manual' AND r.location_hash = ''))
             ORDER BY c.id ASC LIMIT %d",
            max(1, min(100, $limit))
        ));
        return array_map('intval', $ids ?: []);
    }

    /** Force every automatic row through current rules; preserve overrides. */
    public function invalidateAutomatic(): ?int
    {
        global $wpdb;
        $changed = $wpdb->query(
            "UPDATE {$wpdb->prefix}bg_constituent_regions
             SET location_hash = '' WHERE assignment_source <> 'manual'"
        );
        if ($changed === false) {
            $this->logger->warn('constituents', 'Automatic regional fingerprints could not be invalidated.', [
                'database_error' => (string) $wpdb->last_error,
            ]);
            return null;
        }
        update_option('bg_constituent_region_backfill_pending', 1, false);
        return (int) $changed;
    }

    /** @param array<string, mixed> $record @return array{region:string,source:string,confidence:float,evidence:string} */
    public function decide(array $record): array
    {
        $decision = self::classifyRecord($record);
        if (in_array($decision['source'], ['out-of-area', 'review'], true)
            && IntelligenceModule::featureEnabled($this->settings, 'regional_matching')
        ) {
            $assisted = $this->assisted(
                self::cityCore(trim((string) ($record['city'] ?? '')), self::state((string) ($record['state'] ?? '')), self::postalKey((string) ($record['postal'] ?? ''))),
                self::state((string) ($record['state'] ?? '')),
                trim((string) ($record['postal'] ?? ''))
            );
            if ($assisted['region'] !== '') return $assisted;
        }
        return $decision;
    }

    /**
     * Rule-only classification with no provider call, shared by constituent
     * records and accounting exports.
     *
     * @param array<string, mixed> $record
     * @return array{region:string,source:string,confidence:float,evidence:string}
     */
    public static function classifyRecord(array $record): array
    {
        $blank = ['region' => '', 'source' => 'blank', 'confidence' => 0.0, 'evidence' => 'No usable address, city, state, or postal code.'];
        if (!\BosqueGrande\Modules\GatheringTree\Vocabulary::regions()) {
            return ['region' => '', 'source' => 'blank', 'confidence' => 0.0, 'evidence' => 'No regions are defined.'];
        }
        $addressRaw = trim((string) ($record['address1'] ?? '') . ' ' . (string) ($record['address2'] ?? ''));
        $cityRaw = trim((string) ($record['city'] ?? ''));
        $stateRaw = trim((string) ($record['state'] ?? ''));
        $postalRaw = trim((string) ($record['postal'] ?? ''));
        $address = self::normal($addressRaw);
        $cityText = self::normal($cityRaw);
        $postal = self::postalKey($postalRaw);
        $state = self::state($stateRaw);
        if ($state === '') $state = self::stateAtEnd($cityRaw, false);
        if ($state === '') $state = self::stateAtEnd($addressRaw, true);
        $city = self::cityCore($cityRaw, $state, $postal);
        if ($address === '' && $city === '' && $state === '' && $postal === '') return $blank;

        $postalRegion = self::postalRegion($postal);
        if ($postalRegion !== '') {
            return ['region' => $postalRegion, 'source' => 'postal', 'confidence' => 1.0, 'evidence' => 'Postal code rule.'];
        }

        $exact = self::cityRegion($city, $state);
        if ($exact === '') $exact = self::townInText($cityText, $state, 2, false);
        if ($exact !== '') {
            return ['region' => $exact, 'source' => 'city', 'confidence' => 1.0, 'evidence' => 'Place name rule.'];
        }

        $fuzzy = self::fuzzyRegion($city, $state);
        if ($fuzzy !== '') {
            return ['region' => $fuzzy, 'source' => 'fuzzy', 'confidence' => 0.86, 'evidence' => 'Unique close spelling of a place name.'];
        }

        // Some imports put the whole mailing address in one field. Parse its
        // delimited components before giving up.
        if ($address !== '') {
            $addressRegion = self::addressRegion($addressRaw, $state, $postal);
            if ($addressRegion !== '') {
                return ['region' => $addressRegion, 'source' => 'address', 'confidence' => 0.9, 'evidence' => 'A unique known place appears in the address.'];
            }
        }

        if ($city !== '' || $postal !== '') {
            return ['region' => self::OUTSIDE, 'source' => 'out-of-area', 'confidence' => 0.9, 'evidence' => 'The location matches none of the region rules.'];
        }

        return ['region' => '', 'source' => 'review', 'confidence' => 0.0, 'evidence' => 'Location is present but not specific enough for a safe assignment.'];
    }

    /** @return array{region:string,source:string,confidence:float,evidence:string} */
    private function assisted(string $city, string $state, string $postal): array
    {
        $blank = ['region' => '', 'source' => 'review', 'confidence' => 0.0, 'evidence' => 'Assisted matching abstained.'];
        if (!$this->assistant->isConfigured()) return $blank;
        $places = [];
        foreach (self::placeRules() as $place => $region) $places[$region][] = $place;
        $reply = $this->assistant->complete(
            'Assign one location to one of an organization\'s regions. Reply only with JSON: '
            . '{"region":"slug-or-empty","confidence":0.0}. Never invent a slug. Each region lists example places '
            . 'that belong to it; use ordinary geography to place nearby towns. Choose "' . self::OUTSIDE . '" when the '
            . 'location is clearly outside every region. Abstain with an empty slug if unsure.',
            'Regions: ' . wp_json_encode(self::regions())
            . "\nExample places by region: " . wp_json_encode($places)
            . "\nCity: {$city}\nState: {$state}\nPostal code: {$postal}",
            ['max_tokens' => 100]
        );
        $decoded = json_decode(trim(str_replace(['```json', '```'], '', $reply)), true);
        if (!is_array($decoded)) return $blank;
        $region = sanitize_title((string) ($decoded['region'] ?? ''));
        $confidence = is_numeric($decoded['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0;
        if (!isset(self::regions()[$region]) || $confidence < 0.72) return $blank;
        return ['region' => $region, 'source' => 'assisted', 'confidence' => $confidence, 'evidence' => 'Constrained regional matching judgment.'];
    }

    private function write(int $constituentId, string $region, string $source, string $hash, float $confidence, string $evidence): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_constituent_regions';
        $data = [
            'region_slug' => isset(self::regions()[$region]) ? $region : '',
            'assignment_source' => bg_substr($source, 0, 24),
            'location_hash' => $hash,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'evidence' => bg_substr($evidence, 0, 255),
            'updated_at' => current_time('mysql', true),
        ];
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT constituent_id FROM {$table} WHERE constituent_id = %d",
            $constituentId
        ));
        $ok = $exists !== null
            ? $wpdb->update($table, $data, ['constituent_id' => $constituentId]) !== false
            : (bool) $wpdb->insert($table, ['constituent_id' => $constituentId] + $data);
        if (!$ok) {
            $this->logger->warn('constituents', 'Derived regional group could not be stored.', [
                'constituent' => $constituentId,
                'database_error' => (string) $wpdb->last_error,
            ]);
        }
        return $ok;
    }

    private static function locationHash(array $record): string
    {
        return hash('sha256', implode('|', [
            self::rulesFingerprint(),
            self::normal((string) ($record['address1'] ?? '')),
            self::normal((string) ($record['address2'] ?? '')),
            self::normal((string) ($record['city'] ?? '')),
            self::state((string) ($record['state'] ?? '')),
            strtoupper(trim((string) ($record['postal'] ?? ''))),
        ]));
    }

    private static function state(string $state): string
    {
        $key = self::normal(ConstituentStore::stripExcelGuard($state));
        if ($key === '') return '';
        if (isset(self::STATES[$key])) return self::STATES[$key];
        $key = trim((string) preg_replace(
            '/\b(?:united states of america|united states|usa|u s a|us)\b/',
            '',
            $key
        ));
        $withoutQualifier = trim((string) preg_replace(
            '/\b(?:state|commonwealth|province)\b/',
            '',
            $key
        ));
        foreach ([$key, $withoutQualifier] as $candidate) {
            if (isset(self::STATES[$candidate])) return self::STATES[$candidate];
            $collapsed = str_replace(' ', '', $candidate);
            if (strlen($collapsed) === 2 && self::isStateCode($collapsed)) return strtoupper($collapsed);
        }
        $tokens = array_reverse(explode(' ', $key));
        foreach ($tokens as $token) {
            if (strlen($token) === 2 && self::isStateCode($token)) return strtoupper($token);
        }
        foreach (self::STATES as $name => $code) {
            if (strlen($name) >= 4 && preg_match('/(?:^| )' . preg_quote($name, '/') . '(?: |$)/', $key)) {
                return $code;
            }
        }
        return '';
    }

    /** Location-text classifier shared by event-title parsing. */
    private static function regionFromLocationText(string $value): string
    {
        $state = self::stateAtEnd($value, false);
        $postal = self::postalKey((string) (preg_match('/\b[A-Z0-9]{3,4}(?:[ -]?[A-Z0-9]{3,4})?\s*$/i', trim($value), $m) ? $m[0] : ''));
        $city = self::cityCore($value, $state, $postal);
        $region = self::cityRegion($city, $state);
        if ($region === '') $region = self::townInText(self::normal($value), $state, 2, false);
        if ($region !== '') return $region;
        return self::postalRegion($postal);
    }

    private static function normal(string $value): string
    {
        $value = strtolower(remove_accents(trim($value)));
        $value = str_replace('&', ' and ', $value);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private static function isStateCode(string $value): bool
    {
        return in_array(strtoupper($value), array_values(self::STATES), true);
    }

    private static function stateAtEnd(string $value, bool $addressField): string
    {
        $hasAddressDelimiter = (bool) preg_match('/[,;\r\n]/', $value);
        $key = self::normal($value);
        if ($key === '') return '';
        $key = trim((string) preg_replace('/\b\d{5}(?:\s*\d{4})?\b\s*$/', '', $key));
        $key = trim((string) preg_replace(
            '/\b(?:united states of america|united states|usa|u s a|us)\b\s*$/',
            '',
            $key
        ));
        if (!str_contains($key, ' ')) return '';
        $aliases = array_keys(self::STATES);
        usort($aliases, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($aliases as $alias) {
            // Without a delimiter, a street such as "10 Washington Street"
            // must not be read as the state of Washington. Two-letter codes
            // remain safe and are handled below.
            if ($addressField && !$hasAddressDelimiter) continue;
            if (str_ends_with($key, ' ' . $alias)) return self::STATES[$alias];
        }
        $tokens = explode(' ', $key);
        $last = (string) end($tokens);
        if (strlen($last) === 2 && self::isStateCode($last)) return strtoupper($last);
        if (count($tokens) >= 3) {
            $spaced = $tokens[count($tokens) - 2] . $tokens[count($tokens) - 1];
            if (strlen($spaced) === 2 && self::isStateCode($spaced)) return strtoupper($spaced);
        }
        return '';
    }

    /** Uppercase letters and digits only, so "01230-1234" and "SW1A 1AA" compare cleanly. */
    private static function postalKey(string $value): string
    {
        $value = strtoupper(ConstituentStore::stripExcelGuard($value));
        return (string) preg_replace('/[^A-Z0-9]+/', '', $value);
    }

    private static function cityCore(string $value, string $state, string $postal): string
    {
        $city = self::normal($value);
        if ($city === '') return '';
        $city = trim((string) preg_replace(
            '/\b(?:united states of america|united states|usa|u s a|us)\b/',
            '',
            $city
        ));
        if ($postal !== '') $city = trim(str_replace(strtolower($postal), '', $city));
        $city = trim((string) preg_replace('/\b\d{5}(?: \d{4})?\b/', '', $city));
        if ($state !== '') {
            $aliases = self::stateAliases($state);
            foreach ($aliases as $alias) {
                if ($city === $alias) {
                    $city = '';
                    break;
                }
                if (str_ends_with($city, ' ' . $alias)) {
                    $city = trim(substr($city, 0, -strlen($alias)));
                    break;
                }
            }
        }
        $city = (string) preg_replace('/^(?:city|town|village|borough) of /', '', $city);
        $city = (string) preg_replace('/ (?:city|town|village|borough)$/', '', $city);
        return trim((string) preg_replace('/\s+/', ' ', $city));
    }

    /** @return list<string> Longest aliases first. */
    private static function stateAliases(string $state): array
    {
        $aliases = [strtolower($state), strtolower($state[0] . ' ' . $state[1])];
        foreach (self::STATES as $name => $code) {
            if ($code === $state) $aliases[] = $name;
        }
        $aliases = array_values(array_unique($aliases));
        usort($aliases, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $aliases;
    }

    /** Longest matching postal prefix wins. */
    private static function postalRegion(string $postal): string
    {
        if ($postal === '') return '';
        $best = '';
        $bestLength = 0;
        foreach (self::postalRules() as $prefix => $region) {
            $length = strlen($prefix);
            if ($length > $bestLength && str_starts_with($postal, $prefix)) {
                $best = $region;
                $bestLength = $length;
            }
        }
        return $best;
    }

    /** @return array<string, string> postal prefix => region */
    private static function postalRules(): array
    {
        $regions = \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
        $out = [];
        foreach ((array) (get_option('bg_region_postal_rules', []) ?: []) as $prefix => $region) {
            $prefix = self::postalKey((string) $prefix);
            if ($prefix !== '' && isset($regions[(string) $region])) $out[$prefix] = (string) $region;
        }
        return $out;
    }

    /** @return array<string, string> place (optionally "place, ST") => region */
    private static function placeRules(): array
    {
        $regions = \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
        $rules = \BosqueGrande\Modules\Exchange\Tagger::rules();
        $out = [];
        foreach ((array) ($rules['gazetteer'] ?? []) as $place => $region) {
            if (isset($regions[(string) $region])) $out[(string) $place] = (string) $region;
        }
        return $out;
    }

    private static function rulesFingerprint(): string
    {
        return substr(hash('sha256', (string) wp_json_encode([self::postalRules(), self::placeRules()])), 0, 16);
    }

    private static function cityRegion(string $city, string $state): string
    {
        if ($city === '') return '';
        $hits = [];
        foreach (self::townMap() as $candidateState => $towns) {
            if ($state !== '' && $candidateState !== '' && $candidateState !== $state) continue;
            if (isset($towns[$city])) $hits[$towns[$city]] = true;
        }
        return count($hits) === 1 ? (string) array_key_first($hits) : '';
    }

    private static function fuzzyRegion(string $city, string $state): string
    {
        if ($city === '' || strlen($city) < 4) return '';
        $bestDistance = PHP_INT_MAX;
        $bestRegions = [];
        foreach (self::townMap() as $candidateState => $towns) {
            if ($state !== '' && $candidateState !== '' && $candidateState !== $state) continue;
            foreach ($towns as $town => $region) {
                $distance = levenshtein($city, $town);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestRegions = [$region => true];
                } elseif ($distance === $bestDistance) {
                    $bestRegions[$region] = true;
                }
            }
        }
        $limit = strlen($city) <= 5 ? 1 : (strlen($city) <= 9 ? 2 : 3);
        if ($bestDistance > $limit || ($bestDistance / max(1, strlen($city))) > 0.25) return '';
        return count($bestRegions) === 1 ? (string) array_key_first($bestRegions) : '';
    }

    private static function addressRegion(string $address, string $state, string $postal): string
    {
        // Delimited components let us recover the locality without treating
        // "Washington Street" or "Lee Road" as a town.
        $hits = [];
        $parts = preg_split('/[\r\n,;]+/', $address) ?: [];
        foreach (array_reverse($parts) as $part) {
            $candidate = self::cityCore((string) $part, $state, $postal);
            $region = self::cityRegion($candidate, $state);
            if ($region !== '') $hits[$region] = true;
        }
        if (count($hits) === 1) return (string) array_key_first($hits);
        if (count($hits) > 1) return '';
        return self::townInText(self::normal($address), $state, 4, true);
    }

    private static function townInText(string $text, string $state, int $minimumLength, bool $excludeStreetSuffix): string
    {
        if ($text === '') return '';
        $hits = [];
        $padded = ' ' . self::normal($text) . ' ';
        foreach (self::townMap() as $candidateState => $towns) {
            if ($state !== '' && $candidateState !== '' && $candidateState !== $state) continue;
            foreach ($towns as $town => $region) {
                if (strlen($town) < $minimumLength) continue;
                $pattern = '/ ' . preg_quote($town, '/') . ' '
                    . ($excludeStreetSuffix
                        ? '(?!(?:street|st|road|rd|avenue|ave|lane|ln|drive|dr|highway|hwy|route|boulevard|blvd|court|ct|way) )'
                        : '') . '/';
                if (preg_match($pattern, $padded)) $hits[$region] = true;
            }
        }
        return count($hits) === 1 ? (string) array_key_first($hits) : '';
    }

    /**
     * Place rules grouped by state. A rule written "Springfield, IL" applies
     * only in that state; a rule with no state applies under the key "".
     *
     * @return array<string, array<string, string>> state => normalized place => region
     */
    private static function townMap(): array
    {
        static $memo = null;
        static $fingerprint = '';
        $current = self::rulesFingerprint();
        if (is_array($memo) && $fingerprint === $current) return $memo;
        $out = [];
        foreach (self::placeRules() as $place => $region) {
            $state = '';
            if (preg_match('/^(.*),\s*([A-Za-z .]+)$/', $place, $m)) {
                $state = self::state($m[2]);
                if ($state !== '') $place = $m[1];
            }
            $key = self::normal($place);
            if ($key !== '') $out[$state][$key] = $region;
        }
        $fingerprint = $current;
        return $memo = $out;
    }
}
