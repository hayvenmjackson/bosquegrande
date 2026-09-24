<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Coordinates imports, exports, staging, mapping, and the transfer ledger.
 * Every run stages verbatim payloads before mapping. Dry runs use the same
 * pipeline with destination writes suppressed.
 */
final class ExchangeModule implements ModuleInterface
{
    private const FIXTURE_DIR = 'bg-fixtures';
    private const CRON_HOOK = 'bg_exchange_cron';

    public function name(): string
    {
        return 'exchange';
    }

    public function label(): string
    {
        return 'Data Transfer';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->set('exchange.staging', fn() => new StagingStore());
        $container->set('exchange.ledger', fn() => new TransferLedger());
        $container->set('exchange.eventbrite.publisher', fn(Container $c) => new EventbritePublisher(
            $c->get('settings'),
            $c->get('logger'),
        ));
        // These dependencies are registered by later modules; the lazy
        // factory is resolved only when an administrator runs the action.
        $container->set('exchange.eventbrite.registrations', fn(Container $c) => new EventbriteRegistrationImporter(
            $c->get('settings'),
            $c->get('encryption'),
            $c->get('constituents.store'),
            $c->get('registration.repo'),
            $c->get('imports.store'),
            $c->get('logger'),
        ));
        $container->set('exchange.runner', fn(Container $c) => new TransferRunner(
            $c->get('exchange.staging'),
            $c->get('exchange.ledger'),
            new Mapper($c->get('logger')),
            new LglAdapter($c->get('lgl'), $c->get('settings'), $c->get('exchange.staging'), $c->get('logger')),
            new EventbriteAdapter($c->get('settings'), $c->get('exchange.staging'), $c->get('logger')),
            $c->get('logger'),
            $c->get('encryption'),
        ));

        add_action(self::CRON_HOOK, function () use ($container): void {
            $runner = $container->get('exchange.runner');
            $runner->run('live', 'scheduled', $this->enabledSources($container->get('settings')));
            $container->get('exchange.staging')->prune(90);
        });
        add_action('init', function () use ($container): void {
            $this->reconcileSchedule($container->get('settings'));
        }, 20);
        add_action('untrashed_post', function (int $postId) use ($container): void {
            if (get_post_type($postId) !== 'bg_event') return;
            (new Deduper($container->get('logger')))->restore($postId);
        });

        if (!is_admin()) return;
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Data Transfer', 'Data Transfer',
                'manage_options', 'bosquegrande-exchange', [$this, 'renderPage']);
        }, 42);
        add_action('admin_post_bg_capture', function () use ($container): void {
            $this->runCapture($container);
        });
        add_action('admin_post_bg_capture_dl', [$this, 'download']);
        add_action('admin_post_bg_writeback_resolve', function () use ($container): void {
            $this->handleWritebackResolve($container);
        });
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Tags & Regions', 'Tags & Regions',
                'manage_options', 'bosquegrande-tagging', [$this, 'renderTaggingPage']);
        }, 43);
        add_action('admin_post_bg_tagging_save', function () use ($container): void {
            $this->handleTaggingSave($container);
        });
        add_action('admin_post_bg_tagging_apply', function () use ($container): void {
            $this->handleTaggingApply($container);
        });
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Duplicate Review', 'Duplicate Review',
                'manage_options', 'bosquegrande-dedup', [$this, 'renderDedupPage']);
        }, 44);
        add_action('admin_post_bg_dedup', function () use ($container): void {
            $this->handleDedup($container);
        });
        add_action('admin_post_bg_transfer', function () use ($container): void {
            $this->handleTransfer($container);
        });
        add_action('admin_post_bg_exchange_settings', function () use ($container): void {
            $this->handleSettings($container);
        });
        add_action('wp_ajax_bgev_eb_export_one', function () use ($container): void {
            $this->handleEventbriteExport($container);
        });
        add_action('wp_ajax_bgev_eb_sync_registrations', function () use ($container): void {
            $this->handleEventbriteRegistrationSync($container);
        });
    }

    public function deactivate(Container $container): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ── Scheduling ─────────────────────────────────────────────── */

    /** @return string[] */
    private function enabledSources(Settings $settings): array
    {
        $sources = [];
        if ((int) $settings->get('exchange_src_lgl', 1) === 1) $sources[] = 'lgl';
        if ((int) $settings->get('exchange_src_eventbrite', 0) === 1) $sources[] = 'eventbrite';
        return $sources;
    }

    private function reconcileSchedule(Settings $settings): void
    {
        $want = (string) $settings->get('exchange_schedule', 'daily');
        $scheduled = wp_next_scheduled(self::CRON_HOOK);
        if ($want === 'manual') {
            if ($scheduled) wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        if (!in_array($want, ['hourly', 'twicedaily', 'daily'], true)) $want = 'daily';
        $current = (string) get_option('bg_exchange_schedule_applied', '');
        if (!$scheduled) {
            wp_schedule_event(time() + 300, $want, self::CRON_HOOK);
        } elseif ($current !== $want) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time() + 300, $want, self::CRON_HOOK);
        }
        if ($current !== $want) update_option('bg_exchange_schedule_applied', $want, false);
    }

    /* ── Handlers ───────────────────────────────────────────────── */

    public function handleTransfer(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_transfer');
        $kind = sanitize_key((string) ($_POST['kind'] ?? 'dry'));
        $runner = $container->get('exchange.runner');

        if ($kind === 'rehearse') {
            $res = $runner->rehearse();
        } else {
            $sources = array_values(array_intersect(
                array_map('sanitize_key', (array) ($_POST['src'] ?? [])),
                ['lgl', 'eventbrite']
            ));
            if (!$sources) $sources = $this->enabledSources($container->get('settings'));
            $res = $runner->run($kind === 'live' ? 'live' : 'dry', 'manual', $sources);
        }

        $c = $res['counts'];
        $msg = sprintf(
            'Run #%d concluded: %d staged, %d created, %d updated, %d skipped, %d held for review, %d defects.',
            $res['run_id'], $c['staged'], $c['created'], $c['updated'], $c['skipped'], $c['held'], $c['errors']
        );
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-exchange', 'bg_notice' => rawurlencode($msg), 'run' => (int) $res['run_id']],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handleSettings(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_exchange_settings');
        /** @var Settings $settings */
        $settings = $container->get('settings');

        $sched = sanitize_key((string) ($_POST['exchange_schedule'] ?? 'daily'));
        $settings->set('exchange_schedule', in_array($sched, ['manual', 'hourly', 'twicedaily', 'daily'], true) ? $sched : 'daily');
        $settings->set('exchange_src_lgl', empty($_POST['exchange_src_lgl']) ? 0 : 1);
        $settings->set('exchange_src_eventbrite', empty($_POST['exchange_src_eventbrite']) ? 0 : 1);
        $mode = sanitize_key((string) ($_POST['exchange_lgl_selector_mode'] ?? 'code_eq'));
        $settings->set('exchange_lgl_selector_mode',
            in_array($mode, ['code_eq', 'code_prefix', 'campaign_eq', 'name_contains', 'all'], true) ? $mode : 'code_eq');
        $settings->set('exchange_lgl_selector_value', sanitize_text_field((string) ($_POST['exchange_lgl_selector_value'] ?? '')));
        $status = sanitize_key((string) ($_POST['exchange_eb_status'] ?? 'live'));
        $settings->set('exchange_eb_status', in_array($status, ['live', 'started', 'ended', 'completed', 'all'], true) ? $status : 'live');

        $this->reconcileSchedule($settings);
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-exchange', 'bg_notice' => rawurlencode('The transfer arrangements of record have been saved.')],
            admin_url('admin.php')
        ));
        exit;
    }

    /** Send the last-saved site event to Eventbrite as a draft or linked update. */
    public function handleEventbriteExport(Container $container): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_send_json_error(['error' => 'This action requires a POST request.'], 405);
        }
        $postId = max(0, (int) ($_POST['pid'] ?? 0));
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? '')));
        if ($postId < 1 || !wp_verify_nonce($nonce, 'bg_eventbrite_export_' . $postId)
            || !current_user_can('manage_options') || !current_user_can('edit_post', $postId)
        ) {
            wp_send_json_error(['error' => 'You do not have permission to send this event to Eventbrite.'], 403);
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event') {
            wp_send_json_error(['error' => 'The BosqueGrande event could not be found.'], 404);
        }

        /** @var EventbritePublisher $publisher */
        $publisher = $container->get('exchange.eventbrite.publisher');
        $result = $publisher->export($postId);
        if (is_wp_error($result)) {
            $errorData = $result->get_error_data();
            $status = is_array($errorData) ? (int) ($errorData['status'] ?? 400) : 400;
            $status = max(400, min(599, $status));
            wp_send_json_error(['error' => $result->get_error_message()], $status);
        }
        wp_send_json_success($result);
    }

    /** Pull attendee records for one exact Eventbrite-linked site event. */
    public function handleEventbriteRegistrationSync(Container $container): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_send_json_error(['error' => 'This action requires a POST request.'], 405);
        }
        $postId = max(0, (int) ($_POST['pid'] ?? 0));
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? '')));
        if ($postId < 1 || !wp_verify_nonce($nonce, 'bg_eventbrite_registrations_' . $postId)
            || !current_user_can('manage_options') || !current_user_can('edit_post', $postId)
        ) {
            wp_send_json_error(['error' => 'You do not have permission to transfer registrations for this event.'], 403);
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event') {
            wp_send_json_error(['error' => 'The BosqueGrande event could not be found.'], 404);
        }
        $dryRun = !empty($_POST['dry_run']);
        /** @var EventbriteRegistrationImporter $importer */
        $importer = $container->get('exchange.eventbrite.registrations');
        $result = $importer->syncEvent($postId, $dryRun);
        if (is_wp_error($result)) {
            $errorData = $result->get_error_data();
            $status = is_array($errorData) ? (int) ($errorData['status'] ?? 400) : 400;
            $status = max(400, min(599, $status));
            wp_send_json_error(['error' => $result->get_error_message()], $status);
        }
        // Counts are sufficient for the browser. Per-attendee evidence stays
        // encrypted in the database and is never returned in page markup.
        wp_send_json_success([
            'ok' => (bool) ($result['ok'] ?? false),
            'dry_run' => (bool) $result['dry_run'],
            'counts' => (array) $result['counts'],
            'note' => (string) $result['note'],
        ]);
    }

    /* ── Screen ─────────────────────────────────────────────────── */

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';

        $plugin = \BosqueGrande\Core\Plugin::instance();
        $container = $plugin->container();
        /** @var Settings $settings */
        $settings = $container->get('settings');
        /** @var TransferLedger $ledger */
        $ledger = $container->get('exchange.ledger');

        $sched = (string) $settings->get('exchange_schedule', 'daily');
        $selMode = (string) $settings->get('exchange_lgl_selector_mode', 'code_eq');
        $selValue = (string) $settings->get('exchange_lgl_selector_value', (string) $settings->get('lgl_event_code', ''));
        $ebStatus = (string) $settings->get('exchange_eb_status', 'live');
        $srcLgl = (int) $settings->get('exchange_src_lgl', 1) === 1;
        $srcEb = (int) $settings->get('exchange_src_eventbrite', 0) === 1;
        $files = $this->existingCaptures();
        $runs = $ledger->recent(15);
        $detail = isset($_GET['run']) ? $ledger->find((int) $_GET['run']) : null;
        ?>
        <div class="wrap">
            <h1>Data Transfer</h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <h2>Scheduled Data Transfer</h2>
            <p style="max-width:760px">BosqueGrande retrieves records from the selected services on this
            schedule, preserves the source data, and applies the configured field-ownership rules. A
            staff-entered value is never overwritten by a transferred value. Every scheduled, manual,
            and trial run is recorded in the Transfer Ledger with its results.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_exchange_settings'); ?>
                <input type="hidden" name="action" value="bg_exchange_settings">
                <table class="form-table">
                    <tr><th>Schedule</th><td>
                        <select name="exchange_schedule">
                            <?php foreach (['manual' => 'Manual only (no schedule)', 'hourly' => 'Every hour', 'twicedaily' => 'Twice daily', 'daily' => 'Once daily'] as $k => $l): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($sched, $k); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Services</th><td>
                        <label><input type="checkbox" name="exchange_src_lgl" value="1" <?php checked($srcLgl); ?>> Little Green Light</label><br>
                        <label><input type="checkbox" name="exchange_src_eventbrite" value="1" <?php checked($srcEb); ?>> Eventbrite</label>
                    </td></tr>
                    <tr><th>LGL record selection</th><td>
                        <select name="exchange_lgl_selector_mode">
                            <?php foreach (['code_eq' => 'Event code equals', 'code_prefix' => 'Event code begins with', 'campaign_eq' => 'Campaign equals', 'name_contains' => 'Name contains', 'all' => 'Every event (explicit election)'] as $k => $l): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($selMode, $k); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="exchange_lgl_selector_value" value="<?php echo esc_attr($selValue); ?>" class="regular-text">
                        <p class="description">Only Little Green Light events matching this selection are transferred.</p>
                    </td></tr>
                    <tr><th>Eventbrite status</th><td>
                        <select name="exchange_eb_status">
                            <?php foreach (['live', 'started', 'ended', 'completed', 'all'] as $k): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($ebStatus, $k); ?>><?php echo esc_html(ucfirst($k)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                </table>
                <p><button class="button button-primary">Save the Transfer Arrangements</button></p>
            </form>

            <h2>Run a Transfer Now</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em">
                <?php wp_nonce_field('bg_transfer'); ?>
                <input type="hidden" name="action" value="bg_transfer">
                <p>
                    <label><input type="checkbox" name="src[]" value="lgl" <?php checked($srcLgl); ?>> Little Green Light</label>
                    &nbsp;&nbsp;
                    <label><input type="checkbox" name="src[]" value="eventbrite" <?php checked($srcEb); ?>> Eventbrite</label>
                </p>
                <p>
                    <button class="button button-primary" name="kind" value="live">Run the Transfer</button>
                    <button class="button" name="kind" value="dry">Trial Run (nothing is altered)</button>
                    <button class="button" name="kind" value="rehearse">Trial Run from Captured Records</button>
                </p>
                <p class="description">A trial run performs the complete procedure and reports the
                results without altering any records. The captured-records trial reads files preserved
                by Record Capture below instead of contacting the services.</p>
            </form>

            <?php if ($detail): ?>
                <h2>Run #<?php echo (int) $detail->id; ?> Results</h2>
                <p><?php echo esc_html(sprintf(
                    '%s run, %s, commenced %s UTC. %d staged, %d created, %d updated, %d skipped, %d held for review, %d defects.',
                    ucfirst((string) $detail->mode), (string) $detail->trig, (string) $detail->started_at,
                    (int) $detail->staged, (int) $detail->created, (int) $detail->updated,
                    (int) $detail->skipped, (int) $detail->held, (int) $detail->errors
                )); ?></p>
                <?php $report = json_decode((string) $detail->report, true); ?>
                <?php if (is_array($report) && $report): ?>
                    <table class="widefat striped" style="max-width:1000px">
                        <thead><tr><th>Source</th><th>Record</th><th>Result</th><th>Post</th><th>Details</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($report, 0, 200) as $r): if (!is_array($r)) continue; ?>
                            <tr>
                                <td><?php echo esc_html((string) ($r['source'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($r['external_id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($r['action'] ?? '')); ?></td>
                                <td><?php echo !empty($r['post_id']) ? '<a href="' . esc_url(get_edit_post_link((int) $r['post_id'])) . '">#' . (int) $r['post_id'] . '</a>' : ''; ?></td>
                                <td><code style="font-size:11px"><?php
                                    $extra = array_diff_key($r, array_flip(['source', 'external_id', 'action', 'post_id', 'sha256']));
                                    echo esc_html(bg_substr((string) wp_json_encode($extra), 0, 220));
                                ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Transfer Ledger</h2>
            <?php if ($runs): ?>
                <table class="widefat striped" style="max-width:1000px">
                    <thead><tr><th>Run</th><th>Kind</th><th>Origin</th><th>Commenced (UTC)</th><th>Staged</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Held</th><th>Defects</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <td>#<?php echo (int) $r->id; ?></td>
                            <td><?php echo esc_html((string) $r->mode); ?></td>
                            <td><?php echo esc_html((string) $r->trig); ?></td>
                            <td><?php echo esc_html((string) $r->started_at); ?></td>
                            <td><?php echo (int) $r->staged; ?></td>
                            <td><?php echo (int) $r->created; ?></td>
                            <td><?php echo (int) $r->updated; ?></td>
                            <td><?php echo (int) $r->skipped; ?></td>
                            <td><?php echo (int) $r->held; ?></td>
                            <td><?php echo (int) $r->errors; ?></td>
                            <td><a href="<?php echo esc_url(add_query_arg(['page' => 'bosquegrande-exchange', 'run' => (int) $r->id], admin_url('admin.php'))); ?>">View results</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No transfer runs have been recorded.</p>
            <?php endif; ?>

            <h2>Amendments Held for Review</h2>
            <?php $wbQueue = get_option('bg_writeback_queue', []); $wbQueue = is_array($wbQueue) ? $wbQueue : []; ?>
            <?php if ($wbQueue): ?>
                <p style="max-width:760px">These updates came from returning registrants while changes
                were configured for staff review. Apply an update to add it to the constituent record,
                or discard it without changing the record. Either action is written to the Activity Log.</p>
                <table class="widefat striped" style="max-width:960px">
                    <thead><tr><th>#</th><th>Constituent</th><th>Fields updated</th><th>Received (UTC)</th><th>Disposition</th></tr></thead>
                    <tbody>
                    <?php foreach ($wbQueue as $idx => $entry): if (!is_array($entry)) continue;
                        $entryData = $this->writebackData($container, $entry);
                        $fingerprint = $this->writebackFingerprint($entry); ?>
                        <tr>
                            <td><?php echo (int) $idx + 1; ?></td>
                            <td><?php echo (int) ($entry['constituent_id'] ?? 0); ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html(implode(', ', array_keys(array_filter($entryData, fn($v) => $v !== '' && $v !== [])))); ?></code></td>
                            <td><?php echo esc_html((string) ($entry['queued_at'] ?? '')); ?></td>
                            <td>
                                <?php foreach (['apply' => 'Apply', 'discard' => 'Discard'] as $operation => $label): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('bg_writeback_resolve_' . $operation . '_' . $fingerprint); ?>
                                        <input type="hidden" name="action" value="bg_writeback_resolve">
                                        <input type="hidden" name="do" value="<?php echo esc_attr($operation); ?>">
                                        <input type="hidden" name="fingerprint" value="<?php echo esc_attr($fingerprint); ?>">
                                        <button class="button button-small" type="submit"><?php echo esc_html($label); ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No amendments are presently held for review.</p>
            <?php endif; ?>

            <h2>Records Held for Review</h2>
            <?php $held = get_posts([
                'post_type' => 'bg_event', 'post_status' => 'draft', 'numberposts' => 30,
                'meta_query' => [['key' => '_bg_src_key', 'compare' => 'EXISTS']],
            ]); ?>
            <?php if ($held): ?>
                <p style="max-width:760px">These transferred events did not include a usable date and
                remain drafts until staff review them. Add the date on the event editing screen, then
                publish the event. A staff-entered date will not be overwritten by a later transfer.</p>
                <table class="widefat striped" style="max-width:860px">
                    <thead><tr><th>Entry</th><th>Source of record</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($held as $hp): ?>
                        <tr>
                            <td><?php echo esc_html($hp->post_title); ?></td>
                            <td><code><?php echo esc_html((string) get_post_meta($hp->ID, '_bg_src_key', true)); ?></code></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($hp->ID)); ?>">Review event</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No entries are presently held for review.</p>
            <?php endif; ?>

            <h2>Record Capture</h2>
            <p style="max-width:760px">Record Capture retrieves the current source records and preserves
            timestamped copies on this site for verification and reference. It is read-only: capturing
            records does not alter BosqueGrande or either connected service.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_capture'); ?>
                <input type="hidden" name="action" value="bg_capture">
                <p>
                    <label><input type="checkbox" name="src[]" value="eventbrite" checked> Eventbrite (event list, first records in detail, & structured content)</label><br>
                    <label><input type="checkbox" name="src[]" value="lgl" checked> Little Green Light (event list & first records in detail)</label>
                </p>
                <p><button class="button">Capture the Records</button></p>
            </form>

            <?php if ($files): ?>
                <h3>Captured records on file</h3>
                <table class="widefat striped" style="max-width:860px">
                    <thead><tr><th>File</th><th>Size</th><th>Captured</th></tr></thead>
                    <tbody>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td><a href="<?php echo esc_url($f['url']); ?>"><?php echo esc_html($f['name']); ?></a></td>
                            <td><?php echo esc_html(size_format($f['size'])); ?></td>
                            <td><?php echo esc_html($f['date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function runCapture(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_capture');

        /** @var Settings $settings */
        $settings = $container->get('settings');
        /** @var Logger $logger */
        $logger = $container->get('logger');

        $sources = array_map('sanitize_key', (array) ($_POST['src'] ?? []));
        /** @var \BosqueGrande\Core\Encryption $encryption */
        $encryption = $container->get('encryption');
        $dir = $this->fixtureDir();
        $stamp = gmdate('Ymd-His');
        $written = 0;
        $notes = [];
        if (!$encryption->available()) {
            $notes[] = 'Capture refused: encryption is unavailable, so raw service records were not written to disk.';
            $sources = [];
        }

        if (in_array('eventbrite', $sources, true)) {
            $token = (string) $settings->get('eventbrite_token', '');
            $org   = (string) $settings->get('eventbrite_org_id', '');
            if ($token === '' || $org === '') {
                $notes[] = 'Eventbrite: token or organization id not on file (see Settings).';
            } else {
                $base = 'https://www.eventbriteapi.com/v3';
                $auth = ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 30];
                $ids = [];
                for ($page = 1; $page <= 3; $page++) {
                    $url = "$base/organizations/$org/events/?status=all&order_by=start_desc&page=$page&expand=venue,ticket_availability,ticket_classes";
                    $body = $this->fetch($url, $auth, $logger);
                    if ($body === null) { $notes[] = "Eventbrite: list page $page failed."; break; }
                    $written += $this->write($dir, "eventbrite-list-p$page-$stamp.bgcap", $body, $encryption);
                    $decoded = json_decode($body, true);
                    foreach (array_slice((array) ($decoded['events'] ?? []), 0, 5) as $e) {
                        if (!empty($e['id'])) $ids[] = (string) $e['id'];
                    }
                    if (empty($decoded['pagination']['has_more_items'])) break;
                }
                foreach (array_slice(array_unique($ids), 0, 5) as $id) {
                    foreach (["events/$id/?expand=logo,venue,ticket_availability,ticket_classes" => "eventbrite-event-$id",
                              "events/$id/structured_content/" => "eventbrite-content-$id"] as $path => $name) {
                        $body = $this->fetch("$base/$path", $auth, $logger);
                        if ($body !== null) $written += $this->write($dir, "$name-$stamp.bgcap", $body, $encryption);
                    }
                }
            }
        }

        if (in_array('lgl', $sources, true)) {
            $key = $this->lglKey($settings);
            if ($key === '') {
                $notes[] = 'LGL: key not on file (see Settings).';
            } else {
                $base = 'https://api.littlegreenlight.com/api/v1';
                $auth = ['headers' => ['Authorization' => 'Bearer ' . $key], 'timeout' => 30];
                $body = $this->fetch("$base/events?limit=100", $auth, $logger);
                $ids = [];
                if ($body === null) {
                    $notes[] = 'LGL: event list failed.';
                } else {
                    $written += $this->write($dir, "lgl-events-$stamp.bgcap", $body, $encryption);
                    $decoded = json_decode($body, true);
                    foreach (array_slice((array) ($decoded['items'] ?? $decoded['data'] ?? []), 0, 10) as $e) {
                        if (!empty($e['id'])) $ids[] = (string) $e['id'];
                    }
                }
                foreach ($ids as $id) {
                    $body = $this->fetch("$base/events/$id", $auth, $logger);
                    if ($body !== null) $written += $this->write($dir, "lgl-event-$id-$stamp.bgcap", $body, $encryption);
                }
            }
        }

        $logger->info('exchange', 'Record capture completed.', ['files' => $written, 'notes' => $notes]);
        $msg = sprintf('Capture concluded: %d file(s) preserved.', $written);
        if ($notes) $msg .= ' ' . implode(' ', $notes);
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-exchange', 'bg_notice' => rawurlencode($msg)],
            admin_url('admin.php')
        ));
        exit;
    }


    public function download(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_capture_dl');
        $name = basename((string) rawurldecode((string) ($_GET['f'] ?? '')));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+\.bgcap$/D', $name)) wp_die('Unrecognized file.');
        $up = wp_get_upload_dir();
        $path = trailingslashit($up['basedir']) . self::FIXTURE_DIR . '/' . $name;
        if (!is_file($path)) wp_die('File not on record.');
        $body = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')
            ->decrypt((string) file_get_contents($path), 'capture:' . $name);
        if ($body === '') wp_die('The capture could not be decrypted with the current site key.');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . substr($name, 0, -6) . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) strlen($body));
        echo $body;
        exit;
    }


    private function fetch(string $url, array $args, Logger $logger): ?string
    {
        $r = wp_remote_get($url, $args);
        if (is_wp_error($r)) {
            $logger->warn('exchange', 'Capture request failed.', ['url' => $url, 'error' => $r->get_error_message()]);
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code < 200 || $code >= 300) {
            $logger->warn('exchange', 'Capture request refused.', ['url' => $url, 'code' => $code]);
            return null;
        }
        return (string) wp_remote_retrieve_body($r);
    }


    private function write(
        string $dir,
        string $name,
        string $body,
        \BosqueGrande\Core\Encryption $encryption
    ): int
    {
        $context = 'capture:' . $name;
        $ciphertext = $encryption->encrypt($body, $context);
        if ($ciphertext === '' || $ciphertext === $body) return 0;
        $ok = $this->writeEncryptedFile(
            trailingslashit($dir) . $name,
            $ciphertext,
            $body,
            $context,
            $encryption
        );
        return $ok ? 1 : 0;
    }


    private function fixtureDir(): string
    {
        $up = wp_get_upload_dir();
        $dir = trailingslashit($up['basedir']) . self::FIXTURE_DIR;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        // Raw service payloads are not for public browsing.
        $ht = trailingslashit($dir) . '.htaccess';
        if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
        $ix = trailingslashit($dir) . 'index.html';
        if (!file_exists($ix)) file_put_contents($ix, '');
        return $dir;
    }


    private function writeEncryptedFile(
        string $path,
        string $ciphertext,
        string $plaintext,
        string $context,
        \BosqueGrande\Core\Encryption $encryption
    ): bool {
        try {
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return false;
        }
        if (file_put_contents($tmp, $ciphertext, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        $written = file_get_contents($tmp);
        $verified = is_string($written)
            && hash_equals($ciphertext, $written)
            && $encryption->decryptOrNull($written, $context) === $plaintext;
        if (!$verified || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }


    private function existingCaptures(): array
    {
        $up = wp_get_upload_dir();
        $dir = trailingslashit($up['basedir']) . self::FIXTURE_DIR;
        if (!is_dir($dir)) return [];
        $out = [];
        $paths = glob(trailingslashit($dir) . '*.bgcap') ?: [];
        foreach ($paths as $path) {
            $name = basename($path);
            $out[] = [
                'name' => $name,
                'url'  => wp_nonce_url(
                    add_query_arg(['action' => 'bg_capture_dl', 'f' => rawurlencode($name)], admin_url('admin-post.php')),
                    'bg_capture_dl'
                ),
                'size' => (int) filesize($path),
                'date' => gmdate('Y-m-d H:i', (int) filemtime($path)) . ' UTC',
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return array_slice($out, 0, 60);
    }


    public function renderDedupPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $pairs = get_transient('bg_dedup_pairs');
        ?>
        <div class="wrap">
            <h1>Duplicate Review</h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p style="max-width:780px">The survey below compares events sharing the same date by title
            similarity (the same edit-distance method Little Green Light’s duplicate finder employs).
            A recurring event on many dates is never flagged. For each candidate pair, elect the copy to
            keep: its twin’s registrations &amp; source linkage transplant to the kept copy, so future
            transfers maintain the survivor rather than re-creating the duplicate, &amp; the retired copy
            goes to the trash, reversibly. Pairs marked Not Duplicates are remembered &amp; never shown
            again. Where registrations differ, keeping the copy that holds them is almost always right.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_dedup'); ?>
                <input type="hidden" name="action" value="bg_dedup">
                <p><button class="button button-primary" name="do" value="survey">Survey for Duplicates</button></p>
            </form>
            <?php if (is_array($pairs)): ?>
                <?php if (!$pairs): ?><p>No candidate pairs. The survey found nothing requiring review.</p><?php endif; ?>
                <?php foreach ($pairs as $i => $pr): $a = $pr['a']; $b = $pr['b']; ?>
                    <div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin:10px 0;max-width:960px;background:#fff">
                        <p style="margin:0 0 8px"><strong><?php echo esc_html(sprintf('%d%% similar', round($pr['similarity'] * 100))); ?></strong>
                            &middot; <?php echo esc_html($pr['date'] !== '' ? $pr['date'] : 'no date of record'); ?></p>
                        <table class="widefat striped">
                            <thead><tr><th></th><th>Copy A (#<?php echo (int) $a['id']; ?>)</th><th>Copy B (#<?php echo (int) $b['id']; ?>)</th></tr></thead>
                            <tbody>
                                <tr><td>Title</td><td><?php echo esc_html($a['title']); ?></td><td><?php echo esc_html($b['title']); ?></td></tr>
                                <tr><td>Location &amp; summary</td><td><?php echo esc_html(mb_strimwidth($a['location'], 0, 120, '...')); ?></td><td><?php echo esc_html(mb_strimwidth($b['location'], 0, 120, '...')); ?></td></tr>
                                <tr><td>Source of record</td><td><code><?php echo esc_html($a['source']); ?></code></td><td><code><?php echo esc_html($b['source']); ?></code></td></tr>
                                <tr><td>Registrations</td><td><?php echo (int) $a['registrations']; ?></td><td><?php echo (int) $b['registrations']; ?></td></tr>
                                <tr><td>Status</td><td><?php echo esc_html($a['status']); ?></td><td><?php echo esc_html($b['status']); ?></td></tr>
                            </tbody>
                        </table>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                            <?php wp_nonce_field('bg_dedup'); ?>
                            <input type="hidden" name="action" value="bg_dedup">
                            <input type="hidden" name="a" value="<?php echo (int) $a['id']; ?>">
                            <input type="hidden" name="b" value="<?php echo (int) $b['id']; ?>">
                            <button class="button" name="do" value="keep_a">Keep A (retire B)</button>
                            <button class="button" name="do" value="keep_b">Keep B (retire A)</button>
                            <button class="button" name="do" value="dismiss">Not Duplicates</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleDedup(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_dedup');
        $deduper = new Deduper($container->get('logger'));
        $do = sanitize_key((string) ($_POST['do'] ?? 'survey'));
        $a = (int) ($_POST['a'] ?? 0);
        $b = (int) ($_POST['b'] ?? 0);
        $msg = '';

        if ($do === 'survey') {
            $pairs = $deduper->scan();
            set_transient('bg_dedup_pairs', $pairs, 30 * MINUTE_IN_SECONDS);
            $msg = sprintf('Survey concluded: %d candidate pair(s) need review.', count($pairs));
        } elseif (in_array($do, ['keep_a', 'keep_b'], true) && $a && $b) {
            [$keep, $lose] = $do === 'keep_a' ? [$a, $b] : [$b, $a];
            $res = $deduper->merge($keep, $lose);
            if (!$res['ok']) {
                $msg = 'The merge could not be completed; both event records remain available.';
            } else {
                $msg = sprintf('Kept #%d; retired #%d to the trash. %d registration(s) transplanted%s. Restoring the retired event reverses the transplant.',
                    $keep, $lose, $res['registrations_moved'], $res['linkage_moved'] ? ', source linkage carried over' : '');
                $this->dropPairFromTransient($a, $b);
            }
        } elseif ($do === 'dismiss' && $a && $b) {
            $deduper->dismiss($a, $b);
            $msg = 'Noted as not duplicates; this pair will not be shown again.';
            $this->dropPairFromTransient($a, $b);
        }
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-dedup', 'bg_notice' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    private function dropPairFromTransient(int $a, int $b): void
    {
        $pairs = get_transient('bg_dedup_pairs');
        if (!is_array($pairs)) return;
        $key = min($a, $b) . ':' . max($a, $b);
        $pairs = array_values(array_filter($pairs, fn($p) => ($p['key'] ?? '') !== $key));
        set_transient('bg_dedup_pairs', $pairs, 30 * MINUTE_IN_SECONDS);
    }

    public function renderTaggingPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $rules = Tagger::rules();
        $toLines = fn(array $m): string => implode("\n", array_map(fn($k, $v) => "$k = $v", array_keys($m), $m));
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $trial = get_transient('bg_tagging_trial');
        ?>
        <div class="wrap">
            <h1>Tags &amp; Regions</h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p style="max-width:780px">Define your event vocabulary first: regions describe where events
            happen, interests describe what they are. The calendar filters use these lists. The rules below
            then tag imported events automatically, in order: an event-code prefix first, then a place name
            found in the event, then keywords. When the rules leave something unresolved, the optional
            assistant may choose only from your vocabulary. Every automatic tag records why it was applied,
            and tags added by staff are never removed.</p>
            <?php $regions = \BosqueGrande\Modules\GatheringTree\Vocabulary::regions();
            $interests = \BosqueGrande\Modules\GatheringTree\Vocabulary::interests(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_tagging_save'); ?>
                <input type="hidden" name="action" value="bg_tagging_save">
                <h2>Vocabulary</h2>
                <table class="form-table">
                    <tr><th>Regions<br><span style="font-weight:400;color:#787c82">One per line: <code>slug = Label</code>, or just a label. Leave empty if location filtering is not useful to you.</span></th>
                        <td><textarea name="vocab_regions" rows="6" class="large-text code"><?php echo esc_textarea(\BosqueGrande\Modules\GatheringTree\Vocabulary::format($regions)); ?></textarea></td></tr>
                    <tr><th>Interests<br><span style="font-weight:400;color:#787c82">One per line: <code>slug = Label</code>, or just a label.</span></th>
                        <td><textarea name="vocab_interests" rows="8" class="large-text code"><?php echo esc_textarea(\BosqueGrande\Modules\GatheringTree\Vocabulary::format($interests)); ?></textarea></td></tr>
                </table>
                <h2>Automatic tagging rules</h2>
                <p>Each line reads <code>term = tag-slug</code>. Lines naming a slug that is not in the vocabulary are ignored.</p>
                <table class="form-table">
                    <tr><th>Event code prefixes<br><span style="font-weight:400;color:#787c82">Highest priority. Example: <code>NORTH = north-county</code></span></th>
                        <td><textarea name="code_map" rows="5" class="large-text code"><?php echo esc_textarea($toLines($rules['code_map'])); ?></textarea></td></tr>
                    <tr><th>Places (place name = region)<br><span style="font-weight:400;color:#787c82">Two places from different regions in one event is a conflict and assigns nothing.</span></th>
                        <td><textarea name="gazetteer" rows="10" class="large-text code"><?php echo esc_textarea($toLines($rules['gazetteer'])); ?></textarea>
                        <p class="description">Regions: <?php echo esc_html($regions ? implode(', ', array_keys($regions)) : 'none defined'); ?></p></td></tr>
                    <tr><th>Postal codes (code or prefix = region)<br><span style="font-weight:400;color:#787c82">Used to place people by their address. The longest matching prefix wins, for example <code>012 = north-county</code>.</span></th>
                        <td><textarea name="postal_rules" rows="6" class="large-text code"><?php
                            $postalRules = get_option('bg_region_postal_rules', []);
                            echo esc_textarea($toLines(is_array($postalRules) ? $postalRules : []));
                        ?></textarea></td></tr>
                    <tr><th>Keywords (word or phrase = interest)</th>
                        <td><textarea name="keywords" rows="12" class="large-text code"><?php echo esc_textarea($toLines($rules['keywords'])); ?></textarea>
                        <p class="description">Interests: <?php echo esc_html($interests ? implode(', ', array_keys($interests)) : 'none defined'); ?></p></td></tr>
                </table>
                <p><button class="button button-primary">Save vocabulary and rules</button></p>
            </form>

            <h2>Apply the rules to untagged events</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_tagging_apply'); ?>
                <input type="hidden" name="action" value="bg_tagging_apply">
                <p>
                    <button class="button" name="kind" value="trial">Preview (changes nothing)</button>
                    <button class="button button-primary" name="kind" value="apply">Apply to untagged events</button>
                </p>
            </form>
            <?php if (is_array($trial) && $trial): ?>
                <h3>Preview</h3>
                <table class="widefat striped" style="max-width:960px">
                    <thead><tr><th>Event</th><th>Would assign</th><th>Evidence</th></tr></thead>
                    <tbody>
                    <?php foreach ($trial as $rowT): ?>
                        <tr>
                            <td><?php echo esc_html((string) $rowT['title']); ?></td>
                            <td><?php echo esc_html($rowT['tags'] ? implode(', ', $rowT['tags']) : '(nothing; left for review)'); ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html(implode('; ', $rowT['evidence'])); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleTaggingSave(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_tagging_save');
        $parse = function (string $raw, array $validSlugs): array {
            $out = [];
            foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '=') === false) continue;
                [$term, $slug] = array_map('trim', explode('=', $line, 2));
                $slug = sanitize_title($slug);
                if ($term !== '' && isset($validSlugs[$slug])) $out[strtolower($term)] = $slug;
            }
            return $out;
        };
        $vocabulary = \BosqueGrande\Modules\GatheringTree\Vocabulary::class;
        if (isset($_POST['vocab_regions']) || isset($_POST['vocab_interests'])) {
            $vocabulary::save(
                $vocabulary::parse(sanitize_textarea_field(wp_unslash((string) ($_POST['vocab_regions'] ?? '')))),
                $vocabulary::parse(sanitize_textarea_field(wp_unslash((string) ($_POST['vocab_interests'] ?? ''))))
            );
        }
        $regions = $vocabulary::regions();
        $interests = $vocabulary::interests();
        Tagger::saveRules([
            'code_map' => $parse(sanitize_textarea_field(wp_unslash((string) ($_POST['code_map'] ?? ''))), $regions + $interests),
            'gazetteer' => $parse(sanitize_textarea_field(wp_unslash((string) ($_POST['gazetteer'] ?? ''))), $regions),
            'keywords' => $parse(sanitize_textarea_field(wp_unslash((string) ($_POST['keywords'] ?? ''))), $interests),
        ]);
        $postal = [];
        foreach (preg_split('/[\r\n]+/', sanitize_textarea_field(wp_unslash((string) ($_POST['postal_rules'] ?? '')))) ?: [] as $line) {
            if (!str_contains($line, '=')) continue;
            [$code, $slug] = array_map('trim', explode('=', $line, 2));
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $code));
            $slug = sanitize_title($slug);
            if ($code !== '' && isset($regions[$slug])) $postal[$code] = $slug;
        }
        update_option('bg_region_postal_rules', $postal, false);
        if ($container->has('constituents.regions')) {
            $container->get('constituents.regions')->invalidateAutomatic();
        }
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-tagging', 'bg_notice' => rawurlencode('Vocabulary and tagging rules saved. Lines naming an unknown slug were ignored.')], admin_url('admin.php')));
        exit;
    }

    public function handleTaggingApply(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_tagging_apply');
        $dry = sanitize_key((string) ($_POST['kind'] ?? 'trial')) !== 'apply';
        $posts = get_posts([
            'post_type' => 'bg_event', 'post_status' => ['publish', 'draft'],
            'numberposts' => -1, 'fields' => 'ids',
        ]);
        $report = [];
        $tagged = 0; $kept = 0; $untagged = 0;
        foreach ($posts as $pid) {
            $res = Tagger::apply((int) $pid, Tagger::documentFor((int) $pid), $dry);
            if ($res['action'] === 'kept') { $kept++; continue; }
            if ($res['action'] === 'tagged') $tagged++;
            else $untagged++;
            if ($dry && count($report) < 100) {
                $report[] = ['title' => get_the_title($pid), 'tags' => $res['tags'], 'evidence' => array_values($res['evidence'])];
            }
        }
        if ($dry) set_transient('bg_tagging_trial', $report, 15 * MINUTE_IN_SECONDS);
        else delete_transient('bg_tagging_trial');
        $container->get('logger')->info('exchange', 'Tagging rules ' . ($dry ? 'trial' : 'application') . ' concluded.', [
            'tagged' => $tagged, 'untagged' => $untagged, 'already_tagged' => $kept,
        ]);
        $msg = sprintf('%s: %d would-be or newly categorized, %d left untagged for review, %d retained without automatic changes.',
            $dry ? 'Trial concluded' : 'Application concluded', $tagged, $untagged, $kept);
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-tagging', 'bg_notice' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public function handleWritebackResolve(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $do = sanitize_key((string) ($_POST['do'] ?? ''));
        $fingerprint = sanitize_text_field((string) ($_POST['fingerprint'] ?? ''));
        if (!in_array($do, ['apply', 'discard'], true) || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            wp_die('Unrecognized amendment action.');
        }
        check_admin_referer('bg_writeback_resolve_' . $do . '_' . $fingerprint);
        $claimOwner = $this->claimWritebackAction();
        if ($claimOwner === '') {
            $this->backToExchange('Nothing changed because another amendment action is already running.');
        }
        try {
        $queue = get_option('bg_writeback_queue', []);
        $queue = is_array($queue) ? $queue : [];
        $logger = $container->get('logger');
        $msg = 'The amendment could not be located; it may already have been resolved.';

        $matches = [];
        foreach ($queue as $candidateIndex => $candidate) {
            if (is_array($candidate) && hash_equals($fingerprint, $this->writebackFingerprint($candidate))) {
                $matches[] = $candidateIndex;
            }
        }
        // Fail closed on a stale target or indistinguishable duplicate rows.
        if (count($matches) === 1) {
            $idx = $matches[0];
            $entry = $queue[$idx];
            $data = $this->writebackData($container, $entry);
            $remove = false;
            if ($do === 'apply') {
                $applied = $data !== [] && apply_filters(
                    'bg_apply_writeback',
                    false,
                    (int) ($entry['constituent_id'] ?? 0),
                    $data
                );
                $logger->info('exchange', 'Held amendment applied by staff.', [
                    'constituent' => (int) ($entry['constituent_id'] ?? 0),
                    'fields' => array_keys(array_filter($data, fn($v) => $v !== '' && $v !== [])),
                    'applied' => (bool) $applied,
                ]);
                $msg = $applied ? 'The amendment has been applied to the master record.' : 'The amendment could not be applied; consult the Activity Log.';
                $remove = (bool) $applied;
            } else {
                $logger->info('exchange', 'Held amendment discarded by staff.', [
                    'constituent' => (int) ($entry['constituent_id'] ?? 0),
                ]);
                $msg = 'The amendment has been discarded without effect.';
                $remove = true;
            }
            if ($remove) {
                unset($queue[$idx]);
                if (!update_option('bg_writeback_queue', array_values($queue), false)) {
                    $logger->error('exchange', 'An amendment action completed but its review-queue row could not be removed.', [
                        'action' => $do,
                        'fingerprint' => $fingerprint,
                    ]);
                    $msg = $do === 'apply'
                        ? 'The amendment was applied, but the review queue could not be updated. Do not apply it again; consult the Activity Log.'
                        : 'The amendment was not removed from the review queue. Consult the Activity Log.';
                }
            }
        }
        } finally {
            OptionLease::release('bg_writeback_action_claim', $claimOwner);
        }
        $this->backToExchange($msg);
    }

    private function writebackFingerprint(array $entry): string
    {
        return hash('sha256', (string) wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function claimWritebackAction(): string
    {
        return OptionLease::acquire('bg_writeback_action_claim', 10 * MINUTE_IN_SECONDS);
    }

    private function backToExchange(string $message): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-exchange', 'bg_notice' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }

    private function lglKey(Settings $settings): string
    {
        return (string) $settings->get('lgl_api_key', '');
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function writebackData(Container $container, array $entry): array
    {
        $ciphertext = (string) ($entry['payload_enc'] ?? '');
        if ($ciphertext === '') return [];
        $plain = $container->get('encryption')->decrypt(
            $ciphertext,
            'writeback:' . max(0, (int) ($entry['constituent_id'] ?? 0))
        );
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

}
