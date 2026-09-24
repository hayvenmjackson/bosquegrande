<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Lgl;

if (!defined('ABSPATH')) exit;

/**
 * Category name matcher. Live LGL accounts name constituent
 * categories inconsistently ("Demographics / Diversity", "Age Rnage",
 * house styles and typos alike), and the old exact-equality match
 * silently returned nothing, which surfaced as demographic answers
 * never prefilling. Matching now runs in five tiers; the first tier
 * with a hit wins, and reads and writes share this one matcher so a
 * fuzzy read can never coexist with an exact write creating a
 * duplicate category:
 *
 *   1. exact category name (case-insensitive)
 *   2. exact category key (case-insensitive, sanitized)
 *   3. normalized containment: letters and digits only, either
 *      direction, so "Diversity" finds "Demographics / Diversity"
 *   4. Levenshtein similarity on the normalized names, the same
 *      measure the Duplicate Finder uses, at 66% or better with the
 *      single best candidate winning
 *   5. assisted automation classification via the bg_lgl_category_match filter, which
 *      the Intelligence module hooks when its feature is enabled.
 *      Only category NAMES ever reach a model; keyword values are
 *      personal data and never leave the site. Verdicts are cached
 *      permanently per schema, so a new account layout is paid for
 *      once (abstentions included).
 */
final class CategoryMatcher
{
    /** Tier 4 floor: 66% similarity, the Duplicate Finder's measure. */
    private const MIN_SIMILARITY = 66;

    /**
     * Resolve the account category matching a configured target name.
     *
     * @param array<int, array<string, mixed>> $categories LGL category
     *        objects (name, key, keywords ...), shape per the API.
     * @return array<string, mixed>|null The matched category, or null.
     */
    public static function match(array $categories, string $target): ?array
    {
        $categories = array_values(array_filter($categories, 'is_array'));
        if (!$categories || trim($target) === '') return null;

        $wantName = $target;
        $wantKey = sanitize_key($target);
        $wantNorm = self::norm($target);

        // Tier 1: exact name.
        foreach ($categories as $cat) {
            if (strcasecmp((string) ($cat['name'] ?? ''), $wantName) === 0) return $cat;
        }
        // Tier 2: exact key.
        foreach ($categories as $cat) {
            $key = (string) ($cat['key'] ?? '');
            if ($key !== '' && strcasecmp($key, $wantKey) === 0) return $cat;
        }
        // Tier 3: normalized containment (either direction).
        if (strlen($wantNorm) >= 3) {
            foreach ($categories as $cat) {
                $catNorm = self::norm((string) ($cat['name'] ?? ''));
                if ($catNorm !== ''
                    && (strpos($catNorm, $wantNorm) !== false || strpos($wantNorm, $catNorm) !== false)
                ) {
                    return $cat;
                }
            }
        }
        // Tier 4: Levenshtein similarity, best single candidate wins.
        if (strlen($wantNorm) >= 3) {
            $best = null;
            $bestScore = self::MIN_SIMILARITY - 1;
            foreach ($categories as $cat) {
                $score = self::similarity($wantNorm, self::norm((string) ($cat['name'] ?? '')));
                if ($score > $bestScore) {
                    $best = $cat;
                    $bestScore = $score;
                }
            }
            if ($best !== null) return $best;
        }
        // Tier 5: assisted automation classification, when the Intelligence module has
        // hooked the seam. Receives names only; returns a name or ''.
        $names = array_map(static fn(array $c) => (string) ($c['name'] ?? ''), $categories);
        $picked = (string) apply_filters('bg_lgl_category_match', '', $target, $names);
        if ($picked !== '') {
            foreach ($categories as $cat) {
                if (strcasecmp((string) ($cat['name'] ?? ''), $picked) === 0) return $cat;
            }
        }
        return null;
    }

    /** Keyword name list of the matched category, or []. @return array<int, string> */
    public static function keywords(array $categories, string $target): array
    {
        $cat = self::match($categories, $target);
        if (!$cat) return [];
        $out = [];
        foreach (($cat['keywords'] ?? []) as $kw) {
            $out[] = is_array($kw) ? (string) ($kw['name'] ?? '') : (string) $kw;
        }
        return $out;
    }

    /**
     * Raw value of the custom ATTRIBUTE matching a target.
     * LGL holds this data in two different structures depending on how
     * the account was built: categories (keyword lists) and custom
     * attributes (one free-text value under a name the account chose,
     * however long or editorial that name is). Attributes carry name
     * and key like categories do, so the same five tiers apply; the
     * containment tier alone resolves names like "DIVERSITY: do you
     * identify as any of the following..." from a configured
     * "Diversity". Only the attribute NAME is ever matched or sent to
     * the optional assisted automation tier; the value is personal data and stays here.
     *
     * @param array<int, array<string, mixed>> $attrs custom_attrs objects.
     */
    public static function attrValue(array $attrs, string $target): string
    {
        $attr = self::match($attrs, $target);
        return $attr ? trim((string) ($attr['value'] ?? '')) : '';
    }

    /** The matched custom attribute itself (id, key, name), or null. */
    public static function matchAttr(array $attrs, string $target): ?array
    {
        return self::match($attrs, $target);
    }

    /** Letters and digits only, lowercased. */
    public static function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    /** 0-100 similarity on normalized strings (DedupFinder's measure). */
    public static function similarity(string $a, string $b): int
    {
        if ($a === '' || $b === '') return 0;
        $max = max(strlen($a), strlen($b));
        return (int) round(100 * (1 - levenshtein(substr($a, 0, 200), substr($b, 0, 200)) / $max));
    }
}
