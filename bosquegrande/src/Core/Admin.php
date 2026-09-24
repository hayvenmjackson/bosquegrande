<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

use BosqueGrande\Modules\Locations\Taxonomy as LocationsTaxonomy;

if (!defined('ABSPATH')) exit;

/**
 * The BosqueGrande admin menu. One top-level menu holds everything: Home
 * (a card dashboard for staff and volunteers), Events, Photos, imports,
 * statistics, surveys, payments, settings, and System Status. The event
 * post type is placed here through register_post_type_args.
 *
 * Capability: the top level opens at edit_posts so volunteers see Home,
 * Events, and Photos; management screens keep manage_options and WP
 * hides them from users who lack it.
 */
final class Admin
{
    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', function (string $hook) {
            $wanted = strpos($hook, 'bosquegrande') !== false;
            if (!$wanted) {
                $screen = get_current_screen();
                $wanted = $screen && (
                    in_array($screen->post_type, ['bg_event', 'bg_newsletter'], true)
                    || $screen->taxonomy === LocationsTaxonomy::taxonomy()
                );
            }
            if (!$wanted) return;
            wp_enqueue_style('bosquegrande-admin', BG_URL . 'assets/admin.css', [], BG_VERSION);
        });

        // Show the Events post type inside the BosqueGrande menu.
        add_filter('register_post_type_args', function (array $args, string $type): array {
            if ($type === 'bg_event') {
                $args['show_in_menu'] = 'bosquegrande';
            }
            return $args;
        }, 10, 2);

        add_action('admin_menu', function () {
            add_menu_page(
                'BosqueGrande',
                'BosqueGrande',
                'edit_posts',
                'bosquegrande',
                [$this, 'renderHome'],
                'dashicons-palmtree',
                58
            );
            add_submenu_page(
                'bosquegrande',
                'Home',
                'Home',
                'edit_posts',
                'bosquegrande',
                [$this, 'renderHome']
            );
            add_submenu_page(
                'bosquegrande',
                'System Status',
                'System Status',
                'manage_options',
                'bosquegrande-status',
                [$this, 'renderStatus']
            );
        });
        add_action('admin_post_bg_crypto_backup', [$this, 'downloadCryptoBackup']);
        add_action('admin_post_bg_crypto_rotate', [$this, 'rotateCryptoKey']);
        add_action('admin_post_bg_crypto_retry', [$this, 'retryCryptoMigration']);
        add_action('admin_post_bg_clear_answer_failure', function (): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('POST required.', 405);
            check_admin_referer('bg_clear_answer_failure');
            delete_option('bg_answer_store_failure');
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        });

        // Boot failures show as a banner, never only as log lines.
        // Answer storage trouble must never be quiet; the forms refuse to
        // complete when these writes fail.
        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) return;
            $when = (string) get_option('bg_answer_store_failure', '');
            if ($when === '') return;
            printf(
                '<div class="notice notice-warning"><p><strong>BosqueGrande: personal-answer storage needs attention.</strong> '
                . 'A form could not verify its encrypted demographic or survey response write (most recently %s UTC), so the form did not report completion. '
                . 'Current versions commit these answers inside the constituent envelope and verify the decrypt-after-write round trip; persistent failures usually mean a database or encryption-key problem. See the System Status page, then clear this warning after the cause is fixed.</p>'
                . '<form method="post" action="%s" style="margin:0 0 10px">%s<input type="hidden" name="action" value="bg_clear_answer_failure"><button type="submit" class="button-link">Clear this warning</button></form></div>',
                esc_html($when),
                esc_url(admin_url('admin-post.php')),
                wp_nonce_field('bg_clear_answer_failure', '_wpnonce', true, false)
            );
        });

        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) return;
            $failed = get_option('bg_boot_failures', []);
            if (!is_array($failed) || $failed === []) return;
            $parts = [];
            foreach ($failed as $label => $msg) {
                $parts[] = $label . ' (' . $msg . ')';
            }
            printf(
                '<div class="notice notice-error"><p><strong>BosqueGrande: %d module(s) failed to start.</strong> %s. Features belonging to these modules are offline until this is fixed. Details on the System Status page.</p></div>',
                count($failed),
                esc_html(implode('; ', $parts))
            );
        });

        // A credential that can no longer be decrypted (salt
        // rotation, site move) used to fail silently, taking mail, LGL,
        // or payments down with it. Now it is announced as a banner.
        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) return;
            $settings = \BosqueGrande\Core\Plugin::instance()->container()->get('settings');
            $labels = [
                'lgl_api_key' => 'LGL API key',
                'stripe_secret_key' => 'Stripe secret key',
                'stripe_webhook_secret' => 'Stripe webhook secret',
                'smtp_password' => 'Mail relay password',
                'eventbrite_token' => 'Eventbrite token',
                'anthropic_api_key' => 'Anthropic API key',
                'openai_api_key' => 'OpenAI API key',
                'gemini_api_key' => 'Gemini API key',
            ];
            $unreadable = [];
            foreach ($labels as $key => $label) {
                if ($settings->secretUnreadable($key)) $unreadable[] = $label;
            }
            if (!$unreadable) return;
            printf(
                '<div class="notice notice-error"><p><strong>BosqueGrande: %d stored credential(s) can no longer be read:</strong> %s. This happens when the site\'s security keys are changed or the site is moved. The features concerned are offline until each credential is entered again on its settings page.</p></div>',
                count($unreadable),
                esc_html(implode(', ', $unreadable))
            );
        });

        // Encryption is self-managing in normal operation. Only conditions
        // that require human attention become global notices.
        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) return;
            /** @var CryptoMigrator $migrator */
            $migrator = $this->container->get('crypto.migrator');
            $report = $migrator->report();
            if (!$report['health']['ok']) {
                printf(
                    '<div class="notice notice-error"><p><strong>BosqueGrande encryption is unavailable.</strong> '
                    . 'Sensitive writes are safely paused rather than stored in plaintext. Status: %s. '
                    . '<a href="%s">Open System Status</a>.</p></div>',
                    esc_html($report['health']['status']),
                    esc_url(admin_url('admin.php?page=bosquegrande-status'))
                );
                return;
            }
            if (($report['migration']['status'] ?? '') === 'blocked') {
                printf(
                    '<div class="notice notice-warning"><p><strong>BosqueGrande protected the readable data, but %d stored item(s) need review.</strong> '
                    . 'No failed source value was erased. <a href="%s">Review and retry on System Status</a>.</p></div>',
                    (int) ($report['migration']['failed'] ?? 0),
                    esc_url(admin_url('admin.php?page=bosquegrande-status'))
                );
            }
        });

    }

    public function downloadCryptoBackup(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_crypto_backup');
        /** @var KeyStore $keys */
        $keys = $this->container->get('keys');
        $body = $keys->exportJson();
        if ($body === '') wp_die('The encryption keyring is unavailable.');
        update_option('bg_crypto_backup_downloaded_at', current_time('mysql', true), false);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="bosquegrande-keyring-backup.json"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    public function rotateCryptoKey(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_crypto_rotate');
        /** @var CryptoMigrator $migrator */
        $migrator = $this->container->get('crypto.migrator');
        $id = $migrator->rotate();
        $notice = $id === null
            ? 'Key rotation was refused; the existing key and data are unchanged.'
            : 'A new encryption key is active. Background re-encryption has started; old keys remain available during migration.';
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-status', 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }

    public function retryCryptoMigration(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_crypto_retry');
        /** @var CryptoMigrator $migrator */
        $migrator = $this->container->get('crypto.migrator');
        $migrator->retry();
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-status', 'bg_notice' => rawurlencode('Encryption migration restarted.')],
            admin_url('admin.php')
        ));
        exit;
    }

    /* ---------------------------------------------------------------
     * Home: the card dashboard for staff and volunteers
     * --------------------------------------------------------------- */

    public function renderHome(): void
    {
        if (!current_user_can('edit_posts')) return;

        $eventCount = wp_count_posts('bg_event');
        $published = (int) ($eventCount->publish ?? 0);
        $cronNext = wp_next_scheduled('bgev_cron');

        echo '<div class="wrap bg-home"><h1>BosqueGrande</h1>';
        echo '<p>Everything lives under six headings. Pick a card, or a heading for its overview.</p>';

        echo '<div class="bg-health">';
        $this->chip('Published events', (string) $published, true);
        if (current_user_can('manage_options')) {
            global $wpdb;
            $constituents = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bg_constituents WHERE status = 'active'"
            );
            $this->chip('Constituents', (string) $constituents, true);
        }
        $this->chip('Calendar sync', $cronNext ? 'Running' : 'Not scheduled', (bool) $cronNext);
        // Proactive notification mail is intentionally manual.
        $lastRelease = (int) get_option('bg_notifications_last_manual_release', 0);
        if ($lastRelease > 0) {
            $this->chip(
                'Notification mail',
                'Manual; last release ' . human_time_diff($lastRelease) . ' ago',
                true
            );
        } else {
            $this->chip('Notification mail', 'Manual release only', true);
        }
        $this->chip('Version', BG_VERSION, true);
        echo '</div>';

        // Cards grouped under the same six heads the sidebar uses,
        // read from SectionNav so there is one vocabulary everywhere. A
        // section with nothing visible to the current user renders no
        // heading. Each heading links to its section landing page.
        foreach (SectionNav::sections() as $key => $section) {
            $visible = array_filter($section['items'],
                static fn(array $c) => current_user_can($c[4]));
            if (!$visible) continue;
            printf(
                '<h2 class="bg-section-title"><a href="%s">%s</a></h2>',
                esc_url(admin_url('admin.php?page=' . SectionNav::LANDINGS[$key])),
                esc_html($section['title'])
            );
            echo '<div class="bg-cards">';
            foreach ($visible as [$title, $desc, $target, $icon, $cap]) {
                printf(
                    '<a class="bg-card" href="%s"><span class="dashicons %s"></span><span class="bg-card-body"><strong>%s</strong><span>%s</span></span></a>',
                    esc_url(SectionNav::url($target)),
                    esc_attr($icon),
                    esc_html($title),
                    esc_html($desc)
                );
            }
            echo '</div>';
        }
        echo '</div>';
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

    /* ---------------------------------------------------------------
     * System Status
     * --------------------------------------------------------------- */

    public function renderStatus(): void
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;

        /** @var ModuleRegistry $modules */
        $modules = $this->container->get('modules');
        /** @var Logger $logger */
        $logger = $this->container->get('logger');

        $cronNext = wp_next_scheduled('bgev_cron');
        $eventCount = wp_count_posts('bg_event');
        $published = $eventCount->publish ?? 0;

        echo '<div class="wrap"><h1>BosqueGrande Status</h1>';
        $notice = isset($_GET['bg_notice'])
            ? sanitize_text_field(rawurldecode((string) $_GET['bg_notice']))
            : '';
        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<h2 class="title">System</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        $this->row('Plugin version', esc_html(BG_VERSION));
        $this->row('Schema version', esc_html((string) get_option('bg_db_version', 0)) . ' of ' . esc_html((string) \BosqueGrande\Core\Schema::VERSION));
        $presentation = \BosqueGrande\Modules\GatheringTree\GatheringTreeModule::presentation();
        $shellBuild = BG_VERSION;
        $theme = wp_get_theme();
        $themeLabel = $theme->exists()
            ? (string) $theme->get('Name') . ' (' . $theme->get_stylesheet() . ')'
            : '(theme metadata unavailable)';
        $themeLabel .= function_exists('wp_is_block_theme') && wp_is_block_theme()
            ? ' (block theme)'
            : ' (classic theme)';
        $shellFiles = [
            BG_DIR . 'src/Modules/GatheringTree/GatheringTreeModule.php',
            BG_DIR . 'src/Modules/GatheringTree/render.php',
            BG_DIR . 'src/Modules/GatheringTree/sitewide-home.php',
            BG_DIR . 'src/Modules/GatheringTree/sitewide-page.php',
            BG_DIR . 'src/Modules/GatheringTree/public-app-page.php',
            BG_DIR . 'src/Modules/GatheringTree/single-bg_event.php',
            BG_DIR . 'src/Modules/GatheringTree/assets/gathering-tree.css',
        ];
        $shellHashes = [];
        $missingShellFiles = [];
        foreach ($shellFiles as $shellFile) {
            if (!is_readable($shellFile)) {
                $missingShellFiles[] = basename($shellFile);
                continue;
            }
            $hash = hash_file('sha256', $shellFile);
            if (!is_string($hash)) {
                $missingShellFiles[] = basename($shellFile);
                continue;
            }
            $shellHashes[] = $hash;
        }
        $shellFingerprint = count($shellHashes) === count($shellFiles)
            ? substr(hash('sha256', implode('|', $shellHashes)), 0, 16)
            : '';
        $bootFailures = get_option('bg_boot_failures', []);
        $bootFailures = is_array($bootFailures) ? $bootFailures : [];
        $this->row('Public pages mode', '<code>' . esc_html($presentation) . '</code>');
        $this->row('Expected public-shell marker', '<code>' . esc_html($shellBuild) . '</code>');
        $this->row('Active WordPress theme', esc_html($themeLabel)
            . '<br><span class="description">The theme remains active in WordPress, but its visual templates are replaced on BosqueGrande public routes.</span>');
        $this->row('Public-shell files', $missingShellFiles === []
            ? '<span style="color:#2A6B40;font-weight:700">Ready</span>'
            : '<span style="color:#b32d2e;font-weight:700">Missing or unreadable: ' . esc_html(implode(', ', $missingShellFiles)) . '</span>');
        $this->row('Public-shell fingerprint', $shellFingerprint !== ''
            ? '<code>' . esc_html($shellFingerprint) . '</code>'
            : '<span style="color:#b32d2e">Unavailable</span>');
        $this->row('Module boot failures', $bootFailures === []
            ? '<span style="color:#2A6B40;font-weight:700">None</span>'
            : '<span style="color:#b32d2e;font-weight:700">' . esc_html((string) count($bootFailures)) . ': ' . esc_html(implode('; ', array_map('strval', $bootFailures))) . '</span>');
        $this->row('Calendar events (published)', esc_html((string) $published));
        $sample = get_posts(['post_type' => 'bg_event', 'post_status' => 'publish',
            'posts_per_page' => 1, 'fields' => 'ids']);
        if ($sample) {
            $regUrl = function_exists('bgev_reg') ? (string) bgev_reg((int) $sample[0]) : '';
            $native = strpos($regUrl, '/bg-register/') !== false;
            $this->row('Registration link check', $native
                ? 'OK (buttons route to the built-in form)'
                : '<span style="color:#b32d2e">FAILED: sample button href is ' . esc_html($regUrl ?: '(empty)') . '</span>');
        }
        $this->row('Calendar sync cron', $cronNext
            ? 'Scheduled, next run ' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $cronNext), 'M j, g:i a'))
            : '<span style="color:#b32d2e">Not scheduled</span>');
        echo '</tbody></table>';

        /** @var CryptoMigrator $crypto */
        $crypto = $this->container->get('crypto.migrator');
        /** @var KeyStore $keys */
        $keys = $this->container->get('keys');
        $cryptoReport = $crypto->report();
        $health = $cryptoReport['health'];
        $migration = $cryptoReport['migration'];
        $lastHealth = get_option('bg_crypto_health', []);
        $lastHealthAt = is_array($lastHealth) ? (string) ($lastHealth['checked_at'] ?? '') : '';

        echo '<h2 class="title">Data protection</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        $this->row('Authenticated encryption', $health['ok']
            ? '<span style="color:#2A6B40;font-weight:700">Ready</span>'
            : '<span style="color:#b32d2e;font-weight:700">Unavailable: ' . esc_html($health['status']) . '</span>');
        $this->row('Active key id', esc_html($health['key_id'] ?: '(none)'));
        $this->row('Key source', esc_html($keys->status()));
        $this->row('Migration', esc_html(ucfirst((string) ($migration['status'] ?? 'not started')))
            . (!empty($migration['stage']) ? ': ' . esc_html((string) $migration['stage']) : ''));
        $this->row('Migration totals', sprintf(
            '%d examined; %d encrypted; %d isolated failure(s)',
            (int) ($migration['processed'] ?? 0),
            (int) ($migration['migrated'] ?? 0),
            (int) ($migration['failed'] ?? 0)
        ));
        $this->row('Daily self-test', $lastHealthAt !== '' ? esc_html($lastHealthAt . ' UTC') : 'Scheduled');
        $this->row('Recovery backup', get_option('bg_crypto_backup_downloaded_at', '')
            ? 'Downloaded ' . esc_html((string) get_option('bg_crypto_backup_downloaded_at')) . ' UTC'
            : 'Not downloaded yet');
        echo '</tbody></table>';

        echo '<p>';
        printf(
            '<a class="button" href="%s">Download emergency key backup</a> ',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=bg_crypto_backup'), 'bg_crypto_backup'))
        );
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-left:4px">';
        wp_nonce_field('bg_crypto_rotate');
        echo '<input type="hidden" name="action" value="bg_crypto_rotate">';
        echo '<button class="button" type="submit" onclick="return confirm(\'Rotate the data key now? Existing keys remain available while records are re-encrypted.\')">Rotate data key</button>';
        echo '</form>';
        if (($migration['status'] ?? '') === 'blocked') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-left:4px">';
            wp_nonce_field('bg_crypto_retry');
            echo '<input type="hidden" name="action" value="bg_crypto_retry">';
            echo '<button class="button button-primary" type="submit">Retry isolated failures</button>';
            echo '</form>';
        }
        echo '</p>';

        if ($cryptoReport['failures']) {
            echo '<details style="max-width:720px"><summary>Migration failure details</summary><ul>';
            foreach (array_slice($cryptoReport['failures'], 0, 20) as $failure) {
                printf(
                    '<li><code>%s %s</code>: %s</li>',
                    esc_html((string) ($failure['type'] ?? '')),
                    esc_html((string) ($failure['id'] ?? '')),
                    esc_html((string) ($failure['message'] ?? ''))
                );
            }
            echo '</ul></details>';
        }

        echo '<h2 class="title">Tables</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        $present = array_map('strval', (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'bg_') . '%')
        ));
        $expected = Schema::tableNames();
        $missing = array_values(array_diff(
            array_map(static fn(string $t): string => $wpdb->prefix . $t, $expected),
            $present
        ));
        $this->row('Database tables', $missing === []
            ? esc_html(sprintf('All %d present', count($expected)))
            : '<span style="color:#b32d2e">Missing: ' . esc_html(implode(', ', $missing)) . '</span>');
        echo '</tbody></table>';

        echo '<h2 class="title">Modules</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        foreach ($modules->all() as $m) {
            $this->row(esc_html($m->label()), esc_html($m->name()));
        }
        echo '</tbody></table>';

        echo '<h2 class="title">Recent log</h2>';
        $entries = $logger->recent(25);
        if (!$entries) {
            echo '<p>No log entries yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Level</th><th>Channel</th><th>Message</th></tr></thead><tbody>';
            foreach ($entries as $e) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html($e->created_at),
                    esc_html($e->level),
                    esc_html($e->channel),
                    esc_html($e->message)
                );
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private function row(string $label, string $valueHtml): void
    {
        echo '<tr><td style="width:280px"><strong>' . $label . '</strong></td><td>' . $valueHtml . '</td></tr>';
    }
}
