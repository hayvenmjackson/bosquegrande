<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

use BosqueGrande\Modules\Intelligence\ProviderInterface;

if (!defined('ABSPATH')) exit;

/** Header-only deterministic matching, with a constrained assisted fallback. */
final class FieldMatcher
{
    /** @return array<string,array{label:string,required:bool,reducer:string,aliases:list<string>}> */
    public static function targets(): array
    {
        return [
            'event_name' => ['label' => 'Event name', 'required' => true, 'reducer' => 'first', 'aliases' => ['event name', 'event title', 'activity name']],
            'event_id' => ['label' => 'External event ID', 'required' => true, 'reducer' => 'first', 'aliases' => ['event id', 'eventbrite event id', 'activity id']],
            'order_number' => ['label' => 'Order number', 'required' => true, 'reducer' => 'first', 'aliases' => ['order', 'order number', 'order no', 'order id']],
            'order_date' => ['label' => 'Registration/order date', 'required' => true, 'reducer' => 'first', 'aliases' => ['order date', 'registration date', 'created date']],
            'first_name' => ['label' => 'Attendee first name', 'required' => true, 'reducer' => 'first', 'aliases' => ['first name', 'attendee first name', 'registrant first name']],
            'last_name' => ['label' => 'Attendee last name', 'required' => true, 'reducer' => 'first', 'aliases' => ['last name', 'attendee last name', 'registrant last name']],
            'email' => ['label' => 'Attendee email', 'required' => false, 'reducer' => 'first', 'aliases' => ['email', 'email address', 'attendee email', 'registrant email']],
            'ticket_type' => ['label' => 'Source ticket type', 'required' => false, 'reducer' => 'first', 'aliases' => ['ticket type', 'ticket', 'registration type']],
            'buyer_first_name' => ['label' => 'Buyer first name (provenance only)', 'required' => false, 'reducer' => 'first', 'aliases' => ['buyer first name', 'purchaser first name']],
            'buyer_last_name' => ['label' => 'Buyer last name (provenance only)', 'required' => false, 'reducer' => 'first', 'aliases' => ['buyer last name', 'purchaser last name']],
            'buyer_email' => ['label' => 'Buyer email (never substituted)', 'required' => false, 'reducer' => 'first', 'aliases' => ['buyer email', 'purchaser email']],
            'event_date' => ['label' => 'Event date/time', 'required' => true, 'reducer' => 'first', 'aliases' => ['date attending', 'event date', 'activity date', 'start date']],
            'order_type' => ['label' => 'Order type', 'required' => false, 'reducer' => 'first', 'aliases' => ['order type', 'payment status']],
            'total_paid' => ['label' => 'Order total (provenance only)', 'required' => false, 'reducer' => 'first', 'aliases' => ['total paid', 'order total', 'amount paid']],
            'attendee_status' => ['label' => 'Attendee/registration status', 'required' => false, 'reducer' => 'first', 'aliases' => ['attendee status', 'registration status']],
            'address1' => ['label' => 'Home address line 1', 'required' => false, 'reducer' => 'first', 'aliases' => ['home address 1', 'address 1', 'street address']],
            'address2' => ['label' => 'Home address line 2', 'required' => false, 'reducer' => 'first', 'aliases' => ['home address 2', 'address 2']],
            'city' => ['label' => 'Home city', 'required' => false, 'reducer' => 'first', 'aliases' => ['home city', 'city']],
            'state' => ['label' => 'Home state', 'required' => false, 'reducer' => 'first', 'aliases' => ['home state', 'state', 'province']],
            'postal' => ['label' => 'Home postal code', 'required' => false, 'reducer' => 'first', 'aliases' => ['home zip', 'home postal code', 'zip', 'zip code', 'postal code']],
            'phone' => ['label' => 'Phone (cell preferred)', 'required' => false, 'reducer' => 'priority', 'aliases' => ['cell phone', 'mobile phone', 'home phone', 'phone']],
            'demographics' => ['label' => 'Demographic selections', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'demographics_other' => ['label' => 'Other demographic detail', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'age_range' => ['label' => 'Age range', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'heard' => ['label' => 'How participant heard', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'heard_other' => ['label' => 'Other outreach source', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'promote' => ['label' => 'Promotion consent', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'relationship' => ['label' => 'Relationship status', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
            'contact_sharing' => ['label' => 'Contact-sharing choice', 'required' => false, 'reducer' => 'drop_conflict', 'aliases' => []],
        ];
    }

    /**
     * @param array<int,string> $headers
     * @return array<string,array{id:string,label:string,columns:list<int>}>
     */
    public function groups(array $headers): array
    {
        $groups = [];
        foreach ($headers as $column => $header) {
            $normal = self::normalize((string) $header);
            if ($normal === '') continue;
            $id = 'g-' . sha1($normal);
            if (!isset($groups[$id])) $groups[$id] = ['id' => $id, 'label' => trim((string) $header), 'columns' => []];
            $groups[$id]['columns'][] = (int) $column;
        }
        $heardFollowups = [];
        $demographicFollowups = [];
        foreach ($headers as $column => $header) {
            $normal = self::normalize((string) $header);
            if ($this->isHeardHeader($normal)) {
                for ($candidate = (int) $column + 1; $candidate <= (int) $column + 6; $candidate++) {
                    $next = self::normalize((string) ($headers[$candidate] ?? ''));
                    if (!$this->isHeardFollowupHeader($next)) break;
                    $heardFollowups[] = $candidate;
                }
            }
            if ($this->isDemographicHeader($normal)) {
                $candidate = (int) $column + 1;
                $next = self::normalize((string) ($headers[$candidate] ?? ''));
                if ($this->isDemographicOtherHeader($next)
                    || in_array($next, ['please specify', 'please list'], true)
                ) $demographicFollowups[] = $candidate;
            }
        }
        $heardFollowups = array_values(array_unique($heardFollowups));
        if ($heardFollowups) {
            $groups['virtual-heard-followup'] = [
                'id' => 'virtual-heard-followup',
                'label' => 'Detail columns immediately following outreach questions',
                'columns' => $heardFollowups,
            ];
        }
        $demographicFollowups = array_values(array_unique($demographicFollowups));
        if ($demographicFollowups) {
            $groups['virtual-demographic-followup'] = [
                'id' => 'virtual-demographic-followup',
                'label' => 'Other-group detail columns immediately following demographic questions',
                'columns' => $demographicFollowups,
            ];
        }
        uasort($groups, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $groups;
    }

    /**
     * @param array<int,string> $headers
     * @return array<string,array{columns:list<int>,groups:list<string>,reducer:string,method:string,confidence:float}>
     */
    public function suggest(array $headers, ?ProviderInterface $assistant = null): array
    {
        $targets = self::targets();
        $groups = $this->groups($headers);
        $byNormal = [];
        foreach ($groups as $id => $group) {
            if (str_starts_with($id, 'virtual-')) continue;
            $byNormal[self::normalize($group['label'])] = $id;
        }
        $mapping = [];
        foreach ($targets as $key => $target) {
            $matched = [];
            foreach ($target['aliases'] as $alias) {
                $normal = self::normalize($alias);
                if (isset($byNormal[$normal])) $matched[] = $byNormal[$normal];
            }
            if ($key === 'phone') {
                $matched = $this->orderedPhoneGroups($matched, $groups);
            } elseif ($matched) {
                $matched = [reset($matched)];
            }
            if ($matched) $mapping[$key] = $this->entry($matched, $groups, $target['reducer'], 'exact', 1.0);
        }

        $families = [
            'demographics' => fn(string $h): bool => $this->isDemographicHeader($h),
            'demographics_other' => fn(string $h): bool => $this->isDemographicOtherHeader($h),
            'age_range' => fn(string $h): bool => str_contains($h, 'age range'),
            'heard' => fn(string $h): bool => $this->isHeardHeader($h),
            'promote' => fn(string $h): bool => str_contains($h, 'spread the word')
                || str_contains($h, 'help promote')
                || (str_contains($h, 'social media') && (str_contains($h, 'invite') || str_contains($h, 'share'))),
            'relationship' => fn(string $h): bool => str_contains($h, 'relationship status')
                || str_contains($h, 'current relationship'),
            'contact_sharing' => fn(string $h): bool => str_contains($h, 'share contact information')
                || str_contains($h, 'contact information with organizers'),
        ];
        foreach ($families as $key => $predicate) {
            $matched = [];
            foreach ($groups as $id => $group) {
                if (str_starts_with($id, 'virtual-')) continue;
                if ($predicate(self::normalize($group['label']))) $matched[] = $id;
            }
            if ($matched) $mapping[$key] = $this->entry($matched, $groups, 'drop_conflict', 'family', 0.98);
        }
        if (isset($groups['virtual-heard-followup'])) {
            $mapping['heard_other'] = $this->entry(
                ['virtual-heard-followup'], $groups, 'drop_conflict', 'adjacent-family', 0.95
            );
        }
        if (isset($groups['virtual-demographic-followup'])) {
            $mapping['demographics_other'] = $this->entry(
                ['virtual-demographic-followup'], $groups, 'drop_conflict', 'adjacent-family', 0.95
            );
        }

        // High-confidence spelling variation is deterministic and advisory.
        // Require both a strong score and a clear margin over the runner-up;
        // anything weaker is left for explicit confirmation or the optional
        // header-only assisted matcher.
        foreach ($targets as $key => $target) {
            if (!empty($mapping[$key]['columns']) || !$target['aliases']) continue;
            $fuzzy = $this->fuzzyGroup((array) $target['aliases'], $groups);
            if ($fuzzy !== null) {
                $mapping[$key] = $this->entry([$fuzzy['id']], $groups, $target['reducer'], 'fuzzy', $fuzzy['confidence']);
            }
        }

        $unresolved = [];
        foreach ($targets as $key => $target) {
            if (empty($mapping[$key]['columns'])) $unresolved[$key] = $target['label'];
        }
        if ($unresolved && $assistant && $assistant->isConfigured()) {
            $mapping += $this->assisted($unresolved, $groups, $assistant);
        }
        foreach ($targets as $key => $target) {
            $mapping[$key] ??= [
                'columns' => [], 'groups' => [], 'reducer' => $target['reducer'],
                'method' => 'unmapped', 'confidence' => 0.0,
            ];
        }
        return $mapping;
    }

    /** @param array<string,list<string>> $selectedGroups */
    public function fromSelection(array $selectedGroups, array $groups): array
    {
        $out = [];
        foreach (self::targets() as $key => $target) {
            $ids = array_values(array_filter(array_map('strval', $selectedGroups[$key] ?? []), static fn(string $id): bool => isset($groups[$id])));
            $out[$key] = $this->entry($ids, $groups, $target['reducer'], $ids ? 'confirmed' : 'unmapped', $ids ? 1.0 : 0.0);
        }
        return $out;
    }

    /** @param array<string,array{columns:list<int>}> $mapping @return list<int> */
    public static function wantedColumns(array $mapping): array
    {
        $columns = [];
        foreach ($mapping as $entry) foreach ((array) ($entry['columns'] ?? []) as $column) $columns[(int) $column] = true;
        $out = array_keys($columns);
        sort($out, SORT_NUMERIC);
        return $out;
    }

    public static function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower(trim($value));
        $value = str_replace(['acquaintenaces', 'latinx'], ['acquaintances', 'latino'], $value);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /** @param list<string> $ids @param array<string,array{id:string,label:string,columns:list<int>}> $groups */
    private function entry(array $ids, array $groups, string $reducer, string $method, float $confidence): array
    {
        $columns = [];
        $seen = [];
        foreach ($ids as $id) foreach ((array) ($groups[$id]['columns'] ?? []) as $column) {
            $column = (int) $column;
            if ($column > 0 && !isset($seen[$column])) {
                $columns[] = $column;
                $seen[$column] = true;
            }
        }
        if ($reducer !== 'priority') sort($columns, SORT_NUMERIC);
        return ['columns' => $columns, 'groups' => array_values($ids), 'reducer' => $reducer, 'method' => $method, 'confidence' => $confidence];
    }

    /** @param list<string> $matched */
    private function orderedPhoneGroups(array $matched, array $groups): array
    {
        $rank = static function (string $id) use ($groups): int {
            $label = self::normalize((string) ($groups[$id]['label'] ?? ''));
            if (str_contains($label, 'cell') || str_contains($label, 'mobile')) return 0;
            if ($label === 'phone') return 1;
            return 2;
        };
        $matched = array_values(array_unique($matched));
        usort($matched, static fn(string $a, string $b): int => $rank($a) <=> $rank($b));
        return $matched;
    }

    private function isDemographicHeader(string $header): bool
    {
        return !str_contains($header, 'count')
            && !str_contains($header, 'specify')
            && (str_contains($header, 'demographic') || str_contains($header, 'diversity')
                || str_contains($header, 'do you identify'));
    }

    private function isDemographicOtherHeader(string $header): bool
    {
        return (str_contains($header, 'please specify') || str_contains($header, 'other'))
            && (str_contains($header, 'demographic') || str_contains($header, 'identify'));
    }

    private function isHeardHeader(string $header): bool
    {
        return str_contains($header, 'how did you hear') || str_contains($header, 'how do you hear');
    }

    private function isHeardFollowupHeader(string $header): bool
    {
        if ($header === '') return false;
        foreach (['specify', 'please list', 'which organization', 'which healthcare', 'what is their name', 'name affiliation', 'name of organiz', 'publication', 'social media site'] as $needle) {
            if (str_contains($header, $needle)) return true;
        }
        return false;
    }

    /** @return array{id:string,confidence:float}|null */
    private function fuzzyGroup(array $aliases, array $groups): ?array
    {
        $scores = [];
        foreach ($groups as $id => $group) {
            if (str_starts_with($id, 'virtual-')) continue;
            $header = self::normalize((string) $group['label']);
            if ($header === '') continue;
            $best = 0.0;
            foreach ($aliases as $alias) {
                $alias = self::normalize((string) $alias);
                $longest = max(strlen($alias), strlen($header));
                if ($alias === '' || $longest < 1) continue;
                $best = max($best, 1.0 - levenshtein($alias, $header) / $longest);
            }
            $scores[$id] = $best;
        }
        arsort($scores, SORT_NUMERIC);
        $ids = array_keys($scores);
        $winner = (float) ($scores[$ids[0] ?? ''] ?? 0.0);
        $runnerUp = (float) ($scores[$ids[1] ?? ''] ?? 0.0);
        if ($winner < 0.86 || $winner - $runnerUp < 0.08 || !isset($ids[0])) return null;
        return ['id' => (string) $ids[0], 'confidence' => round($winner, 3)];
    }

    /**
     * Only header names and target labels enter this method. No row value is
     * accepted by its signature, so PII cannot be sent accidentally.
     */
    private function assisted(array $unresolved, array $groups, ProviderInterface $assistant): array
    {
        $candidates = [];
        foreach ($groups as $id => $group) {
            if (str_starts_with($id, 'virtual-')) continue;
            $candidates[$id] = bg_substr((string) $group['label'], 0, 240);
            if (count($candidates) >= 200) break;
        }
        $answer = trim($assistant->complete(
            'You match spreadsheet HEADER NAMES to database field labels. You receive no row values. '
                . 'Return only a JSON object whose keys are the requested target keys and whose values are exact candidate IDs or null. Abstain when uncertain.',
            "Targets:\n" . wp_json_encode($unresolved, JSON_PRETTY_PRINT)
                . "\nCandidate header IDs and names:\n" . wp_json_encode($candidates, JSON_PRETTY_PRINT),
            ['max_tokens' => 500]
        ));
        $answer = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $answer) ?: '';
        $decoded = json_decode($answer, true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($unresolved as $key => $_label) {
            $id = (string) ($decoded[$key] ?? '');
            if ($id !== '' && isset($groups[$id])) {
                $out[$key] = $this->entry([$id], $groups, self::targets()[$key]['reducer'], 'assisted', 0.60);
            }
        }
        return $out;
    }
}
