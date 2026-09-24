<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Auditor;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Jobs;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * Site Audit. Detect and propose, review first. The scanner reads the
 * pages visitors actually receive; findings land on a review screen with
 * evidence. The only automated fix is the scrub list: link domains can be
 * added (by click, or automatically for known-retired platforms when the
 * auto toggle is on), and the calendar unwraps those links at render
 * time. Nothing is written to any event; the scrub list is an option,
 * fully reversible, and every automatic addition is logged.
 */
final class AuditorModule implements ModuleInterface
{
    public const JOB          = 'audit_batch';
    public const OPT_SCRUB    = 'bg_scrub_domains';
    public const OPT_AUTO     = 'bg_audit_auto';
    public const OPT_LAST     = 'bg_audit_last';
    public const BATCH        = 8;

    public function name(): string
    {
        return 'auditor';
    }

    public function label(): string
    {
        return 'Site Audit';
    }

    public function activate(Container $container): void
    {
        if (!wp_next_scheduled('bgev_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bgev_cron');
        }
    }

    public function boot(Container $container): void
    {
        if (!wp_next_scheduled('bgev_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bgev_cron');
        }
        /** @var Jobs $jobs */
        $jobs = $container->get('jobs');
        $jobs->register(self::JOB, function (array $args) use ($container): void {
            $scanner = new Scanner();
            $ids     = array_map('intval', (array) ($args['ids'] ?? []));
            $runId   = (string) ($args['run'] ?? '');
            $scanner->auditBatch($ids, $runId);
            $this->maybeAutoScrub($container, $ids);
        });

        // Auto mode: after each calendar sync tick, audit recently changed events.
        add_action('bgev_cron', function () use ($container): void {
            if (get_option(self::OPT_AUTO) !== '1') return;
            $this->queueChangedSinceLastRun($container);
        }, 99);

        if (is_admin()) {
            $this->registerAdmin($container);
        }
    }

    public function deactivate(Container $container): void
    {
        wp_unschedule_hook('bgev_cron');
    }

    /* ---------------------------------------------------------------
     * Queueing
     * --------------------------------------------------------------- */

    /** @param int[] $ids */
    private function queue(Container $container, array $ids): array
    {
        /** @var Jobs $jobs */
        $jobs  = $container->get('jobs');
        $runId = gmdate('Ymd-His');
        $chunks = array_chunk($ids, self::BATCH);
        $queued = 0;
        foreach ($chunks as $i => $chunk) {
            if ($jobs->enqueue(self::JOB, ['ids' => $chunk, 'run' => $runId], $i * 15)) {
                $queued += count($chunk);
            }
        }
        update_option(self::OPT_LAST, ['run' => $runId, 'time' => time(), 'queued' => $queued], false);
        return ['queued' => $queued, 'batches' => count($chunks), 'run' => $runId];
    }

    private function queueChangedSinceLastRun(Container $container): void
    {
        $last = get_option(self::OPT_LAST, []);
        $since = is_array($last) && !empty($last['time']) ? (int) $last['time'] : time() - DAY_IN_SECONDS;
        $q = new \WP_Query([
            'post_type'      => 'bg_event',
            'post_status'    => 'publish',
            'posts_per_page' => 25,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'date_query'     => [['column' => 'post_modified_gmt',
                'after' => gmdate('Y-m-d H:i:s', $since)]],
        ]);
        $ids = array_map('intval', $q->posts);
        if ($ids) {
            $this->queue($container, $ids);
            $container->get('logger')->info('auditor',
                sprintf('Auto audit queued %d changed event(s).', count($ids)));
        }
    }

    /** Auto mode may scrub only known-retired platforms, and logs every addition. @param int[] $ids */
    private function maybeAutoScrub(Container $container, array $ids): void
    {
        if (get_option(self::OPT_AUTO) !== '1') return;
        /** @var Logger $logger */
        $logger = $container->get('logger');
        foreach ($ids as $id) {
            $audit = json_decode((string) get_post_meta($id, Scanner::META, true), true);
            foreach ((array) ($audit['findings'] ?? []) as $f) {
                $domain = (string) ($f['domain'] ?? '');
                if ($domain === '' || ($f['category'] ?? '') !== 'legacy_link') continue;
                foreach (Scanner::RETIRED as $retired) {
                    if (strpos($domain, $retired) !== false && self::addScrubDomain($domain)) {
                        $logger->warn('auditor', sprintf(
                            'Auto-scrub: links to %s are now unwrapped at render (event %d). Reversible on the Site Audit page.',
                            $domain, $id
                        ));
                    }
                }
            }
        }
    }

    public static function scrubDomains(): array
    {
        // The scrub list is site data, never shipped defaults: domains an
        // admin retires on the Site Audit page.
        $d = get_option(self::OPT_SCRUB, []);
        return is_array($d) ? array_values(array_filter(array_map('strval', $d))) : [];
    }

    public static function addScrubDomain(string $domain): bool
    {
        $domain = strtolower(sanitize_text_field($domain));
        if ($domain === '') return false;
        $list = self::scrubDomains();
        if (in_array($domain, $list, true)) return false;
        $list[] = $domain;
        update_option(self::OPT_SCRUB, $list, false);
        return true;
    }

    /* ---------------------------------------------------------------
     * Admin
     * --------------------------------------------------------------- */

    private function registerAdmin(Container $container): void
    {
        add_action('admin_menu', function () use ($container) {
            add_submenu_page('bosquegrande', 'Site Audit', 'Site Audit',
                'manage_options', 'bosquegrande-audit', function () use ($container) {
                    $this->render($container);
                });
        }, 39);

        add_action('admin_post_bg_audit_run', function () use ($container) {
            $this->guard('bg_audit_run');
            $scanner = new Scanner();
            $result  = $this->queue($container, $scanner->targets());
            $this->back(['msg' => 'queued', 'queued' => $result['queued']]);
        });

        add_action('admin_post_bg_audit_scrub', function () {
            $this->guard('bg_audit_scrub');
            $domain = (string) ($_POST['domain'] ?? '');
            $added  = self::addScrubDomain($domain);
            $this->back(['msg' => $added ? 'scrubbed' : 'noop']);
        });

        add_action('admin_post_bg_audit_unscrub', function () {
            $this->guard('bg_audit_unscrub');
            $domain = strtolower(sanitize_text_field((string) ($_POST['domain'] ?? '')));
            $list = array_values(array_diff(self::scrubDomains(), [$domain]));
            update_option(self::OPT_SCRUB, $list, false);
            $this->back(['msg' => 'unscrubbed']);
        });

        add_action('admin_post_bg_audit_auto', function () {
            $this->guard('bg_audit_auto');
            update_option(self::OPT_AUTO, !empty($_POST['auto']) ? '1' : '', false);
            $this->back(['msg' => 'settings']);
        });
    }

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_POST['_wpnonce'] ?? ''))), $action)) {
            wp_die('Security check failed. Please go back and try again.');
        }
    }

    private function back(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'bosquegrande-audit'], $args), admin_url('admin.php')));
        exit;
    }

    private function render(Container $container): void
    {
        if (!current_user_can('manage_options')) return;

        $msg = sanitize_key((string) ($_GET['msg'] ?? ''));
        $notices = [
            'queued'     => sprintf('Audit queued for %d event(s). Findings appear below as the background work runs; refresh in a few minutes.', (int) ($_GET['queued'] ?? 0)),
            'scrubbed'   => 'Domain added to the scrub list. Links to it are now unwrapped at render, site-wide, reversibly.',
            'unscrubbed' => 'Domain removed from the scrub list.',
            'settings'   => 'Audit settings saved.',
            'noop'       => 'Nothing to change.',
        ];
        if (isset($notices[$msg])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($notices[$msg]));
        }

        echo '<div class="wrap"><h1>Site Audit</h1>';
        echo '<p>The audit reads every event page exactly as a visitor (and any page cache) receives it, then flags anything that would misdirect or confuse registration: links to retired platforms, a missing or wrong REGISTER button, dates that contradict the listing, broken text. Findings are suggestions with evidence; nothing changes without a decision here.</p>';

        // Run + auto toggle
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:24px">';
        wp_nonce_field('bg_audit_run');
        echo '<input type="hidden" name="action" value="bg_audit_run" />';
        submit_button('Run audit now', 'primary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        wp_nonce_field('bg_audit_auto');
        echo '<input type="hidden" name="action" value="bg_audit_auto" />';
        echo '<label><input type="checkbox" name="auto" value="1" ' . checked(get_option(self::OPT_AUTO) === '1', true, false) . '> ';
        echo 'Audit changed events automatically after each calendar sync, and auto-unwrap links to known retired platforms (logged, reversible)</label> ';
        submit_button('Save', 'secondary', 'submit', false);
        echo '</form>';

        $this->renderScrubList();
        $this->renderFindings();
        echo '</div>';
    }

    private function renderScrubList(): void
    {
        $list = self::scrubDomains();
        echo '<h2>Scrub list</h2>';
        echo '<p class="description">Links whose address contains any entry below are unwrapped at render time: the words stay, the link goes. This never edits the event itself, so it survives every calendar sync and can be undone here at any time.</p>';
        if ($list) {
            echo '<ul>';
            foreach ($list as $d) {
                echo '<li><code>' . esc_html($d) . '</code> ';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                wp_nonce_field('bg_audit_unscrub');
                echo '<input type="hidden" name="action" value="bg_audit_unscrub" />';
                echo '<input type="hidden" name="domain" value="' . esc_attr($d) . '" />';
                echo '<button type="submit" class="button-link">remove</button></form></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>Empty.</p>';
        }
    }

    private function renderFindings(): void
    {
        $q = new \WP_Query([
            'post_type' => 'bg_event', 'post_status' => 'any',
            'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_key' => Scanner::META,
        ]);
        echo '<h2>Findings</h2>';
        $rows = [];
        foreach ($q->posts as $id) {
            $audit = json_decode((string) get_post_meta((int) $id, Scanner::META, true), true);
            if (!is_array($audit)) continue;
            foreach ((array) ($audit['findings'] ?? []) as $f) {
                if (is_array($f)) $rows[] = [(int) $id, $f, (int) ($audit['time'] ?? 0)];
            }
        }
        if (!$rows) {
            echo '<p>No findings on record. Run the audit above.</p>';
            return;
        }
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($rows, static fn(array $a, array $b): int
            => ($rank[(string) ($a[1]['severity'] ?? '')] ?? 3) <=> ($rank[(string) ($b[1]['severity'] ?? '')] ?? 3));

        echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Event</th><th>Finding</th><th>Evidence</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as [$id, $f, $time]) {
            $sev = (string) ($f['severity'] ?? 'low');
            $color = $sev === 'high' ? '#b32d2e' : ($sev === 'medium' ? '#8a6d00' : '#5A6A61');
            echo '<tr>';
            printf('<td style="color:%s;font-weight:600">%s</td>', esc_attr($color), esc_html(strtoupper($sev)));
            printf('<td><a href="%s">%s</a></td>', esc_url((string) get_edit_post_link($id)), esc_html(get_the_title($id) ?: ('#' . $id)));
            printf('<td>%s%s</td>', esc_html((string) $f['category']),
                !empty($f['note']) ? '<br><span class="description">' . esc_html((string) $f['note']) . '</span>' : '');
            printf('<td>%s</td>', esc_html((string) ($f['evidence'] ?? '')));
            echo '<td>';
            if (!empty($f['domain'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('bg_audit_scrub');
                echo '<input type="hidden" name="action" value="bg_audit_scrub" />';
                echo '<input type="hidden" name="domain" value="' . esc_attr((string) $f['domain']) . '" />';
                echo '<button type="submit" class="button">Unwrap links to ' . esc_html((string) $f['domain']) . '</button></form>';
            } else {
                echo '<a class="button" href="' . esc_url((string) get_edit_post_link($id)) . '">Open event</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">High findings misdirect or block registration. The audit re-checks an event on the next run; fixed findings disappear.</p>';
    }
}
