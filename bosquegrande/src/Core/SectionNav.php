<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

use BosqueGrande\Modules\Correspondence\ComposeScreen;
use BosqueGrande\Modules\Locations\Taxonomy as LocationsTaxonomy;
use BosqueGrande\Modules\Revenue\RevenueModule;
use BosqueGrande\Modules\Appearance\AppearanceModule;

if (!defined('ABSPATH')) exit;

/**
 * The six-heading admin. The sidebar carries exactly six rows, one per
 * section, plus Reinstall. Every tool keeps its own page and URL; the
 * sidebar rows lead to section landing pages, and a band with tabs sits
 * above every screen in a section so the tools stay one click apart.
 *
 * Mechanics: every module registers its submenu pages exactly as
 * before, so capability gating, page hooks, and direct URLs are
 * untouched. WordPress performs its access check against the full
 * submenu early in the request; only afterward, at the last possible
 * admin_head priority (which still fires before the sidebar is
 * printed), is the submenu pruned to the six landing rows. An early
 * observer stays attached to the document root as well as the current
 * admin-menu container, reacquires BosqueGrande's submenu even when a
 * host replaces the container itself, and reapplies the same allow-list
 * before paint. Hidden pages therefore remain reachable and cap-gated
 * while legacy rows cannot reappear.
 *
 * This class is also the single source of the section vocabulary: the
 * sidebar, the Home dashboard cards, the band tabs, and the landing
 * pages all read the same map.
 */
final class SectionNav
{
    /** Landing page slugs, in sidebar order, keyed by section. */
    public const LANDINGS = [
        'people' => 'bosquegrande-people',
        'money' => 'bosquegrande-money',
        'events' => 'bosquegrande-events-hub',
        'data' => 'bosquegrande-data',
        'reports' => 'bosquegrande-reports',
        'system' => 'bosquegrande-system',
    ];

    public function __construct(private Container $container)
    {
    }

    /**
     * The section map, built at runtime so the Revenue noun and the
     * Locations URL resolve. Item shape: [label, description, target,
     * dashicon, capability]. Targets are either 'page:{slug}' or a
     * relative admin URL.
     *
     * @return array<string, array{title: string, cap: string, lead: string,
     *   items: array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>}>
     */
    public static function sections(): array
    {
        $grantTags = 'edit-tags.php?taxonomy=bg_grant_tag&post_type=bg_event';

        return [
            'people' => [
                'title' => 'People & Mail',
                'cap' => 'manage_options',
                'lead' => 'Everyone your organization knows, and everything you send them. '
                    . 'Records live encrypted in Constituents; mail flows from the composer '
                    . 'through your subscriber groups and lands in the archive.',
                'items' => [
                    ['Constituents', 'Your people: encrypted records, timelines, tags, and duplicates.',
                        'page:bosquegrande-constituents', 'dashicons-groups', 'manage_options'],
                    ['Duplicate Finder', 'Scan for likely duplicate people; review and merge them in bulk.',
                        'page:bosquegrande-people-dedup', 'dashicons-admin-users', 'manage_options'],
                    ['Newsletters', 'Compose in the visual or HTML editor; release now or save for later.',
                        'page:' . ComposeScreen::PAGE, 'dashicons-email-alt', 'manage_options'],
                    ['Newsletter Archive', 'Every newsletter you have composed or sent, kept on the site.',
                        'edit.php?post_type=bg_newsletter', 'dashicons-book', 'manage_options'],
                    ['Subscriber Groups', 'Audiences, one-click subscribe links, and the suppression list.',
                        'page:bosquegrande-groups', 'dashicons-megaphone', 'manage_options'],
                    ['Newsletter Templates', 'The email shells: pick one per newsletter, edit them, add your own.',
                        'page:bosquegrande-nl-template', 'dashicons-layout', 'manage_options'],
                    ['Correspondence', 'Mail delivery: the relay, test messages, and the send log.',
                        'page:bosquegrande-mail', 'dashicons-email', 'manage_options'],
                ],
            ],
            'money' => [
                'title' => 'Money',
                'cap' => 'manage_options',
                'lead' => 'Every dollar in one ledger: giving forms, event fees, refunds, '
                    . 'and the bookkeeper export.',
                'items' => [
                    [RevenueModule::noun(), 'The money ledger, giving forms, refunds, and the QuickBooks export.',
                        'page:bosquegrande-revenue', 'dashicons-money-alt', 'manage_options'],
                    ['Payment Setup', 'Registration payments and connection status.',
                        'page:bosquegrande-payments', 'dashicons-cart', 'manage_options'],
                ],
            ],
            'events' => [
                'title' => 'Events',
                'cap' => 'edit_posts',
                'lead' => 'The calendar and everything around it: the events themselves, their '
                    . 'places and photos, what attendees are asked afterward, and the tools '
                    . 'that keep imported events tidy.',
                'items' => [
                    ['Events', 'All calendar events: add, edit, publish.',
                        'edit.php?post_type=bg_event', 'dashicons-calendar-alt', 'edit_posts'],
                    ['Event Organizers', 'Invite organizers, assign events, set permissions, and approve their event requests.',
                        'page:bosquegrande-organizers', 'dashicons-businessperson', 'manage_options'],
                    ['Organizer App Review', 'Review walk-ins, event closeouts, and cash or check records submitted by organizers.',
                        'page:bosquegrande-organizer-app', 'dashicons-clipboard', 'bg_view_payment_details'],
                    ['Tags & Regions', 'Your event vocabulary and the rules that tag events and place people in regions.',
                        'page:bosquegrande-tagging', 'dashicons-tag', 'manage_options'],
                    ['Grant Tags', 'Grant labels for events, with optional rules that apply them automatically.',
                        $grantTags, 'dashicons-tag', 'manage_categories'],
                    ['Photos', 'Give every event a featured photo, in bulk or one by one.',
                        'page:bosquegrande-photos', 'dashicons-format-image', 'edit_posts'],
                    ['Event Statistics', 'Registrations and attendance at a glance.',
                        'page:bosquegrande-stats', 'dashicons-chart-bar', 'manage_options'],
                    ['Surveys and Mail Release', 'Review due reminders and surveys, release bounded batches, and edit survey questions.',
                        'page:bosquegrande-survey', 'dashicons-feedback', 'manage_options'],
                    ['Site Audit', 'Reads every event page like a visitor and flags anything misdirecting registration.',
                        'page:bosquegrande-audit', 'dashicons-shield', 'manage_options'],
                    ['Adopt Events', 'Bring in stray event posts left behind by earlier calendar plugins.',
                        'page:bosquegrande-adopt', 'dashicons-migrate', 'manage_options'],
                    ['Event Retagging', 'Re-run the tagging rules over every event, with optional assistant help.',
                        'page:bosquegrande-history-tools', 'dashicons-update', 'manage_options'],
                ],
            ],
            'data' => [
                'title' => 'Data & Connections',
                'cap' => 'manage_options',
                'lead' => 'Bringing your contacts and events in, and keeping them in step with '
                    . 'other systems: spreadsheet imports, Little Green Light, Eventbrite, '
                    . 'and duplicate review.',
                'items' => [
                    ['Connectors', 'Little Green Light sync and its direction, and every outside service.',
                        'page:bosquegrande-connectors', 'dashicons-randomize', 'manage_options'],
                    ['Import Studio', 'Bring in contacts and past events from spreadsheets, with a dry run and rollback.',
                        'page:bosquegrande-import-studio', 'dashicons-media-spreadsheet', 'manage_options'],
                    ['Data Transfer', 'Event transfers from Eventbrite and Little Green Light, run now or on a schedule.',
                        'page:bosquegrande-exchange', 'dashicons-database-import', 'manage_options'],
                    ['Duplicate Review', 'Review and resolve duplicate transferred events.',
                        'page:bosquegrande-dedup', 'dashicons-image-flip-horizontal', 'manage_options'],
                ],
            ],
            'reports' => [
                'title' => 'Reports & Assisted Tools',
                'cap' => 'manage_options',
                'lead' => 'What the numbers say, and the optional intelligence on top. The '
                    . 'assistant works from aggregates only; individual records and '
                    . 'sensitive fields never reach an assisted feature.',
                'items' => [
                    ['Grant Reporting', 'Prepare, save, export, and print funder-ready reports from verified totals and clearly labeled staff inputs.',
                        'page:bosquegrande-grant-reporting', 'dashicons-media-spreadsheet', 'manage_options'],
                    ['Insights', 'Twelve months of growth, money, and activity at a glance.',
                        'page:bosquegrande-insights', 'dashicons-chart-area', 'manage_options'],
                    ['Assistant', 'Ask questions about your organization\'s overall activity.',
                        'page:bosquegrande-assistant', 'dashicons-format-chat', 'manage_options'],
                    ['Assisted Tools', 'Optional writing, search, classification, and review assistance.',
                        'page:bosquegrande-ai', 'dashicons-lightbulb', 'manage_options'],
                ],
            ],
            'system' => [
                'title' => 'Settings & System Health',
                'cap' => 'edit_posts',
                'lead' => 'The plugin\'s own controls: public wording and styling, connections and '
                    . 'keys, the setup wizard, and the health checks that tell you '
                    . 'everything is running.',
                'items' => [
                    ['Wording & Styling', 'Colors, the public page header, and every piece of visitor-facing wording.',
                        'page:' . AppearanceModule::PAGE, 'dashicons-admin-appearance', 'edit_others_posts'],
                    ['Settings', 'Your organization, and the keys for Stripe, Eventbrite, Little Green Light, and mail.',
                        'page:bosquegrande-settings', 'dashicons-admin-generic', 'manage_options'],
                    ['Setup', 'The guided first-run setup, revisitable any time.',
                        'page:bosquegrande-setup', 'dashicons-admin-tools', 'manage_options'],
                    ['System Status', 'Health checks and the activity log.',
                        'page:bosquegrande-status', 'dashicons-heart', 'manage_options'],
                ],
            ],
        ];
    }

    /** A target ('page:{slug}' or relative admin URL) as a full admin URL. */
    public static function url(string $target): string
    {
        if (strpos($target, 'page:') === 0) {
            return admin_url('admin.php?page=' . substr($target, 5));
        }
        return admin_url($target);
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            foreach (self::sections() as $key => $section) {
                add_submenu_page(
                    'bosquegrande',
                    $section['title'],
                    $section['title'],
                    $section['cap'],
                    self::LANDINGS[$key],
                    function () use ($key): void {
                        $this->renderLanding($key);
                    }
                );
            }
        });

        // Prune the sidebar to the six landing rows plus Reinstall.
        // This must remain after WordPress's access check: pruning on
        // admin_menu makes otherwise valid direct tool URLs fail the
        // core user_can_access_admin_page() check. Running last on
        // admin_head also closes the intermittent case where a plugin
        // mutates the submenu after our old default-priority callback.
        add_action('admin_head', function (): void {
            $this->pruneSidebar();
        }, PHP_INT_MAX);
        // WordPress fires adminmenu immediately before rendering the
        // sidebar. Repeat the same allow-list there to catch components
        // that add submenu rows after admin_head.
        add_action('adminmenu', function (): void {
            $this->pruneSidebar();
        }, PHP_INT_MAX);

        // Defensive display-only guard. Some managed-host dashboard screens
        // render or replace the sidebar without reaching the ordinary admin
        // footer, which exposed every legacy tool row on the first page load.
        // Start in admin_head, before the menu HTML is parsed or painted. A
        // permanent document-root observer catches hosts that replace
        // #adminmenumain itself; a second observer follows whichever current
        // container is live. Every mutation reacquires the submenu before
        // pruning it.
        // Access and callbacks remain untouched; this changes display only.
        add_action('admin_head', function (): void {
            $allowed = array_values(self::LANDINGS);
            $allowed[] = Reinstaller::PAGE;
            ?>
            <style>
            /*
             * First-paint guard for modern browsers. Because this selector is
             * evaluated against the live DOM, a cached host-side replacement
             * cannot reveal legacy rows while JavaScript reacquires the menu.
             * Older browsers ignore the whole block and use the observer.
             */
            @supports selector(li:has(> a)) {
                #toplevel_page_bosquegrande > .wp-submenu > li {
                    display: none !important;
                }
                <?php foreach ($allowed as $slug): ?>
                #toplevel_page_bosquegrande > .wp-submenu > li:has(> a[href*="<?php
                    echo esc_attr('page=' . $slug);
                ?>"]) {
                    display: list-item !important;
                }
                <?php endforeach; ?>
            }
            </style>
            <script>
            (function () {
                var allowed = new Set(<?php echo wp_json_encode($allowed); ?>);
                var selector = '#toplevel_page_bosquegrande .wp-submenu';
                var containerSelector = '#adminmenumain';
                var containerObserver = null;
                var rootObserver = null;
                var observedContainer = null;
                var syncQueued = false;

                function prune(root) {
                    root.querySelectorAll('a').forEach(function (link) {
                        var page = '';
                        try {
                            page = new URL(link.href, window.location.href)
                                .searchParams.get('page') || '';
                        } catch (e) {
                            page = '';
                        }
                        if (!allowed.has(page)) {
                            var row = link.closest('li');
                            if (row) row.remove();
                        }
                    });
                }

                function sync() {
                    var root = document.querySelector(selector);
                    if (root) {
                        prune(root);
                    }
                }

                function scheduleSync() {
                    if (syncQueued) {
                        return;
                    }
                    syncQueued = true;
                    window.requestAnimationFrame(function () {
                        syncQueued = false;
                        findContainer();
                        sync();
                    });
                }

                function watch(container) {
                    if (observedContainer === container && containerObserver) {
                        sync();
                        return;
                    }

                    if (containerObserver) {
                        containerObserver.disconnect();
                    }

                    observedContainer = container;
                    sync();
                    containerObserver = new MutationObserver(function () {
                        // Never retain the old .wp-submenu node: managed hosts
                        // may replace it wholesale during the initial dashboard.
                        scheduleSync();
                    });
                    containerObserver.observe(container, {
                        childList: true,
                        subtree: true
                    });
                }

                function findContainer() {
                    var container = document.querySelector(containerSelector);
                    if (!container) {
                        if (containerObserver) {
                            containerObserver.disconnect();
                            containerObserver = null;
                        }
                        observedContainer = null;
                        return false;
                    }

                    watch(container);
                    return true;
                }

                /*
                 * This observer deliberately stays connected: if the admin
                 * menu is later replaced wholesale, a disconnected observer
                 * would be left watching a detached node.
                 */
                rootObserver = new MutationObserver(scheduleSync);
                rootObserver.observe(document.documentElement, {
                    childList: true,
                    subtree: true
                });
                findContainer();

                window.addEventListener('pageshow', function () {
                    scheduleSync();
                });
            })();
            </script>
            <?php
        }, PHP_INT_MAX);

        // Highlight the owning section row when the current screen is
        // one of its tools rather than the landing page itself.
        add_filter('submenu_file', function ($submenu_file, $parent_file) {
            $key = $this->currentSection();
            if ($key !== null && $parent_file === 'bosquegrande') {
                return self::LANDINGS[$key];
            }
            return $submenu_file;
        }, 10, 2);

        // Managed event taxonomy screens live under edit-tags.php; point them
        // back at the BosqueGrande section navigation.
        add_filter('parent_file', function ($parent_file) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && in_array($screen->taxonomy, [LocationsTaxonomy::taxonomy(), 'bg_grant_tag'], true)) {
                return 'bosquegrande';
            }
            return $parent_file;
        });

        // The section band with tabs, printed above every screen that
        // belongs to a section (including the landing pages).
        add_action('in_admin_header', function (): void {
            $this->renderBand();
        });
    }

    /* ---------------------------------------------------------------
     * Sidebar
     * --------------------------------------------------------------- */

    private function pruneSidebar(): void
    {
        global $submenu;
        if (empty($submenu['bosquegrande']) || !is_array($submenu['bosquegrande'])) return;

        $order = array_values(self::LANDINGS);
        $order[] = Reinstaller::PAGE;
        $rank = array_flip($order);

        $kept = [];
        foreach ($submenu['bosquegrande'] as $item) {
            $slug = (string) ($item[2] ?? '');
            if (isset($rank[$slug])) $kept[$rank[$slug]] = $item;
        }
        ksort($kept);
        $submenu['bosquegrande'] = array_values($kept);
    }

    /* ---------------------------------------------------------------
     * Current screen resolution
     * --------------------------------------------------------------- */

    /** The current screen as a section target string, or '' when it is none. */
    private function currentTarget(): string
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== '') return 'page:' . $page;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return '';
        if (in_array($screen->base, ['edit', 'post'], true)) {
            if ($screen->post_type === 'bg_event') return 'edit.php?post_type=bg_event';
            if ($screen->post_type === 'bg_newsletter') return 'edit.php?post_type=bg_newsletter';
        }
        if (in_array($screen->base, ['edit-tags', 'term'], true)
            && in_array($screen->taxonomy, [LocationsTaxonomy::taxonomy(), 'bg_grant_tag'], true)) {
            if ($screen->taxonomy === 'bg_grant_tag') {
                return 'edit-tags.php?taxonomy=bg_grant_tag&post_type=bg_event';
            }
            return 'page:bosquegrande-tagging';
        }
        return '';
    }

    /** The section key owning the current screen, or null. */
    private function currentSection(): ?string
    {
        $target = $this->currentTarget();
        if ($target === '') return null;

        foreach (self::sections() as $key => $section) {
            if ($target === 'page:' . self::LANDINGS[$key]) return $key;
            foreach ($section['items'] as $item) {
                if ($item[2] === $target) return $key;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------
     * The section band
     * --------------------------------------------------------------- */

    private function renderBand(): void
    {
        $key = $this->currentSection();
        if ($key === null) return;

        $sections = self::sections();
        $section = $sections[$key];
        if (!current_user_can($section['cap'])) return;

        $target = $this->currentTarget();
        $landing = 'page:' . self::LANDINGS[$key];

        echo '<div class="bg-secband">';
        printf(
            '<div class="bg-secband-brand"><a href="%s">BosqueGrande</a></div>',
            esc_url(admin_url('admin.php?page=bosquegrande'))
        );
        printf('<h2>%s</h2>', esc_html($section['title']));

        echo '<nav class="bg-sectabs">';
        printf(
            '<a class="bg-sectab%s" href="%s">Overview</a>',
            $target === $landing ? ' active' : '',
            esc_url(self::url($landing))
        );
        foreach ($section['items'] as [$label, $desc, $itemTarget, $icon, $cap]) {
            if (!current_user_can($cap)) continue;
            printf(
                '<a class="bg-sectab%s" href="%s">%s</a>',
                $itemTarget === $target ? ' active' : '',
                esc_url(self::url($itemTarget)),
                esc_html($label)
            );
        }
        echo '</nav></div>';
    }

    /* ---------------------------------------------------------------
     * Landing pages
     * --------------------------------------------------------------- */

    private function renderLanding(string $key): void
    {
        $sections = self::sections();
        $section = $sections[$key];
        if (!current_user_can($section['cap'])) return;

        printf('<div class="wrap bg-landing"><h1>%s</h1>', esc_html($section['title']));
        printf('<p class="bg-lead">%s</p>', esc_html($section['lead']));

        echo '<div class="bg-health">';
        $this->chips($key);
        echo '</div>';

        $this->spotlight($key);

        echo '<h2 class="bg-section-title">Tools</h2><div class="bg-cards">';
        foreach ($section['items'] as [$label, $desc, $target, $icon, $cap]) {
            if (!current_user_can($cap)) continue;
            printf(
                '<a class="bg-card" href="%s"><span class="dashicons %s"></span><span class="bg-card-body"><strong>%s</strong><span>%s</span></span></a>',
                esc_url(self::url($target)),
                esc_attr($icon),
                esc_html($label),
                esc_html($desc)
            );
        }
        echo '</div>';

        $notes = $this->notes($key);
        if ($notes) {
            echo '<h2 class="bg-section-title">Good to know</h2><ul class="bg-notes">';
            foreach ($notes as $note) {
                printf('<li>%s</li>', esc_html($note));
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private function chips(string $key): void
    {
        global $wpdb;
        $settings = $this->container->get('settings');
        $since30 = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        // Ledger timestamps are UTC. Convert the site's local New Year
        // boundary before calculating the business-facing year-to-date chip.
        $yearStartUtc = get_gmt_from_date(
            current_time('Y') . '-01-01 00:00:00',
            'Y-m-d H:i:s'
        );

        switch ($key) {
            case 'people':
                $this->chip('Constituents', (string) (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bg_constituents WHERE status = 'active'"
                ), true);
                $this->chip('Subscribed', (string) (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT constituent_id) FROM {$wpdb->prefix}bg_subscriptions WHERE status = 'active'"
                ), true);
                $this->chip('Suppressed', (string) (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bg_suppression"
                ), true);
                $this->chip('Sent, 30 days', (string) (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bg_send_queue WHERE status = 'sent' AND sent_at >= %s",
                    $since30
                )), true);
                $drafts = wp_count_posts('bg_newsletter');
                $this->chip('Drafts', (string) (int) ($drafts->draft ?? 0), true);
                break;

            case 'money':
                $received30 = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_cents), 0) FROM {$wpdb->prefix}bg_payments
                     WHERE kind <> 'refund' AND created_at >= %s",
                    $since30
                ));
                $refunds30 = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_cents), 0) FROM {$wpdb->prefix}bg_payments
                     WHERE kind = 'refund' AND created_at >= %s",
                    $since30
                ));
                $ytd = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_cents), 0) FROM {$wpdb->prefix}bg_payments
                     WHERE kind <> 'refund' AND created_at >= %s",
                    $yearStartUtc
                ));
                $this->chip('Received, 30 days', $this->money($received30), true);
                $this->chip('Refunded, 30 days', $this->money($refunds30), $refunds30 === 0);
                $this->chip('Received this year', $this->money($ytd), true);
                $stripe = $settings->hasSecret('stripe_secret_key');
                $this->chip('Stripe', $stripe ? 'Connected' : 'Not connected', $stripe);
                break;

            case 'events':
                $counts = wp_count_posts('bg_event');
                $this->chip('Published events', (string) (int) ($counts->publish ?? 0), true);
                $today = current_time('Y-m-d');
                // Match the public calendar: a multi-day event remains upcoming
                // through its nonempty end date; otherwise its start date is its
                // effective last day.
                $this->chip('Upcoming', (string) (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bg_start_date'
                     LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_bg_end_date'
                     WHERE p.post_type = 'bg_event' AND p.post_status = 'publish'
                       AND COALESCE(NULLIF(e.meta_value, ''), s.meta_value) >= %s",
                    $today
                )), true);
                if (current_user_can('manage_options')) {
                    $this->chip('Registrations, 30 days', (string) (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}bg_registrations WHERE created_at >= %s",
                        $since30
                    )), true);
                }
                $noPhoto = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bg_image'
                     WHERE p.post_type = 'bg_event' AND p.post_status = 'publish'
                       AND (m.meta_value IS NULL OR m.meta_value = '')"
                );
                $this->chip('Missing a photo', (string) $noPhoto, $noPhoto === 0);
                break;

            case 'data':
                $lgl = $settings->hasSecret('lgl_api_key');
                $this->chip('LGL', $lgl ? 'Connected' : 'Not connected', $lgl);
                $posture = (string) get_option('bg_lgl_posture', 'off');
                $this->chip('Sync posture', ucfirst($posture), true);
                $eb = $settings->hasSecret('eventbrite_token');
                $this->chip('Eventbrite', $eb ? 'Token on file' : 'No token', true);
                $lastRun = $wpdb->get_row(
                    "SELECT started_at, sources FROM {$wpdb->prefix}bg_transfer_runs ORDER BY id DESC LIMIT 1"
                );
                $this->chip(
                    'Last transfer',
                    $lastRun ? human_time_diff(strtotime($lastRun->started_at . ' UTC')) . ' ago' : 'Never run',
                    true
                );
                break;

            case 'reports':
                $aiOn = (int) $settings->get('ai_enabled', 0) === 1;
                $this->chip('assisted features', $aiOn ? 'On' : 'Off', true);
                $provider = (string) $settings->get('ai_provider', 'anthropic');
                $keyMap = [
                    'anthropic' => 'anthropic_api_key',
                    'openai' => 'openai_api_key',
                    'gemini' => 'gemini_api_key',
                ];
                $hasKey = isset($keyMap[$provider]) && $settings->hasSecret($keyMap[$provider]);
                $this->chip(
                    'Provider',
                    ucfirst($provider) . ($hasKey ? ', key on file' : ', no key'),
                    !$aiOn || $hasKey
                );
                if ($aiOn) {
                    $features = \BosqueGrande\Modules\Intelligence\IntelligenceModule::features();
                    $on = 0;
                    foreach (array_keys($features) as $feature) {
                        if (\BosqueGrande\Modules\Intelligence\IntelligenceModule::featureEnabled($settings, $feature)) {
                            $on++;
                        }
                    }
                    $this->chip('Switched on', $on . ' of ' . count($features), true);
                }
                break;

            case 'system':
                $this->chip('Version', BG_VERSION, true);
                $schema = (string) get_option('bg_db_version', 0);
                $this->chip('Schema', $schema . ' of ' . \BosqueGrande\Core\Schema::VERSION, (string) \BosqueGrande\Core\Schema::VERSION === $schema);
                $host = trim((string) $settings->get('smtp_host', ''));
                $this->chip('Mail transport', $host !== '' ? 'SMTP relay' : 'Site mail', true);
                $cronNext = wp_next_scheduled('bgev_cron');
                $this->chip('Calendar sync', $cronNext ? 'Scheduled' : 'Not scheduled', (bool) $cronNext);
                $lastRelease = (int) get_option('bg_notifications_last_manual_release', 0);
                $this->chip(
                    'Notification mail',
                    $lastRelease > 0
                        ? 'Manual; last release ' . human_time_diff($lastRelease) . ' ago'
                        : 'Manual release only',
                    true
                );
                $enc = $this->container->get('encryption')->available();
                $this->chip('Encryption', $enc ? 'Key in place' : 'No key', $enc);
                break;
        }
    }

    /** One timely fact per section, when there is one. */
    private function spotlight(string $key): void
    {
        global $wpdb;

        if ($key === 'events') {
            // Include events already under way. Their start date still controls
            // display order, while the effective last day controls eligibility.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT p.ID, p.post_title, s.meta_value AS start_date FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bg_start_date'
                 LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_bg_end_date'
                 WHERE p.post_type = 'bg_event' AND p.post_status = 'publish'
                   AND COALESCE(NULLIF(e.meta_value, ''), s.meta_value) >= %s
                 ORDER BY s.meta_value ASC LIMIT 1",
                current_time('Y-m-d')
            ));
            if ($row) {
                printf(
                    '<p class="bg-spotlight"><span class="dashicons dashicons-calendar-alt"></span> Next on the calendar: <a href="%s">%s</a>, %s.</p>',
                    esc_url(get_edit_post_link((int) $row->ID) ?: '#'),
                    esc_html($row->post_title),
                    esc_html(date_i18n('M j', strtotime($row->start_date)))
                );
            }
        }
    }

    /** @return string[] */
    private function notes(string $key): array
    {
        switch ($key) {
            case 'people':
                return [
                    'A newsletter goes out only after an administrator composes it, selects a subscriber group and template, reviews a received test, and explicitly chooses Release now. Save for later sends nothing.',
                    'Unsubscribes are honored automatically. Anyone on the suppression list is skipped at send time, whatever group they belong to.',
                    'Duplicate Finder is safe to run any time; nothing merges without your review, and the more complete record is always kept as the primary.',
                ];
            case 'money':
                return [
                    'The [bg_donate] shortcode places the giving form on any page; branding for it lives under Setup.',
                    'Refunds remain in the local ledger but wait in the QBO review queue. Stripe has already returned the money; the bookkeeper records the accounting treatment without sending funds again.',
                    'The QuickBooks export produces a review queue and a CSV. Nothing posts to QuickBooks by itself.',
                ];
            case 'events':
                return [
                    'Photos set here appear on the public calendar immediately; the Photos screen shows every event still missing one.',
                    'Site Audit reads every public event page the way a visitor would and flags anything that misdirects registration. Run it after large edits.',
                    'Adopt Events and Location Backfill exist for cleanup after imports; day to day, everything happens under Events and Photos.',
                ];
            case 'data':
                return [
                    'Sync postures: Push sends BosqueGrande changes to LGL, Pull brings LGL changes here, and Two-way review stages both directions for your approval. Off is a fine resting state; set a posture deliberately.',
                    'Import Studio maps large XLSX, CSV, or TSV files with administrator confirmation, a complete dry run, encrypted staging, regional assignment, and guarded rollback.',
                    'Data Transfer covers event imports from Eventbrite and LGL, run on demand or on a schedule; Tagging Rules label what arrives.',
                    'Duplicate Review remembers your "not duplicates" verdicts permanently, so nothing is asked twice.',
                ];
            case 'reports':
                return [
                    'Grant Reporting prepares date-, program-, event-, and region-scoped snapshots with finances, participation, demographics, feedback, exports, and explicit data-quality notes.',
                    'The assistant answers from aggregate numbers only. It never reads individual constituent records, and sensitive custom fields are excluded from every assisted feature.',
                    'Each assisted feature has its own switch on Assisted Tools, and the master switch turns all of it off at once.',
                    'Insights needs no assisted automation at all; the twelve-month view is computed from your own tables.',
                ];
            case 'system':
                return [
                    'System Status runs administrator-only health checks. Calendar sync should be scheduled; newsletters require an explicit release, and follow-up automation runs only when an administrator has enabled it.',
                    'Settings holds every connection and key; secrets are stored encrypted and are never redisplayed.',
                    'Reinstall, at the bottom of the menu, resets configuration and credentials. It never touches constituents, events, newsletters, or payments.',
                ];
        }
        return [];
    }

    private function chip(string $label, string $value, bool $ok): void
    {
        printf(
            '<span class="bg-chip %s"><span>%s</span> %s</span>',
            $ok ? 'bg-chip-ok' : 'bg-chip-warn',
            esc_html($label),
            esc_html($value)
        );
    }

    private function money(int $cents): string
    {
        return Money::format($cents);
    }
}
