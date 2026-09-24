<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Assisted tools are controlled by one global switch plus per-feature
 * settings. Deterministic behavior remains available when they are disabled.
 *
 * Security and product invariants:
 * 1. Assisted behavior is visibly labeled and never writes a suggestion
 *    without an explicit staff action.
 * 2. Provider keys are supplied by the site, encrypted, and never redisplayed.
 * 3. Features register through the bg_ai_features filter as
 *    key => [label, description]; each gets its own toggle here. A
 *    feature runs only when the global switch AND its toggle are on
 *    (featureEnabled() answers both at once).
 * 4. Demographic field values are never sent to a provider. That rule
 *    is enforced at call sites and restated here so it is read before
 *    any new feature is written.
 */
final class IntelligenceModule implements ModuleInterface
{
    public function name(): string
    {
        return 'intelligence';
    }

    public function label(): string
    {
        return 'Intelligence (assisted features and providers)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);

        // The one service every assisted automation call site resolves. Never null:
        // NullProvider answers when assisted automation is off or unkeyed.
        $container->set('ai', function (Container $c): ProviderInterface {
            return self::resolveProvider($c->get('settings'), $c->get('logger'));
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Assisted Tools', 'Assisted Tools',
                'manage_options', 'bosquegrande-ai', [$this, 'renderPage']);
        }, 47);
        add_action('admin_post_bg_ai_save', function () use ($container): void {
            $this->handleSave($container);
        });
        add_action('admin_post_bg_ai_test', function () use ($container): void {
            $this->handleTest($container);
        });

        // ── Visible assisted features ───────────────────────────────────────
        add_filter('bg_ai_features', function (array $features): array {
            $features['assistant'] = [
                'label' => 'Site assistant',
                'description' => 'A question-and-answer helper on its own screen. It sees aggregate site facts only (counts, event titles, totals), has no access to any individual person\'s details, and cannot change anything.',
            ];
            $features['event_copy'] = [
                'label' => 'Event tag help',
                'description' => 'Suggest tags from the saved event title and description. Suggestions appear for you to copy in; nothing saves itself.',
            ];
            $features['smart_search'] = [
                'label' => 'Smart event search',
                'description' => 'The [bg_event_search] box understands phrases like "outdoor stuff next month". With this off the same box does plain keyword search.',
            ];
            $features['narrative'] = [
                'label' => 'Involvement summaries',
                'description' => 'A one-paragraph history of a constituent\'s involvement, written from their timeline entries only (never contact details, demographics, or custom fields).',
            ];
            $features['dedup_assist'] = [
                'label' => 'Duplicate review advice',
                'description' => 'One advisory sentence next to ambiguous duplicate pairs (names and cities only). You still click every merge.',
            ];
            $features['lgl_mapping'] = [
                'label' => 'LGL field name matching',
                'description' => 'When Little Green Light category names do not resemble the configured names closely enough for the built-in fuzzy match, ask the model which category is which. Only category NAMES are sent; the values inside them never leave the site. Each account layout is classified once and the verdict is kept.',
            ];
            return $features;
        });

        // The category-match seam. CategoryMatcher applies this
        // filter as its last tier; it receives category NAMES only and
        // must answer with one of them or an empty string. Verdicts are
        // cached permanently per (target, schema) so a layout is paid
        // for once, abstentions included.
        add_filter('bg_lgl_category_match', function ($picked, string $target, array $names) use ($container) {
            if ($picked !== '' || !$names) return $picked;
            /** @var Settings $settings */
            $settings = $container->get('settings');
            if (!self::featureEnabled($settings, 'lgl_mapping')) return '';

            $names = array_values(array_filter(array_map('strval', $names)));
            $cacheKey = md5($target . '|' . implode('|', $names));
            $cache = get_option('bg_lgl_catmap_ai', []);
            if (!is_array($cache)) $cache = [];
            if (array_key_exists($cacheKey, $cache)) {
                return (string) $cache[$cacheKey];
            }

            $provider = self::resolveProvider($settings, $container->get('logger'));
            $answer = trim($provider->complete(
                'You match database field names. Reply with EXACTLY one name copied verbatim from the provided list, or the single word NONE. No other text.',
                "A donor database has constituent categories with these names:\n- "
                    . implode("\n- ", $names)
                    . "\n\nWhich one holds what an events site calls \"{$target}\"? "
                    . 'Reply with that exact name, or NONE if none of them plausibly does.',
                ['max_tokens' => 60]
            ));
            $verdict = '';
            foreach ($names as $name) {
                if (strcasecmp($answer, $name) === 0) {
                    $verdict = $name;
                    break;
                }
            }
            // Abstentions ('' from NONE, refusals, transport failures on
            // a NullProvider) are cached too, EXCEPT genuine emptiness
            // from an unconfigured provider, which should retry once a
            // key arrives.
            if ($answer !== '' || $verdict !== '') {
                $cache[$cacheKey] = $verdict;
                update_option('bg_lgl_catmap_ai', $cache, false);
                /** @var Logger $logger */
                $logger = $container->get('logger');
                $logger->info('lgl', 'Category mapping classified.', [
                    'target' => $target,
                    'matched' => $verdict !== '' ? $verdict : '(none)',
                    'candidates' => count($names),
                ]);
            }
            return $verdict;
        }, 10, 3);

        $container->set('intelligence.assistant', fn(Container $c) => new Assistant($c));
        $container->set('intelligence.supportdesk', fn(Container $c) => new SupportDesk($c->get('logger')));

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Assistant', 'Assistant',
                'manage_options', 'bosquegrande-assistant', [$this, 'renderAssistantPage']);
        }, 15);

        add_action('admin_post_bg_assistant_ask', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_assistant_ask');
            $settings = $container->get('settings');
            if (!self::featureEnabled($settings, 'assistant')) {
                wp_safe_redirect(admin_url('admin.php?page=bosquegrande-assistant'));
                exit;
            }
            $question = sanitize_text_field((string) wp_unslash((string) ($_POST['q'] ?? '')));
            $key = 'bg_assistant_' . get_current_user_id();
            $history = get_transient($key);
            if (!is_array($history)) $history = [];
            if ($question !== '') {
                $answer = $container->get('intelligence.assistant')->ask($question, $history);
                $history[] = ['role' => 'user', 'text' => $question];
                $history[] = ['role' => 'assistant', 'text' => $answer];
                set_transient($key, array_slice($history, -20), HOUR_IN_SECONDS);
            }
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande-assistant'));
            exit;
        });
        add_action('admin_post_bg_assistant_clear', function (): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_assistant_clear');
            delete_transient('bg_assistant_' . get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande-assistant'));
            exit;
        });

        // Event tag help: a meta box that proposes, never saves.
        add_action('add_meta_boxes_bg_event', function () use ($container): void {
            if (!self::featureEnabled($container->get('settings'), 'event_copy')) return;
            add_meta_box('bg_writing_help', 'Writing help', function (\WP_Post $post): void {
                $stash = get_transient('bg_evcopy_' . $post->ID . '_' . get_current_user_id());
                if (is_array($stash)) {
                    if (!empty($stash['tags'])) {
                        echo '<p><strong>Suggested tags:</strong> ' . esc_html(implode(', ', $stash['tags']))
                            . '</p><p class="description">Add the ones that fit in the Tags box; none are applied for you.</p>';
                    }
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('bg_event_copy_' . $post->ID);
                echo '<input type="hidden" name="action" value="bg_event_copy">'
                    . '<input type="hidden" name="what" value="tags">'
                    . '<input type="hidden" name="post" value="' . (int) $post->ID . '">'
                    . '<p><button class="button" type="submit">Suggest tags</button></p></form>'
                    . '<p class="description">Uses the saved title and description; save the event first for best results.</p>';
            }, 'bg_event', 'side', 'default');
        });
        add_action('admin_post_bg_event_copy', function () use ($container): void {
            $postId = (int) ($_POST['post'] ?? 0);
            if (!current_user_can('edit_post', $postId)) wp_die('Insufficient permissions.');
            check_admin_referer('bg_event_copy_' . $postId);
            $post = get_post($postId);
            $settings = $container->get('settings');
            if ($post && (string) ($_POST['what'] ?? '') === 'tags'
                && self::featureEnabled($settings, 'event_copy')) {
                $ai = $container->get('ai');
                $stash = get_transient('bg_evcopy_' . $postId . '_' . get_current_user_id());
                if (!is_array($stash)) $stash = [];
                unset($stash['summary']);
                $known = get_terms(['taxonomy' => 'bg_tag', 'hide_empty' => false, 'fields' => 'names']);
                $stash['tags'] = Helpers::suggestTags(
                    $ai,
                    (string) $post->post_title,
                    bg_substr(wp_strip_all_tags((string) $post->post_content), 0, 2400),
                    is_array($known) ? $known : []
                );
                set_transient('bg_evcopy_' . $postId . '_' . get_current_user_id(), $stash, HOUR_IN_SECONDS);
            }
            wp_safe_redirect(get_edit_post_link($postId, 'url'));
            exit;
        });

        // Smart event search: shortcode plus REST, keyword fallback built in.
        add_shortcode('bg_event_search', function (): string {
            $id = 'bg-evs-' . wp_rand(100, 999);
            $label = self::word('event_search_label', 'Search events');
            $placeholder = self::word('event_search_placeholder', 'Search events...');
            $button = self::word('event_search_button', 'Search');
            $searching = self::word('event_search_searching', 'Searching...');
            $empty = self::word('event_search_empty', 'Nothing found.');
            $untitled = self::word('event_search_untitled', 'Untitled event');
            $error = self::word('event_search_error', 'Something went wrong.');
            return '<form class="bg-event-search" id="' . esc_attr($id) . '" role="search" aria-label="' . esc_attr($label) . '" style="max-width:520px">'
                . '<div style="display:flex;gap:8px"><input type="search" name="q" required maxlength="160" aria-label="' . esc_attr($label) . '" placeholder="' . esc_attr($placeholder) . '" '
                . 'style="flex:1;padding:10px;border:1px solid #ccc;border-radius:8px">'
                . '<button type="submit" style="background:#2E7D4F;color:#fff;border:0;border-radius:999px;padding:10px 22px;font-weight:700;cursor:pointer">' . esc_html($button) . '</button></div>'
                . '<ul data-bg-results aria-live="polite" aria-busy="false" style="list-style:none;padding:0"></ul></form>'
                . '<script>(function(){var f=document.getElementById(' . wp_json_encode($id) . ');if(!f)return;'
                . 'f.addEventListener("submit",function(e){e.preventDefault();'
                . 'var ul=f.querySelector("[data-bg-results]"),li=document.createElement("li");ul.textContent="";ul.setAttribute("aria-busy","true");li.textContent=' . wp_json_encode($searching) . ';ul.appendChild(li);'
                . 'fetch(' . wp_json_encode(esc_url_raw(rest_url('bosquegrande/v1/event-search'))) . '+"?q="+encodeURIComponent(f.q.value))'
                . '.then(function(r){return r.json();}).then(function(d){if(!d||!Array.isArray(d.results))throw new Error("invalid response");ul.textContent="";ul.setAttribute("aria-busy","false");'
                . 'if(!d.results||!d.results.length){var empty=document.createElement("li");empty.textContent=String(d.message||' . wp_json_encode($empty) . ');ul.appendChild(empty);return;}'
                . 'd.results.forEach(function(ev){var li=document.createElement("li"),a=document.createElement("a"),strong=document.createElement("strong"),span=document.createElement("span");'
                . 'li.style.padding="8px 0";a.href=String(ev.url||"#");strong.textContent=String(ev.title||' . wp_json_encode($untitled) . ');span.textContent=" "+String(ev.date||"");'
                . 'a.appendChild(strong);li.appendChild(a);li.appendChild(span);ul.appendChild(li);});})'
                . '.catch(function(){var failed=document.createElement("li");ul.textContent="";ul.setAttribute("aria-busy","false");failed.textContent=' . wp_json_encode($error) . ';ul.appendChild(failed);});});})();</script>';
        });
        add_action('rest_api_init', function () use ($container): void {
            register_rest_route('bosquegrande/v1', '/event-search', [
                'methods' => 'GET',
                'permission_callback' => '__return_true', // Public search over published events only.
                'callback' => function (\WP_REST_Request $req) use ($container) {
                    $raw = (string) $req->get_param('q');
                    if (strlen($raw) > 512) {
                        return new \WP_REST_Response(['results' => [], 'message' => self::wordFormat(
                            'event_search_too_long',
                            'Search phrases must be {max} characters or fewer.',
                            ['max' => 160]
                        )], 400);
                    }
                    $q = trim(sanitize_text_field($raw));
                    if (bg_substr($q, 0, 160) !== $q) {
                        return new \WP_REST_Response(['results' => [], 'message' => self::wordFormat(
                            'event_search_too_long',
                            'Search phrases must be {max} characters or fewer.',
                            ['max' => 160]
                        )], 400);
                    }
                    if ($q === '') return new \WP_REST_Response(['results' => []], 200);

                    $cacheKey = 'bg_evs_' . md5(strtolower($q));
                    $cached = get_transient($cacheKey);
                    if (is_array($cached)) {
                        return new \WP_REST_Response(['results' => $cached], 200, ['Cache-Control' => 'public, max-age=60']);
                    }

                    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
                    if (!self::claimPublicSearchBudget('admit-ip:' . hash('sha256', $ip), 30, MINUTE_IN_SECONDS)
                        || !self::claimPublicSearchBudget('admit-global', 200, MINUTE_IN_SECONDS)
                    ) {
                        return new \WP_REST_Response([
                            'results' => [],
                            'message' => self::word('event_search_busy', 'Search is busy. Please wait a moment and try again.'),
                        ], 429, ['Cache-Control' => 'no-store']);
                    }

                    $settings = $container->get('settings');
                    $parsed = null;
                    if (self::featureEnabled($settings, 'smart_search')) {
                        $ai = $container->get('ai');
                        if ($ai->isConfigured()) {
                            $slot = self::acquirePublicSearchSlot();
                            $allowed = $slot !== []
                                && self::claimPublicSearchBudget('ip:' . hash('sha256', $ip), 6, MINUTE_IN_SECONDS)
                                && self::claimPublicSearchBudget('global', 30, MINUTE_IN_SECONDS)
                                && self::claimPublicSearchBudget(
                                    'daily:' . gmdate('Y-m-d'),
                                    max(1, (int) apply_filters('bg_ai_public_search_daily_limit', 200)),
                                    DAY_IN_SECONDS + HOUR_IN_SECONDS
                                );
                            if ($allowed) {
                                try {
                                    $known = get_terms(['taxonomy' => 'bg_tag', 'hide_empty' => false, 'fields' => 'names']);
                                    $parsed = Helpers::parseSearch($ai, $q, is_array($known) ? $known : []);
                                } finally {
                                    self::releasePublicSearchSlot($slot);
                                }
                            } elseif ($slot !== []) {
                                self::releasePublicSearchSlot($slot);
                            }
                        }
                    }
                    if ($parsed === null) {
                        $parsed = ['keywords' => [$q], 'tags' => [], 'from' => '', 'to' => ''];
                    }
                    $results = self::runEventSearch($parsed);
                    set_transient($cacheKey, $results, 5 * MINUTE_IN_SECONDS);
                    return new \WP_REST_Response(['results' => $results], 200, ['Cache-Control' => 'public, max-age=60']);
                },
            ]);
        });

        // Involvement narrative on the constituent profile.
        add_action('bg_constituent_profile', function (int $constituentId) use ($container): void {
            if (!self::featureEnabled($container->get('settings'), 'narrative')) return;
            $stash = get_transient('bg_narrative_' . $constituentId);
            echo '<h2>Involvement summary</h2>';
            if (is_string($stash) && $stash !== '') {
                echo '<p style="max-width:680px;background:#F3F8F3;border:1px solid #D9E5D9;border-radius:8px;padding:12px">'
                    . esc_html($stash) . '</p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bg_narrative_' . $constituentId);
            echo '<input type="hidden" name="action" value="bg_narrative">'
                . '<input type="hidden" name="id" value="' . (int) $constituentId . '">'
                . '<button class="button">' . ($stash ? 'Rewrite the summary' : 'Write an involvement summary') . '</button> '
                . '<span class="description">Written from the timeline entries only; contact details and custom fields are never sent.</span></form>';
        }, 20);
        add_action('admin_post_bg_narrative', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            $id = (int) ($_POST['id'] ?? 0);
            check_admin_referer('bg_narrative_' . $id);
            $settings = $container->get('settings');
            if (self::featureEnabled($settings, 'narrative')) {
                $record = $container->get('constituents.store')->get($id);
                $timeline = $container->get('constituents.activity')->for($id);
                if ($record) {
                    $text = Helpers::narrative($container->get('ai'), $record['first_name'], $timeline);
                    if ($text !== '') set_transient('bg_narrative_' . $id, $text, DAY_IN_SECONDS);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande-constituents&view=edit&id=' . $id));
            exit;
        });
    }

    /** @param array<string,array<string,mixed>> $defaults @return array<string,array<string,mixed>> */
    public static function wordingDefaults(array $defaults): array
    {
        $copy = [
            'event_search_label' => ['Event search: accessible field label', 'Search events'],
            'event_search_placeholder' => ['Event search: field placeholder', 'Search events...'],
            'event_search_button' => ['Event search: submit button', 'Search'],
            'event_search_searching' => ['Event search: loading status', 'Searching...'],
            'event_search_empty' => ['Event search: no-results status', 'Nothing found.'],
            'event_search_untitled' => ['Event search: missing-title fallback', 'Untitled event'],
            'event_search_error' => ['Event search: request error', 'Something went wrong.'],
            'event_search_too_long' => ['Event search: query-length error ({max})', 'Search phrases must be {max} characters or fewer.'],
            'event_search_busy' => ['Event search: rate-limit notice', 'Search is busy. Please wait a moment and try again.'],
        ];
        foreach ($copy as $key => [$label, $text]) {
            if (isset($defaults[$key])) continue;
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'Event search'];
        }
        if (isset($defaults['event_search_too_long'])) {
            $defaults['event_search_too_long']['required_tokens'] = ['max'];
        }
        return $defaults;
    }

    private static function word(string $key, string $fallback): string
    {
        return function_exists('bg_str') ? (string) \bg_str($key, $fallback) : $fallback;
    }

    /** @param array<string, scalar|null> $tokens */
    private static function wordFormat(string $key, string $fallback, array $tokens): string
    {
        if (function_exists('bg_str_format')) {
            return (string) \bg_str_format($key, $fallback, $tokens);
        }
        $replace = [];
        foreach ($tokens as $name => $value) {
            $replace['{' . sanitize_key((string) $name) . '}'] = (string) $value;
        }
        return strtr(self::word($key, $fallback), $replace);
    }

    /**
     * Provider selection: global switch off, or no key for the chosen
     * provider, resolves to NullProvider. $logger is duck-typed so the
     * stub harness can pass its own.
     */
    public static function resolveProvider(Settings $settings, object $logger): ProviderInterface
    {
        if ((int) $settings->get('ai_enabled', 0) !== 1) {
            return new NullProvider();
        }
        $choice = (string) $settings->get('ai_provider', 'anthropic');
        $model = (string) $settings->get('ai_model', '');
        $provider = match ($choice) {
            'openai' => new OpenAiProvider((string) $settings->get('openai_api_key', ''), $logger, $model),
            'gemini' => new GeminiProvider((string) $settings->get('gemini_api_key', ''), $logger, $model),
            default => new AnthropicProvider((string) $settings->get('anthropic_api_key', ''), $logger, $model),
        };
        return $provider->isConfigured() ? $provider : new NullProvider();
    }

    /**
     * The one question a feature asks before calling the provider.
     * True only when the global switch and the feature's own toggle
     * are both on.
     */
    public static function featureEnabled(Settings $settings, string $feature): bool
    {
        if ((int) $settings->get('ai_enabled', 0) !== 1) return false;
        $toggles = $settings->get('ai_features', []);
        if (!is_array($toggles)) $toggles = [];
        // A registered feature defaults ON once the global switch is on;
        // the office turns individual features off, not on.
        return (int) ($toggles[$feature] ?? 1) === 1;
    }

    /**
     * Serialize a small transient counter with an add_option mutex. If the
     * mutex cannot be obtained, fail closed and use deterministic search.
     */
    private static function claimPublicSearchBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_evs_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 15);
        if ($owner === '') return false;
        try {
            $key = 'bg_evs_budget_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    /** At most two provider calls may occupy PHP workers at once. */
    private static function acquirePublicSearchSlot(): array
    {
        for ($i = 0; $i < 2; $i++) {
            $key = 'bg_evs_busy_' . $i;
            $owner = OptionLease::acquire($key, 120);
            if ($owner !== '') return ['key' => $key, 'owner' => $owner];
        }
        return [];
    }

    /** @param array{key?:string,owner?:string} $slot */
    private static function releasePublicSearchSlot(array $slot): void
    {
        $key = (string) ($slot['key'] ?? '');
        $owner = (string) ($slot['owner'] ?? '');
        if (preg_match('/^bg_evs_busy_[01]$/', $key)) OptionLease::release($key, $owner);
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function features(): array
    {
        $features = apply_filters('bg_ai_features', []);
        return is_array($features) ? $features : [];
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $settings = \BosqueGrande\Core\Plugin::instance()->container()->get('settings');
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $enabled = (int) $settings->get('ai_enabled', 0) === 1;
        $provider = (string) $settings->get('ai_provider', 'anthropic');
        $toggles = $settings->get('ai_features', []);
        if (!is_array($toggles)) $toggles = [];
        $features = self::features();
        ?>
        <div class="wrap">
            <h1>Assisted Tools</h1>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p style="max-width:760px">BosqueGrande can provide optional writing, search, classification,
            and review assistance. With the switch off, everything still works through its standard tools.
            Assistance runs through your own provider key, each feature has its own toggle, and demographic
            answers are never sent to a provider.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_ai_save'); ?>
                <input type="hidden" name="action" value="bg_ai_save">
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Assisted tools</th>
                        <td><label><input type="checkbox" name="ai_enabled" value="1" <?php checked($enabled); ?>>
                            Enabled</label>
                        <p class="description">The master switch. Off means no assisted automation runs anywhere, no data leaves for any provider, and every feature falls back to its standard behavior.</p></td></tr>
                    <tr><th scope="row">Provider</th>
                        <td><select name="ai_provider">
                            <option value="anthropic" <?php selected($provider, 'anthropic'); ?>>Anthropic (Claude)</option>
                            <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
                            <option value="gemini" <?php selected($provider, 'gemini'); ?>>Google (Gemini)</option>
                        </select>
                        <input type="text" name="ai_model" class="regular-text code" placeholder="Model (optional; a sensible default is used)"
                            value="<?php echo esc_attr((string) $settings->get('ai_model', '')); ?>">
                        </td></tr>
                    <tr><th scope="row">Anthropic API key</th>
                        <td><input type="password" name="anthropic_api_key" class="regular-text" autocomplete="new-password"
                            placeholder="<?php echo $settings->hasSecret('anthropic_api_key') ? 'Key on file (leave blank to keep)' : 'sk-ant-...'; ?>"></td></tr>
                    <tr><th scope="row">OpenAI API key</th>
                        <td><input type="password" name="openai_api_key" class="regular-text" autocomplete="new-password"
                            placeholder="<?php echo $settings->hasSecret('openai_api_key') ? 'Key on file (leave blank to keep)' : 'sk-...'; ?>"></td></tr>
                    <tr><th scope="row">Gemini API key</th>
                        <td><input type="password" name="gemini_api_key" class="regular-text" autocomplete="new-password"
                            placeholder="<?php echo $settings->hasSecret('gemini_api_key') ? 'Key on file (leave blank to keep)' : 'AIza...'; ?>"></td></tr>
                </table>

                <h2 class="title">Features</h2>
                <?php if ($features): ?>
                    <table class="form-table" role="presentation">
                    <?php foreach ($features as $key => $f): ?>
                        <tr><th scope="row"><?php echo esc_html($f['label']); ?></th>
                            <td><label><input type="checkbox" name="ai_features[<?php echo esc_attr($key); ?>]" value="1"
                                <?php checked((int) ($toggles[$key] ?? 1) === 1); ?>> On</label>
                            <p class="description"><?php echo esc_html($f['description']); ?></p></td></tr>
                    <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p style="max-width:760px">No assisted features are currently registered.</p>
                <?php endif; ?>

                <p><button class="button button-primary">Save Assisted Tools settings</button></p>
            </form>

            <h2 class="title">Test the connection</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_ai_test'); ?>
                <input type="hidden" name="action" value="bg_ai_test">
                <button class="button">Run a test call</button>
                <p class="description">Sends one tiny request to your chosen provider and reports the result. Costs a fraction of a cent on your key.</p>
            </form>
        </div>
        <?php
    }

    private function handleSave(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_ai_save');
        $settings = $container->get('settings');
        $settings->set('ai_enabled', isset($_POST['ai_enabled']) ? 1 : 0);
        $provider = (string) ($_POST['ai_provider'] ?? 'anthropic');
        $settings->set('ai_provider', in_array($provider, ['anthropic', 'openai', 'gemini'], true) ? $provider : 'anthropic');
        $settings->set('ai_model', sanitize_text_field((string) ($_POST['ai_model'] ?? '')));
        $secretsSaved = true;
        foreach (['anthropic_api_key', 'openai_api_key', 'gemini_api_key'] as $key) {
            $v = trim((string) ($_POST[$key] ?? ''));
            if ($v !== '') $secretsSaved = $settings->set($key, $v) && $secretsSaved;
        }
        $known = array_keys(self::features());
        $posted = (array) ($_POST['ai_features'] ?? []);
        $toggles = [];
        foreach ($known as $key) {
            $toggles[$key] = isset($posted[$key]) ? 1 : 0;
        }
        $settings->set('ai_features', $toggles);
        $container->get('logger')->info('intelligence', 'Assisted Tools settings saved.', [
            'enabled' => (int) $settings->get('ai_enabled', 0),
            'provider' => (string) $settings->get('ai_provider', ''),
        ]);
        $notice = $secretsSaved
            ? 'Assisted Tools settings saved.'
            : 'Assisted Tools options were saved, but one or more provider keys were refused because encrypted storage is unavailable.';
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-ai', 'bg_notice' => rawurlencode($notice)], admin_url('admin.php')));
        exit;
    }

    private function handleTest(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_ai_test');
        /** @var ProviderInterface $ai */
        $ai = $container->get('ai');
        if ($ai instanceof NullProvider) {
            $msg = 'Assisted Tools is off or no key is on file for the chosen provider, so there is nothing to test.';
        } else {
            $reply = $ai->complete('Reply with the single word: ready', 'Connection test.', ['max_tokens' => 16]);
            $msg = $reply !== ''
                ? 'Connection working. ' . ucfirst($ai->name()) . ' replied: ' . sanitize_text_field($reply)
                : 'The call failed; the reason is in the log (System Status page).';
        }
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-ai', 'bg_notice' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array{keywords: list<string>, tags: list<string>, from: string, to: string} $f
     * @return list<array{title: string, url: string, date: string}>
     */
    public static function runEventSearch(array $f): array
    {
        global $wpdb;
        if (!$f['keywords'] && !$f['tags'] && $f['from'] === '' && $f['to'] === '') {
            return []; // No usable filter: everything would match, which is not a search result.
        }
        $joins = "JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_bg_start_date'";
        $where = ["p.post_type = 'bg_event'", "p.post_status = 'publish'"];
        $params = [];
        if ($f['from'] !== '') { $where[] = 'd.meta_value >= %s'; $params[] = $f['from']; }
        if ($f['to'] !== '') { $where[] = 'd.meta_value <= %s'; $params[] = $f['to']; }
        $ors = [];
        foreach (array_slice($f['keywords'], 0, 5) as $kw) {
            $ors[] = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($kw) . '%';
        }
        if ($f['tags']) {
            $joins .= " LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                        LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bg_tag'
                        LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id";
            foreach (array_slice($f['tags'], 0, 5) as $tag) {
                $ors[] = 't.name = %s';
                $params[] = $tag;
            }
        }
        if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
        $sql = "SELECT DISTINCT p.ID, p.post_title, d.meta_value AS start_date FROM {$wpdb->posts} p {$joins}
                WHERE " . implode(' AND ', $where) . ' ORDER BY d.meta_value ASC LIMIT 20';
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[] = [
                'title' => (string) $row->post_title,
                'url' => esc_url_raw((string) get_permalink((int) $row->ID)),
                'date' => (string) $row->start_date,
            ];
        }
        return $out;
    }

    public function renderAssistantPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        $settings = $container->get('settings');
        $history = get_transient('bg_assistant_' . get_current_user_id());
        if (!is_array($history)) $history = [];
        ?>
        <div class="wrap"><h1>Assistant</h1>
        <?php if (!self::featureEnabled($settings, 'assistant')): ?>
            <p>The assistant is off. Turn it on under
            <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-ai')); ?>">Assisted Tools</a>
            (it needs the global switch and a provider key).</p></div>
            <?php return; ?>
        <?php endif; ?>
        <p style="max-width:760px">Ask about this site: numbers, upcoming events, where a screen lives. The
        assistant sees aggregate facts only; it has no access to any individual person's details and cannot
        change anything.</p>
        <div style="max-width:760px">
            <?php if (!$history): ?><p><em>No conversation yet.</em></p><?php endif; ?>
            <?php foreach ($history as $turn): ?>
                <p style="padding:10px 14px;border-radius:10px;max-width:90%;<?php
                    echo $turn['role'] === 'user' ? 'background:#EAF3EA;margin-left:auto' : 'background:#F6F7F7;border:1px solid #e2e4e7'; ?>">
                    <strong><?php echo $turn['role'] === 'user' ? 'You' : 'Assistant'; ?>:</strong>
                    <?php echo esc_html((string) $turn['text']); ?></p>
            <?php endforeach; ?>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:760px;display:flex;gap:8px;margin-top:12px">
            <?php wp_nonce_field('bg_assistant_ask'); ?>
            <input type="hidden" name="action" value="bg_assistant_ask">
            <input type="text" name="q" required placeholder="Ask something..." style="flex:1">
            <button class="button button-primary">Ask</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
            <?php wp_nonce_field('bg_assistant_clear'); ?>
            <input type="hidden" name="action" value="bg_assistant_clear">
            <button class="button">Clear the conversation</button>
        </form>
        </div>
        <?php
    }
}
