<?php

declare(strict_types=1);

namespace BGCompanion\Launcher;

if (!defined('ABSPATH')) exit;

/**
 * Settings for BosqueGrande's own Organizer App button. BosqueGrande draws
 * the button (shown by default on the front page to signed-in organizers);
 * this plugin decides who sees it and where, restyles it, and puts a
 * prominent open-or-install button above the Organizer Portal. The app
 * itself always requires an organizer sign-in.
 */
final class Plugin
{
    public const OPTION = 'bgol_settings';

    /** @var array<string, int|string> */
    private const DEFAULTS = [
        'audience' => 'organizers',
        'pages' => 'front',
        'position' => 'bottom-right',
        'color' => '',
        'portal_button' => 1,
    ];

    public static function boot(): void
    {
        $self = new self();
        add_filter('bg_organizer_launcher_show', [self::class, 'show'], 10, 2);
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_action('wp_head', [$self, 'styles'], 31);
        add_action('wp_enqueue_scripts', [$self, 'enqueue']);
        add_filter('do_shortcode_tag', [$self, 'portalButton'], 20, 2);
        add_action('admin_menu', [$self, 'menu'], 63);
        add_action('admin_post_bgol_save', [$self, 'save']);
        add_action('admin_notices', [$self, 'requireCore']);
    }

    /** @return array<string, int|string> */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
        return [
            'audience' => in_array($s['audience'], ['organizers', 'everyone', 'nobody'], true) ? (string) $s['audience'] : 'organizers',
            'pages' => $s['pages'] === 'all' ? 'all' : 'front',
            'position' => in_array($s['position'], ['bottom-right', 'bottom-left', 'top-center'], true) ? (string) $s['position'] : 'bottom-right',
            'color' => (string) (sanitize_hex_color((string) $s['color']) ?? ''),
            'portal_button' => (int) $s['portal_button'] === 1 ? 1 : 0,
        ];
    }

    public static function coreActive(): bool
    {
        return defined('BG_VERSION') && version_compare((string) BG_VERSION, '5.0.0', '>=');
    }

    /** Decide for BosqueGrande whether its launcher shows on this page. */
    public static function show(bool $default, bool $isOrganizer = false): bool
    {
        $s = self::settings();
        if ($s['audience'] === 'nobody') return false;
        if ($s['pages'] === 'front' && !is_front_page()) return false;
        if (is_admin() || is_feed() || is_embed()) return false;
        return $s['audience'] === 'everyone' || $isOrganizer;
    }

    /** @param array<string, array<string, string>> $defaults */
    public static function wordingDefaults(array $defaults): array
    {
        $copy = [
            'bgol_portal_label' => ['Organizer Portal: open-or-install button', 'Open or install the Organizer App'],
            'bgol_portal_hint' => ['Organizer Portal: note under the button', 'Add it to your phone\'s home screen for check-in, walk-ins, and rosters at the door.'],
        ];
        foreach ($copy as $key => [$label, $text]) {
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'Organizer Launcher'];
        }
        return $defaults;
    }

    public static function word(string $key): string
    {
        $defaults = self::wordingDefaults([]);
        $fallback = (string) ($defaults[$key]['text'] ?? '');
        return function_exists('bg_str') ? (string) bg_str($key, $fallback) : $fallback;
    }

    public static function appUrl(): string
    {
        return (string) apply_filters('bg_organizer_home_url', home_url('/bg-organizer-app/'));
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

    /** Placement and color for BosqueGrande's launcher button. */
    public function styles(): void
    {
        if (is_admin()) return;
        $s = self::settings();
        $css = '';
        if ($s['position'] === 'bottom-left') {
            $css .= '.bg-organizer-home-launcher{left:16px!important;right:auto!important}';
        } elseif ($s['position'] === 'top-center') {
            $css .= '.bg-organizer-home-launcher{top:14px!important;bottom:auto!important;left:50%!important;right:auto!important;transform:translateX(-50%)}'
                . '.admin-bar .bg-organizer-home-launcher{top:46px!important}@media(max-width:782px){.admin-bar .bg-organizer-home-launcher{top:60px!important}}';
        }
        if ($s['color'] !== '') {
            $on = self::textOn((string) $s['color']);
            $css .= '.bg-organizer-home-launcher,.bgol-portal a{background:' . $s['color'] . '!important;color:' . $on . '!important}';
        } elseif ($s['portal_button'] && class_exists('\BosqueGrande\Core\Organization')) {
            $css .= '.bgol-portal a{color:' . self::textOn(\BosqueGrande\Core\Organization::brandColor()) . '!important}';
        }
        if ($css !== '') echo '<style id="bgol-launcher">' . $css . '</style>' . "\n";
    }

    public function enqueue(): void
    {
        if (self::settings()['portal_button']) wp_enqueue_style('bgol-portal', BGOL_URL . 'assets/portal.css', [], BGOL_VERSION);
    }

    /** A prominent open-or-install button above the Organizer Portal. */
    public function portalButton($output, $tag)
    {
        if ($tag !== 'bg_organizer_portal' || !is_string($output) || !self::settings()['portal_button']) return $output;
        if (str_contains($output, 'class="bgol-portal"')) return $output;
        return '<div class="bgol-portal"><a href="' . esc_url(self::appUrl()) . '">' . esc_html(self::word('bgol_portal_label')) . '</a>'
            . '<p>' . esc_html(self::word('bgol_portal_hint')) . '</p></div>' . $output;
    }

    public function requireCore(): void
    {
        if (self::coreActive() || !current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-warning"><p><strong>BosqueGrande Organizer Launcher</strong> needs BosqueGrande 5.0 or later.</p></div>';
    }

    public function menu(): void
    {
        $parent = self::coreActive() ? 'bosquegrande' : 'options-general.php';
        add_submenu_page($parent, 'Organizer Launcher', 'Organizer Launcher', 'manage_options', 'bgol-settings', [$this, 'renderSettings']);
    }

    public function renderSettings(): void
    {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $radio = static function (string $name, string $value, string $current, string $label): string {
            return '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . checked($value, $current, false) . '> ' . esc_html($label) . '</label><br>';
        };
        echo '<div class="wrap"><h1>Organizer Launcher</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        echo '<p>BosqueGrande shows an Organizer App button that takes event organizers to the app for check-in, walk-ins, and rosters. '
            . 'Choose who sees it and where. The app itself always asks organizers to sign in.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bgol_save');
        echo '<input type="hidden" name="action" value="bgol_save"><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Who sees the button</th><td><fieldset><legend class="screen-reader-text">Who sees the button</legend>'
            . $radio('audience', 'organizers', (string) $s['audience'], 'Signed-in event organizers and staff')
            . $radio('audience', 'everyone', (string) $s['audience'], 'Everyone, including visitors')
            . $radio('audience', 'nobody', (string) $s['audience'], 'Nobody (organizers use the portal or a bookmark)') . '</fieldset></td></tr>';
        echo '<tr><th scope="row">Pages</th><td><fieldset><legend class="screen-reader-text">Pages</legend>'
            . $radio('pages', 'front', (string) $s['pages'], 'Front page only')
            . $radio('pages', 'all', (string) $s['pages'], 'Every public page') . '</fieldset></td></tr>';
        echo '<tr><th scope="row"><label for="bgol-position">Position</label></th><td><select id="bgol-position" name="position">'
            . '<option value="bottom-right"' . selected('bottom-right', $s['position'], false) . '>Bottom right</option>'
            . '<option value="bottom-left"' . selected('bottom-left', $s['position'], false) . '>Bottom left</option>'
            . '<option value="top-center"' . selected('top-center', $s['position'], false) . '>Top center</option></select>'
            . '<p class="description">If you also use the Floating Assistant or Floating Actions, pick a corner they do not use.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgol-color">Color</label></th><td><input id="bgol-color" type="text" name="color" value="' . esc_attr((string) $s['color'])
            . '" class="regular-text code" placeholder="BosqueGrande main color"><p class="description">Leave blank to follow BosqueGrande\'s colors.</p></td></tr>';
        echo '<tr><th scope="row">Organizer Portal</th><td><label><input type="checkbox" name="portal_button" value="1"' . checked(1, $s['portal_button'], false)
            . '> Show a large open-or-install button at the top of the Organizer Portal</label></td></tr>';
        echo '</table><p><button class="button button-primary">Save</button></p></form>';
        if (self::coreActive()) {
            echo '<p>The button text is edited in <a href="' . esc_url(admin_url('admin.php?page=bosquegrande-wording-styling')) . '">Wording &amp; Styling</a> '
                . '(groups "Organizer App: shell" and "Organizer Launcher").</p>';
        }
        echo '</div>';
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to change these settings.');
        check_admin_referer('bgol_save');
        $in = wp_unslash($_POST);
        update_option(self::OPTION, [
            'audience' => sanitize_key((string) ($in['audience'] ?? 'organizers')),
            'pages' => ($in['pages'] ?? '') === 'all' ? 'all' : 'front',
            'position' => sanitize_key((string) ($in['position'] ?? 'bottom-right')),
            'color' => (string) (sanitize_hex_color((string) ($in['color'] ?? '')) ?? ''),
            'portal_button' => empty($in['portal_button']) ? 0 : 1,
        ], false);
        wp_safe_redirect(add_query_arg(['page' => 'bgol-settings', 'saved' => 1], admin_url(self::coreActive() ? 'admin.php' : 'options-general.php')));
        exit;
    }
}
