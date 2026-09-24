<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Connectors;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Lgl\LglClient;

if (!defined('ABSPATH')) exit;

/**
 * Connectors: one screen for the outside services BosqueGrande talks to.
 * Little Green Light sync (direction, run now, last-run counts, and the
 * two-way review), plus pointers to the Eventbrite, Stripe, and QuickBooks
 * screens.
 */
final class ConnectorsModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-connectors';
    public const JOB = 'lgl_sync';

    public function name(): string
    {
        return 'connectors';
    }

    public function label(): string
    {
        return 'Connectors (Little Green Light sync)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->set('connectors.lglsync', fn(Container $c) => new LglSync(
            $c->get('constituents.store'), $c->get('constituents.activity'),
            $c->get('lgl'), $c->get('logger'), $c->get('encryption')
        ));

        // The chained sync worker: one chunk per run, requeue until done.
        $container->get('jobs')->register(self::JOB, function (array $args) use ($container): void {
            /** @var LglSync $sync */
            $sync = $container->get('connectors.lglsync');
            $op = (string) ($args['op'] ?? '');
            $offset = (int) ($args['offset'] ?? 0);
            $runId = (int) ($args['run'] ?? 0);
            $totals = (array) ($args['totals'] ?? []);
            $counts = match ($op) {
                'pull' => $sync->pullChunk($offset, false),
                'twoway' => $sync->pullChunk($offset, true),
                'push' => $sync->pushChunk($offset),
                'pull_selected' => $sync->pullSelectedChunk((array) ($args['ids'] ?? []), $offset),
                'push_selected' => $sync->pushSelectedChunk((array) ($args['ids'] ?? []), $offset),
                default => ['seen' => 0],
            };
            foreach ($counts as $key => $n) {
                $totals[$key] = (int) ($totals[$key] ?? 0) + (int) $n;
            }
            if ((int) ($counts['seen'] ?? 0) >= LglSync::CHUNK) {
                $queued = $container->get('jobs')->enqueue(self::JOB, [
                    'op' => $op, 'offset' => $offset + LglSync::CHUNK,
                    'run' => $runId, 'totals' => $totals,
                    'ids' => (array) ($args['ids'] ?? []),
                ], 15);
                if (!$queued) {
                    $totals['errors'] = (int) ($totals['errors'] ?? 0) + 1;
                    $container->get('logger')->error('connectors', 'LGL sync stopped because its next chunk could not be scheduled.', [
                        'operation' => $op,
                        'offset' => $offset + LglSync::CHUNK,
                    ]);
                    if ($runId) $sync->closeRun($runId, $totals);
                }
                return;
            }
            if ($runId) $sync->closeRun($runId, $totals);
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Connectors', 'Connectors',
                'manage_options', self::PAGE, [$this, 'renderPage']);
        }, 16);

        foreach (['lgl_posture', 'lgl_run', 'lgl_pull_all', 'lgl_push_selected', 'lgl_pull_selected', 'lgl_diff', 'lgl_diff_clear', 'uninstall_opt', 'refresh_caches'] as $op) {
            add_action('admin_post_bg_' . $op, function () use ($container, $op): void {
                $method = 'handle' . str_replace('_', '', ucwords($op, '_'));
                $this->{$method}($container);
            });
        }
    }

    /* ── Screen ───────────────────────────────────────────────────── */

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        /** @var LglClient $lgl */
        $lgl = $container->get('lgl');
        /** @var LglSync $sync */
        $sync = $container->get('connectors.lglsync');
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $posture = (string) get_option('bg_lgl_posture', 'off');
        $lastRun = $sync->lastRun();
        $diffs = $sync->diffs();
        ?>
        <div class="wrap"><h1>Connectors</h1>
        <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

        <h2>Little Green Light</h2>
        <?php if (!$lgl->hasKey()): ?>
            <p>Not connected. Add the LGL API key under
            <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-settings')); ?>">Settings</a> to sync constituents.</p>
        <?php else: ?>
            <p style="max-width:760px">BosqueGrande is the system of record; LGL is optional. Pick who wins:
            <strong>Push</strong> (BosqueGrande is master, LGL follows), <strong>Pull</strong> (LGL is master, BG
            follows), or <strong>Two-way review</strong> (nothing writes itself; every difference below waits
            for your click). Matching uses the linked LGL id first. Email only finds candidates;
            shared-contact candidates must identify one unique person or the row stops for review.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
                <?php wp_nonce_field('bg_lgl_posture'); ?>
                <input type="hidden" name="action" value="bg_lgl_posture">
                <select name="posture">
                    <option value="off" <?php selected($posture, 'off'); ?>>Off</option>
                    <option value="push" <?php selected($posture, 'push'); ?>>Push (BosqueGrande is master)</option>
                    <option value="pull" <?php selected($posture, 'pull'); ?>>Pull (LGL is master)</option>
                    <option value="twoway" <?php selected($posture, 'twoway'); ?>>Two-way review</option>
                </select>
                <button class="button">Save posture</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_lgl_run'); ?>
                <input type="hidden" name="action" value="bg_lgl_run">
                <button class="button button-primary" <?php disabled($posture, 'off'); ?>>Run sync now</button>
                <span class="description">Runs in the background in chunks of <?php echo (int) LglSync::CHUNK; ?>; results land below.</span>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px"
                  onsubmit="return confirm('Pull every LGL constituent into BosqueGrande now? Nonblank LGL values will update matching local fields, and new LGL records will be created in BosqueGrande.');">
                <?php wp_nonce_field('bg_lgl_pull_all'); ?>
                <input type="hidden" name="action" value="bg_lgl_pull_all">
                <button class="button button-primary">Pull all constituents from LGL</button>
                <p class="description" style="max-width:760px">Runs a complete LGL-to-BosqueGrande pull regardless of the saved posture.
                Records link by their saved LGL id first. Exact current email addresses find candidates, but name,
                phone, or address evidence must identify one person when contact details are shared. Nonblank LGL values
                update the matching local fields; blank LGL values do not erase local values. Every current LGL email address
                is added to the same contact record.</p>
            </form>
            <?php if ($lastRun): ?>
                <p><strong>Last run</strong> (<?php echo esc_html((string) $lastRun->mode); ?>,
                started <?php echo esc_html((string) $lastRun->started_at); ?> UTC):
                <?php echo $lastRun->finished_at
                    ? esc_html(sprintf('%d created, %d updated, %d staged for review, %d skipped, %d errors.',
                        (int) $lastRun->created, (int) $lastRun->updated, (int) $lastRun->staged,
                        (int) $lastRun->skipped, (int) $lastRun->errors))
                    : 'still running.'; ?></p>
            <?php endif; ?>

            <?php if ($diffs): ?>
                <h3>Two-way review (<?php echo count($diffs); ?> difference<?php echo count($diffs) === 1 ? '' : 's'; ?>)</h3>
                <table class="widefat striped" style="max-width:960px">
                    <thead><tr><th>Record</th><th>Field</th><th>BosqueGrande has</th><th>LGL has</th><th>Apply</th></tr></thead>
                    <tbody>
                    <?php foreach ($diffs as $d):
                        $record = $container->get('constituents.store')->get((int) $d['constituent_id']);
                        $label = $record ? trim($record['first_name'] . ' ' . $record['last_name'])
                            : ((string) $d['field'] === '__create__' ? 'New LGL record' : ('#' . $d['constituent_id']));
                        $differenceId = (int) ($d['_id'] ?? 0);
                        $fingerprint = (string) ($d['_fingerprint'] ?? '');
                    ?>
                        <tr><td><?php echo esc_html($label); ?></td>
                            <td><code><?php echo esc_html((string) $d['field']); ?></code></td>
                            <td><?php echo esc_html((string) $d['local']); ?></td>
                            <td><?php echo esc_html((string) $d['remote']); ?></td>
                            <td><?php if (!str_starts_with((string) $d['field'], '__')): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('bg_lgl_diff_' . $differenceId . '_local_to_remote'); ?>
                                    <input type="hidden" name="action" value="bg_lgl_diff">
                                    <input type="hidden" name="difference_id" value="<?php echo $differenceId; ?>">
                                    <input type="hidden" name="fingerprint" value="<?php echo esc_attr($fingerprint); ?>">
                                    <input type="hidden" name="dir" value="local_to_remote">
                                    <button class="button button-small" type="submit">BosqueGrande &rarr; LGL</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('bg_lgl_diff_' . $differenceId . '_remote_to_local'); ?>
                                    <input type="hidden" name="action" value="bg_lgl_diff">
                                    <input type="hidden" name="difference_id" value="<?php echo $differenceId; ?>">
                                    <input type="hidden" name="fingerprint" value="<?php echo esc_attr($fingerprint); ?>">
                                    <input type="hidden" name="dir" value="remote_to_local">
                                    <button class="button button-small" type="submit">
                                        <?php echo (string) $d['field'] === '__create__' ? 'Import record' : 'LGL &rarr; BosqueGrande'; ?>
                                    </button>
                                </form></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Discard every staged difference without applying?');">
                    <?php wp_nonce_field('bg_lgl_diff_clear'); ?>
                    <input type="hidden" name="action" value="bg_lgl_diff_clear">
                    <p><button class="button" type="submit">Discard all</button></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Maintenance</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;max-width:760px">
            <?php wp_nonce_field('bg_refresh_caches'); ?>
            <input type="hidden" name="action" value="bg_refresh_caches">
            <button class="button">Refresh caches</button>
            <span class="description">If events show old photos or summaries after an import, or Register
            buttons lead nowhere, a host cache is serving stale data; this clears it and rebuilds permalinks.</span>
        </form>

        <h2>Also connected</h2>
        <p style="max-width:760px"><strong>Eventbrite</strong> events come into BosqueGrande through
        <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-exchange')); ?>">Data Transfer</a>,
        on demand or on a schedule. From a saved event, an administrator can also create or update its
        Eventbrite copy and bring that event's Eventbrite registrations in;
        <strong>Stripe</strong> is configured under
        <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-payments')); ?>">Payment Setup</a>;
        <strong>QuickBooks</strong> exports from the
        <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-revenue')); ?>"><?php echo esc_html(\BosqueGrande\Modules\Revenue\RevenueModule::noun()); ?></a> screen.
        </p>

        <h2>Danger zone</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:760px">
            <?php wp_nonce_field('bg_uninstall_opt'); ?>
            <input type="hidden" name="action" value="bg_uninstall_opt">
            <label><input type="checkbox" name="wipe" value="1" <?php checked((int) get_option('bg_delete_on_uninstall', 0), 1); ?>>
            Delete all BosqueGrande data if the plugin is ever uninstalled: contacts, registrations, payments, settings, events, newsletters, and tags.</label>
            <p class="description">Off by default: uninstalling normally leaves all data in place, and
            reinstalling picks it right back up. Turn this on only when decommissioning for good.
            The keyring file is kept, so a database backup stays readable.</p>
            <p><button class="button">Save</button></p>
        </form>
        </div>
        <?php
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode($notice), admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    private function handleLglPosture(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_lgl_posture');
        $posture = (string) ($_POST['posture'] ?? 'off');
        update_option('bg_lgl_posture', in_array($posture, LglSync::POSTURES, true) ? $posture : 'off', false);
        $this->back('Posture saved.');
    }

    private function handleLglRun(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_lgl_run');
        $posture = (string) get_option('bg_lgl_posture', 'off');
        if ($posture === 'off') $this->back('Sync is off; pick a posture first.');
        /** @var LglSync $sync */
        $sync = $container->get('connectors.lglsync');
        $runId = $sync->openRun($posture, 'manual');
        if ($runId < 1) {
            $this->back('The sync could not open its audit record, so nothing was scheduled. Check the BosqueGrande log and database health.');
        }
        $scheduled = $container->get('jobs')->enqueue(
            self::JOB,
            ['op' => $posture, 'offset' => 0, 'run' => $runId, 'totals' => []],
            2
        );
        if (!$scheduled) $sync->closeRun($runId, ['errors' => 1]);
        $this->back($scheduled
            ? 'Sync started; refresh in a minute for the results.'
            : 'The run was opened, but WordPress could not schedule it. Check Site Health and WP-Cron.');
    }

    private function handleLglPullAll(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_lgl_pull_all');
        /** @var LglClient $lgl */
        $lgl = $container->get('lgl');
        if (!$lgl->hasKey()) {
            $this->back('LGL is not connected. Add the LGL API key under Settings, then run the pull again.');
        }
        /** @var LglSync $sync */
        $sync = $container->get('connectors.lglsync');
        $runId = $sync->openRun('pull', 'manual_pull');
        if ($runId < 1) {
            $this->back('The full LGL pull could not open its audit record, so nothing was scheduled. Check the BosqueGrande log and database health.');
        }
        $scheduled = $container->get('jobs')->enqueue(
            self::JOB,
            ['op' => 'pull', 'offset' => 0, 'run' => $runId, 'totals' => []],
            2
        );
        if (!$scheduled) $sync->closeRun($runId, ['errors' => 1]);
        $this->back($scheduled
            ? 'Full LGL pull started. It will process every constituent in the background; refresh this page for the final counts.'
            : 'The full LGL pull could not be scheduled. Check Site Health and WP-Cron.');
    }

    private function handleLglPushSelected(Container $container): void
    {
        $this->handleLglSelected($container, 'push');
    }

    private function handleLglPullSelected(Container $container): void
    {
        $this->handleLglSelected($container, 'pull');
    }

    private function handleLglSelected(Container $container, string $direction): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_lgl_selected', '_bg_lgl_selected_nonce');
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $returnId = count($ids) === 1 ? $ids[0] : 0;
        if (!$ids) $this->backToConstituents('Select at least one constituent first.', $returnId);
        /** @var LglClient $lgl */
        $lgl = $container->get('lgl');
        if (!$lgl->hasKey()) $this->backToConstituents('LGL is not connected. Add the API key under Settings first.', $returnId);
        /** @var LglSync $sync */
        $sync = $container->get('connectors.lglsync');
        $runId = $sync->openRun($direction, 'selected_' . $direction);
        if ($runId < 1) $this->backToConstituents('The selected sync could not open its audit record, so nothing was scheduled.', $returnId);
        $scheduled = $container->get('jobs')->enqueue(self::JOB, [
            'op' => $direction . '_selected',
            'ids' => $ids,
            'offset' => 0,
            'run' => $runId,
            'totals' => [],
        ], 2);
        if (!$scheduled) $sync->closeRun($runId, ['errors' => 1]);
        $this->backToConstituents($scheduled
            ? sprintf('%s selected sync started for %d local record(s). Refresh shortly, then check the record timeline or Connectors run totals.', strtoupper($direction), count($ids))
            : 'WordPress could not schedule the selected sync. Check Site Health and WP-Cron.', $returnId);
    }

    private function backToConstituents(string $notice, int $id = 0): void
    {
        $url = admin_url('admin.php?page=bosquegrande-constituents');
        if ($id > 0) $url .= '&view=edit&id=' . $id;
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode($notice), $url));
        exit;
    }

    private function handleLglDiff(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $differenceId = (int) ($_POST['difference_id'] ?? 0);
        $dir = sanitize_key((string) ($_POST['dir'] ?? ''));
        if (!in_array($dir, ['local_to_remote', 'remote_to_local'], true)) wp_die('Unrecognized direction.');
        check_admin_referer('bg_lgl_diff_' . $differenceId . '_' . $dir);
        $claimOwner = $this->claimLglReviewAction();
        if ($claimOwner === '') $this->back('Not applied; another reconciliation action is already running.');
        try {
            $ok = $container->get('connectors.lglsync')->applyDiff(
                $differenceId,
                $dir,
                sanitize_text_field((string) ($_POST['fingerprint'] ?? ''))
            );
        } finally {
            OptionLease::release('bg_lgl_review_action_claim', $claimOwner);
        }
        $this->back($ok ? 'Applied.' : 'Not applied; the difference may already be resolved.');
    }

    private function handleLglDiffClear(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_lgl_diff_clear');
        $claimOwner = $this->claimLglReviewAction();
        if ($claimOwner === '') $this->back('Nothing was discarded; another reconciliation action is already running.');
        try {
            $cleared = $container->get('connectors.lglsync')->clearDiffs();
        } finally {
            OptionLease::release('bg_lgl_review_action_claim', $claimOwner);
        }
        $this->back($cleared
            ? 'Staged differences discarded.'
            : 'The staged differences could not be discarded. Check the database and try again.');
    }

    private function claimLglReviewAction(): string
    {
        return OptionLease::acquire('bg_lgl_review_action_claim', 10 * MINUTE_IN_SECONDS);
    }

    private function handleUninstallOpt(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_uninstall_opt');
        update_option('bg_delete_on_uninstall', empty($_POST['wipe']) ? 0 : 1, false);
        $this->back(empty($_POST['wipe'])
            ? 'Uninstall will keep all data (the default).'
            : 'Uninstall will now DELETE all BosqueGrande data.');
    }

    private function handleRefreshCaches(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_refresh_caches');
        $ids = get_posts([
            'post_type' => 'bg_event',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        foreach ($ids as $id) clean_post_cache((int) $id);
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        flush_rewrite_rules(false);
        $container->get('logger')->info('connectors', 'Event, object, and rewrite caches refreshed.', [
            'events' => count($ids),
        ]);
        $this->back('Caches refreshed.');
    }
}
