<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Modules\Lgl\CategoryMatcher;

if (!defined('ABSPATH')) exit;

/**
 * Historical event participation imported from LGL constituent data.
 *
 * LGL accounts use house labels for this rollup. Match known labels first,
 * then use the shared tolerant/assisted schema-name matcher. Only field names
 * enter that matcher; event values remain on this WordPress installation.
 */
final class ParticipationHistory
{
    public const LABEL = 'Past Events';

    /** @return list<string> */
    public static function sourceNames(): array
    {
        return [
            'Past Events',
            'Past Event Attendance',
            'Attended Events List',
            'Attended Events',
            'Events Attended',
            'Event Attendance',
            'Event History',
        ];
    }

    /**
     * Read the account's participation rollup from either a custom attribute
     * or a constituent category. Category keywords are joined in LGL's normal
     * semicolon-separated round-trip format.
     */
    public static function remoteValue(array $remote): string
    {
        $attrs = array_values(array_filter((array) ($remote['custom_attrs'] ?? []), 'is_array'));
        $categories = array_values(array_filter((array) ($remote['categories'] ?? []), 'is_array'));

        $attr = self::matchKnownSource($attrs);
        if ($attr) {
            $value = trim((string) ($attr['value'] ?? $attr['description'] ?? ''));
            if ($value !== '') return $value;
        }

        $category = self::matchKnownSource($categories);
        $categoryValue = self::categoryValue($category);
        if ($categoryValue !== '') return $categoryValue;

        // No known house label was present. Use the shared fuzzy/assisted
        // matcher only now, after deterministic matches in both containers.
        $attr = self::assistedMatch($attrs);
        if ($attr) {
            $value = trim((string) ($attr['value'] ?? $attr['description'] ?? ''));
            if ($value !== '') return $value;
        }
        return self::categoryValue(self::assistedMatch($categories));
    }

    /**
     * Split one LGL rollup without breaking commas inside event titles. When
     * LGL's documented semicolon/newline format is present, commas are text;
     * comma splitting remains as a compatibility fallback for older imports.
     *
     * @return list<string>
     */
    public static function events(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [];
        $parts = preg_match('/[;\r\n]/', $value)
            ? (preg_split('/[;\r\n]+/', $value) ?: [])
            : (preg_split('/,+/', $value) ?: []);
        $out = [];
        foreach ($parts as $part) {
            $part = trim(wp_strip_all_tags(html_entity_decode((string) $part, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $key = self::eventKey($part);
            if ($key === '' || in_array($key, [
                'yes', 'no', 'none', 'na', 'notapplicable', 'never',
                'false', 'true', 'unknown', '0',
            ], true)) continue;
            $out[$key] = $part;
        }
        return array_values($out);
    }

    /** A comparison key for deduplicating an imported title. */
    public static function eventKey(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower(remove_accents(trim($value)));
        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /** @return list<int> Every local field capable of holding this rollup. */
    public static function fieldIds(ConstituentStore $store): array
    {
        $ids = [];
        foreach ($store->fieldDefinitions() as $definition) {
            if (self::isField(
                (string) ($definition->field_key ?? ''),
                (string) ($definition->label ?? '')
            )) {
                $ids[] = (int) $definition->id;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function isField(string $key, string $label): bool
    {
        $key = self::schemaKey($key);
        $label = self::schemaKey($label);
        if ($key === self::schemaKey(CoreFields::PAST_EVENTS)) return true;
        foreach (self::sourceNames() as $name) {
            $wanted = self::schemaKey($name);
            if ($key === $wanted || $label === $wanted) return true;
        }
        return false;
    }

    /** @param list<array<string, mixed>> $candidates */
    private static function matchKnownSource(array $candidates): ?array
    {
        if (!$candidates) return null;
        $wanted = array_map([self::class, 'schemaKey'], self::sourceNames());

        // Known account labels are deterministic and take priority over a
        // fuzzy judgment. Accept both API name and key/category_name shapes.
        foreach ($candidates as $candidate) {
            foreach (['name', 'category_name', 'key'] as $field) {
                $candidateKey = self::schemaKey((string) ($candidate[$field] ?? ''));
                if ($candidateKey !== '' && in_array($candidateKey, $wanted, true)) return $candidate;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $candidates */
    private static function assistedMatch(array $candidates): ?array
    {
        if (!$candidates) return null;
        $named = array_map(static function (array $candidate): array {
            if (empty($candidate['name']) && !empty($candidate['category_name'])) {
                $candidate['name'] = (string) $candidate['category_name'];
            }
            return $candidate;
        }, $candidates);
        // Values are deliberately absent from CategoryMatcher's decision.
        return CategoryMatcher::match($named, self::LABEL);
    }

    private static function categoryValue(?array $category): string
    {
        if (!$category) return '';
        $parts = [];
        foreach ((array) ($category['keywords'] ?? []) as $keyword) {
            $part = trim((string) (is_array($keyword) ? ($keyword['name'] ?? '') : $keyword));
            if ($part !== '') $parts[] = $part;
        }
        return implode('; ', $parts);
    }

    private static function schemaKey(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($value)));
    }
}
