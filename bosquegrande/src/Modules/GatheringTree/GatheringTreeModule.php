<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GatheringTree;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * The calendar module.
 *
 * Owns the public presentation and the event storage contract: the
 * bg_event post type, the bg_tag taxonomy, _bg_* meta, the calendar
 * settings option, the bgev_* template functions, and the shortcodes.
 * Data movement (Eventbrite, LGL, staging, the mapper) lives in Exchange;
 * the renderer reads published posts only.
 */
final class GatheringTreeModule implements ModuleInterface
{
    public function name(): string
    {
        return 'gathering_tree';
    }

    public function label(): string
    {
        return 'Calendar';
    }

    public function activate(Container $container): void
    {
        require_once __DIR__ . '/Search.php';
        require_once __DIR__ . '/render.php';
        bgev_register_cpt();
        bgev_ensure_fixed_tags();
        flush_rewrite_rules();
    }

    public function boot(Container $container): void
    {
        require_once __DIR__ . '/Search.php';
        require_once __DIR__ . '/render.php';

        add_action('after_setup_theme', static function (): void {
            register_nav_menus(['bosquegrande_header' => 'BosqueGrande pages header']);
        });

        add_action('template_redirect', function (): void {
            if (is_admin()) return;
            $hasParams = false;
            foreach (['bg_view', 'bg_scope', 'bg_pg', 'bg_month', 'bg_year', 'bg_date', 'bgev_loc', 'bgev_int'] as $param) {
                if (!empty($_GET[$param])) { $hasParams = true; break; }
            }
            $hasParams = $hasParams || isset($_GET['bgev_q']) || isset($_GET['bg_return']);
            $post = get_post();
            $isRegistration = (int) get_query_var('bg_register') > 0;
            $isDonation = (int) get_query_var('bg_donation') > 0
                || (int) get_query_var('bg_monthly_donation') > 0;
            $hasCalendar = false;
            if ($post) {
                foreach (['bosquegrande', 'bg_calendar', 'bg_filter', 'bg_submit', 'bosquegrande_profile', 'bg_donate', 'bg_monthly_donation'] as $tag) {
                    if (has_shortcode((string) $post->post_content, $tag)) { $hasCalendar = true; break; }
                }
            }
            if ($hasParams || $hasCalendar || $isRegistration || $isDonation) {
                // Views, filters, search, and paging live in the address. A
                // page cache that ignores query strings would serve visitors
                // each other's variants, so these pages are never cached.
                if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
                nocache_headers();
                if (!headers_sent()) {
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
                    header('Vary: Cookie', false);
                }
            }
        }, 1);

        // Register one authoritative resolver after the active theme has had
        // its setup pass. The same callback is deliberately moved to the end
        // of the maximum-priority bucket immediately before template loading;
        // this closes the remaining case where a theme or page builder adds
        // its own maximum-priority template filter during a later hook.
        if (did_action('after_setup_theme')) {
            $this->registerOwnedTemplateResolver();
        } else {
            add_action('after_setup_theme', [$this, 'registerOwnedTemplateResolver'], PHP_INT_MAX);
        }
        add_action('wp', [$this, 'armFinalOwnedTemplateResolver'], PHP_INT_MAX);

        // Non-visual diagnostics identify the executing build independently
        // from the final template. The owned templates add a second marker of
        // their own, so an active build and a later template conflict cannot
        // be mistaken for an old package or cached response.
        add_action('wp_head', [$this, 'printShellBuildMarker'], 0);
        add_action('send_headers', [$this, 'sendShellBuildHeaders'], PHP_INT_MAX);

        add_action('wp_enqueue_scripts', function (): void {
            $s = get_option(BGEV_OPT, []);
            $deps = [];
            // Opt-in: loading the font sends each visitor's address to Google.
            if (!empty($s['load_inter_font'])) {
                wp_enqueue_style(
                    'bg-gt-fonts',
                    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
                    [],
                    null
                );
                $deps[] = 'bg-gt-fonts';
            }
            $cssPath = __DIR__ . '/assets/gathering-tree.css';
            $cssHash = is_readable($cssPath) ? hash_file('sha256', $cssPath) : false;
            $cssVersion = BG_VERSION . (is_string($cssHash) ? '.' . substr($cssHash, 0, 10) : '');
            wp_enqueue_style(
                'bg-gt-css',
                plugins_url('assets/gathering-tree.css', __FILE__),
                $deps,
                $cssVersion
            );
        }, 20);

        // Owned documents carry no theme markup. Remove active-theme and
        // theme-global presentation while retaining third-party integration
        // styles. Ordinary pages keep WordPress's core block layout CSS so
        // galleries, columns, covers, and the homepage hero retain structure.
        add_action('wp_enqueue_scripts', [$this, 'scrubOwnedDocumentStyles'], PHP_INT_MAX);
        // Some themes enqueue their stylesheet from wp_head or another late
        // callback. Repeat the same narrow scrub immediately before styles
        // print so those handles cannot reintroduce the native presentation.
        add_action('wp_print_styles', [$this, 'scrubOwnedDocumentStyles'], PHP_INT_MAX);

        // Events use a purpose-built WYSIWYG/HTML editor rather than
        // Gutenberg. The post type still advertises its normal REST support on
        // public/API requests; only the wp-admin editing surface is simplified.
        add_filter('use_block_editor_for_post_type', static function (bool $use, string $type): bool {
            return $type === 'bg_event' ? false : $use;
        }, 100, 2);
        if (is_admin()) {
            add_action('init', static function (): void {
                remove_post_type_support('bg_event', 'title');
                remove_post_type_support('bg_event', 'editor');
                remove_post_type_support('bg_event', 'excerpt');
                remove_post_type_support('bg_event', 'thumbnail');
            }, 30);
        }

        // Feed the purpose-built fields into WordPress's normal post save before
        // the record is written. This avoids a second wp_update_post() pass and
        // preserves the existing ownership/audit hooks.
        add_filter('wp_insert_post_data', static function (array $data, array $postarr): array {
            if (($data['post_type'] ?? '') !== 'bg_event') return $data;
            if (!isset($_POST['bgev_mb_n']) || !wp_verify_nonce((string) wp_unslash($_POST['bgev_mb_n']), 'bgev_mb')) return $data;
            if (isset($_POST['bgev_ed_title'])) {
                $data['post_title'] = sanitize_text_field(wp_unslash((string) $_POST['bgev_ed_title']));
            }
            if (isset($_POST['bgev_ed_content'])) {
                $html = wp_unslash((string) $_POST['bgev_ed_content']);
                $data['post_content'] = current_user_can('unfiltered_html') ? $html : wp_kses_post($html);
            }
            if (isset($_POST['bgev_ed_slug'])) {
                $wanted = sanitize_title(wp_unslash((string) $_POST['bgev_ed_slug']));
                if ($wanted === '' && !empty($data['post_title'])) $wanted = sanitize_title((string) $data['post_title']);
                if ($wanted !== '') $data['post_name'] = $wanted;
            }
            return $data;
        }, 20, 2);

        add_action('add_meta_boxes', function (): void {
            add_meta_box('bgev_meta', 'Event Editor', 'bgev_meta_box', 'bg_event', 'normal', 'high');
            add_meta_box('bg_ownership', 'Sync ownership', [$this, 'renderOwnershipBox'], 'bg_event', 'side', 'default');
        });
        add_action('add_meta_boxes_bg_event', static function (): void {
            // These controls are now consolidated inside Event Editor.
            remove_meta_box('postexcerpt', 'bg_event', 'normal');
            remove_meta_box('postimagediv', 'bg_event', 'side');
            remove_meta_box('slugdiv', 'bg_event', 'normal');
            remove_meta_box('bg_photos', 'bg_event', 'side');
        }, 100);

        // A manual save marks posted _bg_* fields as staff-entered. Exchange
        // respects those ownership marks and does not overwrite the values
        // with imported source data.
        add_action('save_post_bg_event', [$this, 'stampOwnership'], 20, 1);
        add_action('save_post_bg_event', [$this, 'handleReleases'], 25, 1);

        add_filter('display_post_states', function (array $states, $post): array {
            if ($post instanceof \WP_Post && $post->post_type === 'bg_event') {
                if (get_post_meta($post->ID, '_bg_placeholder_date', true)) {
                    $states['bg_provisional'] = 'Provisional date, pending confirmation';
                }
                if ($post->post_status === 'draft' && get_post_meta($post->ID, '_bg_start_date', true) === '') {
                    $states['bg_dateless'] = 'Held for review (no date of record)';
                }
            }
            return $states;
        }, 10, 2);
    }

    public function deactivate(Container $container): void
    {
        flush_rewrite_rules();
    }

    /** Install the one authoritative public-template decision. */
    public function registerOwnedTemplateResolver(): void
    {
        remove_filter('template_include', [$this, 'ownedPublicTemplate'], PHP_INT_MAX);
        add_filter('template_include', [$this, 'ownedPublicTemplate'], PHP_INT_MAX);
    }

    /**
     * Arm a final reassertion after the main query and theme setup are known.
     *
     * Adding this template_redirect callback from the late `wp` hook places it
     * after callbacks registered conventionally by the theme and page builders.
     * It still uses WordPress's supported template_include contract and does
     * not include a file, exit early, or skip another template_redirect task.
     */
    public function armFinalOwnedTemplateResolver(): void
    {
        if (!$this->ownsPublicDocument()) return;
        remove_action('template_redirect', [$this, 'reassertOwnedTemplateResolver'], PHP_INT_MAX);
        add_action('template_redirect', [$this, 'reassertOwnedTemplateResolver'], PHP_INT_MAX);
    }

    /** Move the resolver to the end of the final maximum-priority bucket. */
    public function reassertOwnedTemplateResolver(): void
    {
        $this->registerOwnedTemplateResolver();
    }

    /**
     * Resolve owned documents in strict order of specificity.
     *
     * The existing per-surface methods and their public filters remain the
     * extension contract; this method only prevents competing callbacks from
     * making three independent, order-sensitive decisions.
     */
    public function ownedPublicTemplate(string $template): string
    {
        if (!$this->publicRequestEligible() || self::presentation() === 'theme') return $template;

        if (is_singular('bg_event')) {
            return $this->singleEventTemplate($template);
        }

        if (is_page()) {
            $page = get_queried_object();
            if ($page instanceof \WP_Post && bgev_public_app_page_context($page) !== '') {
                return $this->publicAppPageTemplate($template);
            }
        }

        if ($this->ownsOrdinaryPage()) {
            return $this->sitewidePageTemplate($template);
        }

        return $template;
    }

    /**
     * Remove only presentation owned by the active theme on BosqueGrande pages.
     *
     * This intentionally retains third-party integration CSS and core block
     * structure where visitor-authored content can use it.
     */
    public function scrubOwnedDocumentStyles(): void
    {
        if (!$this->ownsPublicDocument()) return;

        // Theme Customizer CSS is printed directly from wp_head rather than
        // registered as a style handle, so remove it explicitly.
        remove_action('wp_head', 'wp_custom_css_cb', 101);

        global $wp_styles;
        if (!$wp_styles instanceof \WP_Styles) return;

        $ordinary = $this->ownsOrdinaryPage();
        // Event descriptions can contain columns, galleries, covers, and
        // other core blocks just like an ordinary Page.
        $usesCoreBlocks = $ordinary || is_singular('bg_event');
        $keep = ['bg-gt-css', 'bg-gt-fonts', 'admin-bar', 'dashicons'];
        if ($usesCoreBlocks) {
            $keep[] = 'wp-block-library';
            $keep[] = 'core-block-supports';
        }

        foreach ((array) $wp_styles->queue as $handle) {
            $handle = (string) $handle;
            if (in_array($handle, $keep, true)) continue;
            $registered = $wp_styles->registered[$handle] ?? null;
            $src = $registered instanceof \_WP_Dependency ? (string) $registered->src : '';
            $nativeHandle = in_array($handle, [
                'global-styles', 'global-styles-inline-css', 'classic-theme-styles',
                'wp-block-library-theme',
            ], true) || (!$usesCoreBlocks && in_array($handle, [
                'wp-block-library', 'core-block-supports',
            ], true));
            $nativeSource = str_contains($src, '/wp-content/themes/')
                || (!$usesCoreBlocks && str_contains($src, '/wp-includes/'));
            if ($nativeHandle || $nativeSource) wp_dequeue_style($handle);
        }
    }

    /** Stable, non-sensitive identity for this public shell build. */
    private function shellBuild(): string
    {
        return sanitize_text_field((string) BG_VERSION);
    }

    /** Emit a stable source marker when this build intends to own the page. */
    public function printShellBuildMarker(): void
    {
        if (!$this->ownsPublicDocument()) return;
        echo '<meta name="bosquegrande-shell-build" content="' . esc_attr($this->shellBuild()) . '">' . "\n";
    }

    /** Add cache-visible diagnostics without exposing configuration or data. */
    public function sendShellBuildHeaders(): void
    {
        if (!$this->ownsPublicDocument() || headers_sent()) return;
        header('X-BosqueGrande-Shell-Build: ' . $this->shellBuild(), true);
        header('X-BosqueGrande-Shell-Intent: owned', true);
    }

    public function singleEventTemplate(string $template): string
    {
        if (is_admin() || !is_singular('bg_event') || is_feed() || is_embed()) {
            return $template;
        }

        $owned = __DIR__ . '/single-bg_event.php';
        if (!is_readable($owned)) return $template;

        /**
         * Allows a deployment to replace the app document deliberately while
         * retaining BosqueGrande's theme-independent default.
         *
         * @param string   $owned Plugin-owned template path.
         * @param \WP_Post|null $event Queried event.
         */
        return (string) apply_filters('bg_single_event_template', $owned, get_queried_object());
    }

    public function publicAppPageTemplate(string $template): string
    {
        if (is_admin() || !is_page() || is_feed() || is_embed()) return $template;
        $page = get_queried_object();
        if (!$page instanceof \WP_Post || bgev_public_app_page_context($page) === '') return $template;

        $owned = __DIR__ . '/public-app-page.php';
        if (!is_readable($owned)) return $template;
        return (string) apply_filters('bg_public_app_page_template', $owned, $page);
    }

    /** Theme-independent document for every ordinary public page and post. */
    public function sitewidePageTemplate(string $template): string
    {
        if (!$this->publicRequestEligible() || !$this->ownsOrdinaryPage()) return $template;
        // A block-theme home view is not necessarily a singular page: its
        // hero and sections may live in the selected front-page/home template
        // rather than post_content. Give that view a renderer which preserves
        // the selected block template while removing its native chrome.
        $owned = (is_front_page() || is_home())
            ? __DIR__ . '/sitewide-home.php'
            : __DIR__ . '/sitewide-page.php';
        if (!is_readable($owned)) return $template;
        return (string) apply_filters('bg_sitewide_page_template', $owned, get_queried_object());
    }

    private function ownsPublicDocument(): bool
    {
        if (!$this->publicRequestEligible()) return false;
        $owned = function_exists('bgev_force_public_document') && bgev_force_public_document();
        if (self::presentation() === 'theme') return (bool) apply_filters('bg_owns_public_document', $owned);
        $owned = $owned || is_singular('bg_event')
            || (int) get_query_var('bg_register') > 0
            || (int) get_query_var('bg_donation') > 0
            || (int) get_query_var('bg_monthly_donation') > 0
            || $this->ownsOrdinaryPage();
        return (bool) apply_filters('bg_owns_public_document', $owned);
    }

    private function ownsOrdinaryPage(): bool
    {
        if (!$this->publicRequestEligible() || self::presentation() !== 'site') return false;
        return is_singular(['page', 'post']) || is_front_page() || is_home();
    }

    /**
     * How much of the public site BosqueGrande presents itself:
     * "theme" leaves every page to the active theme, "app" (the default)
     * frames only BosqueGrande's own pages, and "site" frames every public
     * page and post with the same header and footer.
     */
    public static function presentation(): string
    {
        $settings = get_option('bosquegrande_settings', []);
        $mode = is_array($settings) ? (string) ($settings['public_presentation'] ?? 'app') : 'app';
        return in_array($mode, ['theme', 'app', 'site'], true) ? $mode : 'app';
    }

    /** Never replace administrative, API, feed, XML, login, or embed output. */
    private function publicRequestEligible(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return false;
        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) return false;
        if (function_exists('wp_is_json_request') && wp_is_json_request()) return false;
        if (is_feed() || is_embed() || is_trackback() || is_robots() || is_favicon()) return false;
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
        return !in_array($script, ['wp-login.php', 'xmlrpc.php'], true);
    }

    public function stampOwnership(int $pid): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        $fromEditor = isset($_POST['bgev_mb_n']);
        $fromQuickEdit = isset($_POST['_inline_edit'])
            && wp_verify_nonce((string) $_POST['_inline_edit'], 'inlineeditnonce');
        if (!$fromEditor && !$fromQuickEdit) return;
        if (!current_user_can('edit_post', $pid)) return;

        // Ownership is derived, not event-driven: a field belongs to the
        // office when its current value differs from the last transferred
        // value (the snapshot). Saving without changing anything changes
        // no ownership, and manually restoring a transferred value
        // releases the field again. Posts with no snapshot were entered
        // directly & are marked office-owned defensively.
        $post = get_post($pid);
        if (!$post instanceof \WP_Post) return;
        $snap = get_post_meta($pid, '_gt_src_snapshot', true);
        $snap = is_array($snap) ? $snap : [];
        $own = get_post_meta($pid, '_gt_ownership', true);
        $own = is_array($own) ? $own : [];
        $changed = false;

        foreach (\BosqueGrande\Modules\Exchange\Mapper::OWNED_FIELDS as $f) {
            if ($f === 'post_title') $current = (string) $post->post_title;
            elseif ($f === 'post_content') $current = (string) $post->post_content;
            else $current = (string) get_post_meta($pid, $f, true);

            if (!array_key_exists($f, $snap)) {
                if (($own[$f] ?? '') !== 'admin') { $own[$f] = 'admin'; $changed = true; }
                continue;
            }
            if ($current !== (string) $snap[$f]) {
                if (($own[$f] ?? '') !== 'admin') { $own[$f] = 'admin'; $changed = true; }
            } elseif (($own[$f] ?? '') === 'admin') {
                $own[$f] = 'source';
                $changed = true;
            }
        }
        if ($changed) update_post_meta($pid, '_gt_ownership', $own);
    }

    /** Fields checked as released on the ownership box go back to the
     *  source on the next sync. Runs after stamping so the release wins
     *  within the same save. */
    public function handleReleases(int $pid): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bg_own_n']) || !wp_verify_nonce((string) $_POST['bg_own_n'], 'bg_own')) return;
        if (!current_user_can('edit_post', $pid)) return;
        $release = array_map('sanitize_text_field', (array) ($_POST['bg_release'] ?? []));
        if (!$release) return;

        $post = get_post($pid);
        if (!$post instanceof \WP_Post) return;
        $own = get_post_meta($pid, '_gt_ownership', true);
        $own = is_array($own) ? $own : [];
        $snap = get_post_meta($pid, '_gt_src_snapshot', true);
        $snap = is_array($snap) ? $snap : [];

        foreach ($release as $f) {
            if (!in_array($f, \BosqueGrande\Modules\Exchange\Mapper::OWNED_FIELDS, true)) continue;
            if ($f === 'post_title') $current = (string) $post->post_title;
            elseif ($f === 'post_content') $current = (string) $post->post_content;
            else $current = (string) get_post_meta($pid, $f, true);
            $own[$f] = 'provisional';
            $snap[$f] = $current;
        }
        update_post_meta($pid, '_gt_ownership', $own);
        update_post_meta($pid, '_gt_src_snapshot', $snap);
    }

    public function renderOwnershipBox(\WP_Post $post): void
    {
        $labels = [
            'post_title'       => 'Title',
            'post_content'     => 'Description',
            '_bg_start_date' => 'Start Date',
            '_bg_start_time' => 'Start Time',
            '_bg_end_date'   => 'End Date',
            '_bg_end_time'   => 'End Time',
            '_bg_reg_url'    => 'Registration URL',
            '_bg_image'      => 'Image URL',
        ];
        $srcKey = (string) get_post_meta($post->ID, '_bg_src_key', true);
        if ($srcKey === '') {
            echo '<p>This event was created here and is not synced from another source.</p>';
            return;
        }
        wp_nonce_field('bg_own', 'bg_own_n');
        $own = get_post_meta($post->ID, '_gt_ownership', true);
        $own = is_array($own) ? $own : [];
        echo '<p style="margin-top:0">Source ID: <code>' . esc_html($srcKey) . '</code></p>';
        echo '<table style="width:100%;font-size:12px;border-spacing:0 4px">';
        foreach ($labels as $f => $label) {
            $o = (string) ($own[$f] ?? 'source');
            $badge = match ($o) {
                'admin'       => '<span style="color:#1F5130;font-weight:600">Edited here</span>',
                'provisional' => '<span style="color:#8a6d00;font-weight:600">Released, waiting for next sync</span>',
                default       => '<span style="color:#1F5130">From source</span>',
            };
            $cb = $o === 'admin'
                ? '<label style="white-space:nowrap"><input type="checkbox" name="bg_release[]" value="' . esc_attr($f) . '"> Release</label>'
                : '';
            echo '<tr><td style="padding:2px 4px">' . esc_html($label) . '</td><td style="padding:2px 4px">' . $badge . '</td><td style="padding:2px 4px;text-align:right">' . $cb . '</td></tr>';
        }
        echo '</table>';
        $ev = get_post_meta($post->ID, \BosqueGrande\Modules\Exchange\Tagger::EVIDENCE_META, true);
        if (is_array($ev) && $ev) {
            echo '<p style="margin:8px 0 2px"><strong>Tags assigned by rule:</strong></p><ul style="margin:2px 0 0 16px;font-size:12px">';
            foreach ($ev as $slug => $why) {
                if ($slug === '_conflict') { echo '<li>' . esc_html($why) . '</li>'; continue; }
                echo '<li><code>' . esc_html($slug) . '</code>: ' . esc_html($why) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p class="description">Fields edited here are never overwritten by a sync. Check Release to let the next sync update that field again.</p>';
    }
}
