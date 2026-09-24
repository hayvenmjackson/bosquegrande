<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GrantReporting;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;

if (!defined('ABSPATH')) exit;

/**
 * Staff-managed grant labels with optional automatic assignment rules.
 *
 * Grant tags live in their own taxonomy and may overlap. Each tag can carry
 * simple rules (a date window, event tags, and title keywords) edited on
 * the Grant Tags screen. Automatic assignment only appends terms: it never
 * replaces a staff choice, and a tag staff removed stays removed.
 */
final class GrantTags
{
    public const TAXONOMY = 'bg_grant_tag';

    private const RULES_META = '_bg_grant_rules';
    private const BACKFILL_OPTION = 'bg_grant_tags_backfill_ruleset';
    private const BACKFILL_LOCK = 'bg_grant_tags_backfill_lock';
    private const BACKFILL_ACTION = 'bg_grant_tags_backfill_batch';
    private const BACKFILL_RETRY_OPTION = 'bg_grant_tags_backfill_retry_count';
    private const PROCESSED_META = '_bg_grant_auto_ruleset';
    private const BATCH_SIZE = 75;
    private const PROVENANCE_META = '_bg_grant_auto_provenance';
    private const SUPPRESSED_META = '_bg_grant_auto_suppressed';

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerTaxonomy'], 6);
        add_action(self::TAXONOMY . '_add_form_fields', [self::class, 'renderAddRuleFields']);
        add_action(self::TAXONOMY . '_edit_form_fields', [self::class, 'renderEditRuleFields']);
        add_action('created_' . self::TAXONOMY, [self::class, 'saveRuleFields']);
        add_action('edited_' . self::TAXONOMY, [self::class, 'saveRuleFields']);
        add_action('admin_init', [self::class, 'maybeBackfill'], 30);
        add_action(self::BACKFILL_ACTION, [self::class, 'runBackfillBatch']);
        add_action('save_post_bg_event', [self::class, 'assignOnSave'], 100, 3);
        add_action('added_post_meta', [self::class, 'assignWhenDated'], 20, 4);
        add_action('updated_post_meta', [self::class, 'assignWhenDated'], 20, 4);
        add_action('set_object_terms', [self::class, 'assignWhenEventTagsChange'], 20, 6);
    }

    public static function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, ['bg_event'], [
            'labels' => [
                'name' => 'Grant Tags',
                'singular_name' => 'Grant Tag',
                'search_items' => 'Search Grant Tags',
                'all_items' => 'All Grant Tags',
                'edit_item' => 'Edit Grant Tag',
                'update_item' => 'Update Grant Tag',
                'add_new_item' => 'Add New Grant Tag',
                'new_item_name' => 'New Grant Tag Name',
                'menu_name' => 'Grant Tags',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'rewrite' => false,
        ]);

        register_post_meta('bg_event', self::PROVENANCE_META, [
            'type' => 'array',
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'grant_tag' => ['type' => 'string'],
                            'ruleset' => ['type' => 'string'],
                            'source' => ['type' => 'string'],
                            'start_year' => ['type' => 'integer'],
                            'assigned_at' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'auth_callback' => static function ($allowed, $metaKey, $postId): bool {
                unset($allowed, $metaKey);
                return current_user_can('edit_post', (int) $postId);
            },
        ]);
    }

    /**
     * @return array{from:string,to:string,tags:list<string>,keywords:list<string>}
     */
    public static function rulesFor(int $termId): array
    {
        $raw = get_term_meta($termId, self::RULES_META, true);
        $raw = is_array($raw) ? $raw : [];
        return [
            'from' => self::validDate((string) ($raw['from'] ?? '')),
            'to' => self::validDate((string) ($raw['to'] ?? '')),
            'tags' => array_values(array_filter(array_map('sanitize_title', (array) ($raw['tags'] ?? [])))),
            'keywords' => array_values(array_filter(array_map(
                static fn($word): string => self::normal((string) $word),
                (array) ($raw['keywords'] ?? [])
            ))),
        ];
    }

    /** A tag with no event tags and no keywords is manual-only. */
    private static function hasCriteria(array $rules): bool
    {
        return $rules['tags'] !== [] || $rules['keywords'] !== [];
    }

    /** Fingerprint of every rule, so rule edits trigger a fresh backfill. */
    private static function ruleset(): string
    {
        static $memo = null;
        if (is_string($memo) && !doing_action('edited_' . self::TAXONOMY) && !doing_action('created_' . self::TAXONOMY)) return $memo;
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        $all = [];
        if (is_array($terms)) {
            foreach ($terms as $term) $all[(string) $term->slug] = self::rulesFor((int) $term->term_id);
        }
        ksort($all);
        return $memo = substr(hash('sha256', (string) wp_json_encode($all)), 0, 16);
    }

    public static function backfillComplete(): bool
    {
        return (string) get_option(self::BACKFILL_OPTION, '') === self::ruleset();
    }

    /**
     * Grant tags whose rules match an event. Within one rule, the date window,
     * event tags, and keywords must all match; any one listed event tag or
     * keyword satisfies its part.
     *
     * @param list<string> $eventTagSlugs Existing bg_tag slugs.
     * @return list<string> Suggested bg_grant_tag slugs.
     */
    public static function suggest(
        string $startDate,
        string $title,
        string $locationText = '',
        array $eventTagSlugs = []
    ): array {
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        if (!is_array($terms) || !$terms) return [];
        $date = self::validDate(substr(trim($startDate), 0, 10));
        $text = ' ' . self::normal($title . ' ' . $locationText) . ' ';
        $tags = array_fill_keys(array_map('sanitize_title', $eventTagSlugs), true);
        $suggested = [];
        foreach ($terms as $term) {
            $rules = self::rulesFor((int) $term->term_id);
            if (!self::hasCriteria($rules)) continue;
            if (($rules['from'] !== '' || $rules['to'] !== '') && $date === '') continue;
            if ($rules['from'] !== '' && $date < $rules['from']) continue;
            if ($rules['to'] !== '' && $date > $rules['to']) continue;
            if ($rules['tags'] && !array_intersect_key(array_fill_keys($rules['tags'], true), $tags)) continue;
            if ($rules['keywords']) {
                $hit = false;
                foreach ($rules['keywords'] as $word) {
                    if (str_contains($text, ' ' . $word . ' ')) { $hit = true; break; }
                }
                if (!$hit) continue;
            }
            $suggested[] = (string) $term->slug;
        }
        return array_values(array_unique($suggested));
    }

    public static function renderAddRuleFields(): void
    {
        wp_nonce_field('bg_grant_rules', 'bg_grant_rules_nonce');
        echo '<div class="form-field"><label>Automatic assignment (optional)</label>';
        self::ruleInputs(['from' => '', 'to' => '', 'tags' => [], 'keywords' => []]);
        echo '</div>';
    }

    public static function renderEditRuleFields(\WP_Term $term): void
    {
        echo '<tr class="form-field"><th scope="row">Automatic assignment</th><td>';
        wp_nonce_field('bg_grant_rules', 'bg_grant_rules_nonce');
        self::ruleInputs(self::rulesFor((int) $term->term_id));
        echo '</td></tr>';
    }

    /** @param array{from:string,to:string,tags:list<string>,keywords:list<string>} $rules */
    private static function ruleInputs(array $rules): void
    {
        $vocabulary = \BosqueGrande\Modules\GatheringTree\Vocabulary::all();
        echo '<p>Leave everything empty to assign this grant tag by hand only.</p>';
        echo '<p><label>Events from <input type="date" name="bg_grant_rules[from]" value="' . esc_attr($rules['from']) . '"></label> ';
        echo '<label>through <input type="date" name="bg_grant_rules[to]" value="' . esc_attr($rules['to']) . '"></label></p>';
        if ($vocabulary) {
            echo '<p>With any of these event tags:</p><p style="columns:2">';
            foreach ($vocabulary as $slug => $label) {
                echo '<label style="display:block"><input type="checkbox" name="bg_grant_rules[tags][]" value="' . esc_attr($slug) . '" '
                    . checked(in_array($slug, $rules['tags'], true), true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '</p>';
        }
        echo '<p><label>Or with any of these words in the title or location (one per line):<br>'
            . '<textarea name="bg_grant_rules[keywords]" rows="4" class="large-text">' . esc_textarea(implode("\n", $rules['keywords'])) . '</textarea></label></p>';
        echo '<p class="description">When both event tags and words are set, an event needs one of each.</p>';
    }

    public static function saveRuleFields(int $termId): void
    {
        if (!isset($_POST['bg_grant_rules_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['bg_grant_rules_nonce'])), 'bg_grant_rules')
            || !current_user_can('manage_categories')
        ) return;
        $posted = isset($_POST['bg_grant_rules']) && is_array($_POST['bg_grant_rules'])
            ? wp_unslash($_POST['bg_grant_rules'])
            : [];
        $keywords = preg_split('/\r\n|\r|\n/', sanitize_textarea_field((string) ($posted['keywords'] ?? ''))) ?: [];
        update_term_meta($termId, self::RULES_META, [
            'from' => self::validDate(sanitize_text_field((string) ($posted['from'] ?? ''))),
            'to' => self::validDate(sanitize_text_field((string) ($posted['to'] ?? ''))),
            'tags' => array_values(array_filter(array_map('sanitize_title', (array) ($posted['tags'] ?? [])))),
            'keywords' => array_values(array_filter(array_map('trim', $keywords))),
        ]);
    }

    private static function validDate(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return '';
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /** @return list<string> */
    public static function suggestForEvent(int $eventId): array
    {
        if ($eventId < 1 || get_post_type($eventId) !== 'bg_event') return [];
        $post = get_post($eventId);
        if (!$post instanceof \WP_Post) return [];
        $terms = wp_get_post_terms($eventId, 'bg_tag', ['fields' => 'slugs']);
        return self::suggest(
            (string) get_post_meta($eventId, '_bg_start_date', true),
            (string) $post->post_title,
            (string) get_post_meta($eventId, '_bg_location_text', true),
            is_wp_error($terms) ? [] : array_values(array_map('strval', $terms))
        );
    }

    /**
     * Append deterministic suggestions without changing any existing term.
     *
     * @return array{suggested:list<string>,assigned:list<string>,error:string}
     */
    public static function assignSuggested(int $eventId): array
    {
        $suggested = self::suggestForEvent($eventId);
        $suppressed = array_fill_keys(array_filter(array_map(
            'sanitize_key',
            (array) get_post_meta($eventId, self::SUPPRESSED_META, true)
        )), true);
        if ($suppressed) {
            $suggested = array_values(array_filter(
                $suggested,
                static fn(string $slug): bool => !isset($suppressed[$slug])
            ));
        }
        $result = ['suggested' => $suggested, 'assigned' => [], 'error' => ''];
        if (!$suggested) return $result;
        if (!taxonomy_exists(self::TAXONOMY)) {
            $result['error'] = 'Grant Tag taxonomy is unavailable.';
            return $result;
        }

        $before = wp_get_post_terms($eventId, self::TAXONOMY, ['fields' => 'slugs']);
        if (is_wp_error($before)) {
            $result['error'] = $before->get_error_message();
            return $result;
        }
        $set = wp_set_object_terms($eventId, $suggested, self::TAXONOMY, true);
        if (is_wp_error($set)) {
            $result['error'] = $set->get_error_message();
            return $result;
        }
        $known = array_fill_keys(array_map('strval', $before), true);
        $result['assigned'] = array_values(array_filter(
            $suggested,
            static fn(string $slug): bool => !isset($known[$slug])
        ));
        if ($result['assigned']) self::recordProvenance($eventId, $result['assigned']);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function provenanceForEvent(int $eventId): array
    {
        $stored = get_post_meta($eventId, self::PROVENANCE_META, true);
        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    public static function assignOnSave(int $postId, \WP_Post $post, bool $update): void
    {
        unset($update);
        if ($post->post_type !== 'bg_event' || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        self::assignSuggested($postId);
    }

    /** Historical imports commonly add dates and locations after inserting the post. */
    public static function assignWhenDated(int $metaId, int $postId, string $metaKey, mixed $value): void
    {
        unset($metaId, $value);
        if (!in_array($metaKey, ['_bg_start_date', '_bg_location_text'], true)
            || get_post_type($postId) !== 'bg_event'
        ) return;
        self::assignSuggested($postId);
    }

    /** Re-evaluate ordinary tags and remember an explicit staff Grant Tag removal. */
    public static function assignWhenEventTagsChange(
        int $postId,
        mixed $terms,
        array $termTaxonomyIds,
        string $taxonomy,
        bool $append,
        array $oldTermTaxonomyIds
    ): void {
        unset($terms);
        if (get_post_type($postId) !== 'bg_event') return;
        if ($taxonomy === 'bg_tag') {
            self::assignSuggested($postId);
            return;
        }
        if ($taxonomy !== self::TAXONOMY) return;

        $old = self::slugsForTermTaxonomyIds($oldTermTaxonomyIds);
        $new = self::slugsForTermTaxonomyIds($termTaxonomyIds);
        $removed = $append ? [] : array_values(array_diff($old, $new));
        $added = array_values(array_diff($new, $old));
        $suggested = array_fill_keys(self::suggestForEvent($postId), true);
        $suppressed = array_fill_keys(array_filter(array_map(
            'sanitize_key',
            (array) get_post_meta($postId, self::SUPPRESSED_META, true)
        )), true);
        foreach ($removed as $slug) {
            if (isset($suggested[$slug])) $suppressed[$slug] = true;
        }
        foreach ($added as $slug) unset($suppressed[$slug]);
        $slugs = array_values(array_filter(array_keys($suppressed)));
        if ($slugs) update_post_meta($postId, self::SUPPRESSED_META, $slugs);
        else delete_post_meta($postId, self::SUPPRESSED_META);
    }

    /** Start a bounded, retry-safe backfill whenever the rules change. */
    public static function maybeBackfill(): void
    {
        if (!current_user_can('manage_options') || !taxonomy_exists(self::TAXONOMY)) return;
        if (self::backfillComplete()) return;

        self::runBackfillBatch();
    }

    /**
     * Process one small batch, then hand the next batch to WP-Cron. Keeping
     * work bounded keeps an administrator's page load fast while every event
     * is still reviewed without manual repetition.
     */
    public static function runBackfillBatch(): void
    {
        if (!taxonomy_exists(self::TAXONOMY)) return;
        if (self::backfillComplete()) return;

        $lockOwner = OptionLease::acquire(self::BACKFILL_LOCK, 15 * MINUTE_IN_SECONDS);
        if ($lockOwner === '') return;
        $ruleset = self::ruleset();

        $failed = false;
        $failedEventIds = [];
        $remaining = false;
        try {
            $statuses = array_values(array_diff(
                array_unique(array_merge(get_post_stati([], 'names'), ['bg_historical'])),
                ['trash', 'auto-draft', 'inherit']
            ));
            $ids = get_posts([
                'post_type' => 'bg_event',
                'post_status' => $statuses,
                'posts_per_page' => self::BATCH_SIZE,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => self::PROCESSED_META, 'compare' => 'NOT EXISTS'],
                    ['key' => self::PROCESSED_META, 'value' => $ruleset, 'compare' => '!='],
                ],
            ]);
            foreach ($ids as $eventId) {
                $assigned = self::assignSuggested((int) $eventId);
                if ($assigned['error'] !== '') {
                    $failed = true;
                    $failedEventIds[] = (int) $eventId;
                    continue;
                }
                update_post_meta((int) $eventId, self::PROCESSED_META, $ruleset);
            }
            $remaining = count($ids) >= self::BATCH_SIZE;
            if (!$failed && !$remaining) update_option(self::BACKFILL_OPTION, $ruleset, false);
        } finally {
            OptionLease::release(self::BACKFILL_LOCK, $lockOwner);
        }

        $retryDelay = 10;
        if ($failed) {
            $retryCount = min(16, 1 + max(0, (int) get_option(self::BACKFILL_RETRY_OPTION, 0)));
            update_option(self::BACKFILL_RETRY_OPTION, $retryCount, false);
            $retryDelay = min(6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (1 << min(6, $retryCount - 1)));
            (new Logger())->warn(
                'grant-reporting',
                'Grant Tag backfill left one or more events pending; the batch will be retried.',
                [
                    'failed_count' => count($failedEventIds),
                    'event_ids' => array_slice($failedEventIds, 0, 25),
                ]
            );
        } else {
            delete_option(self::BACKFILL_RETRY_OPTION);
        }
        if (($remaining || $failed) && !wp_next_scheduled(self::BACKFILL_ACTION)) {
            wp_schedule_single_event(time() + $retryDelay, self::BACKFILL_ACTION);
        }
    }

    private static function normal(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace('@', ' at ', $value);
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @param list<int> $termTaxonomyIds @return list<string> */
    private static function slugsForTermTaxonomyIds(array $termTaxonomyIds): array
    {
        $out = [];
        foreach (array_map('intval', $termTaxonomyIds) as $termTaxonomyId) {
            if ($termTaxonomyId < 1) continue;
            $term = get_term_by('term_taxonomy_id', $termTaxonomyId, self::TAXONOMY);
            if ($term instanceof \WP_Term) $out[] = sanitize_key((string) $term->slug);
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** @param list<string> $assigned */
    private static function recordProvenance(int $eventId, array $assigned): void
    {
        $entries = self::provenanceForEvent($eventId);
        $known = [];
        foreach ($entries as $entry) {
            $slug = sanitize_key((string) ($entry['grant_tag'] ?? ''));
            if ($slug !== '') $known[$slug] = true;
        }
        $year = (int) substr((string) get_post_meta($eventId, '_bg_start_date', true), 0, 4);
        foreach ($assigned as $slug) {
            if (isset($known[$slug])) continue;
            $entries[] = [
                'grant_tag' => $slug,
                'ruleset' => self::ruleset(),
                'source' => 'grant-tag-rules',
                'start_year' => $year,
                'assigned_at' => gmdate('c'),
            ];
        }
        update_post_meta($eventId, self::PROVENANCE_META, $entries);
    }
}
