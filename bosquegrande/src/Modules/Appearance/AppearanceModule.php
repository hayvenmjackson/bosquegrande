<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Appearance;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * One guarded editor for BosqueGrande's public wording, navigation, and
 * visual system. Staff and volunteer editors may use it by default without
 * receiving the broader manage_options capability. Deployments may narrow
 * that capability with bg_wording_styling_capability.
 */
final class AppearanceModule implements ModuleInterface
{
    private const WORDING_OPT = 'bg_wording';
    private const NAV_OPT = 'bg_public_navigation';
    private const WORDING_CHUNK_SIZE = 100;
    public const PAGE = 'bosquegrande-wording-styling';

    public const DEFAULT_COLORS = [
        'color_primary'   => '#1F5130',
        'color_accent'    => '#C98A1B',
        'color_success'   => '#2E7D4F',
        'color_text'      => '#1F2A24',
        'color_card_bg'   => '#FFFFFF',
        'color_donor_a'   => '#1F5130',
        'color_donor_b'   => '#2E7D4F',
        'color_header_a'  => '#173D25',
        'color_header_b'  => '#1F5130',
        'color_header_c'  => '#2A6B40',
        'color_profile_a' => '#2A6B40',
        'color_profile_b' => '#3C8A56',
        'color_join_a'    => '#C98A1B',
        'color_join_b'    => '#E0A93B',
        'color_page_bg'   => '#F4F6F3',
    ];

    private const DEFAULT_LAYOUT = [
        'content_max_width' => 1200,
        'surface_radius' => 18,
        'logo_size' => 56,
    ];

    public function name(): string
    {
        return 'appearance';
    }

    public function label(): string
    {
        return 'Wording & Styling';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        require_once __DIR__ . '/strings.php';

        add_action('wp_head', [$this, 'printPalette'], 100);
        add_action('admin_head', [$this, 'printPalette'], 100);

        if (is_admin()) {
            add_action('admin_menu', function (): void {
                $cap = self::capability();
                add_submenu_page(
                    'bosquegrande',
                    'Wording & Styling',
                    'Wording & Styling',
                    $cap,
                    self::PAGE,
                    [$this, 'renderPage']
                );
            }, 45);
            add_action('admin_post_bg_save_public_appearance', [$this, 'savePage']);
        }
    }

    public function deactivate(Container $container): void
    {
    }

    /**
     * Editors and administrators by default. Wording includes email text, so
     * contributors and authors are deliberately excluded; a site may widen
     * or narrow this with the bg_wording_styling_capability filter.
     */
    public static function capability(): string
    {
        $capability = sanitize_key((string) apply_filters(
            'bg_wording_styling_capability',
            'edit_others_posts'
        ));
        return $capability !== '' ? $capability : 'edit_others_posts';
    }

    /** Print validated custom properties; no saved value becomes raw CSS. */
    public function printPalette(): void
    {
        $settings = get_option(BGEV_OPT, []);
        $settings = is_array($settings) ? $settings : [];
        $color = static function (string $key) use ($settings): string {
            $value = sanitize_hex_color((string) ($settings[$key] ?? ''));
            return $value ?: self::DEFAULT_COLORS[$key];
        };
        $number = static function (string $key) use ($settings): int {
            $bounds = [
                'content_max_width' => [760, 1800],
                'surface_radius' => [0, 40],
                'logo_size' => [36, 84],
            ];
            [$minimum, $maximum] = $bounds[$key];
            $value = (int) ($settings[$key] ?? self::DEFAULT_LAYOUT[$key]);
            return max($minimum, min($maximum, $value));
        };

        echo "<style id='bgc-vars'>:root{"
            . '--bgc-primary:' . esc_html($color('color_primary')) . ';'
            . '--bgc-accent:' . esc_html($color('color_accent')) . ';'
            . '--bgc-success-dk:' . esc_html($color('color_success')) . ';'
            . '--bgc-success:' . esc_html($color('color_success')) . ';'
            . '--bgc-text:' . esc_html($color('color_text')) . ';'
            . '--bgc-white:' . esc_html($color('color_card_bg')) . ';'
            . '--bgc-donor-a:' . esc_html($color('color_donor_a')) . ';'
            . '--bgc-donor-b:' . esc_html($color('color_donor_b')) . ';'
            . '--bg-header-a:' . esc_html($color('color_header_a')) . ';'
            . '--bg-header-b:' . esc_html($color('color_header_b')) . ';'
            . '--bg-header-c:' . esc_html($color('color_header_c')) . ';'
            . '--bg-profile-a:' . esc_html($color('color_profile_a')) . ';'
            . '--bg-profile-b:' . esc_html($color('color_profile_b')) . ';'
            . '--bg-join-a:' . esc_html($color('color_join_a')) . ';'
            . '--bg-join-b:' . esc_html($color('color_join_b')) . ';'
            . '--bg-page-bg:' . esc_html($color('color_page_bg')) . ';'
            . '--bg-content-max:' . $number('content_max_width') . 'px;'
            . '--bg-surface-radius:' . $number('surface_radius') . 'px;'
            . '--bg-logo-size:' . $number('logo_size') . 'px;'
            . "--bgc-head:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;"
            . "}</style>\n";
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::capability())) return;
        $settings = get_option(BGEV_OPT, []);
        $settings = is_array($settings) ? $settings : [];
        $defaults = bg_wording_defaults();
        $overrides = get_option(self::WORDING_OPT, []);
        $overrides = is_array($overrides) ? $overrides : [];
        $nav = function_exists('bgev_public_nav_editor_config')
            ? bgev_public_nav_editor_config()
            : ['show_profile' => 1, 'show_donation' => 1];
        $notice = isset($_GET['bg_notice'])
            ? sanitize_text_field(wp_unslash((string) $_GET['bg_notice']))
            : '';
        ?>
        <div class="wrap bg-wording-styling-admin">
            <h1>Wording &amp; Styling</h1>
            <?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p style="max-width:820px">Colors, the header on BosqueGrande's public pages, and every piece of visitor-facing
            wording. An empty wording field uses the standard text shown in grey. Changes apply as soon as they are saved.</p>

            <details open>
                <summary><strong>Styling</strong></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bg_save_public_appearance'); ?>
                    <input type="hidden" name="action" value="bg_save_public_appearance">
                    <input type="hidden" name="section" value="styling">
                    <table class="form-table"><tbody>
                    <?php foreach ($this->colorFields() as $key => $label):
                        $value = sanitize_hex_color((string) ($settings[$key] ?? '')) ?: self::DEFAULT_COLORS[$key]; ?>
                        <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                        <td><input type="color" id="<?php echo esc_attr($key); ?>" name="colors[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"> <code><?php echo esc_html($value); ?></code></td></tr>
                    <?php endforeach; ?>
                        <tr><th scope="row"><label for="bg-content-width">Content maximum width</label></th>
                        <td><input type="number" id="bg-content-width" name="layout[content_max_width]" min="760" max="1800" step="10" value="<?php echo esc_attr((string) ($settings['content_max_width'] ?? self::DEFAULT_LAYOUT['content_max_width'])); ?>"> pixels</td></tr>
                        <tr><th scope="row"><label for="bg-surface-radius">Container corner radius</label></th>
                        <td><input type="number" id="bg-surface-radius" name="layout[surface_radius]" min="0" max="40" value="<?php echo esc_attr((string) ($settings['surface_radius'] ?? self::DEFAULT_LAYOUT['surface_radius'])); ?>"> pixels</td></tr>
                        <tr><th scope="row"><label for="bg-logo-size">Header logo size</label></th>
                        <td><input type="number" id="bg-logo-size" name="layout[logo_size]" min="36" max="84" value="<?php echo esc_attr((string) ($settings['logo_size'] ?? self::DEFAULT_LAYOUT['logo_size'])); ?>"> pixels</td></tr>
                        <tr><th scope="row">Typeface</th><td><label><input type="checkbox" name="load_inter_font" value="1" <?php checked(!empty($settings['load_inter_font'])); ?>> Load the Inter typeface from Google Fonts. When this is off, public pages use each device's system font and no visitor data goes to Google.</label></td></tr>
                    </tbody></table>
                    <p><button class="button button-primary">Save styling</button></p>
                </form>
            </details>

            <details open>
                <summary><strong>Public pages</strong></summary>
                <?php $presentation = \BosqueGrande\Modules\GatheringTree\GatheringTreeModule::presentation(); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bg_save_public_appearance'); ?>
                    <input type="hidden" name="action" value="bg_save_public_appearance">
                    <input type="hidden" name="section" value="navigation">
                    <table class="form-table"><tbody>
                        <tr><th scope="row">Page frame</th><td>
                            <p><label><input type="radio" name="nav[presentation]" value="app" <?php checked($presentation, 'app'); ?>> <strong>BosqueGrande pages only</strong> (recommended): events, the calendar, My Profile, registration, and giving use BosqueGrande's header and footer; the rest of the site uses your theme.</label></p>
                            <p><label><input type="radio" name="nav[presentation]" value="theme" <?php checked($presentation, 'theme'); ?>> <strong>Theme everywhere</strong>: event pages, the calendar, and My Profile render inside your theme. Checkout and confirmation screens keep BosqueGrande's frame.</label></p>
                            <p><label><input type="radio" name="nav[presentation]" value="site" <?php checked($presentation, 'site'); ?>> <strong>Whole site</strong>: every public page and post uses BosqueGrande's header and footer instead of the theme's.</label></p>
                        </td></tr>
                        <tr><th scope="row">Header menu</th><td>
                            <p>The header uses the WordPress menu assigned to the <strong>BosqueGrande pages header</strong> location. Without one, it shows Home and the events calendar.</p>
                            <p><a class="button" href="<?php echo esc_url(admin_url('nav-menus.php?action=locations')); ?>">Manage menu locations</a></p>
                            <p class="description">The logo and organization name come from BosqueGrande Settings.</p>
                        </td></tr>
                        <tr><th scope="row">Header buttons</th><td>
                            <p><label><input type="checkbox" name="nav[show_profile]" value="1" <?php checked(!empty($nav['show_profile'])); ?>> Show a My Profile button</label></p>
                            <p><label><input type="checkbox" name="nav[show_donation]" value="1" <?php checked(!empty($nav['show_donation'])); ?>> Show a Donate button (only once Stripe is connected)</label></p>
                        </td></tr>
                        <tr><th scope="row"><label for="bg-nav-profile-url">My Profile address</label></th><td><input class="large-text code" type="text" id="bg-nav-profile-url" name="nav[profile_url]" value="<?php echo esc_attr((string) ($nav['profile_url'] ?? '')); ?>"><p class="description">Leave blank to use the first page containing [bosquegrande_profile].</p></td></tr>
                        <tr><th scope="row"><label for="bg-nav-donation-url">Donate address</label></th><td><input class="large-text code" type="text" id="bg-nav-donation-url" name="nav[donation_url]" value="<?php echo esc_attr((string) ($nav['donation_url'] ?? '')); ?>"><p class="description">Leave blank for the built-in giving form, or enter a page on this site containing [bg_donate].</p></td></tr>
                    </tbody></table>
                    <p><button class="button button-primary">Save public page settings</button></p>
                </form>
            </details>

            <details>
                <summary><strong>All BosqueGrande wording</strong></summary>
                <p>Every registered public wording key is listed here. Clear an override and save its group to restore the standard. Groups are saved independently so large catalogs cannot be truncated by PHP's input-variable limit.</p>
                <?php
                $wordingGroups = [];
                foreach ($defaults as $key => $definition) {
                    $group = trim((string) ($definition['group'] ?? 'General BosqueGrande wording'));
                    if ($group === '') $group = 'General BosqueGrande wording';
                    $wordingGroups[$group][$key] = $definition;
                }
                foreach ($wordingGroups as $group => $entries):
                    $chunks = array_chunk($entries, self::WORDING_CHUNK_SIZE, true);
                    foreach ($chunks as $chunkIndex => $chunk):
                        $part = count($chunks) > 1
                            ? ' (part ' . ($chunkIndex + 1) . ' of ' . count($chunks) . ')'
                            : '';
                        $scope = implode(',', array_keys($chunk));
                ?>
                    <details class="bg-wording-subgroup">
                        <summary><strong><?php echo esc_html($group . $part); ?></strong> <span><?php echo esc_html((string) count($chunk)); ?> fields</span></summary>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('bg_save_public_appearance'); ?>
                            <input type="hidden" name="action" value="bg_save_public_appearance">
                            <input type="hidden" name="section" value="wording">
                            <input type="hidden" name="wording_scope" value="<?php echo esc_attr($scope); ?>">
                            <input type="hidden" name="wording_group" value="<?php echo esc_attr($group . $part); ?>">
                            <table class="form-table"><tbody>
                            <?php foreach ($chunk as $key => $definition): ?>
                                <tr><th scope="row"><label for="bg-wording-<?php echo esc_attr($key); ?>"><?php echo esc_html((string) $definition['label']); ?></label><br><code><?php echo esc_html($key); ?></code></th>
                                <td><textarea id="bg-wording-<?php echo esc_attr($key); ?>" name="w[<?php echo esc_attr($key); ?>]" rows="<?php echo strlen((string) $definition['text']) > 160 ? '5' : '2'; ?>" class="large-text" placeholder="<?php echo esc_attr((string) $definition['text']); ?>"><?php echo esc_textarea((string) ($overrides[$key] ?? '')); ?></textarea>
                                <p class="description"><?php echo ($overrides[$key] ?? '') === '' ? 'Standard wording in effect.' : '<strong>Revised wording in effect.</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php $requiredTokens = array_values(array_filter(array_map('sanitize_key', (array) ($definition['required_tokens'] ?? []))));
                                if ($requiredTokens): ?> Required placeholders are retained automatically: <code><?php echo esc_html('{' . implode('}, {', $requiredTokens) . '}'); ?></code>.<?php endif; ?></p></td></tr>
                            <?php endforeach; ?>
                            </tbody></table>
                            <p><button class="button button-primary">Save <?php echo esc_html($group . $part); ?> wording</button></p>
                        </form>
                    </details>
                <?php endforeach; endforeach; ?>
            </details>

            <details>
                <summary><strong>Survey questions</strong></summary>
                <p>Post-event survey questions keep their existing canonical storage and editor so reporting IDs and past answers cannot drift. Open that editor here; no duplicate copy is stored in Wording &amp; Styling.</p>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-survey')); ?>">Edit survey questions</a></p>
            </details>
        </div>
        <style>
            .bg-wording-styling-admin>details{max-width:1100px;margin:18px 0;padding:14px 18px;background:#fff;border:1px solid #c3c4c7;border-radius:8px}
            .bg-wording-styling-admin>details>summary{cursor:pointer;font-size:1.15rem}
            .bg-wording-styling-admin .bg-nav-editor{border-collapse:collapse;width:100%;max-width:1050px}
            .bg-wording-styling-admin .bg-nav-editor th,.bg-wording-styling-admin .bg-nav-editor td{padding:9px;border-bottom:1px solid #e2e4e7;vertical-align:top;text-align:left}
            .bg-wording-styling-admin .bg-nav-child td:first-child{padding-left:34px}
            .bg-wording-styling-admin .bg-wording-subgroup{margin:12px 0;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px}.bg-wording-styling-admin .bg-wording-subgroup>summary{cursor:pointer;font-size:1rem}.bg-wording-styling-admin .bg-wording-subgroup>summary span{color:#646970;font-weight:400;margin-left:.4em}
            .bg-wording-styling-admin code{font-size:.78rem}
        </style>
        <?php
    }

    /** @return array<string,string> */
    private function colorFields(): array
    {
        return [
            'color_primary' => 'Principal color (headings and controls)',
            'color_accent' => 'Accent color',
            'color_success' => 'Affirmative color',
            'color_text' => 'Body text color',
            'color_card_bg' => 'Container background',
            'color_donor_a' => 'Monthly-donor gradient start',
            'color_donor_b' => 'Monthly-donor gradient end',
            'color_header_a' => 'Header gradient start',
            'color_header_b' => 'Header gradient middle',
            'color_header_c' => 'Header gradient end',
            'color_profile_a' => 'My Profile button gradient start',
            'color_profile_b' => 'My Profile button gradient end',
            'color_join_a' => 'Join Us/Donate button gradient start',
            'color_join_b' => 'Join Us/Donate button gradient end',
            'color_page_bg' => 'Site background',
        ];
    }

    public function savePage(): void
    {
        if (!current_user_can(self::capability())) wp_die('Insufficient permissions.');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('POST required.', 405);
        check_admin_referer('bg_save_public_appearance');
        $section = sanitize_key(wp_unslash((string) ($_POST['section'] ?? '')));
        if ($section === 'styling') {
            $this->saveStylingValues((array) ($_POST['colors'] ?? []), (array) ($_POST['layout'] ?? []), !empty($_POST['load_inter_font']));
            $notice = 'Sitewide styling was saved.';
        } elseif ($section === 'navigation') {
            $this->saveNavigationValues((array) ($_POST['nav'] ?? []));
            $notice = 'Public page settings were saved.';
        } elseif ($section === 'wording') {
            $scope = $this->postedWordingScope($_POST['wording_scope'] ?? '');
            if ($scope === []) wp_die('The wording group was missing or invalid.', 400);
            $this->saveWordingValues((array) ($_POST['w'] ?? []), $scope);
            $postedGroup = $_POST['wording_group'] ?? '';
            $group = sanitize_text_field(bg_substr(
                wp_unslash(is_scalar($postedGroup) ? (string) $postedGroup : ''),
                0,
                160
            ));
            $notice = ($group !== '' ? $group : 'BosqueGrande') . ' wording was saved.';
        } else {
            wp_die('Unknown settings section.', 400);
        }
        $this->redirect($notice);
    }

    /** @param array<string,mixed> $colors @param array<string,mixed> $layout */
    private function saveStylingValues(array $colors, array $layout, bool $loadInter): void
    {
        $settings = get_option(BGEV_OPT, []);
        $settings = is_array($settings) ? $settings : [];
        foreach (array_keys(self::DEFAULT_COLORS) as $key) {
            $value = sanitize_hex_color(wp_unslash((string) ($colors[$key] ?? '')));
            $settings[$key] = $value ?: self::DEFAULT_COLORS[$key];
        }
        $bounds = [
            'content_max_width' => [760, 1800],
            'surface_radius' => [0, 40],
            'logo_size' => [36, 84],
        ];
        foreach ($bounds as $key => [$minimum, $maximum]) {
            $value = (int) ($layout[$key] ?? $settings[$key] ?? self::DEFAULT_LAYOUT[$key]);
            $settings[$key] = max($minimum, min($maximum, $value));
        }
        $settings['load_inter_font'] = $loadInter ? 1 : 0;
        update_option(BGEV_OPT, $settings);
    }

    /** @return string[] */
    private function postedWordingScope($raw): array
    {
        if (!is_scalar($raw)) return [];
        $keys = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            explode(',', bg_substr(wp_unslash((string) $raw), 0, 20000))
        ))));
        return array_slice($keys, 0, self::WORDING_CHUNK_SIZE);
    }

    /**
     * Save only fields actually present in this bounded form. Existing values
     * outside the scope survive, and an unexpectedly truncated POST cannot
     * erase an override whose textarea never reached PHP.
     *
     * @param array<string,mixed> $input
     * @param string[]|null $scope
     */
    private function saveWordingValues(array $input, ?array $scope = null): void
    {
        $definitions = bg_wording_defaults();
        $existing = get_option(self::WORDING_OPT, []);
        $existing = is_array($existing) ? $existing : [];
        $out = [];
        foreach ($existing as $key => $value) {
            if (isset($definitions[$key]) && is_scalar($value)) $out[$key] = (string) $value;
        }
        $keys = $scope === null ? array_keys($input) : $scope;
        foreach (array_values(array_unique(array_map('sanitize_key', $keys))) as $key) {
            if (!isset($definitions[$key]) || !array_key_exists($key, $input)) continue;
            if (!is_scalar($input[$key])) continue;
            $definition = $definitions[$key];
            $value = trim(bg_substr(wp_unslash((string) $input[$key]), 0, 20000));
            unset($out[$key]);
            if ($value === '') continue;
            // Email subjects and subject-field labels are necessarily
            // single-line. Enforce that at the shared save boundary so an
            // authorized wording editor cannot persist CR/LF mail headers.
            $label = strtolower((string) ($definition['label'] ?? ''));
            $singleLine = !empty($definition['single_line'])
                || str_ends_with((string) $key, '_subject')
                || str_contains($label, 'subject');
            $value = $singleLine ? sanitize_text_field($value) : wp_kses_post($value);
            foreach ((array) ($definition['required_tokens'] ?? []) as $token) {
                $token = sanitize_key((string) $token);
                if ($token === '') continue;
                $marker = '{' . $token . '}';
                if (!str_contains($value, $marker)) {
                    $separator = $singleLine ? ' ' : "\n\n";
                    $value = rtrim($value) . $separator . $marker;
                }
            }
            $out[$key] = $value;
        }
        update_option(self::WORDING_OPT, $out);
    }

    /** @param array<string,mixed> $input */
    private function saveNavigationValues(array $input): void
    {
        $input = wp_unslash($input);
        update_option(self::NAV_OPT, [
            'profile_url' => $this->sanitizeUrl((string) ($input['profile_url'] ?? '')),
            'donation_url' => $this->sanitizeUrl((string) ($input['donation_url'] ?? '')),
            'show_profile' => empty($input['show_profile']) ? 0 : 1,
            'show_donation' => empty($input['show_donation']) ? 0 : 1,
        ]);
        $presentation = sanitize_key((string) ($input['presentation'] ?? 'app'));
        if (!in_array($presentation, ['theme', 'app', 'site'], true)) $presentation = 'app';
        $settings = get_option('bosquegrande_settings', []);
        if (!is_array($settings)) $settings = [];
        $settings['public_presentation'] = $presentation;
        update_option('bosquegrande_settings', $settings, false);
        \BosqueGrande\Core\Plugin::instance()->container()->get('settings')->refresh();
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return '/' . ltrim(sanitize_text_field($url), '/');
        }
        return (string) esc_url_raw($url, ['http', 'https']);
    }

    private function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE, 'bg_notice' => $notice],
            admin_url('admin.php')
        ));
        exit;
    }
}
