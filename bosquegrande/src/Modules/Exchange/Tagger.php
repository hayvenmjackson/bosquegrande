<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

if (!defined('ABSPATH')) exit;

/**
 * Rule-based event tagging with recorded evidence. Three layers, in order:
 *
 *   1. Event code prefixes. A region or program encoded in the source
 *      system's event code outranks any text match.
 *   2. Place names. A place found in the event text assigns its region.
 *      Places from two different regions are a conflict and assign
 *      nothing, because a wrong region is worse than none.
 *   3. Keywords. Whole-word matches assign interests; an event may
 *      earn several.
 *
 * All three tables are editable on the Tagging Rules screen. The optional
 * assistant runs only after the rules and may choose only from the site's
 * vocabulary. Automatic tags carry their provenance; tags applied by staff
 * are never removed or replaced.
 */
final class Tagger
{
    private const RULES_OPT = 'bg_tagging_rules';
    public const EVIDENCE_META = '_bg_tag_evidence';
    public const AUTO_META = '_bg_auto_tag_slugs';

    /** @return array{code_map: array<string, string>, gazetteer: array<string, string>, keywords: array<string, string>} */
    public static function rules(): array
    {
        $defaults = self::defaults();
        $saved = get_option(self::RULES_OPT, null);
        if (!is_array($saved)) return $defaults;
        // Once saved, the saved tables are authoritative, so a deleted default
        // line stays deleted.
        foreach (['code_map', 'gazetteer', 'keywords'] as $section) {
            if (is_array($saved[$section] ?? null)) $defaults[$section] = $saved[$section];
        }
        return $defaults;
    }

    /** @param array{code_map: array<string, string>, gazetteer: array<string, string>, keywords: array<string, string>} $rules */
    public static function saveRules(array $rules): void
    {
        update_option(self::RULES_OPT, $rules, false);
    }

    /**
     * Starter tables. Place names and event codes are specific to each
     * organization, so they start empty. The keyword list covers the
     * starter interest vocabulary and is ignored for any slug the site
     * has removed from its vocabulary.
     * @return array{code_map: array<string, string>, gazetteer: array<string, string>, keywords: array<string, string>}
     */
    public static function defaults(): array
    {
        return [
            'code_map' => [],
            'gazetteer' => [],
            'keywords' => [
                'potluck' => 'social',
                'dinner' => 'social',
                'brunch' => 'social',
                'lunch' => 'social',
                'mixer' => 'social',
                'social' => 'social',
                'coffee' => 'social',
                'trivia' => 'social',
                'game night' => 'social',
                'meetup' => 'social',
                'networking' => 'social',
                'party' => 'social',
                'workshop' => 'learning',
                'class' => 'learning',
                'lecture' => 'learning',
                'talk' => 'learning',
                'seminar' => 'learning',
                'training' => 'learning',
                'webinar' => 'learning',
                'panel' => 'learning',
                'concert' => 'arts-culture',
                'gallery' => 'arts-culture',
                'museum' => 'arts-culture',
                'film' => 'arts-culture',
                'movie' => 'arts-culture',
                'theater' => 'arts-culture',
                'theatre' => 'arts-culture',
                'music' => 'arts-culture',
                'book club' => 'arts-culture',
                'dance' => 'arts-culture',
                'poetry' => 'arts-culture',
                'craft' => 'arts-culture',
                'hike' => 'recreation',
                'hiking' => 'recreation',
                'walk' => 'recreation',
                'yoga' => 'recreation',
                'swim' => 'recreation',
                'bike' => 'recreation',
                'kayak' => 'recreation',
                'paddle' => 'recreation',
                'ski' => 'recreation',
                'outdoor' => 'recreation',
                'outdoors' => 'recreation',
                'fitness' => 'recreation',
                'volunteer' => 'volunteering',
                'volunteers' => 'volunteering',
                'cleanup' => 'volunteering',
                'service day' => 'volunteering',
                'fundraiser' => 'fundraising',
                'benefit' => 'fundraising',
                'gala' => 'fundraising',
                'auction' => 'fundraising',
            ],
        ];
    }

    /**
     * Decide assignments for one event. Pure: no writes here.
     * @param array{title: string, code: string, location: string, body: string} $doc
     * @return array{tags: array<int, string>, evidence: array<string, string>}
     */
    public static function decide(array $doc): array
    {
        $rules = self::rules();
        $tags = [];
        $evidence = [];

        $code = strtolower(trim($doc['code']));
        $text = strtolower(' ' . $doc['title'] . ' ' . $doc['location'] . ' ' . $doc['body'] . ' ');
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        // Layer 1: event code prefixes (outrank any text match).
        $region = '';
        if ($code !== '') {
            foreach ($rules['code_map'] as $prefix => $slug) {
                $prefix = strtolower(trim($prefix));
                if ($prefix !== '' && strpos($code, $prefix) === 0) {
                    $region = $slug;
                    $evidence[$slug] = 'event code begins with "' . $prefix . '"';
                    break;
                }
            }
        }

        // Layer 2: gazetteer, only when layer 1 was silent.
        if ($region === '') {
            $hits = [];
            foreach ($rules['gazetteer'] as $town => $slug) {
                $town = strtolower(trim($town));
                if ($town !== '' && strpos($text, ' ' . $town . ' ') !== false) {
                    $hits[$slug] = $town;
                }
            }
            if (count($hits) === 1) {
                $region = (string) array_key_first($hits);
                $evidence[$region] = 'the town "' . $hits[$region] . '" appears in the event';
            } elseif (count($hits) > 1) {
                $evidence['_conflict'] = 'towns from several regions appear (' . implode(', ', array_values($hits)) . '); no region assigned';
            }
        }
        if ($region !== '') $tags[] = $region;

        // Layer 3: keywords; an event may earn several interests.
        foreach ($rules['keywords'] as $word => $slug) {
            $word = strtolower(trim($word));
            if ($word === '' || in_array($slug, $tags, true)) continue;
            if (strpos($text, ' ' . $word . ' ') !== false) {
                $tags[] = $slug;
                $evidence[$slug] = 'the word "' . $word . '" appears in the event';
            }
        }

        return ['tags' => $tags, 'evidence' => $evidence];
    }

    /** @return array{action: string, tags: array<int, string>, evidence: array<string, string>} */
    public static function apply(int $postId, array $doc, bool $dry, bool $allowIntelligence = true): array
    {
        $locationTax = class_exists('\\BosqueGrande\\Modules\\Locations\\Taxonomy')
            ? \BosqueGrande\Modules\Locations\Taxonomy::taxonomy()
            : 'bg_tag';
        $locationTags = \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
        $interestTags = \BosqueGrande\Modules\GatheringTree\Vocabulary::interests();
        $locationSlugs = array_keys($locationTags);
        $interestSlugs = array_keys($interestTags);
        $approved = array_values(array_unique(array_merge($locationSlugs, $interestSlugs)));

        $existingInterests = self::termSlugs($postId, 'bg_tag');
        $existingLocations = $locationTax === 'bg_tag'
            ? array_values(array_intersect($existingInterests, $locationSlugs))
            : self::termSlugs($postId, $locationTax);
        $existingAll = array_values(array_unique(array_merge($existingInterests, $existingLocations)));

        $automatic = get_post_meta($postId, self::AUTO_META, true);
        $automatic = is_array($automatic)
            ? array_values(array_intersect(array_map('sanitize_key', $automatic), $existingAll))
            : [];
        $manualInterests = array_values(array_diff($existingInterests, $automatic));
        $manualLocations = array_values(array_diff($existingLocations, $automatic));
        $hasManualInterest = (bool) array_intersect($manualInterests, $interestSlugs);
        $hasManualLocation = (bool) array_intersect($manualLocations, $locationSlugs);

        $res = self::decide($doc);
        $res['tags'] = array_values(array_intersect($res['tags'], $approved));
        $res['evidence'] = array_filter(
            $res['evidence'],
            static fn(string $key): bool => str_starts_with($key, '_') || in_array($key, $approved, true),
            ARRAY_FILTER_USE_KEY
        );
        $hasRuleLocation = (bool) array_intersect($res['tags'], $locationSlugs);
        $hasRuleInterest = (bool) array_intersect($res['tags'], $interestSlugs);
        if ($allowIntelligence && ((!$hasRuleLocation && !$hasManualLocation)
            || (!$hasRuleInterest && !$hasManualInterest)
        )) {
            $res = self::withIntelligentFallback(
                $doc,
                $res,
                !$hasRuleLocation && !$hasManualLocation,
                !$hasRuleInterest && !$hasManualInterest,
                $locationTags,
                $interestTags
            );
        }
        $locations = $hasManualLocation
            ? []
            : array_slice(array_values(array_intersect($res['tags'], $locationSlugs)), 0, 1);
        $interests = $hasManualInterest
            ? []
            : array_values(array_intersect($res['tags'], $interestSlugs));
        $newAutomatic = array_values(array_unique(array_merge($locations, $interests)));

        if (!$newAutomatic && !$automatic) {
            return [
                'action' => $existingAll ? 'kept' : 'untagged',
                'tags' => [],
                'evidence' => $res['evidence'],
            ];
        }
        if (!$dry) {
            if ($locationTax === 'bg_tag') {
                $kept = array_values(array_unique(array_merge(
                    $manualInterests,
                    $manualLocations,
                    $newAutomatic
                )));
                wp_set_object_terms($postId, $kept, 'bg_tag', false);
            } else {
                wp_set_object_terms(
                    $postId,
                    array_values(array_unique(array_merge($manualLocations, $locations))),
                    $locationTax,
                    false
                );
                wp_set_object_terms(
                    $postId,
                    array_values(array_unique(array_merge($manualInterests, $interests))),
                    'bg_tag',
                    false
                );
            }
            update_post_meta($postId, self::AUTO_META, $newAutomatic);
            update_post_meta($postId, self::EVIDENCE_META, $res['evidence']);
        }
        return [
            'action' => $newAutomatic ? 'tagged' : 'kept',
            'tags' => $newAutomatic,
            'evidence' => $res['evidence'],
        ];
    }

    /**
     * @param array{title:string,code:string,location:string,body:string} $doc
     * @param array{tags:list<string>,evidence:array<string,string>} $res
     * @param array<string,string> $locationTags
     * @param array<string,string> $interestTags
     * @return array{tags:list<string>,evidence:array<string,string>}
     */
    private static function withIntelligentFallback(
        array $doc,
        array $res,
        bool $needLocation,
        bool $needInterests,
        array $locationTags,
        array $interestTags
    ): array {
        try {
            $container = \BosqueGrande\Core\Plugin::instance()->container();
            $settings = $container->get('settings');
            $enabled = \BosqueGrande\Modules\Intelligence\IntelligenceModule::featureEnabled($settings, 'event_tagging')
                || \BosqueGrande\Modules\Intelligence\IntelligenceModule::featureEnabled($settings, 'event_copy');
            if (!$enabled) return $res;
            $classified = \BosqueGrande\Modules\Intelligence\Helpers::classifyEventTags(
                $container->get('ai'),
                $doc,
                $needLocation ? $locationTags : [],
                $needInterests ? $interestTags : []
            );
        } catch (\Throwable $e) {
            return $res;
        }
        $confidence = (float) ($classified['confidence'] ?? 0.0);
        $label = 'assistant suggestion from the vocabulary, confidence ' . number_format($confidence, 2);
        $location = sanitize_key((string) ($classified['location'] ?? ''));
        if ($needLocation && array_key_exists($location, $locationTags)) {
            $res['tags'][] = $location;
            $res['evidence'][$location] = $label;
        }
        if ($needInterests) {
            foreach ((array) ($classified['interests'] ?? []) as $slug) {
                $slug = sanitize_key((string) $slug);
                if (!array_key_exists($slug, $interestTags)) continue;
                $res['tags'][] = $slug;
                $res['evidence'][$slug] = $label;
            }
        }
        $res['tags'] = array_values(array_unique($res['tags']));
        return $res;
    }

    /** @return list<string> */
    private static function termSlugs(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms)) return [];
        $slugs = [];
        foreach ($terms as $term) {
            if (is_object($term) && isset($term->slug)) {
                $slug = sanitize_key((string) $term->slug);
                if ($slug !== '') $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    /** @param int $postId @return array{title: string, code: string, location: string, body: string} */
    public static function documentFor(int $postId): array
    {
        $post = get_post($postId);
        return [
            'title' => $post ? (string) $post->post_title : '',
            'code' => (string) get_post_meta($postId, '_bg_src_code', true),
            'location' => (string) get_post_meta($postId, '_bg_location_text', true)
                . ' ' . (string) get_post_meta($postId, '_bg_summary', true),
            'body' => $post ? wp_strip_all_tags((string) $post->post_content) : '',
        ];
    }
}
