<?php

declare(strict_types=1);

namespace BGCompanion\Assistant;

if (!defined('ABSPATH')) exit;

/**
 * Wiring for the Floating Assistant: settings, the visitor chat route, the
 * button on public pages, and the bridge that hands training info to
 * BosqueGrande's assistant.
 *
 * The answering itself stays in BosqueGrande: its assistant, its escalation
 * path, and the service provider key the site owner entered there. If
 * BosqueGrande is missing or its assisted tools are off or unkeyed, the
 * button does not render and nothing breaks.
 */
final class Plugin
{
    public const OPTION = 'bgca_settings';
    public const FEATURE = 'visitor_assistant';
    private const ROUTE = 'assistant-chat';

    /** @var array<string, int|string> */
    private const DEFAULTS = [
        'enabled' => 1,
        'side' => 'left',
        'edge' => 20,
        'offset' => 24,
        'zindex' => 99990,
        'color' => '',
        'hide_on' => '',
        'per_visitor' => 8,
        'daily_cap' => 300,
        'delete_on_uninstall' => 0,
    ];

    public static function boot(): void
    {
        $self = new self();
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_filter('bg_ai_features', [self::class, 'registerFeature']);
        add_filter('bg_assistant_reference', [self::class, 'reference'], 10, 3);
        add_action('init', [Training::class, 'maybeInstall'], 5);
        add_action('admin_menu', [$self, 'menu'], 60);
        add_action('admin_post_bgca_save', [$self, 'saveSettings']);
        add_action('admin_notices', [$self, 'requireCore']);
        add_action('rest_api_init', [$self, 'routes']);
        add_action('wp_enqueue_scripts', [$self, 'enqueue']);
        add_action('wp_footer', [$self, 'render']);
        (new TrainingPage())->boot();
    }

    /* ── Settings ─────────────────────────────────────────────── */

    /** @return array<string, int|string> */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
        return [
            'enabled' => (int) $s['enabled'] === 1 ? 1 : 0,
            'side' => $s['side'] === 'right' ? 'right' : 'left',
            'edge' => max(0, min(600, (int) $s['edge'])),
            'offset' => max(0, min(600, (int) $s['offset'])),
            'zindex' => max(10, min(2147483000, (int) $s['zindex'])),
            'color' => (string) (sanitize_hex_color((string) $s['color']) ?? ''),
            'hide_on' => (string) $s['hide_on'],
            'per_visitor' => max(1, min(100, (int) $s['per_visitor'])),
            'daily_cap' => max(0, min(100000, (int) $s['daily_cap'])),
            'delete_on_uninstall' => (int) $s['delete_on_uninstall'] === 1 ? 1 : 0,
        ];
    }

    public static function coreActive(): bool
    {
        return defined('BG_VERSION') && class_exists('\BosqueGrande\Core\Plugin')
            && version_compare((string) BG_VERSION, '5.0.0', '>=');
    }

    /**
     * Whether visitors can get answers, and if not, what to fix.
     *
     * @return array{ready: bool, reason: string, provider: string}
     */
    public static function status(): array
    {
        if (!self::coreActive()) {
            return ['ready' => false, 'provider' => '', 'reason' => defined('BG_VERSION')
                ? 'This companion needs BosqueGrande 5.0 or later.'
                : 'BosqueGrande is not active.'];
        }
        try {
            $c = \BosqueGrande\Core\Plugin::instance()->container();
            $settings = $c->get('settings');
            if ((int) $settings->get('ai_enabled', 0) !== 1) {
                return ['ready' => false, 'provider' => '', 'reason' => 'Assisted Tools are turned off in BosqueGrande.'];
            }
            if (!\BosqueGrande\Modules\Intelligence\IntelligenceModule::featureEnabled($settings, self::FEATURE)) {
                return ['ready' => false, 'provider' => '', 'reason' => 'The "Visitor assistant" feature is turned off in Assisted Tools.'];
            }
            $ai = $c->get('ai');
            if (!$ai->isConfigured()) {
                return ['ready' => false, 'provider' => '', 'reason' => 'No service provider key is saved in Assisted Tools yet.'];
            }
            return ['ready' => true, 'provider' => (string) $ai->name(), 'reason' => ''];
        } catch (\Throwable $e) {
            return ['ready' => false, 'provider' => '', 'reason' => 'BosqueGrande\'s assisted tools could not be loaded.'];
        }
    }

    public static function providerLabel(string $name): string
    {
        return ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini'][$name] ?? $name;
    }

    public static function toolsUrl(): string
    {
        return admin_url('admin.php?page=bosquegrande-ai');
    }

    /** @param array<string, array{label: string, description: string}> $features */
    public static function registerFeature(array $features): array
    {
        $features[self::FEATURE] = [
            'label' => 'Visitor assistant',
            'description' => 'The Floating Assistant button on public pages. It answers from public site facts and the training info you mark for visitors, never from contact records, and cannot change anything.',
        ];
        return $features;
    }

    /** Hand the matching training info to BosqueGrande's assistant. */
    public static function reference(string $current, string $audience, string $question): string
    {
        $own = Training::reference($audience === 'staff' ? 'staff' : 'visitor', $question);
        if ($own === '') return $current;
        return trim($current . "\n\n" . $own);
    }

    /** @param array<string, array<string, string>> $defaults */
    public static function wordingDefaults(array $defaults): array
    {
        $copy = [
            'bgca_button_label' => ['Floating assistant: button label', 'Questions?'],
            'bgca_panel_title' => ['Floating assistant: panel heading', 'Ask us a question'],
            'bgca_greeting' => ['Floating assistant: first message', 'Hi! Ask me about our events, registering, newsletters, or anything on this site.'],
            'bgca_placeholder' => ['Floating assistant: question field placeholder', 'Type a question...'],
            'bgca_send' => ['Floating assistant: send button', 'Send'],
            'bgca_close' => ['Floating assistant: close button label', 'Close'],
            'bgca_thinking' => ['Floating assistant: waiting message', 'Thinking...'],
            'bgca_note' => ['Floating assistant: note under the answers', 'Answers are automated and can be wrong. For anything important, contact us directly.'],
            'bgca_error' => ['Floating assistant: error message', 'Something went wrong. Please try again.'],
            'bgca_offline' => ['Floating assistant: unavailable message', 'The assistant is offline right now.'],
            'bgca_too_long' => ['Floating assistant: question too long', 'Please keep questions under 500 characters.'],
            'bgca_slow_down' => ['Floating assistant: per-visitor limit reached', 'You have asked quite a few questions. Please wait a few minutes and try again.'],
            'bgca_daily_limit' => ['Floating assistant: daily limit reached', 'The assistant has answered all the questions it can for today. Please try again tomorrow, or contact us directly.'],
        ];
        foreach ($copy as $key => [$label, $text]) {
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'Floating Assistant'];
        }
        return $defaults;
    }

    public static function word(string $key): string
    {
        $defaults = self::wordingDefaults([]);
        $fallback = (string) ($defaults[$key]['text'] ?? '');
        return function_exists('bg_str') ? (string) bg_str($key, $fallback) : $fallback;
    }

    public function requireCore(): void
    {
        if (self::coreActive() || !current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-warning"><p><strong>BosqueGrande Floating Assistant</strong> needs BosqueGrande 5.0 or later. '
            . 'Install and activate it, and the assistant will be available.</p></div>';
    }

    public function menu(): void
    {
        $parent = self::coreActive() ? 'bosquegrande' : 'options-general.php';
        add_submenu_page($parent, 'Floating Assistant', 'Floating Assistant', 'manage_options', 'bgca-settings', [$this, 'renderSettings']);
    }

    public function renderSettings(): void
    {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $status = self::status();
        $brand = self::brandColor();
        echo '<div class="wrap"><h1>Floating Assistant</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        echo '<p>A button on your public pages that opens a small chat. Answers come from your site\'s public facts and the '
            . '<a href="' . esc_url(TrainingPage::url()) . '">training info</a> you mark for visitors. When those do not answer a question, '
            . 'the assistant says so and forwards it to your designated contact instead of guessing.</p>';
        if ($status['ready']) {
            echo '<div class="notice notice-info inline"><p>Ready. Answers use your own ' . esc_html(self::providerLabel($status['provider']))
                . ' key from <a href="' . esc_url(self::toolsUrl()) . '">Assisted Tools</a>; the provider bills that account for each question.</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>The button is hidden right now.</strong> ' . esc_html($status['reason'])
                . (self::coreActive() ? ' <a href="' . esc_url(self::toolsUrl()) . '">Open Assisted Tools</a>.' : '') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bgca_save');
        echo '<input type="hidden" name="action" value="bgca_save"><h2>Button</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Show the button</th><td><label><input type="checkbox" name="enabled" value="1"' . checked(1, $s['enabled'], false)
            . '> On public pages</label></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-side">Corner</label></th><td><select id="bgca-side" name="side">'
            . '<option value="left"' . selected('left', $s['side'], false) . '>Bottom left</option>'
            . '<option value="right"' . selected('right', $s['side'], false) . '>Bottom right</option></select>'
            . '<p class="description">Bottom left keeps clear of the subscribe and donate buttons most sites put bottom right.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-edge">Distance from that side</label></th><td><input id="bgca-edge" type="number" name="edge" min="0" max="600" value="'
            . esc_attr((string) $s['edge']) . '" class="small-text"> px</td></tr>';
        echo '<tr><th scope="row"><label for="bgca-offset">Distance from the bottom</label></th><td><input id="bgca-offset" type="number" name="offset" min="0" max="600" value="'
            . esc_attr((string) $s['offset']) . '" class="small-text"> px<p class="description">The button also steps upward on its own when another fixed button already sits in its spot.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-color">Color</label></th><td><input id="bgca-color" type="text" name="color" value="' . esc_attr((string) $s['color'])
            . '" placeholder="' . esc_attr($brand) . '" class="regular-text code" pattern="#[0-9A-Fa-f]{6}|#[0-9A-Fa-f]{3}|">'
            . '<p class="description">Leave blank to use BosqueGrande\'s main color (' . esc_html($brand) . '). Text color is chosen for contrast.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-z">Stack order (z-index)</label></th><td><input id="bgca-z" type="number" name="zindex" min="10" max="2147483000" value="'
            . esc_attr((string) $s['zindex']) . '" class="regular-text"></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-hide">Hide on these pages</label></th><td><textarea id="bgca-hide" name="hide_on" rows="3" class="large-text code">'
            . esc_textarea((string) $s['hide_on']) . '</textarea><p class="description">One page slug or address path per line, for example <code>donate</code> or <code>/checkout/</code>.</p></td></tr>';
        echo '</table><h2>Limits on your provider bill</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bgca-pv">Questions per visitor</label></th><td><input id="bgca-pv" type="number" name="per_visitor" min="1" max="100" value="'
            . esc_attr((string) $s['per_visitor']) . '" class="small-text"> every 10 minutes</td></tr>';
        echo '<tr><th scope="row"><label for="bgca-cap">Questions per day, whole site</label></th><td><input id="bgca-cap" type="number" name="daily_cap" min="0" max="100000" value="'
            . esc_attr((string) $s['daily_cap']) . '" class="small-text"><p class="description">Each question is one request to your provider. 0 means no daily limit.'
            . ' Answered today: ' . (int) get_option('bgca_day_' . gmdate('Ymd'), 0) . '.</p></td></tr>';
        echo '</table><h2>Removal</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">When this plugin is deleted</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"'
            . checked(1, $s['delete_on_uninstall'], false) . '> Also delete all training info and these settings</label>'
            . '<p class="description">Off by default, so reinstalling picks everything back up.</p></td></tr>';
        echo '</table><p><button class="button button-primary">Save</button></p></form>';
        if (self::coreActive()) {
            echo '<p>Wording on the button and in the chat is edited with the rest of your site\'s wording in '
                . '<a href="' . esc_url(admin_url('admin.php?page=bosquegrande-wording-styling')) . '">Wording &amp; Styling</a> (group "Floating Assistant").</p>';
        }
        echo '</div>';
    }

    public function saveSettings(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to change these settings.');
        check_admin_referer('bgca_save');
        $in = wp_unslash($_POST);
        $hide = array_filter(array_map(
            static fn(string $line): string => substr(sanitize_text_field($line), 0, 200),
            preg_split('/\R/', (string) ($in['hide_on'] ?? '')) ?: []
        ));
        update_option(self::OPTION, [
            'enabled' => empty($in['enabled']) ? 0 : 1,
            'side' => ($in['side'] ?? '') === 'right' ? 'right' : 'left',
            'edge' => (int) ($in['edge'] ?? 20),
            'offset' => (int) ($in['offset'] ?? 24),
            'zindex' => (int) ($in['zindex'] ?? 99990),
            'color' => (string) (sanitize_hex_color((string) ($in['color'] ?? '')) ?? ''),
            'hide_on' => implode("\n", array_slice($hide, 0, 50)),
            'per_visitor' => (int) ($in['per_visitor'] ?? 8),
            'daily_cap' => (int) ($in['daily_cap'] ?? 300),
            'delete_on_uninstall' => empty($in['delete_on_uninstall']) ? 0 : 1,
        ], false);
        wp_safe_redirect(add_query_arg(['page' => 'bgca-settings', 'saved' => 1], admin_url(self::coreActive() ? 'admin.php' : 'options-general.php')));
        exit;
    }

    /* ── Visitor chat ─────────────────────────────────────────── */

    public function routes(): void
    {
        register_rest_route('bosquegrande/v1', '/' . self::ROUTE, [
            'methods' => 'POST',
            // Public by design, like the page it serves; bounded by the limits below.
            'permission_callback' => '__return_true',
            'callback' => [$this, 'chat'],
        ]);
    }

    public function chat(\WP_REST_Request $req): \WP_REST_Response
    {
        $s = self::settings();
        if (!$s['enabled'] || !self::status()['ready']) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('bgca_offline')], 503);
        }
        $message = trim(sanitize_textarea_field((string) $req->get_param('message')));
        if ($message === '' || Training::length($message) > 500) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('bgca_too_long')], 400);
        }

        $ip = self::clientAddress();
        $visitorKey = 'bgca_rate_' . md5($ip);
        $used = (int) get_transient($visitorKey);
        if ($used >= (int) $s['per_visitor']) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('bgca_slow_down')], 429);
        }
        if (!$this->claimDaily((int) $s['daily_cap'])) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('bgca_daily_limit')], 429);
        }
        set_transient($visitorKey, $used + 1, 10 * MINUTE_IN_SECONDS);

        $history = [];
        $raw = json_decode((string) $req->get_param('history'), true);
        if (is_array($raw)) {
            foreach (array_slice($raw, -6) as $turn) {
                if (!is_array($turn)) continue;
                $text = mb_substr(sanitize_textarea_field((string) ($turn['text'] ?? '')), 0, 600);
                if ($text !== '') $history[] = ['role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => $text];
            }
        }
        $page = self::samePage((string) $req->get_param('page'));

        try {
            $assistant = \BosqueGrande\Core\Plugin::instance()->container()->get('intelligence.assistant');
            $reply = (string) $assistant->askPublic($message, $history, $page, $ip);
        } catch (\Throwable $e) {
            $reply = self::word('bgca_error');
        }
        return new \WP_REST_Response(['ok' => true, 'message' => $reply], 200);
    }

    /**
     * Count one question against the site's daily limit in a single atomic
     * UPDATE, so simultaneous questions neither overshoot the limit nor
     * wrongly see it as reached. False when the limit is reached.
     */
    private function claimDaily(int $cap): bool
    {
        global $wpdb;
        $key = 'bgca_day_' . gmdate('Ymd');
        $created = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $key
        ));
        if ($created) {
            // A new day: keep only today's counter.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", 'bgca\_day\_2%', $key));
        }
        $claimed = $cap > 0
            ? $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value + 0 < %d", $key, $cap))
            : $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $key));
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        return (int) $claimed === 1;
    }

    /**
     * The visitor's address for the per-visitor limit. IPv6 visitors are
     * grouped by their /64 network, which one household or device controls.
     * Sites behind a proxy that does not pass the real address to PHP can
     * supply it through the bgca_client_address filter.
     */
    public static function clientAddress(): string
    {
        $ip = (string) apply_filters('bgca_client_address', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (str_contains($ip, ':') && function_exists('inet_pton')) {
            $packed = @inet_pton($ip);
            if (is_string($packed) && strlen($packed) === 16) return bin2hex(substr($packed, 0, 8)) . '::/64';
        }
        return $ip;
    }

    /** The page a question came from, kept only when it is on this site. */
    public static function samePage(string $url): string
    {
        $url = esc_url_raw($url, ['http', 'https']);
        if ($url === '') return '';
        $page = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($page) || !is_array($home) || isset($page['user']) || isset($page['pass'])) return '';
        $port = static fn(array $u): int => (int) ($u['port'] ?? (($u['scheme'] ?? '') === 'https' ? 443 : 80));
        $same = strtolower((string) ($page['host'] ?? '')) === strtolower((string) ($home['host'] ?? ''))
            && $port($page) === $port($home)
            && str_starts_with((string) ($page['path'] ?? '/'), rtrim((string) ($home['path'] ?? '/'), '/') . '/');
        return $same ? $url : '';
    }

    /* ── Button ───────────────────────────────────────────────── */

    private function shouldRender(): bool
    {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed()) return false;
        $s = self::settings();
        if (!$s['enabled'] || !self::status()['ready']) return false;
        $path = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') . '/';
        foreach (preg_split('/\R/', (string) $s['hide_on']) ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;
            if (str_contains($rule, '/')) {
                if (str_starts_with($path, '/' . trim($rule, '/') . '/')) return false;
            } elseif (is_page($rule) || is_single($rule)) {
                return false;
            }
        }
        return true;
    }

    public static function brandColor(): string
    {
        if (class_exists('\BosqueGrande\Core\Organization')) return \BosqueGrande\Core\Organization::brandColor();
        return '#1F5130';
    }

    /** Black or white, whichever reads better on the given color. */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $channel = static function (string $h): float {
            $v = hexdec($h) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#ffffff' : '#111111';
    }

    public function enqueue(): void
    {
        if (!$this->shouldRender()) return;
        $s = self::settings();
        $color = (string) ($s['color'] ?: self::brandColor());
        wp_enqueue_style('bgca-widget', BGCA_URL . 'assets/widget.css', [], BGCA_VERSION);
        wp_add_inline_style('bgca-widget', sprintf(
            '#bgca-wrap{--bgca-color:%1$s;--bgca-on:%2$s;%3$s:%4$dpx;bottom:%5$dpx;z-index:%6$d;align-items:%7$s}',
            esc_attr($color), esc_attr(self::textOn($color)), $s['side'] === 'right' ? 'right' : 'left',
            (int) $s['edge'], (int) $s['offset'], (int) $s['zindex'], $s['side'] === 'right' ? 'flex-end' : 'flex-start'
        ));
        wp_enqueue_script('bgca-widget', BGCA_URL . 'assets/widget.js', [], BGCA_VERSION, true);
        wp_localize_script('bgca-widget', 'BGCA', [
            'endpoint' => esc_url_raw(rest_url('bosquegrande/v1/' . self::ROUTE)),
            'greeting' => self::word('bgca_greeting'),
            'thinking' => self::word('bgca_thinking'),
            'error' => self::word('bgca_error'),
        ]);
    }

    public function render(): void
    {
        if (!wp_script_is('bgca-widget', 'enqueued')) return;
        $label = self::word('bgca_button_label');
        ?>
<div id="bgca-wrap">
  <div id="bgca-panel" role="dialog" aria-modal="false" aria-labelledby="bgca-title" hidden>
    <div id="bgca-head"><span id="bgca-title"><?php echo esc_html(self::word('bgca_panel_title')); ?></span>
      <button type="button" id="bgca-close" aria-label="<?php echo esc_attr(self::word('bgca_close')); ?>">&times;</button></div>
    <div id="bgca-log" role="log" aria-live="polite"></div>
    <form id="bgca-form"><label class="screen-reader-text" for="bgca-in"><?php echo esc_html(self::word('bgca_panel_title')); ?></label>
      <input id="bgca-in" type="text" maxlength="500" autocomplete="off" placeholder="<?php echo esc_attr(self::word('bgca_placeholder')); ?>">
      <button id="bgca-send" type="submit"><?php echo esc_html(self::word('bgca_send')); ?></button></form>
    <p id="bgca-note"><?php echo esc_html(self::word('bgca_note')); ?></p>
  </div>
  <button type="button" id="bgca-btn" aria-expanded="false" aria-controls="bgca-panel">
    <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm8 11.5a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5ZM12 5.5c-1.9 0-3.3 1.2-3.4 3h1.9c.1-.8.7-1.3 1.5-1.3.9 0 1.5.5 1.5 1.3 0 .6-.3.9-1.1 1.4-.9.6-1.4 1.2-1.3 2.4v.4h1.8v-.3c0-.7.3-1 1.1-1.5.9-.6 1.5-1.3 1.5-2.4 0-1.8-1.5-3-3.5-3Z"/></svg>
    <span><?php echo esc_html($label); ?></span>
  </button>
</div>
        <?php
    }
}
