<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GatheringTree;

if (!defined('ABSPATH')) exit;

/**
 * The event tag vocabulary: regions (where) and interests (what). Both lists
 * are edited on the Tagging Rules screen and stored as slug => label maps.
 * Calendar filters, automatic tagging, organizer tools, and reports read
 * only from here.
 */
final class Vocabulary
{
    private const OPTION = 'bg_event_vocabulary';

    /** A neutral starter set so a new calendar has working interest filters. */
    private const DEFAULT_INTERESTS = [
        'social' => 'Social',
        'learning' => 'Learning',
        'arts-culture' => 'Arts & Culture',
        'recreation' => 'Recreation',
        'volunteering' => 'Volunteering',
        'fundraising' => 'Fundraising',
    ];

    /** @return array<string, string> */
    public static function regions(): array
    {
        return self::stored()['regions'];
    }

    /** @return array<string, string> */
    public static function interests(): array
    {
        return self::stored()['interests'];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::regions() + self::interests();
    }

    public static function isRegion(string $slug): bool
    {
        return isset(self::regions()[$slug]);
    }

    /**
     * Parse "Label" or "slug = Label" lines into a slug => label map.
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_contains($line, '=')) {
                [$slug, $label] = array_map('trim', explode('=', $line, 2));
            } else {
                $slug = '';
                $label = $line;
            }
            $label = sanitize_text_field($label);
            $slug = sanitize_title($slug !== '' ? $slug : $label);
            if ($slug === '' || $label === '') continue;
            $out[$slug] = $label;
        }
        return array_slice($out, 0, 60, true);
    }

    /** @param array<string, string> $map */
    public static function format(array $map): string
    {
        $lines = [];
        foreach ($map as $slug => $label) $lines[] = $slug . ' = ' . $label;
        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $regions
     * @param array<string, string> $interests
     */
    public static function save(array $regions, array $interests): void
    {
        // A slug belongs to one axis only; regions win a collision.
        $interests = array_diff_key($interests, $regions);
        update_option(self::OPTION, ['regions' => $regions, 'interests' => $interests], false);
        self::ensureTerms();
    }

    /** Create any missing terms and keep labels in step with the vocabulary. */
    public static function ensureTerms(): void
    {
        if (!taxonomy_exists('bg_tag')) return;
        foreach (self::all() as $slug => $label) {
            $term = get_term_by('slug', $slug, 'bg_tag');
            if (!$term) {
                wp_insert_term($label, 'bg_tag', ['slug' => $slug]);
            } elseif (!is_wp_error($term) && $term->name !== $label) {
                wp_update_term((int) $term->term_id, 'bg_tag', ['name' => $label]);
            }
        }
    }

    /** @return array{regions: array<string, string>, interests: array<string, string>} */
    private static function stored(): array
    {
        $saved = get_option(self::OPTION, null);
        if (!is_array($saved)) {
            return ['regions' => [], 'interests' => self::DEFAULT_INTERESTS];
        }
        $clean = static function ($map): array {
            $out = [];
            foreach ((array) $map as $slug => $label) {
                $slug = sanitize_title((string) $slug);
                if ($slug !== '' && is_scalar($label) && (string) $label !== '') $out[$slug] = (string) $label;
            }
            return $out;
        };
        return ['regions' => $clean($saved['regions'] ?? []), 'interests' => $clean($saved['interests'] ?? [])];
    }
}
