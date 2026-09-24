<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GatheringTree;

if (!defined('ABSPATH')) exit;

/**
 * Gathering Tree Search: fixed-rule ranked retrieval over the
 * event corpus. The grammar is the one every visitor already knows
 * from general web search without knowing they know it: terms are
 * required (implicit AND), "quoted phrases" are required exactly,
 * -term excludes, OR between two terms accepts either. Matching folds
 * case and simple plurals. Fields are weighted (title over tags over
 * location over body), and when no entry satisfies every term, the
 * nearest partial matches are returned under a notice instead of an
 * empty page. Every step is deterministic: the same query always
 * returns the same entries, which is what makes it testable in the
 * Board Testing Guide.
 */
final class Search
{
    private const WEIGHTS = ['title' => 8, 'tags' => 5, 'location' => 3, 'body' => 1];

    /**
     * @param int[] $postIds candidate posts (already scoped/filtered)
     * @return array{ids: int[], strict: bool}
     */
    public static function run(string $query, array $postIds): array
    {
        $parsed = self::parse($query);
        if (!$parsed['terms'] && !$parsed['phrases'] && !$parsed['orGroups']) {
            return ['ids' => $postIds, 'strict' => true];
        }

        $scored = [];
        $partial = [];
        foreach ($postIds as $pid) {
            $doc = self::document($pid);
            if ($doc === null) continue;
            $res = self::score($parsed, $doc);
            if ($res === null) continue;             // excluded or zero relevance
            if ($res['strict']) $scored[$pid] = $res['score'];
            else $partial[$pid] = $res['score'];
        }

        if ($scored) {
            arsort($scored);
            return ['ids' => array_keys($scored), 'strict' => true];
        }
        arsort($partial);
        return ['ids' => array_keys(array_slice($partial, 0, 40, true)), 'strict' => false];
    }

    /** @return array{terms: string[], phrases: string[], excludes: string[], orGroups: array<int, string[]>} */
    public static function parse(string $query): array
    {
        $query = trim($query);
        $phrases = [];
        $query = (string) preg_replace_callback('/"([^"]+)"/', function ($m) use (&$phrases) {
            $phrases[] = self::norm($m[1]);
            return ' ';
        }, $query);

        $rawTokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        $excludes = [];
        $orGroups = [];
        $n = count($rawTokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $rawTokens[$i];
            if (strcasecmp($tok, 'OR') === 0) continue;
            if ($tok[0] === '-' && strlen($tok) > 1) {
                $excludes[] = self::stem(self::norm(substr($tok, 1)));
                continue;
            }
            // "a OR b": both sides leave the required set, form a group.
            $isLeft = ($i + 1 < $n) && strcasecmp($rawTokens[$i + 1], 'OR') === 0;
            $isRight = ($i - 1 >= 0) && strcasecmp($rawTokens[$i - 1], 'OR') === 0;
            $stemmed = self::stem(self::norm($tok));
            if ($stemmed === '') continue;
            if ($isLeft) {
                $orGroups[] = [$stemmed];
            } elseif ($isRight && $orGroups) {
                $orGroups[count($orGroups) - 1][] = $stemmed;
            } else {
                $terms[] = $stemmed;
            }
        }
        return [
            'terms' => array_values(array_unique($terms)),
            'phrases' => array_values(array_filter($phrases)),
            'excludes' => array_values(array_unique($excludes)),
            'orGroups' => $orGroups,
        ];
    }

    /**
     * @param array{terms: string[], phrases: string[], excludes: string[], orGroups: array<int, string[]>} $q
     * @param array{title: string, tags: string, location: string, body: string} $doc
     * @return ?array{score: int, strict: bool} null when excluded or nothing matches
     */
    public static function score(array $q, array $doc): ?array
    {
        $stems = [];
        foreach ($doc as $field => $text) {
            $stems[$field] = array_map([self::class, 'stem'],
                preg_split('/[^a-z0-9]+/', self::norm($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        $whole = self::norm(implode(' ', $doc));

        foreach ($q['excludes'] as $ex) {
            foreach ($stems as $fieldStems) {
                if (in_array($ex, $fieldStems, true)) return null;
            }
        }

        $requirements = 0;
        $satisfied = 0;
        $score = 0;

        $termHit = function (string $term) use ($stems): int {
            $s = 0;
            foreach (self::WEIGHTS as $field => $w) {
                $count = count(array_keys($stems[$field], $term, true));
                $s += $count * $w;
            }
            return $s;
        };

        foreach ($q['terms'] as $term) {
            $requirements++;
            $s = $termHit($term);
            if ($s > 0) { $satisfied++; $score += $s; }
        }
        foreach ($q['orGroups'] as $group) {
            $requirements++;
            $best = 0;
            foreach ($group as $term) $best = max($best, $termHit($term));
            if ($best > 0) { $satisfied++; $score += $best; }
        }
        foreach ($q['phrases'] as $phrase) {
            $requirements++;
            if ($phrase !== '' && strpos($whole, $phrase) !== false) {
                $satisfied++;
                $score += 10;
            }
        }

        if ($satisfied === 0) return null;
        return ['score' => $score, 'strict' => $satisfied === $requirements];
    }

    /** @return ?array{title: string, tags: string, location: string, body: string} */
    private static function document(int $pid): ?array
    {
        $post = get_post($pid);
        if (!$post) return null;
        $tagNames = [];
        $taxonomies = ['bg_tag'];
        if (class_exists('\\BosqueGrande\\Modules\\Locations\\Taxonomy')) {
            $taxonomies[] = \BosqueGrande\Modules\Locations\Taxonomy::taxonomy();
        }
        foreach (array_unique($taxonomies) as $taxonomy) {
            $terms = get_the_terms($pid, $taxonomy);
            if (is_array($terms)) {
                foreach ($terms as $t) $tagNames[] = (string) $t->name;
            }
        }
        return [
            'title' => (string) $post->post_title,
            'tags' => implode(' ', $tagNames),
            'location' => (string) get_post_meta($pid, '_bg_location_text', true),
            'body' => wp_strip_all_tags((string) $post->post_content),
        ];
    }

    private static function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /** Plural folding, deliberately shallow: potlucks/potluck, parties/party. */
    public static function stem(string $token): string
    {
        if (strlen($token) > 4 && substr($token, -4) === 'sses') return substr($token, 0, -2);
        if (strlen($token) > 4 && substr($token, -3) === 'ies') return substr($token, 0, -3) . 'y';
        if (strlen($token) > 3 && substr($token, -2) === 'es' && !in_array(substr($token, -3, 1), ['s'], true)) {
            $token = substr($token, 0, -1); // dances -> dance; leaves "classes" -> "classe" alone below
        }
        if (strlen($token) > 3 && substr($token, -1) === 's' && substr($token, -2) !== 'ss') {
            return substr($token, 0, -1);
        }
        return $token;
    }
}
