<?php

declare(strict_types=1);

namespace BGCompanion\Actions;

if (!defined('ABSPATH')) exit;

/**
 * Floating action buttons for public pages: Subscribe (to a BosqueGrande
 * newsletter group), Donate (to BosqueGrande's giving page), and one custom
 * link, plus a QR code of the first action for people reading on a big
 * screen. Subscribing goes through BosqueGrande's own double opt-in; this
 * plugin only provides the buttons and a signup page.
 */
final class Plugin
{
    public const OPTION = 'bgcf_settings';

    /** @var array<string, int|string> */
    private const DEFAULTS = [
        'subscribe' => 1,
        'group' => 'general',
        'signup_page' => 0,
        'signup_path' => 'bg-newsletter-signup',
        'donate' => 0,
        'custom' => 0,
        'custom_url' => '',
        'qr' => 1,
        'side' => 'right',
        'edge' => 24,
        'offset' => 24,
        'zindex' => 9999,
        'color' => '',
        'new_tab' => 0,
        'hide_on' => '',
    ];

    public static function boot(): void
    {
        $self = new self();
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_action('init', [self::class, 'rewrite']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'bgcf_signup';
            return $vars;
        });
        add_filter('redirect_canonical', static fn($redirect) => self::isSignupPage() ? false : $redirect);
        add_filter('pre_get_document_title', static fn(string $title): string
            => self::isSignupPage() ? self::word('bgcf_signup_title') . ' - ' . get_bloginfo('name') : $title);
        add_action('template_redirect', [$self, 'signupPage'], 0);
        add_action('wp_enqueue_scripts', [$self, 'enqueue']);
        add_action('wp_footer', [$self, 'render']);
        add_action('admin_menu', [$self, 'menu'], 62);
        add_action('admin_post_bgcf_save', [$self, 'save']);
        add_action('admin_notices', [$self, 'requireCore']);
    }

    /* ── Settings ─────────────────────────────────────────────── */

    /** @return array<string, int|string> */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
        return [
            'subscribe' => (int) $s['subscribe'] === 1 ? 1 : 0,
            'group' => sanitize_key((string) $s['group']) ?: 'general',
            'signup_page' => max(0, (int) $s['signup_page']),
            'signup_path' => self::cleanPath((string) $s['signup_path']) ?: 'bg-newsletter-signup',
            'donate' => (int) $s['donate'] === 1 ? 1 : 0,
            'custom' => (int) $s['custom'] === 1 ? 1 : 0,
            'custom_url' => esc_url_raw((string) $s['custom_url']),
            'qr' => (int) $s['qr'] === 1 ? 1 : 0,
            'side' => $s['side'] === 'left' ? 'left' : 'right',
            'edge' => max(0, min(600, (int) $s['edge'])),
            'offset' => max(0, min(600, (int) $s['offset'])),
            'zindex' => max(10, min(2147483000, (int) $s['zindex'])),
            'color' => (string) (sanitize_hex_color((string) $s['color']) ?? ''),
            'new_tab' => (int) $s['new_tab'] === 1 ? 1 : 0,
            'hide_on' => (string) $s['hide_on'],
        ];
    }

    private static function cleanPath(string $path): string
    {
        $path = strtolower(trim($path, " /\t\n"));
        $path = (string) preg_replace('#[^a-z0-9/_-]+#', '-', $path);
        return trim((string) preg_replace('#/+#', '/', $path), '/-');
    }

    public static function coreActive(): bool
    {
        return defined('BG_VERSION') && function_exists('bg_str');
    }

    /** @param array<string, array<string, string>> $defaults */
    public static function wordingDefaults(array $defaults): array
    {
        $copy = [
            'bgcf_subscribe_label' => ['Floating actions: subscribe button', 'Subscribe'],
            'bgcf_donate_label' => ['Floating actions: donate button', 'Donate'],
            'bgcf_custom_label' => ['Floating actions: custom button', 'Get involved'],
            'bgcf_qr_label' => ['Floating actions: QR code description', 'Scan with your phone to open this link'],
            'bgcf_signup_title' => ['Newsletter signup page: browser title', 'Newsletter signup'],
            'bgcf_signup_eyebrow' => ['Newsletter signup page: small heading', 'Newsletter'],
            'bgcf_signup_heading' => ['Newsletter signup page: heading', 'Subscribe to our newsletter'],
            'bgcf_signup_intro' => ['Newsletter signup page: introduction', 'Enter your email address. We will send you a link to confirm, and your subscription starts once you use it.'],
            'bgcf_signup_unavailable' => ['Newsletter signup page: unavailable message', 'Newsletter signup is not available right now. Please try again later.'],
            'bgcf_signup_privacy' => ['Newsletter signup page: note under the form', 'You can unsubscribe at any time with the link in every newsletter.'],
            'bgcf_signup_skip' => ['Newsletter signup page: skip link', 'Skip to the signup form'],
        ];
        foreach ($copy as $key => [$label, $text]) {
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'Floating Actions'];
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
        echo '<div class="notice notice-warning"><p><strong>BosqueGrande Floating Actions</strong> needs BosqueGrande 5.0 or later for its newsletter signup and donation links.</p></div>';
    }

    /* ── Links ────────────────────────────────────────────────── */

    public static function signupUrl(): string
    {
        $s = self::settings();
        if ($s['signup_page'] > 0 && get_post_status((int) $s['signup_page']) === 'publish') return (string) get_permalink((int) $s['signup_page']);
        return home_url('/' . $s['signup_path'] . '/');
    }

    public static function donateUrl(): string
    {
        return function_exists('bgev_public_donation_url') ? (string) bgev_public_donation_url() : '';
    }

    /** @return list<array{key: string, label: string, url: string}> Enabled actions, in display order. */
    public static function actions(): array
    {
        $s = self::settings();
        $out = [];
        if ($s['subscribe'] && shortcode_exists('bg_subscribe')) {
            $out[] = ['key' => 'subscribe', 'label' => self::word('bgcf_subscribe_label'), 'url' => self::signupUrl()];
        }
        if ($s['donate'] && self::donateUrl() !== '') {
            $out[] = ['key' => 'donate', 'label' => self::word('bgcf_donate_label'), 'url' => self::donateUrl()];
        }
        if ($s['custom'] && $s['custom_url'] !== '') {
            $out[] = ['key' => 'custom', 'label' => self::word('bgcf_custom_label'), 'url' => (string) $s['custom_url']];
        }
        return $out;
    }

    /* ── Signup page ──────────────────────────────────────────── */

    public static function rewrite(): void
    {
        $s = self::settings();
        if ($s['signup_page'] === 0) {
            add_rewrite_rule('^' . preg_quote((string) $s['signup_path'], '#') . '/?$', 'index.php?bgcf_signup=1', 'top');
        }
        if (get_option('bgcf_flush')) {
            delete_option('bgcf_flush');
            flush_rewrite_rules(false);
        }
    }

    public static function isSignupPage(): bool
    {
        $s = self::settings();
        if ($s['signup_page'] > 0) return false;
        if ((int) get_query_var('bgcf_signup') === 1) return true;
        // Also answer before permalinks are flushed.
        $request = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $expected = trim($home . '/' . $s['signup_path'], '/');
        return $request === $expected;
    }

    public function signupPage(): void
    {
        if (!self::isSignupPage()) return;
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_404 = false;
            $wp_query->is_home = false;
        }
        status_header(200);
        nocache_headers();
        $group = (string) self::settings()['group'];
        $form = shortcode_exists('bg_subscribe') ? do_shortcode('[bg_subscribe group="' . esc_attr($group) . '"]') : '';
        $framed = function_exists('bgev_output_owned_document');
        $content = '<div class="bgcf-signup' . ($framed ? ' bgcf-framed' : '') . '"><section class="bgcf-signup-card" aria-labelledby="bgcf-signup-title">'
            . '<p class="bgcf-eyebrow">' . esc_html(self::word('bgcf_signup_eyebrow')) . '</p>'
            . '<h1 id="bgcf-signup-title">' . esc_html(self::word('bgcf_signup_heading')) . '</h1>'
            . '<p>' . esc_html(self::word('bgcf_signup_intro')) . '</p>'
            . '<div class="bgcf-form">' . ($form !== ''
                ? $form
                : '<p role="alert"><strong>' . esc_html(self::word('bgcf_signup_unavailable')) . '</strong></p>') . '</div>'
            . '<p class="bgcf-note">' . esc_html(self::word('bgcf_signup_privacy')) . '</p></section></div>'
            . '<style>.bgcf-signup{box-sizing:border-box;padding:clamp(32px,7vw,88px) 20px}'
            . '.bgcf-signup-card{box-sizing:border-box;width:min(680px,100%);margin:0 auto;padding:clamp(24px,5vw,48px);border:1px solid #dcdcde;'
            . 'border-top:5px solid var(--bgc-primary,#1F5130);border-radius:14px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08)}'
            . '.bgcf-signup-card h1{margin:.2em 0 .6em;line-height:1.15}.bgcf-eyebrow{margin:0;font-weight:700;font-size:13px;letter-spacing:.08em;'
            . 'text-transform:uppercase;color:var(--bgc-primary,#1F5130)}.bgcf-form{margin:24px 0 16px}.bgcf-form .bg-subscribe{max-width:none!important}'
            . '.bgcf-note{margin-bottom:0;font-size:.9rem;color:#50575e}'
            . '.bgcf-framed{padding:clamp(8px,3vw,32px) 0}.bgcf-framed .bgcf-signup-card{border:0;box-shadow:none;padding:0}</style>';
        if ($framed) {
            bgev_output_owned_document($content, [
                'context' => 'subscription',
                'body_class' => 'bgcf-signup-page',
                'main_id' => 'bgcf-signup-main',
                'skip_label' => self::word('bgcf_signup_skip'),
            ]);
        } else {
            get_header();
            echo $content; // Built from escaped parts above.
            get_footer();
        }
        exit;
    }

    /* ── Buttons ──────────────────────────────────────────────── */

    private function shouldRender(): bool
    {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || self::isSignupPage()) return false;
        if (!self::actions()) return false;
        foreach (['bg_register', 'bg_donation', 'bg_monthly_donation', 'bg_survey'] as $var) {
            if (get_query_var($var)) return false;
        }
        $path = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') . '/';
        $own = [self::signupUrl(), self::donateUrl(), home_url('/bg-organizer-app/')];
        if (function_exists('bgev_public_profile_url')) $own[] = (string) bgev_public_profile_url();
        foreach ($own as $url) {
            $ownPath = '/' . trim((string) wp_parse_url($url, PHP_URL_PATH), '/') . '/';
            if ($url !== '' && $ownPath !== '//' && $path === $ownPath) return false;
        }
        foreach (preg_split('/\R/', (string) self::settings()['hide_on']) ?: [] as $rule) {
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
        wp_enqueue_style('bgcf-actions', BGCF_URL . 'assets/actions.css', [], BGCF_VERSION);
        wp_add_inline_style('bgcf-actions', sprintf(
            '.bgcf-wrap{--bgcf-color:%1$s;--bgcf-on:%2$s;%3$s:%4$dpx;bottom:%5$dpx;z-index:%6$d;align-items:%7$s}',
            esc_attr($color), esc_attr(self::textOn($color)), $s['side'] === 'left' ? 'left' : 'right',
            (int) $s['edge'], (int) $s['offset'], (int) $s['zindex'], $s['side'] === 'left' ? 'flex-start' : 'flex-end'
        ));
    }

    public function render(): void
    {
        if (!wp_style_is('bgcf-actions', 'enqueued')) return;
        $s = self::settings();
        $actions = self::actions();
        $target = $s['new_tab'] ? ' target="_blank" rel="noopener"' : '';
        echo '<nav class="bgcf-wrap" aria-label="Quick actions">';
        if ($s['qr']) {
            try {
                $qr = new Qr($actions[0]['url'], 1);
                echo '<a class="bgcf-qr" href="' . esc_url($actions[0]['url']) . '"' . $target . ' aria-label="'
                    . esc_attr($actions[0]['label'] . ': ' . self::word('bgcf_qr_label')) . '">' . $qr->svg(2) . '</a>';
            } catch (\Throwable $e) {
                // A very long custom address can exceed QR capacity; the buttons still work.
            }
        }
        foreach ($actions as $i => $action) {
            echo '<a class="bgcf-action bgcf-' . esc_attr($action['key']) . ($i > 0 ? ' bgcf-secondary' : '') . '" href="' . esc_url($action['url']) . '"' . $target . '>'
                . esc_html($action['label']) . '</a>';
        }
        echo '</nav>';
    }

    /* ── Admin ────────────────────────────────────────────────── */

    public function menu(): void
    {
        $parent = self::coreActive() ? 'bosquegrande' : 'options-general.php';
        add_submenu_page($parent, 'Floating Actions', 'Floating Actions', 'manage_options', 'bgcf-settings', [$this, 'renderSettings']);
    }

    public function renderSettings(): void
    {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $groups = [];
        if (class_exists('\BosqueGrande\Core\Plugin')) {
            try {
                $groups = \BosqueGrande\Core\Plugin::instance()->container()->get('correspondence.groups')->all();
            } catch (\Throwable $e) {
                $groups = [];
            }
        }
        $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']) ?: [];
        $donate = self::donateUrl();
        echo '<div class="wrap"><h1>Floating Actions</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        $problems = [
            'empty' => 'The address cannot be empty.',
            'reserved' => 'That address belongs to WordPress or BosqueGrande.',
            'taken' => 'One of your pages or posts already uses that address. Choose another, or pick that page as the signup page.',
        ];
        $problem = sanitize_key((string) ($_GET['path_problem'] ?? ''));
        if (isset($problems[$problem])) {
            echo '<div class="notice notice-error is-dismissible"><p>The signup page address was not changed. ' . esc_html($problems[$problem]) . '</p></div>';
        }
        echo '<p>Buttons that stay in the corner of your public pages. The first one also gets a QR code on large screens, so people reading on a computer can open it on their phone.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bgcf_save');
        echo '<input type="hidden" name="action" value="bgcf_save"><h2>Actions</h2><table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Subscribe</th><td><label><input type="checkbox" name="subscribe" value="1"' . checked(1, $s['subscribe'], false) . '> Show a Subscribe button</label>';
        echo '<p><label for="bgcf-group">Newsletter group</label> <select id="bgcf-group" name="group">';
        if (!$groups) echo '<option value="' . esc_attr((string) $s['group']) . '">' . esc_html((string) $s['group']) . '</option>';
        foreach ($groups as $g) {
            echo '<option value="' . esc_attr((string) $g->slug) . '"' . selected((string) $s['group'], (string) $g->slug, false) . '>' . esc_html((string) $g->name) . '</option>';
        }
        echo '</select></p><p><label for="bgcf-page">Signup page</label> <select id="bgcf-page" name="signup_page"><option value="0">Built-in page at the address below</option>';
        foreach ($pages as $p) {
            echo '<option value="' . (int) $p->ID . '"' . selected((int) $s['signup_page'], (int) $p->ID, false) . '>' . esc_html(get_the_title($p)) . '</option>';
        }
        echo '</select></p><p><label for="bgcf-path">Built-in page address</label> <code>' . esc_html(home_url('/')) . '</code><input id="bgcf-path" type="text" name="signup_path" value="'
            . esc_attr((string) $s['signup_path']) . '" class="regular-text code" style="width:16em"><code>/</code></p>'
            . '<p class="description">Choose one of your own pages instead if it already has a <code>[bg_subscribe group="' . esc_html((string) $s['group']) . '"]</code> form.</p></td></tr>';

        echo '<tr><th scope="row">Donate</th><td><label><input type="checkbox" name="donate" value="1"' . checked(1, $s['donate'], false) . '> Show a Donate button</label>'
            . '<p class="description">' . ($donate !== ''
                ? 'Opens your giving page: <code>' . esc_html($donate) . '</code> (set in BosqueGrande &gt; Wording &amp; Styling, site navigation).'
                : 'Needs BosqueGrande\'s giving page.') . '</p></td></tr>';

        echo '<tr><th scope="row">Custom link</th><td><label><input type="checkbox" name="custom" value="1"' . checked(1, $s['custom'], false) . '> Show a button to this address:</label><br>'
            . '<input type="url" name="custom_url" value="' . esc_attr((string) $s['custom_url']) . '" class="regular-text code" placeholder="https://">'
            . '<p class="description">For example your volunteer page or events calendar.</p></td></tr>';

        echo '<tr><th scope="row">QR code</th><td><label><input type="checkbox" name="qr" value="1"' . checked(1, $s['qr'], false) . '> Show a QR code for the first button on large screens</label>'
            . '<p class="description">Made on your own site; no outside service sees the address.</p></td></tr>';
        echo '<tr><th scope="row">Links</th><td><label><input type="checkbox" name="new_tab" value="1"' . checked(1, $s['new_tab'], false) . '> Open in a new tab</label></td></tr>';

        echo '</table><h2>Placement and color</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bgcf-side">Corner</label></th><td><select id="bgcf-side" name="side"><option value="right"' . selected('right', $s['side'], false)
            . '>Bottom right</option><option value="left"' . selected('left', $s['side'], false) . '>Bottom left</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="bgcf-edge">Distance from that side</label></th><td><input id="bgcf-edge" type="number" name="edge" min="0" max="600" value="' . esc_attr((string) $s['edge']) . '" class="small-text"> px</td></tr>';
        echo '<tr><th scope="row"><label for="bgcf-offset">Distance from the bottom</label></th><td><input id="bgcf-offset" type="number" name="offset" min="0" max="600" value="' . esc_attr((string) $s['offset']) . '" class="small-text"> px</td></tr>';
        echo '<tr><th scope="row"><label for="bgcf-color">Color</label></th><td><input id="bgcf-color" type="text" name="color" value="' . esc_attr((string) $s['color'])
            . '" placeholder="' . esc_attr(self::brandColor()) . '" class="regular-text code"><p class="description">Leave blank to use BosqueGrande\'s main color. Text color is chosen for contrast.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgcf-z">Stack order (z-index)</label></th><td><input id="bgcf-z" type="number" name="zindex" min="10" max="2147483000" value="' . esc_attr((string) $s['zindex']) . '" class="regular-text"></td></tr>';
        echo '<tr><th scope="row"><label for="bgcf-hide">Hide on these pages</label></th><td><textarea id="bgcf-hide" name="hide_on" rows="3" class="large-text code">' . esc_textarea((string) $s['hide_on'])
            . '</textarea><p class="description">One page slug or address path per line. The buttons already stay off the signup, giving, registration, survey, My Profile, and organizer app pages.</p></td></tr>';
        echo '</table><p><button class="button button-primary">Save</button></p></form>';
        if (self::coreActive()) {
            echo '<p>Button labels and the signup page text are edited in <a href="' . esc_url(admin_url('admin.php?page=bosquegrande-wording-styling')) . '">Wording &amp; Styling</a> (group "Floating Actions").</p>';
        }
        echo '</div>';
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to change these settings.');
        check_admin_referer('bgcf_save');
        $in = wp_unslash($_POST);
        $before = self::settings();
        $path = self::cleanPath((string) ($in['signup_path'] ?? ''));
        $problem = $path !== $before['signup_path'] ? self::pathProblem($path) : '';
        if ($problem !== '') $path = (string) $before['signup_path'];
        $hide = array_filter(array_map(
            static fn(string $line): string => substr(sanitize_text_field($line), 0, 200),
            preg_split('/\R/', (string) ($in['hide_on'] ?? '')) ?: []
        ));
        update_option(self::OPTION, [
            'subscribe' => empty($in['subscribe']) ? 0 : 1,
            'group' => sanitize_key((string) ($in['group'] ?? 'general')),
            'signup_page' => max(0, (int) ($in['signup_page'] ?? 0)),
            'signup_path' => $path,
            'donate' => empty($in['donate']) ? 0 : 1,
            'custom' => empty($in['custom']) ? 0 : 1,
            'custom_url' => esc_url_raw((string) ($in['custom_url'] ?? ''), ['http', 'https']),
            'qr' => empty($in['qr']) ? 0 : 1,
            'side' => ($in['side'] ?? '') === 'left' ? 'left' : 'right',
            'edge' => (int) ($in['edge'] ?? 24),
            'offset' => (int) ($in['offset'] ?? 24),
            'zindex' => (int) ($in['zindex'] ?? 9999),
            'color' => (string) (sanitize_hex_color((string) ($in['color'] ?? '')) ?? ''),
            'new_tab' => empty($in['new_tab']) ? 0 : 1,
            'hide_on' => implode("\n", array_slice($hide, 0, 50)),
        ], false);
        $after = self::settings();
        // Rebuild permalinks on the next request, once only the new address is registered.
        if ($after['signup_path'] !== $before['signup_path'] || $after['signup_page'] !== $before['signup_page']) {
            update_option('bgcf_flush', 1, true);
        }
        $args = ['page' => 'bgcf-settings', 'saved' => 1];
        if ($problem !== '') $args['path_problem'] = $problem;
        wp_safe_redirect(add_query_arg($args, admin_url(self::coreActive() ? 'admin.php' : 'options-general.php')));
        exit;
    }

    /**
     * Why an address cannot be the built-in signup page (empty, reserved, or
     * taken), or '' when it can.
     * It must not take over one of the site's own pages or a BosqueGrande
     * or WordPress route.
     */
    public static function pathProblem(string $path): string
    {
        if ($path === '') return 'empty';
        $first = explode('/', $path)[0];
        $reserved = ['wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login.php', 'feed', 'comments', 'events', 'bg-organizer-app',
            'bg-organizer-app-offline', 'bg-organizer-app-manifest.webmanifest', 'bg-register', 'bg-donation', 'bg-monthly-donation', 'bg-survey'];
        if (in_array($first, $reserved, true)) return 'reserved';
        if (url_to_postid(home_url('/' . $path . '/')) > 0 || get_page_by_path($path) !== null) {
            return 'taken';
        }
        foreach ([self::donateUrl(), function_exists('bgev_public_profile_url') ? (string) bgev_public_profile_url() : ''] as $url) {
            if ($url !== '' && trim((string) wp_parse_url($url, PHP_URL_PATH), '/') === trim((string) wp_parse_url(home_url('/' . $path . '/'), PHP_URL_PATH), '/')) {
                return 'reserved';
            }
        }
        return '';
    }
}
