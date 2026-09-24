<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\Intelligence\IntelligenceModule;

if (!defined('ABSPATH')) exit;

/** Administrator-only spreadsheet mapping and historical import workflow. */
final class ImportModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-import-studio';
    private const MAX_UPLOAD = 104857600; // 100 MiB.

    private ?Container $container = null;

    public function name(): string { return 'imports'; }
    public function label(): string { return 'Import Studio'; }
    public function activate(Container $container): void {}
    public function deactivate(Container $container): void {}

    public function boot(Container $container): void
    {
        $this->container = $container;
        $container->set('imports.store', fn(Container $c) => new ImportStore($c->get('encryption'), $c->get('logger')));
        $container->set('imports.reader', fn() => new SpreadsheetReader());
        $container->set('imports.runner', fn(Container $c) => new ImportRunner(
            $c, $c->get('imports.store'), $c->get('imports.reader')
        ));

        add_action('init', [$this, 'registerHistoricalStatus'], 5);
        add_filter('bg_ai_features', static function (array $features): array {
            $features['import_mapping'] = [
                'label' => 'Import field-name matching help',
                'description' => 'When exact and deterministic header matching cannot identify a required spreadsheet column, ask for a constrained suggestion. Only column headings are sent; person, event, order, demographic, and contact values never leave the site. Every suggested mapping still requires administrator confirmation.',
            ];
            return $features;
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Import Studio', 'Import Studio', 'manage_options', self::PAGE, [$this, 'render']);
        }, 48);
        add_action('admin_post_bg_import_upload', [$this, 'upload']);
        add_action('admin_post_bg_import_mapping', [$this, 'saveMapping']);
        add_action('admin_post_bg_import_stage', [$this, 'stage']);
        add_action('admin_post_bg_import_discard', [$this, 'discard']);
        add_action('wp_ajax_bg_import_commit_batch', [$this, 'commitAjax']);
        add_action('wp_ajax_bg_import_rollback_batch', [$this, 'rollbackAjax']);
    }

    public function registerHistoricalStatus(): void
    {
        register_post_status('bg_historical', [
            'label' => 'Historical',
            'public' => false,
            'internal' => false,
            'protected' => true,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Historical <span class="count">(%s)</span>', 'Historical <span class="count">(%s)</span>'),
        ]);
    }

    public function upload(): void
    {
        $this->authorize('bg_import_upload');
        $file = $_FILES['source_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->redirect(0, 'The spreadsheet upload did not complete.');
        }
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $type = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $size = max(0, (int) ($file['size'] ?? 0));
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!in_array($type, ['xlsx', 'csv', 'tsv'], true)) $this->redirect(0, 'Use an .xlsx, .csv, or .tsv file.');
        if ($size < 1 || $size > self::MAX_UPLOAD || !is_uploaded_file($tmp)) $this->redirect(0, 'The upload is empty, too large, or could not be verified.');

        $directory = \BosqueGrande\Core\PrivateStorage::directory('bg-private-imports');
        if (!wp_mkdir_p($directory)) $this->redirect(0, 'BosqueGrande could not create its private import directory.');
        $this->protectDirectory($directory);
        $path = $directory . '/' . wp_generate_uuid4() . '.' . $type;
        if (!move_uploaded_file($tmp, $path)) $this->redirect(0, 'BosqueGrande could not move the upload into private storage.');
        @chmod($path, 0600);

        try {
            $reader = $this->container()->get('imports.reader');
            $inventory = $reader->inventory($path, $type);
            $sheet = $this->preferredSheet((array) ($inventory['sheets'] ?? []));
            $headers = $reader->headers($path, $type, $sheet);
            if (!(array) ($headers['cells'] ?? [])) throw new \RuntimeException('No header row was found.');
            $matcher = new FieldMatcher();
            $settings = $this->container()->get('settings');
            $assistant = IntelligenceModule::featureEnabled($settings, 'import_mapping')
                ? $this->container()->get('ai') : null;
            $mapping = $matcher->suggest((array) $headers['cells'], $assistant);
            $store = $this->store();
            $jobId = $store->createJob($name, $type, hash_file('sha256', $path) ?: '', $path, $inventory, get_current_user_id());
            if ($jobId < 1 || !$store->setMapping($jobId, $sheet, $mapping)) {
                throw new \RuntimeException('The encrypted import job could not be created.');
            }
            $store->updateJob($jobId, ['status' => 'suggested']);
            $this->redirect($jobId, 'Spreadsheet uploaded. Review and confirm the mappings before the dry run.', false);
        } catch (\Throwable $e) {
            @unlink($path);
            $this->container()->get('logger')->warn('imports', 'Spreadsheet upload was rejected.', [
                'reason' => bg_substr($e->getMessage(), 0, 180),
            ]);
            $this->redirect(0, $e->getMessage());
        }
    }

    public function saveMapping(): void
    {
        $this->authorize('bg_import_mapping');
        $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
        $job = $this->store()->job($jobId);
        if (!$job || !in_array((string) $job->status, ['uploaded', 'suggested', 'mapped', 'dry_run'], true)) $this->redirect($jobId, 'That import can no longer be remapped.');
        try {
            $path = $this->store()->filePath($job);
            $sheet = sanitize_text_field((string) ($_POST['source_sheet'] ?? ''));
            $this->assertSheet($job, $sheet);
            $headers = $this->container()->get('imports.reader')->headers($path, (string) $job->source_type, $sheet);
            $matcher = new FieldMatcher();
            $groups = $matcher->groups((array) $headers['cells']);
            $sheetChanged = !hash_equals((string) $job->source_sheet, $sheet);
            if ($sheetChanged) {
                $settings = $this->container()->get('settings');
                $assistant = IntelligenceModule::featureEnabled($settings, 'import_mapping')
                    ? $this->container()->get('ai') : null;
                $mapping = $matcher->suggest((array) $headers['cells'], $assistant);
            } else {
                $posted = is_array($_POST['mapping'] ?? null) ? wp_unslash($_POST['mapping']) : [];
                $selected = [];
                foreach (FieldMatcher::targets() as $key => $_target) {
                    $selected[$key] = array_values(array_filter(array_map('sanitize_text_field', (array) ($posted[$key] ?? []))));
                }
                $mapping = $matcher->fromSelection($selected, $groups);
            }
            if (!$this->store()->setMapping($jobId, $sheet, $mapping)) throw new \RuntimeException('The confirmed mapping could not be saved.');
            if ($sheetChanged) {
                $this->store()->updateJob($jobId, ['status' => 'suggested']);
                $this->redirect($jobId, 'Worksheet changed. Review its new suggestions, then save the confirmed mapping once more.', false);
            }
            $this->redirect($jobId, 'Mapping saved. Run the dry run to validate every row.', false);
        } catch (\Throwable $e) {
            $this->redirect($jobId, $e->getMessage());
        }
    }

    public function stage(): void
    {
        $this->authorize('bg_import_stage');
        $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
        try {
            $this->container()->get('imports.runner')->stage($jobId);
            $this->redirect($jobId, 'Dry run complete. Review the aggregate counts before committing.', false);
        } catch (\Throwable $e) {
            $this->redirect($jobId, $e->getMessage());
        }
    }

    public function discard(): void
    {
        $this->authorize('bg_import_discard');
        $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
        $job = $this->store()->job($jobId);
        if (!$job || !in_array((string) $job->status, ['uploaded', 'suggested', 'mapped', 'dry_run'], true)) {
            $this->redirect($jobId, 'Only an uncommitted import can be discarded.');
        }
        if (!$this->store()->purgeStaging($jobId, $job)) {
            $this->redirect($jobId, 'The private source could not be discarded. Check filesystem permissions and try again.');
        }
        if (!$this->store()->updateJob($jobId, [
            'status' => 'discarded',
            'completed_at' => current_time('mysql', true),
        ])) $this->redirect($jobId, 'The source was purged, but its job status could not be finalized.');
        $this->redirect($jobId, 'Uncommitted source and encrypted staged rows were discarded.', false);
    }

    public function commitAjax(): void
    {
        $this->ajaxAuthorize();
        try {
            wp_send_json_success($this->container()->get('imports.runner')->commitBatch(max(0, (int) ($_POST['job_id'] ?? 0)), 25));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function rollbackAjax(): void
    {
        $this->ajaxAuthorize();
        try {
            wp_send_json_success($this->container()->get('imports.runner')->rollbackBatch(max(0, (int) ($_POST['job_id'] ?? 0)), 25));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not permitted.');
        $store = $this->store();
        $jobId = max(0, (int) ($_GET['job'] ?? 0));
        $job = $jobId ? $store->job($jobId) : null;
        $notice = sanitize_text_field((string) ($_GET['bg_notice'] ?? ''));
        $error = (int) ($_GET['bg_error'] ?? 0) === 1;
        ?>
        <div class="wrap">
          <h1>Import Studio</h1>
          <p>Map large spreadsheets into historical events, registrations, people records, and reportable demographics. Nothing is committed until an administrator confirms the mapping, reviews a full dry run, and starts the import.</p>
          <?php if ($notice !== ''): ?><div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
          <?php if (!$store->available()): ?><div class="notice notice-error"><p>Import Studio is unavailable until encryption and database migration 26 are ready.</p></div><?php return; endif; ?>

          <div class="card" style="max-width:1000px">
            <h2>Start an import</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="bg_import_upload"><?php wp_nonce_field('bg_import_upload'); ?>
              <input type="file" name="source_file" accept=".xlsx,.csv,.tsv" required>
              <?php submit_button('Upload spreadsheet', 'secondary', 'submit', false); ?>
            </form>
            <p class="description">Maximum 100 MiB. Files are placed in protected private storage, row payloads are encrypted, and both are purged after commit. Conflicting source values are omitted from canonical fields; the audit keeps only issue codes and counts, never the discarded values.</p>
          </div>
          <?php if ($job): $this->renderJob($job); endif; ?>
          <h2>Import history</h2>
          <table class="widefat striped"><thead><tr><th>Job</th><th>Source</th><th>Status</th><th>Rows</th><th>Created</th><th>Matched</th><th>Skipped</th><th>Failed</th><th>Started</th></tr></thead><tbody>
          <?php foreach ($store->jobs() as $item): ?>
            <tr><td><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&job=' . (int) $item->id)); ?>">#<?php echo (int) $item->id; ?></a></td><td><?php echo esc_html((string) $item->source_name); ?></td><td><?php echo esc_html($this->statusLabel((string) $item->status)); ?></td><td><?php echo number_format_i18n((int) $item->total_rows); ?></td><td><?php echo number_format_i18n((int) $item->created_count); ?></td><td><?php echo number_format_i18n((int) $item->matched_count); ?></td><td><?php echo number_format_i18n((int) $item->skipped_count); ?></td><td><?php echo number_format_i18n((int) $item->failed_count); ?></td><td><?php echo esc_html((string) $item->created_at); ?> UTC</td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php
    }

    private function renderJob(object $job): void
    {
        $summary = $this->store()->summary($job);
        $rollbackReady = in_array((string) $job->status, ['complete', 'complete_errors', 'rolling_back'], true)
            || ((string) $job->status === 'rollback_conflicts' && $this->store()->remainingMutations((int) $job->id) > 0);
        $editable = in_array((string) $job->status, ['uploaded', 'suggested', 'mapped', 'dry_run'], true)
            && $this->store()->filePath($job) !== '';
        ?>
        <hr><h2>Import job #<?php echo (int) $job->id; ?>: <?php echo esc_html((string) $job->source_name); ?></h2>
        <p><strong>Status:</strong> <?php echo esc_html($this->statusLabel((string) $job->status)); ?>. <strong>Conflict policy:</strong> contradictory canonical values are dropped. <strong>Attendance policy:</strong> historical rows are confirmed registrations, never inferred physical attendance. <strong>Financial policy:</strong> source totals remain encrypted provenance and are never posted to the local ledger.</p>
        <?php if ($editable) $this->renderMapping($job, $summary); ?>
        <?php if ($editable): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Discard this uncommitted source file and its staged rows?');"><input type="hidden" name="action" value="bg_import_discard"><input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>"><?php wp_nonce_field('bg_import_discard'); ?><button class="button" type="submit">Discard uncommitted import</button></form><?php endif; ?>
        <?php if (in_array((string) $job->status, ['dry_run', 'committing', 'purge_failed', 'complete', 'complete_errors', 'rolling_back', 'rolled_back', 'rollback_conflicts'], true)) $this->renderSummary($job, $summary); ?>
        <?php if (in_array((string) $job->status, ['dry_run', 'committing', 'purge_failed'], true)): ?>
          <p><button class="button button-primary bg-import-run" data-mode="commit" data-job="<?php echo (int) $job->id; ?>"><?php echo (string) $job->status === 'purge_failed' ? 'Resume private-source cleanup' : 'Commit import'; ?></button> <span class="bg-import-progress" aria-live="polite"></span></p>
        <?php elseif ($rollbackReady): ?>
          <p><button class="button bg-import-run" data-mode="rollback" data-job="<?php echo (int) $job->id; ?>">Guarded rollback</button> <span class="bg-import-progress" aria-live="polite"></span></p>
        <?php endif; ?>
        <?php if (in_array((string) $job->status, ['dry_run', 'committing', 'purge_failed', 'complete', 'complete_errors', 'rolling_back'], true) || $rollbackReady): ?>
        <script>
        (()=>{const b=document.querySelector('.bg-import-run[data-job="<?php echo (int) $job->id; ?>"]');if(!b)return;const o=document.querySelector('.bg-import-progress');b.addEventListener('click',async()=>{if(b.dataset.mode==='rollback'&&!confirm('Rollback only unchanged records created or updated by this job. Continue?'))return;b.disabled=true;const action=b.dataset.mode==='commit'?'bg_import_commit_batch':'bg_import_rollback_batch';while(true){const body=new URLSearchParams({action,job_id:b.dataset.job,_ajax_nonce:'<?php echo esc_js(wp_create_nonce('bg_import_batch')); ?>'});let data;try{const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});data=await r.json()}catch(e){o.textContent='Request failed. Reload to resume safely.';break}if(!data.success){o.textContent=(data.data&&data.data.message)||'Batch failed. Reload to inspect the job.';break}const x=data.data;o.textContent=x.done?'Finished. Reloading…':('Processed batch; '+x.remaining+' remain.');if(x.done){location.reload();return}}b.disabled=false})})();
        </script>
        <?php endif;
    }

    private function renderMapping(object $job, array $summary): void
    {
        try {
            $path = $this->store()->filePath($job);
            $inventory = (array) ($summary['inventory'] ?? []);
            $sheets = (array) ($inventory['sheets'] ?? []);
            $headers = $this->container()->get('imports.reader')->headers($path, (string) $job->source_type, (string) $job->source_sheet);
            $matcher = new FieldMatcher();
            $groups = $matcher->groups((array) $headers['cells']);
            $mapping = $this->store()->mapping($job);
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="bg_import_mapping"><input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>"><?php wp_nonce_field('bg_import_mapping'); ?>
          <p><label><strong>Worksheet:</strong> <select name="source_sheet">
          <?php foreach ($sheets as $sheet): $name = (string) ($sheet['name'] ?? ''); ?><option value="<?php echo esc_attr($name); ?>" <?php selected($name, (string) $job->source_sheet); ?>><?php echo esc_html($name . ' · ' . number_format_i18n((int) ($sheet['rows'] ?? 0)) . ' rows, ' . number_format_i18n((int) ($sheet['columns'] ?? 0)) . ' columns'); ?></option><?php endforeach; ?>
          </select></label></p>
          <table class="widefat striped"><thead><tr><th>Field</th><th>Source heading group(s)</th><th>Rule</th><th>Initial suggestion</th></tr></thead><tbody>
          <?php foreach (FieldMatcher::targets() as $key => $target): $selectedGroups = (array) ($mapping[$key]['groups'] ?? []); ?>
            <tr><th><?php echo esc_html($target['label']); ?><?php if ($target['required']): ?> <span aria-label="required" style="color:#b32d2e">*</span><?php endif; ?></th><td><select name="mapping[<?php echo esc_attr($key); ?>][]" multiple size="4" style="min-width:360px;max-width:100%"><option value="">(Unmapped)</option><?php foreach ($groups as $id => $group): ?><option value="<?php echo esc_attr($id); ?>" <?php selected(in_array($id, $selectedGroups, true)); ?>><?php echo esc_html((string) $group['label'] . ' (' . count((array) $group['columns']) . ')'); ?></option><?php endforeach; ?></select></td><td><?php echo esc_html(str_replace('_', ' ', $target['reducer'])); ?></td><td><?php echo esc_html((string) ($mapping[$key]['method'] ?? 'unmapped') . ' / ' . number_format_i18n(100 * (float) ($mapping[$key]['confidence'] ?? 0), 0) . '%'); ?></td></tr>
          <?php endforeach; ?></tbody></table>
          <?php submit_button('Save confirmed mapping'); ?>
        </form>
        <?php if ((string) $job->status !== 'suggested'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:-10px">
          <input type="hidden" name="action" value="bg_import_stage"><input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>"><?php wp_nonce_field('bg_import_stage'); ?><?php submit_button('Run full dry run', 'secondary'); ?>
        </form><?php else: ?><p><strong>Save the reviewed mapping before running the dry run.</strong></p><?php endif; ?>
        <?php
    }

    private function renderSummary(object $job, array $summary): void
    {
        $counts = (array) ($summary['counts'] ?? []);
        $issues = (array) ($summary['issues'] ?? []);
        ?>
        <h3>Aggregate validation result</h3>
        <table class="widefat striped" style="max-width:900px"><thead><tr><th>Ready: new person</th><th>Ready: matched person</th><th>Duplicates</th><th>Already imported</th><th>Excluded</th><th>Invalid/failed</th></tr></thead><tbody><tr><td><?php echo number_format_i18n((int) ($counts['ready_new'] ?? $counts['imported_new'] ?? 0)); ?></td><td><?php echo number_format_i18n((int) ($counts['ready_match'] ?? $counts['imported_match'] ?? 0)); ?></td><td><?php echo number_format_i18n((int) ($counts['duplicate'] ?? 0)); ?></td><td><?php echo number_format_i18n((int) ($counts['already_imported'] ?? 0)); ?></td><td><?php echo number_format_i18n((int) ($counts['excluded'] ?? 0)); ?></td><td><?php echo number_format_i18n((int) ($counts['invalid'] ?? 0) + (int) ($counts['failed'] ?? 0)); ?></td></tr></tbody></table>
        <?php if ($issues): ?><h4>Issue-code audit</h4><ul><?php foreach ($issues as $code => $count): ?><li><code><?php echo esc_html((string) $code); ?></code>: <?php echo number_format_i18n((int) $count); ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p class="description">Imported addresses pass through the normal regional rules. Manual regional overrides remain authoritative, and historical participation continues to support regional filters and regional breakdowns in Grant Reports.</p>
        <?php if ((int) ($summary['rollback_conflicts'] ?? 0) > 0): ?><p><strong>Rollback conflicts:</strong> <?php echo number_format_i18n((int) $summary['rollback_conflicts']); ?> subsequently changed records were preserved instead of overwritten.</p><?php endif;
    }

    private function preferredSheet(array $sheets): string
    {
        foreach ($sheets as $sheet) if (strcasecmp((string) ($sheet['name'] ?? ''), 'Combined-4032') === 0) return (string) $sheet['name'];
        usort($sheets, static fn(array $a, array $b): int => (int) ($b['rows'] ?? 0) <=> (int) ($a['rows'] ?? 0));
        return (string) ($sheets[0]['name'] ?? '');
    }

    private function assertSheet(object $job, string $sheet): void
    {
        $allowed = [];
        foreach ((array) (($this->store()->summary($job)['inventory']['sheets'] ?? [])) as $item) $allowed[] = (string) ($item['name'] ?? '');
        if ($sheet === '' || !in_array($sheet, $allowed, true)) throw new \RuntimeException('Choose a worksheet from this uploaded file.');
    }

    private function protectDirectory(string $directory): void
    {
        $files = [
            '.htaccess' => "Deny from all\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
            'index.php' => "<?php http_response_code(404); exit;\n",
        ];
        foreach ($files as $name => $body) {
            $target = $directory . '/' . $name;
            if (!file_exists($target)) @file_put_contents($target, $body, LOCK_EX);
        }
    }

    private function authorize(string $nonce): void
    {
        if (!current_user_can('manage_options')) wp_die('Not permitted.');
        check_admin_referer($nonce);
    }

    private function ajaxAuthorize(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not permitted.'], 403);
        check_ajax_referer('bg_import_batch');
    }

    private function redirect(int $jobId, string $message, bool $error = true): void
    {
        $args = ['page' => self::PAGE, 'bg_notice' => bg_substr(sanitize_text_field($message), 0, 300)];
        if ($jobId > 0) $args['job'] = $jobId;
        if ($error) $args['bg_error'] = 1;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    private function store(): ImportStore { return $this->container()->get('imports.store'); }
    private function container(): Container
    {
        if (!$this->container) throw new \RuntimeException('Import Studio is not initialized.');
        return $this->container;
    }
}
