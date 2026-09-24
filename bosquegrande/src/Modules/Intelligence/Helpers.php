<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;


if (!defined('ABSPATH')) exit;

/**
 * Stateless helpers for assisted features. Every method
 * takes the provider, degrades to a null result when unconfigured, and
 * follows the two containment rules: suggestions never save themselves
 * (a person applies them), and prompts carry no PII beyond what the
 * feature strictly needs (the narrative sends first name plus timeline
 * summaries, which are non-PII by the ActivityLog convention; never
 * email, phone, address, or custom field values).
 */
final class Helpers
{
    /** @return list<string> Suggested tags, or [] when unavailable. */
    public static function suggestTags(ProviderInterface $ai, string $title, string $description, array $existingTags): array
    {
        if (!$ai->isConfigured()) return [];
        $reply = $ai->complete(
            'You tag community events. Reply with ONLY a JSON array of 2 to 5 short tag strings, no prose, '
            . 'no code fences. Prefer tags from the existing list when they fit; invent at most one new tag.',
            "Existing tags: " . ($existingTags ? implode(', ', $existingTags) : '(none yet)')
            . "\nEvent title: {$title}\nEvent description: {$description}",
            ['max_tokens' => 120]
        );
        $decoded = json_decode(trim(str_replace(['```json', '```'], '', $reply)), true);
        if (!is_array($decoded)) return [];
        $tags = [];
        foreach ($decoded as $tag) {
            if (is_string($tag) && trim($tag) !== '') $tags[] = bg_substr(trim($tag), 0, 48);
        }
        return array_slice(array_values(array_unique($tags)), 0, 5);
    }

    /**
     * Constrained event classification for the transfer pipeline. The model
     * may select only the fixed, client-approved slugs and must abstain when
     * confidence is below the conservative threshold. The caller still owns
     * all writes and validates the returned vocabulary again.
     *
     * @param array{title:string,code:string,location:string,body:string} $doc
     * @param array<string,string> $locationTags slug => display label
     * @param array<string,string> $interestTags slug => display label
     * @return array{location:string,interests:list<string>,confidence:float}
     */
    public static function classifyEventTags(
        ProviderInterface $ai,
        array $doc,
        array $locationTags,
        array $interestTags
    ): array {
        $blank = ['location' => '', 'interests' => [], 'confidence' => 0.0];
        if (!$ai->isConfigured() || (!$locationTags && !$interestTags)) return $blank;

        $reply = $ai->complete(
            'Classify one community event using ONLY the approved slugs supplied. '
            . 'Reply with one JSON object: {"location":"slug-or-empty","interests":["slug"],"confidence":0.0}. '
            . 'Choose at most one location and at most three interests. Never invent a slug. '
            . 'If the event text does not support a choice, leave that field empty. No prose or code fences.',
            "Approved locations:\n" . wp_json_encode($locationTags)
            . "\nApproved interests:\n" . wp_json_encode($interestTags)
            . "\nEvent code: " . bg_substr((string) ($doc['code'] ?? ''), 0, 120)
            . "\nEvent title: " . bg_substr((string) ($doc['title'] ?? ''), 0, 300)
            . "\nEvent location: " . bg_substr((string) ($doc['location'] ?? ''), 0, 500)
            . "\nEvent text: " . bg_substr(wp_strip_all_tags((string) ($doc['body'] ?? '')), 0, 2400),
            ['max_tokens' => 180]
        );
        $decoded = json_decode(trim(str_replace(['```json', '```'], '', $reply)), true);
        if (!is_array($decoded)) return $blank;
        $confidence = is_numeric($decoded['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $decoded['confidence']))
            : 0.0;
        if ($confidence < 0.78) return $blank;

        $location = sanitize_key((string) ($decoded['location'] ?? ''));
        if (!array_key_exists($location, $locationTags)) $location = '';
        $interests = [];
        foreach ((array) ($decoded['interests'] ?? []) as $slug) {
            $slug = sanitize_key((string) $slug);
            if (array_key_exists($slug, $interestTags)) $interests[] = $slug;
        }
        return [
            'location' => $location,
            'interests' => array_slice(array_values(array_unique($interests)), 0, 3),
            'confidence' => $confidence,
        ];
    }

    /**
     * Natural-language calendar search translated to structured filters.
     * @return array{keywords: list<string>, tags: list<string>, from: string, to: string}|null
     */
    public static function parseSearch(ProviderInterface $ai, string $query, array $knownTags): ?array
    {
        if (!$ai->isConfigured()) return null;
        $reply = $ai->complete(
            'You turn a person\'s event-search phrase into filters. Reply with ONLY a JSON object '
            . '{"keywords": [..], "tags": [..], "from": "YYYY-MM-DD" or "", "to": "YYYY-MM-DD" or ""}. '
            . 'No prose, no code fences. Tags must come from the known list. Today is ' . gmdate('Y-m-d') . '.',
            'Known tags: ' . ($knownTags ? implode(', ', $knownTags) : '(none)') . "\nSearch phrase: {$query}",
            ['max_tokens' => 160]
        );
        $decoded = json_decode(trim(str_replace(['```json', '```'], '', $reply)), true);
        if (!is_array($decoded)) return null;
        $clean = static fn($list) => array_values(array_filter(array_map(
            static fn($v) => is_string($v) ? trim($v) : '', is_array($list) ? $list : []
        )));
        $date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : '';
        return [
            'keywords' => $clean($decoded['keywords'] ?? []),
            'tags' => $clean($decoded['tags'] ?? []),
            'from' => $date($decoded['from'] ?? ''),
            'to' => $date($decoded['to'] ?? ''),
        ];
    }

    /**
     * The longitudinal constituent narrative. Input is deliberately
     * thin: first name and the timeline lines only.
     * @param list<object> $timeline ActivityLog rows.
     */
    public static function narrative(ProviderInterface $ai, string $firstName, array $timeline): string
    {
        if (!$ai->isConfigured()) return '';
        $lines = [];
        foreach (array_slice($timeline, 0, 60) as $row) {
            $lines[] = substr((string) $row->created_at, 0, 10) . ' ' . $row->kind . ': ' . $row->summary;
        }
        if (!$lines) return '';
        return trim($ai->complete(
            'You summarize a supporter\'s involvement history for a small organization\'s staff. One short '
            . 'paragraph: how long they have been involved, what they show up for, giving pattern if any, and '
            . 'anything notable about the arc. Warm, factual, no flattery, no guesses beyond the entries.',
            "Supporter first name: {$firstName}\nTimeline (newest first):\n" . implode("\n", $lines),
            ['max_tokens' => 260]
        ));
    }

    /**
     * A merge recommendation for one ambiguous duplicate pair. Name
     * and city only; the human clicks, the model only advises.
     * @param array<string, string> $a @param array<string, string> $b
     */
    public static function dedupAdvice(ProviderInterface $ai, array $a, array $b, string $reason): string
    {
        if (!$ai->isConfigured()) return '';
        $shape = static fn(array $r): string => sprintf('name "%s %s", city "%s", record created %s',
            $r['first_name'], $r['last_name'], $r['city'], substr($r['created_at'] ?? '', 0, 10));
        return trim($ai->complete(
            'Two person records might be duplicates. In ONE sentence, say whether they look like the same '
            . 'person and why, or what would settle it. You cannot merge anything; a person decides.',
            'Flagged because: ' . $reason . "\nRecord A: " . $shape($a) . "\nRecord B: " . $shape($b),
            ['max_tokens' => 80]
        ));
    }
}
