<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\GrantReporting;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\Plugin;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\Intelligence\IntelligenceModule;

if (!defined('ABSPATH')) exit;

/** Interactive, reproducible grant-report preparation over aggregate data. */
final class GrantReportingModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-grant-reporting';
    private const PREVIEW_TTL = 2 * HOUR_IN_SECONDS;

    public function name(): string
    {
        return 'grant-reporting';
    }

    public function label(): string
    {
        return 'Grant Reporting';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        GrantTags::boot();
        $container->set('grant.builder', fn(Container $c) => new ReportBuilder($c));
        $container->set('grant.store', fn(Container $c) => new ReportStore(
            $c->get('encryption'),
            $c->get('logger')
        ));

        add_filter('bg_ai_features', function (array $features): array {
            $features['grant_reporting_narrative'] = [
                'label' => 'Grant report drafting',
                'description' => 'Draft neutral funder-ready language from non-identifying totals, staff context, and data-quality notes. Individual records and sensitive demographic breakdowns are never sent.',
            ];
            return $features;
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Grant Reporting', 'Grant Reporting',
                'manage_options', self::PAGE, [$this, 'renderPage']);
        }, 12);
        add_action('admin_post_bg_grant_preview', [$this, 'preview']);
        add_action('admin_post_bg_grant_save', [$this, 'save']);
        add_action('admin_post_bg_grant_draft', [$this, 'draft']);
        add_action('admin_post_bg_grant_narrative', [$this, 'saveNarrative']);
        add_action('admin_post_bg_grant_export', [$this, 'export']);
    }

    public function preview(): void
    {
        $this->guard('bg_grant_preview');
        $container = Plugin::instance()->container();
        $config = ReportBuilder::fromRequest($_POST);
        $snapshot = $container->get('grant.builder')->build($config);
        $existing = $this->loadPreview($container);
        $reportId = max(0, (int) ($_POST['report_id'] ?? 0));
        $narrative = $existing && (int) ($existing['report_id'] ?? 0) === $reportId
            ? (string) ($existing['narrative'] ?? '')
            : '';
        $stored = $this->storePreview($container, [
            'report_id' => $reportId,
            'config' => $config,
            'snapshot' => $snapshot,
            'narrative' => $narrative,
        ]);
        $this->redirect($reportId, $stored ? 'prepared' : 'save-failed');
    }

    public function save(): void
    {
        $this->guard('bg_grant_save');
        $container = Plugin::instance()->container();
        $preview = $this->loadPreview($container);
        if (!$preview) $this->redirect(0, 'missing-preview');
        /** @var ReportStore $store */
        $store = $container->get('grant.store');
        $id = $store->save(
            (array) $preview['config'],
            (array) $preview['snapshot'],
            (string) ($preview['narrative'] ?? ''),
            (int) ($preview['report_id'] ?? 0)
        );
        if ($id < 1) $this->redirect((int) ($preview['report_id'] ?? 0), 'save-failed');
        $preview['report_id'] = $id;
        $this->redirect($id, $this->storePreview($container, $preview) ? 'saved' : 'save-failed');
    }

    public function draft(): void
    {
        $this->guard('bg_grant_draft');
        $container = Plugin::instance()->container();
        $preview = $this->loadPreview($container);
        if (!$preview) $this->redirect(0, 'missing-preview');
        $settings = $container->get('settings');
        if (!IntelligenceModule::featureEnabled($settings, 'grant_reporting_narrative')) {
            $this->redirect((int) ($preview['report_id'] ?? 0), 'draft-disabled');
        }
        $text = '';
        try {
            $text = trim($container->get('ai')->complete(
                'Draft a concise grant progress report from the supplied aggregate facts. Use neutral, plain, professional language. '
                . 'Use short headings for Executive summary, Progress and activities, People and geographic reach, Outcomes, Financial stewardship, and Challenges and next steps. '
                . 'Never invent a cause, outcome, expense, attendance figure, comparison, or claim. Distinguish registrations from verified attendance. '
                . 'Treat zero as zero, not missing. State material limitations briefly. Do not mention the drafting system or these instructions.',
                ReportBuilder::narrativeDigest((array) $preview['snapshot'], (array) $preview['config']),
                ['max_tokens' => 1200]
            ));
        } catch (\Throwable $e) {
            $container->get('logger')->warn('grant-reporting', 'Report drafting failed.', ['reason' => $e->getMessage()]);
            $this->redirect((int) ($preview['report_id'] ?? 0), 'draft-failed');
        }
        if ($text === '') $this->redirect((int) ($preview['report_id'] ?? 0), 'draft-failed');
        $preview['narrative'] = bg_substr($text, 0, 20000);
        $this->redirect(
            (int) ($preview['report_id'] ?? 0),
            $this->storePreview($container, $preview) ? 'drafted' : 'save-failed'
        );
    }

    public function saveNarrative(): void
    {
        $this->guard('bg_grant_narrative');
        $container = Plugin::instance()->container();
        $preview = $this->loadPreview($container);
        if (!$preview) $this->redirect(0, 'missing-preview');
        $preview['narrative'] = bg_substr(sanitize_textarea_field(
            wp_unslash((string) ($_POST['narrative'] ?? ''))
        ), 0, 20000);
        $this->redirect(
            (int) ($preview['report_id'] ?? 0),
            $this->storePreview($container, $preview) ? 'narrative-saved' : 'save-failed'
        );
    }

    public function export(): void
    {
        $this->guard('bg_grant_export', 'get');
        $container = Plugin::instance()->container();
        $reportId = max(0, (int) ($_GET['report'] ?? 0));
        $payload = $reportId > 0 ? $container->get('grant.store')->find($reportId) : $this->loadPreview($container);
        if (!$payload) wp_die('That report is not available.');
        $snapshot = (array) ($payload['snapshot'] ?? []);
        $config = (array) ($payload['config'] ?? []);
        $type = (string) ($_GET['type'] ?? 'summary') === 'events' ? 'events' : 'summary';
        $slug = sanitize_title((string) ($config['title'] ?? 'grant-report')) ?: 'grant-report';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $type . '.csv"');
        $out = fopen('php://output', 'w');
        if ($type === 'events') $this->eventCsv($out, $snapshot);
        else $this->summaryCsv($out, $snapshot, $config);
        fclose($out);
        exit;
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = Plugin::instance()->container();
        /** @var ReportBuilder $builder */
        $builder = $container->get('grant.builder');
        /** @var ReportStore $store */
        $store = $container->get('grant.store');
        $reportId = max(0, (int) ($_GET['report'] ?? 0));
        $new = isset($_GET['new']);
        $preview = $new ? null : $this->loadPreview($container);
        if ($reportId > 0 && (!$preview || (int) ($preview['report_id'] ?? 0) !== $reportId)) {
            $preview = $store->find($reportId);
            if ($preview) $this->storePreview($container, $preview);
        }
        $config = is_array($preview['config'] ?? null) ? $preview['config'] : ReportBuilder::defaults();
        $snapshot = is_array($preview['snapshot'] ?? null) ? $preview['snapshot'] : [];
        $narrative = (string) ($preview['narrative'] ?? '');
        $reportId = (int) ($preview['report_id'] ?? $preview['id'] ?? $reportId);
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        echo '<div class="wrap bg-grant"><div class="bg-grant-no-print">';
        echo '<h1>Grant Reporting</h1>';
        echo '<p style="max-width:900px">Prepare a funder-ready, reproducible report from BosqueGrande data. Automated figures remain separate from staff-entered figures, and saved snapshots preserve exactly what was reported at the time.</p>';
        if (!GrantTags::backfillComplete()) {
            echo '<div class="notice notice-warning inline"><p><strong>Grant tag rules are being applied.</strong> '
                . 'BosqueGrande is reviewing events in small background batches after a rule change. Refresh this screen before filing a report; grant-filtered results can be incomplete until this notice disappears.</p></div>';
        }
        $this->notice($notice);
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '&new=1')) . '">Start a new report</a></p>';
        $this->renderForm($builder, $config, $reportId);
        echo '</div>';

        if ($snapshot) {
            $this->renderActions($container, $reportId, $narrative);
            $this->renderReport($config, $snapshot, $narrative);
        }
        echo '<div class="bg-grant-no-print">';
        $this->renderSaved($store);
        echo '</div></div>';
        $this->styles();
    }

    private function renderForm(ReportBuilder $builder, array $config, int $reportId): void
    {
        $events = $builder->eventOptions();
        $programs = $builder->programOptions();
        $grants = $builder->grantOptions();
        $selectedEvents = array_fill_keys(array_map('intval', (array) ($config['event_ids'] ?? [])), true);
        $selectedPrograms = array_fill_keys(array_map('strval', (array) ($config['program_tags'] ?? [])), true);
        $grantValues = array_values(array_map('strval', (array) ($config['grant_tags'] ?? [])));
        $selectedGrant = (string) ($grantValues[0] ?? '');
        $selectedMode = (string) ($config['event_mode'] ?? 'all') === 'selected';
        $participantRegion = (string) ($config['participant_region_slug'] ?? $config['region_slug'] ?? '');
        $eventRegion = (string) ($config['event_region_slug'] ?? '');
        $optionalOpen = false;
        foreach (['grant_award_cents', 'grant_received_cents', 'grant_expenses_cents', 'other_income_cents', 'in_kind_cents', 'verified_attendance', 'volunteer_hours', 'target_people', 'target_events'] as $key) {
            if ((float) ($config[$key] ?? 0) > 0) $optionalOpen = true;
        }
        foreach (['objective', 'activities', 'outcomes', 'challenges', 'next_steps', 'notes'] as $key) {
            if (trim((string) ($config[$key] ?? '')) !== '') $optionalOpen = true;
        }
        ?>
        <section class="bg-grant-builder bg-grant-no-print">
          <header class="bg-grant-builder-header">
            <h2><?php echo $reportId > 0 ? 'Update this grant report' : 'Build a grant report'; ?></h2>
            <p>Choose the reporting period and activity scope. Participant home region and event location region are separate filters, so reports can measure either geographic reach or where programs were delivered. BosqueGrande fills event, participant, demographic, survey, and ledger totals automatically.</p>
          </header>
          <form id="bg-grant-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_grant_preview'); ?>
            <input type="hidden" name="action" value="bg_grant_preview">
            <input type="hidden" name="report_id" value="<?php echo (int) $reportId; ?>">

            <section class="bg-grant-step" aria-labelledby="bg-grant-step-one">
              <div class="bg-grant-step-heading"><span>1</span><div><h3 id="bg-grant-step-one">Name the report and choose its dates</h3><p>Only the title and date range are required.</p></div></div>
              <div class="bg-grant-grid">
                <label class="bg-grant-wide">Report title<input type="text" name="title" maxlength="191" required value="<?php echo esc_attr((string) $config['title']); ?>"></label>
                <label>Start date<input type="date" name="period_start" required value="<?php echo esc_attr((string) $config['period_start']); ?>"></label>
                <label>End date<input type="date" name="period_end" required value="<?php echo esc_attr((string) $config['period_end']); ?>"></label>
                <label>Funder name <span class="bg-optional-label">Optional</span><input type="text" name="funder_name" maxlength="191" value="<?php echo esc_attr((string) $config['funder_name']); ?>"></label>
                <label>Grant or award reference <span class="bg-optional-label">Optional</span><input type="text" name="grant_reference" maxlength="191" value="<?php echo esc_attr((string) $config['grant_reference']); ?>"></label>
              </div>
            </section>

            <section class="bg-grant-step" aria-labelledby="bg-grant-step-two">
              <div class="bg-grant-step-heading"><span>2</span><div><h3 id="bg-grant-step-two">Choose what the report covers</h3><p>Use all activity in the period, or narrow the report by grant, region, event, or program.</p></div></div>
              <div class="bg-grant-grid">
                <label>Participants live in<select name="participant_region_slug"><option value="">All participant regions</option><?php
                  foreach (RegionalGroups::regions() as $slug => $label) {
                      printf('<option value="%s"%s>%s</option>', esc_attr($slug), selected($participantRegion, $slug, false), esc_html($label));
                  }
                ?></select><span class="description">Uses the authoritative regional assignment saved with each constituent.</span></label>
                <label>Events held in<select name="event_region_slug"><option value="">All event regions</option><?php
                  foreach (RegionalGroups::regions() as $slug => $label) {
                      printf('<option value="%s"%s>%s</option>', esc_attr($slug), selected($eventRegion, $slug, false), esc_html($label));
                  }
                ?></select><span class="description">Uses each event's Location first, then the title for legacy records. Selecting both region filters applies both.</span></label>
                <label class="bg-grant-wide">Grant tags <span class="bg-optional-label">Optional</span>
                  <select name="grant_tags[]" aria-describedby="bg-grant-tag-help">
                    <option value="" <?php selected($selectedGrant, ''); ?>>All grant tags</option>
                    <?php foreach ($grants as $slug => $name): ?>
                      <option value="<?php echo esc_attr($slug); ?>" <?php selected($selectedGrant, $slug); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span id="bg-grant-tag-help" class="description"><?php echo $grants
                      ? 'Choose one grant to include events carrying that tag, or choose All grant tags for no grant filter. Events may still carry several overlapping grant tags.'
                      : 'No grant tags are configured yet.'; ?></span>
                </label>
                <label>Small-group privacy<select name="privacy_threshold">
                  <option value="3" <?php selected((int) $config['privacy_threshold'], 3); ?>>Replace nonzero counts below 3 with &lt;3</option>
                  <option value="5" <?php selected((int) $config['privacy_threshold'], 5); ?>>Replace nonzero counts below 5 with &lt;5</option>
                  <option value="0" <?php selected((int) $config['privacy_threshold'], 0); ?>>Show exact counts</option>
                </select><span class="description">Longitudinal person-level cells below this threshold omit their exact value from the saved snapshot and CSV.</span></label>
                <label>Longitudinal intervals<select name="trend_granularity">
                  <option value="auto" <?php selected((string) ($config['trend_granularity'] ?? 'auto'), 'auto'); ?>>Automatic: monthly, quarterly, or yearly</option>
                  <option value="month" <?php selected((string) ($config['trend_granularity'] ?? 'auto'), 'month'); ?>>Monthly</option>
                  <option value="quarter" <?php selected((string) ($config['trend_granularity'] ?? 'auto'), 'quarter'); ?>>Quarterly</option>
                  <option value="year" <?php selected((string) ($config['trend_granularity'] ?? 'auto'), 'year'); ?>>Yearly</option>
                </select><span class="description">Automatic uses months through two years, quarters through five years, and years for longer ranges.</span></label>
              </div>

              <div class="bg-grant-scope-layout">
                <fieldset><legend>Events</legend>
                  <label class="bg-inline"><input type="radio" name="event_mode" value="all" <?php checked(!$selectedMode); ?> aria-controls="bg-grant-event-picker"> Every reportable event in the date range</label>
                  <label class="bg-inline"><input type="radio" name="event_mode" value="selected" <?php checked($selectedMode); ?> aria-controls="bg-grant-event-picker"> Only the events I select</label>
                  <div id="bg-grant-event-picker" class="bg-grant-picker" <?php echo $selectedMode ? '' : 'hidden'; ?>>
                    <?php if ($events): ?>
                      <label class="bg-grant-search-label">Find an event<input id="bg-grant-event-search" type="search" autocomplete="off" placeholder="Search by date or title"></label>
                      <div class="bg-grant-checklist" role="group" aria-label="Events included in the report">
                        <?php foreach ($events as $event):
                            $eventLabel = ($event['date'] ?: 'No date') . ' | ' . ($event['title'] ?: 'Untitled')
                                . ' | ' . ((string) ($event['region_label'] ?? '') ?: 'Unassigned / needs review')
                                . ($event['status'] !== 'publish' ? ' [' . $event['status'] . ']' : '');
                        ?>
                          <label class="bg-grant-check-item"><input type="checkbox" name="event_ids[]" value="<?php echo (int) $event['id']; ?>" <?php checked(isset($selectedEvents[$event['id']])); ?>><span><?php echo esc_html($eventLabel); ?></span></label>
                        <?php endforeach; ?>
                      </div>
                      <span class="description">The date range above still applies. Historical events are labeled. Auto-delisted events are included only when selected here.</span>
                    <?php else: ?>
                      <p>No published or historical events are available.</p>
                    <?php endif; ?>
                  </div>
                </fieldset>

                <fieldset><legend>Program tags <span class="bg-optional-label">Optional</span></legend>
                  <p class="description">Leave every box clear to include all programs. Events with several tags can appear in several program totals.</p>
                  <?php if ($programs): ?>
                    <div class="bg-grant-checklist bg-grant-program-list" role="group" aria-label="Program tags included in the report">
                      <?php foreach ($programs as $slug => $name): ?>
                        <label class="bg-grant-check-item"><input type="checkbox" name="program_tags[]" value="<?php echo esc_attr($slug); ?>" <?php checked(isset($selectedPrograms[$slug])); ?>><span><?php echo esc_html($name); ?></span></label>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <p>No program tags are configured.</p>
                  <?php endif; ?>
                </fieldset>
              </div>

              <p class="bg-grant-primary-action"><button class="button button-primary button-hero">Generate or refresh report</button><span>BosqueGrande will calculate the report from the current database.</span></p>
            </section>

            <details class="bg-grant-optional" <?php echo $optionalOpen ? 'open' : ''; ?>>
              <summary><strong>3. Add optional staff figures and context</strong><span>Use this only for information BosqueGrande cannot calculate.</span></summary>
              <div class="bg-grant-optional-body">
                <p class="description">These values remain clearly labeled as staff-entered in the report.</p>
                <div class="bg-grant-grid">
                  <?php $this->moneyInput('grant_award', 'Grant award amount', (int) $config['grant_award_cents']); ?>
                  <?php $this->moneyInput('grant_received', 'Grant funds received', (int) $config['grant_received_cents']); ?>
                  <?php $this->moneyInput('grant_expenses', 'Grant-funded expenses', (int) $config['grant_expenses_cents']); ?>
                  <?php $this->moneyInput('other_income', 'Other program income', (int) $config['other_income_cents']); ?>
                  <?php $this->moneyInput('in_kind', 'In-kind value', (int) $config['in_kind_cents']); ?>
                  <label>Verified attendance<input type="number" min="0" name="verified_attendance" value="<?php echo (int) $config['verified_attendance']; ?>"><span class="description">Actual door check-ins, if staff have them.</span></label>
                  <label>Volunteer hours<input type="number" min="0" step="0.25" name="volunteer_hours" value="<?php echo esc_attr((string) $config['volunteer_hours']); ?>"></label>
                  <label>People target<input type="number" min="0" name="target_people" value="<?php echo (int) $config['target_people']; ?>"></label>
                  <label>Event target<input type="number" min="0" name="target_events" value="<?php echo (int) $config['target_events']; ?>"></label>
                </div>
                <div class="bg-grant-text-grid">
                  <p class="bg-grant-wide description">Context may be included in drafted report language. Do not enter names, contact details, or other person-specific information.</p>
                  <?php foreach ([
                      'objective' => 'Grant objective', 'activities' => 'Activities and accomplishments',
                      'outcomes' => 'Observed outcomes', 'challenges' => 'Challenges or changes',
                      'next_steps' => 'Next steps', 'notes' => 'Additional notes',
                  ] as $key => $label): ?>
                    <label><?php echo esc_html($label); ?><textarea name="<?php echo esc_attr($key); ?>" rows="4"><?php echo esc_textarea((string) $config[$key]); ?></textarea></label>
                  <?php endforeach; ?>
                </div>
                <p><button class="button button-primary">Generate report with these additions</button></p>
              </div>
            </details>
          </form>
        </section>
        <script>
        (function () {
          const form = document.getElementById('bg-grant-form');
          if (!form) return;
          const picker = document.getElementById('bg-grant-event-picker');
          const radios = form.querySelectorAll('input[name="event_mode"]');
          const syncPicker = function () {
            const selected = form.querySelector('input[name="event_mode"]:checked');
            if (picker) picker.hidden = !selected || selected.value !== 'selected';
          };
          radios.forEach(function (radio) { radio.addEventListener('change', syncPicker); });
          syncPicker();

          const search = document.getElementById('bg-grant-event-search');
          if (search) search.addEventListener('input', function () {
            const needle = search.value.trim().toLocaleLowerCase();
            form.querySelectorAll('#bg-grant-event-picker .bg-grant-check-item').forEach(function (row) {
              row.hidden = needle !== '' && !row.textContent.toLocaleLowerCase().includes(needle);
            });
          });
        }());
        </script>
        <?php
    }

    private function renderActions(Container $container, int $reportId, string $narrative): void
    {
        $summary = wp_nonce_url(add_query_arg([
            'action' => 'bg_grant_export', 'type' => 'summary', 'report' => $reportId,
        ], admin_url('admin-post.php')), 'bg_grant_export');
        $events = wp_nonce_url(add_query_arg([
            'action' => 'bg_grant_export', 'type' => 'events', 'report' => $reportId,
        ], admin_url('admin-post.php')), 'bg_grant_export');
        ?>
        <div class="bg-grant-actions bg-grant-no-print">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_grant_save'); ?><input type="hidden" name="action" value="bg_grant_save">
            <button class="button button-primary"><?php echo $reportId ? 'Update saved snapshot' : 'Save report snapshot'; ?></button>
          </form>
          <?php if (IntelligenceModule::featureEnabled($container->get('settings'), 'grant_reporting_narrative')): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field('bg_grant_draft'); ?><input type="hidden" name="action" value="bg_grant_draft">
              <button class="button"><?php echo $narrative !== '' ? 'Redraft report language' : 'Draft report language'; ?></button>
            </form>
          <?php endif; ?>
          <a class="button" href="<?php echo esc_url($summary); ?>">Download summary CSV</a>
          <a class="button" href="<?php echo esc_url($events); ?>">Download event CSV</a>
          <button class="button" type="button" onclick="window.print()">Print / save as PDF</button>
        </div>
        <?php
    }

    private function renderReport(array $config, array $report, string $narrative): void
    {
        $summary = (array) ($report['summary'] ?? []);
        $demographics = (array) ($report['demographics'] ?? []);
        $privacy = (int) ($config['privacy_threshold'] ?? 3);
        $sourceBreakdownAvailable = (int) ($report['schema'] ?? 1) >= 2;
        $ageSourceNote = $sourceBreakdownAvailable
            ? (int) ($demographics['age_profile_sources'] ?? 0) . ' current profiles; ' . (int) ($demographics['age_historical_sources'] ?? 0) . ' historical fallbacks'
            : 'Refresh this saved report to calculate answer sources';
        $attributeSourceNote = $sourceBreakdownAvailable
            ? (int) ($demographics['attributes_profile_sources'] ?? 0) . ' current profiles; ' . (int) ($demographics['attributes_historical_sources'] ?? 0) . ' historical fallbacks'
            : 'Refresh this saved report to calculate answer sources';
        $scope = (array) ($report['scope'] ?? []);
        $scopeLabel = (string) ($scope['scope_label'] ?? $scope['region_label'] ?? 'All regions');
        $grantNames = array_values(array_map('strval', (array) ($scope['grant_tag_names'] ?? [])));
        ?>
        <article class="bg-grant-report">
          <header>
            <h1><?php echo esc_html((string) $config['title']); ?></h1>
            <?php if ((string) $config['funder_name'] !== ''): ?><p><strong>Prepared for:</strong> <?php echo esc_html((string) $config['funder_name']); ?></p><?php endif; ?>
            <?php if ((string) $config['grant_reference'] !== ''): ?><p><strong>Grant reference:</strong> <?php echo esc_html((string) $config['grant_reference']); ?></p><?php endif; ?>
            <p><strong>Reporting period:</strong> <?php echo esc_html((string) $report['period']['start']); ?> through <?php echo esc_html((string) $report['period']['end']); ?>
               &nbsp; <strong>Scope:</strong> <?php echo esc_html($scopeLabel); ?></p>
            <?php if ($grantNames): ?><p><strong>Grant tags:</strong> <?php echo esc_html(implode('; ', $grantNames)); ?></p><?php endif; ?>
            <p class="description">Generated from BosqueGrande at <?php echo esc_html((string) $report['generated_at']); ?> UTC. Staff-entered figures are marked.</p>
          </header>

          <h2>Key results</h2>
          <div class="bg-metrics">
            <?php $this->metric('Events delivered', (string) ($summary['events'] ?? 0), $this->progress((int) ($summary['events'] ?? 0), (int) ($summary['target_events'] ?? 0))); ?>
            <?php $this->metric('Confirmed registrations', (string) ($summary['confirmed_registrations'] ?? 0), 'Automated'); ?>
            <?php $this->metric('Unique identified participants', (string) ($summary['identified_unique_participants'] ?? 0), $this->progress((int) ($summary['identified_unique_participants'] ?? 0), (int) ($summary['target_people'] ?? 0))); ?>
            <?php if ((int) ($summary['verified_attendance'] ?? 0) > 0) $this->metric('Verified attendance', (string) $summary['verified_attendance'], 'Staff-entered'); ?>
            <?php $this->metric('New participant registrations', (string) ($summary['new_participant_registrations'] ?? 0), 'Automated'); ?>
            <?php $this->metric('Volunteer interest', (string) ($summary['volunteer_interest_people'] ?? 0), 'Unique people'); ?>
            <?php if ((float) ($summary['volunteer_hours'] ?? 0) > 0) $this->metric('Volunteer hours', number_format((float) $summary['volunteer_hours'], 2), 'Staff-entered'); ?>
          </div>

          <?php $this->staffContext($config); ?>
          <?php $this->renderNarrativeEditor($narrative); ?>

          <?php $this->longitudinalTables((array) ($report['longitudinal'] ?? [])); ?>

          <h2>Events and program activity</h2>
          <?php $this->eventTable((array) ($report['events'] ?? [])); ?>
          <?php $this->twoColumnTable('Program or event tag', 'Events', 'Registrations', (array) ($report['programs'] ?? []), 'events', 'registrations'); ?>
          <?php if (!empty($report['grants'])): ?><p class="description">Grant categories may overlap. One event can appear under more than one grant, so these rows should not be added together.</p><?php endif; ?>
          <?php $this->twoColumnTable('Grant tag', 'Events', 'Registrations', (array) ($report['grants'] ?? []), 'events', 'registrations'); ?>

          <h2>Geographic reach</h2>
          <div class="bg-grant-columns">
            <div><?php $this->twoColumnTable('Event location region', 'Events', 'Registrations', (array) ($report['event_regions'] ?? []), 'events', 'registrations', 'label'); ?></div>
            <div><?php $this->twoColumnTable('Participant home region', 'Unique people', 'Registrations', (array) ($report['regions'] ?? []), 'participants', 'registrations', 'label'); ?></div>
          </div>

          <h2>Participant demographics</h2>
          <p class="description">Counts use unique identified participants. Age and demographic selections come first from each participant's latest encrypted constituent profile. One unambiguous historical answer is used only when the current profile field is blank. Multi-select categories do not add to the participant total, and small cells follow this report's privacy setting.</p>
          <div class="bg-metrics bg-demographic-coverage">
            <?php $this->metric(
                'Age range coverage',
                (int) ($demographics['age_answered'] ?? 0) . ' of ' . (int) ($demographics['base_participants'] ?? 0),
                $ageSourceNote
            ); ?>
            <?php $this->metric(
                'Demographic coverage',
                (int) ($demographics['attributes_answered'] ?? 0) . ' of ' . (int) ($demographics['base_participants'] ?? 0),
                $attributeSourceNote
            ); ?>
          </div>
          <div class="bg-grant-columns">
            <?php $this->countTable('Age range', (array) ($demographics['age_ranges'] ?? []), $privacy); ?>
            <?php $this->countTable('Demographic selections', (array) ($demographics['attributes'] ?? []), $privacy); ?>
            <?php $this->countTable('Relationship status', (array) ($demographics['relationships'] ?? []), $privacy); ?>
            <?php $this->countTable('How participants heard', (array) ($demographics['heard_about_events'] ?? []), 0); ?>
          </div>

          <h2>Participant feedback and outcomes</h2>
          <div class="bg-metrics">
            <?php $this->metric('Survey respondents', (string) ($report['outcomes']['respondents'] ?? 0), 'Unique registration links'); ?>
            <?php $this->metric('Response rate', number_format((float) ($report['outcomes']['response_rate'] ?? 0), 1) . '%', 'Of confirmed registrations'); ?>
            <?php $this->metric('Average score', number_format((float) ($report['outcomes']['overall_average'] ?? 0), 2) . ' / 5', 'All scored responses'); ?>
          </div>
          <?php $this->questionTable((array) ($report['outcomes']['questions'] ?? [])); ?>

          <h2>Financial stewardship</h2>
          <div class="bg-grant-columns">
            <?php $this->moneyTable('Local ledger activity', [
                'Donations' => (int) ($report['financials']['donations_cents'] ?? 0),
                'Event fees' => (int) ($report['financials']['event_fees_cents'] ?? 0),
                'Sales' => (int) ($report['financials']['sales_cents'] ?? 0),
                'Refunds' => -(int) ($report['financials']['refunds_cents'] ?? 0),
                'Net recorded revenue' => (int) ($report['financials']['net_cents'] ?? 0),
                'Outside selected scope / unattributed' => (int) ($report['financials']['unattributed_cents'] ?? 0),
            ], 'Automated'); ?>
            <?php $this->moneyTable('Grant-specific figures', [
                'Grant award' => (int) ($report['manual_financials']['grant_award_cents'] ?? 0),
                'Grant funds received' => (int) ($report['manual_financials']['grant_received_cents'] ?? 0),
                'Grant-funded expenses' => (int) ($report['manual_financials']['grant_expenses_cents'] ?? 0),
                'Remaining received balance' => (int) ($report['manual_financials']['grant_balance_cents'] ?? 0),
                'Other program income' => (int) ($report['manual_financials']['other_income_cents'] ?? 0),
                'In-kind value' => (int) ($report['manual_financials']['in_kind_cents'] ?? 0),
            ], 'Staff-entered'); ?>
          </div>

          <h2>Community and donor engagement</h2>
          <table class="widefat striped bg-compact"><tbody>
            <?php foreach ([
                'Active constituents now' => 'active_constituents_now',
                'New constituents in period' => 'new_constituents_in_period',
                'Monthly donors now' => 'monthly_donors_now',
                'Past donors now' => 'past_donors_now',
                'Never donated now' => 'never_donated_now',
                'Donor status not checked' => 'donor_status_not_checked',
            ] as $label => $key): ?>
              <tr><th><?php echo esc_html($label); ?></th><td><?php echo (int) ($report['community'][$key] ?? 0); ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <?php $this->countTable('Active newsletter subscriptions now', (array) ($report['community']['newsletter_subscribers_now'] ?? []), 0); ?>

          <h2>Data quality and reporting notes</h2>
          <ul><?php foreach ((array) ($report['quality'] ?? []) as $note) echo '<li>' . esc_html((string) $note) . '</li>'; ?></ul>
          <p class="description"><strong>Sources:</strong> <?php echo esc_html(implode('; ', array_map('strval', (array) ($report['sources'] ?? [])))); ?>.</p>
        </article>
        <?php
    }

    private function renderNarrativeEditor(string $narrative): void
    {
        if ($narrative !== '') {
            echo '<h2>Report narrative</h2><div class="bg-grant-narrative">' . wpautop(esc_html($narrative)) . '</div>';
        }
        ?>
        <details class="bg-grant-no-print"><summary><?php echo $narrative !== '' ? 'Edit report language' : 'Write report language manually'; ?></summary>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_grant_narrative'); ?><input type="hidden" name="action" value="bg_grant_narrative">
            <textarea name="narrative" rows="14" style="width:100%;max-width:1000px"><?php echo esc_textarea($narrative); ?></textarea>
            <p><button class="button">Keep this language in the preview</button></p>
          </form>
        </details>
        <?php
    }

    private function staffContext(array $config): void
    {
        $items = [
            'Grant objective' => 'objective', 'Activities and accomplishments' => 'activities',
            'Observed outcomes' => 'outcomes', 'Challenges or changes' => 'challenges',
            'Next steps' => 'next_steps', 'Additional notes' => 'notes',
        ];
        $shown = false;
        foreach ($items as $key) if (trim((string) ($config[$key] ?? '')) !== '') $shown = true;
        if (!$shown) return;
        echo '<section><h2>Staff context</h2><p class="description">Staff-entered</p>';
        foreach ($items as $label => $key) {
            $value = trim((string) ($config[$key] ?? ''));
            if ($value !== '') echo '<h3>' . esc_html($label) . '</h3>' . wpautop(esc_html($value));
        }
        echo '</section>';
    }

    private function renderSaved(ReportStore $store): void
    {
        $reports = $store->recent();
        echo '<h2>Saved report snapshots</h2>';
        if (!$reports) { echo '<p>No saved reports yet.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Report</th><th>Funder / reference</th><th>Period</th><th>Updated (UTC)</th><th></th></tr></thead><tbody>';
        foreach ($reports as $report) {
            $url = add_query_arg(['page' => self::PAGE, 'report' => (int) $report->id], admin_url('admin.php'));
            printf('<tr><td>%s</td><td>%s</td><td>%s to %s</td><td>%s</td><td><a class="button" href="%s">Open</a></td></tr>',
                esc_html((string) $report->title),
                esc_html(trim((string) $report->funder_name . ((string) $report->grant_reference !== '' ? ' / ' . (string) $report->grant_reference : '')) ?: '-'),
                esc_html((string) $report->period_start), esc_html((string) $report->period_end),
                esc_html((string) $report->updated_at), esc_url($url));
        }
        echo '</tbody></table>';
    }

    /** Render a granular time series while respecting snapshot-level suppression. */
    private function longitudinalTables(array $longitudinal): void
    {
        $buckets = (array) ($longitudinal['buckets'] ?? []);
        if (!$buckets) return;
        echo '<h2>Changes over time</h2><p class="description">Intervals: ' . esc_html((string) ($longitudinal['granularity'] ?? ''))
            . '. ' . esc_html((string) ($longitudinal['privacy_note'] ?? '')) . ' '
            . esc_html((string) ($longitudinal['profile_note'] ?? '')) . '</p>';
        echo '<table class="widefat striped bg-longitudinal"><thead><tr><th>Period</th><th>Events</th><th>Registrations</th><th>Unique participants</th><th>New</th><th>Returning</th><th>New/returning unknown</th><th>Change from prior interval</th></tr></thead><tbody>';
        foreach ($buckets as $bucket) {
            $changes = (array) ($bucket['changes'] ?? []);
            $change = 'Events ' . $this->signedChange($changes['events'] ?? null)
                . '; registrations ' . $this->signedChange($changes['registrations'] ?? null)
                . '; unique people ' . $this->signedChange($changes['unique_participants'] ?? null);
            echo '<tr><td>' . esc_html((string) ($bucket['label'] ?? $bucket['key'] ?? '')) . '</td><td>' . (int) ($bucket['events'] ?? 0)
                . '</td><td>' . esc_html($this->longitudinalDisplay($bucket['registrations'] ?? 0)) . '</td><td>' . esc_html((string) ($bucket['unique_participants']['display'] ?? '0'))
                . '</td><td>' . esc_html((string) ($bucket['new_participants']['display'] ?? '0'))
                . '</td><td>' . esc_html((string) ($bucket['returning_participants']['display'] ?? '0'))
                . '</td><td>' . esc_html((string) ($bucket['unknown_newcomer_participants']['display'] ?? '0'))
                . '</td><td>' . esc_html($change) . '</td></tr>';
        }
        echo '</tbody></table>';

        $sections = [
            'programs' => ['Program activity by interval', 'Program', 'events', 'registrations'],
            'event_regions' => ['Event-location activity by interval', 'Event region', 'events', 'registrations'],
        ];
        foreach ($sections as $key => [$title, $labelHeading, $aKey, $bKey]) {
            echo '<details class="bg-longitudinal-detail"><summary><strong>' . esc_html($title) . '</strong></summary>';
            echo '<table class="widefat striped"><thead><tr><th>Period</th><th>' . esc_html($labelHeading) . '</th><th>Events</th><th>Registrations</th></tr></thead><tbody>';
            $shown = false;
            foreach ($buckets as $bucket) foreach ((array) ($bucket[$key] ?? []) as $label => $row) {
                $shown = true;
                $displayLabel = $key === 'event_regions' && is_array($row) && isset($row['label']) ? (string) $row['label'] : (string) $label;
                if ($key === 'event_regions' && $displayLabel === (string) $label) {
                    $displayLabel = $label === 'unassigned' ? 'Unassigned / needs review' : RegionalGroups::label((string) $label);
                }
                echo '<tr><td>' . esc_html((string) ($bucket['label'] ?? '')) . '</td><td>' . esc_html($displayLabel)
                    . '</td><td>' . (int) ($row[$aKey] ?? 0) . '</td><td>' . esc_html($this->longitudinalDisplay($row[$bKey] ?? 0)) . '</td></tr>';
            }
            if (!$shown) echo '<tr><td colspan="4">No data in this scope.</td></tr>';
            echo '</tbody></table></details>';
        }

        echo '<details class="bg-longitudinal-detail"><summary><strong>Participant home region by interval</strong></summary><table class="widefat striped"><thead><tr><th>Period</th><th>Region</th><th>Unique participants</th></tr></thead><tbody>';
        foreach ($buckets as $bucket) foreach ((array) ($bucket['participant_regions'] ?? []) as $row) {
            echo '<tr><td>' . esc_html((string) ($bucket['label'] ?? '')) . '</td><td>' . esc_html((string) ($row['label'] ?? ''))
                . '</td><td>' . esc_html((string) ($row['participants']['display'] ?? '0')) . '</td></tr>';
        }
        echo '</tbody></table></details>';

        foreach (['age_ranges' => 'Age ranges by interval', 'demographics' => 'Demographic selections by interval'] as $key => $title) {
            echo '<details class="bg-longitudinal-detail"><summary><strong>' . esc_html($title) . '</strong></summary><table class="widefat striped"><thead><tr><th>Period</th><th>Category</th><th>Unique participants</th></tr></thead><tbody>';
            foreach ($buckets as $bucket) foreach ((array) ($bucket[$key] ?? []) as $label => $cell) {
                echo '<tr><td>' . esc_html((string) ($bucket['label'] ?? '')) . '</td><td>' . esc_html((string) $label)
                    . '</td><td>' . esc_html((string) ($cell['display'] ?? '0')) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
    }

    private function longitudinalDisplay(mixed $value): string
    {
        if (is_array($value)) return (string) ($value['display'] ?? '0');
        return (string) (int) $value;
    }

    private function signedChange(mixed $value): string
    {
        if (!is_int($value)) return 'n/a';
        return $value > 0 ? '+' . $value : (string) $value;
    }

    private function eventTable(array $events): void
    {
        if (!$events) { echo '<p>No events matched this report.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Date</th><th>Event region</th><th>Programs</th><th>Grant tags</th><th>Registrations</th><th>Unique identified</th><th>Fee registrations</th><th>Survey average</th></tr></thead><tbody>';
        foreach ($events as $event) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $event['title']), esc_html((string) $event['date']),
                esc_html((string) ($event['region_label'] ?? 'Unassigned / needs review')),
                esc_html(implode('; ', array_map('strval', (array) $event['program_tags'])) ?: 'Uncategorized'),
                esc_html(implode('; ', array_map('strval', (array) ($event['grant_tags'] ?? []))) ?: 'Not assigned'),
                (int) $event['registrations'], (int) $event['identified_unique_participants'],
                esc_html(\BosqueGrande\Core\Money::format((int) $event['registration_fee_cents'])),
                (int) $event['survey_scores'] > 0 ? esc_html(number_format((float) $event['survey_average'], 2) . ' / 5') : '-');
        }
        echo '</tbody></table>';
    }

    private function twoColumnTable(string $heading, string $aLabel, string $bLabel, array $rows, string $aKey, string $bKey, string $labelKey = ''): void
    {
        if (!$rows) return;
        echo '<table class="widefat striped bg-compact"><thead><tr><th>' . esc_html($heading) . '</th><th>' . esc_html($aLabel) . '</th><th>' . esc_html($bLabel) . '</th></tr></thead><tbody>';
        foreach ($rows as $key => $row) {
            $label = $labelKey !== '' ? (string) ($row[$labelKey] ?? $key) : (string) $key;
            printf('<tr><td>%s</td><td>%d</td><td>%d</td></tr>', esc_html($label), (int) ($row[$aKey] ?? 0), (int) ($row[$bKey] ?? 0));
        }
        echo '</tbody></table>';
    }

    private function countTable(string $title, array $rows, int $threshold): void
    {
        if (!$rows) return;
        echo '<div><h3>' . esc_html($title) . '</h3><table class="widefat striped bg-compact"><thead><tr><th>Category</th><th>Count</th></tr></thead><tbody>';
        foreach ($rows as $label => $count) {
            $display = $threshold > 0 && (int) $count > 0 && (int) $count < $threshold ? '&lt;' . $threshold : (string) (int) $count;
            echo '<tr><td>' . esc_html((string) $label) . '</td><td>' . $display . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function questionTable(array $questions): void
    {
        if (!$questions) { echo '<p>No survey responses in this scope.</p>'; return; }
        echo '<table class="widefat striped bg-compact"><thead><tr><th>Question</th><th>Responses</th><th>Average</th></tr></thead><tbody>';
        foreach ($questions as $row) printf('<tr><td>%s</td><td>%d</td><td>%s / 5</td></tr>',
            esc_html((string) $row['label']), (int) $row['responses'], esc_html(number_format((float) $row['average'], 2)));
        echo '</tbody></table>';
    }

    private function moneyTable(string $title, array $rows, string $source): void
    {
        echo '<div><h3>' . esc_html($title) . '</h3><p class="description">' . esc_html($source) . '</p><table class="widefat striped bg-compact"><tbody>';
        foreach ($rows as $label => $cents) {
            $negative = (int) $cents < 0;
            echo '<tr><th>' . esc_html((string) $label) . '</th><td>' . ($negative ? '−' : '') . esc_html(\BosqueGrande\Core\Money::format(abs((int) $cents))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function metric(string $label, string $value, string $note): void
    {
        echo '<div class="bg-metric"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong><small>' . esc_html($note) . '</small></div>';
    }

    private function progress(int $actual, int $target): string
    {
        return $target > 0 ? min(999, (int) round($actual / $target * 100)) . '% of target ' . $target : 'Automated';
    }

    private function moneyInput(string $name, string $label, int $cents): void
    {
        printf('<label>%s ($)<input type="number" min="0" step="0.01" name="%s" value="%s"></label>',
            esc_html($label), esc_attr($name), esc_attr(number_format($cents / 100, 2, '.', '')));
    }

    /** @param resource $out */
    private function summaryCsv($out, array $report, array $config): void
    {
        fputcsv($out, ['Section', 'Metric', 'Value', 'Source'], ',', '"', '');
        $row = static function (string $section, string $metric, string $value, string $source) use ($out): void {
            fputcsv($out, array_map([ConstituentStore::class, 'safeCsv'], [$section, $metric, $value, $source]), ',', '"', '');
        };
        $scope = (array) ($report['scope'] ?? []);
        $row('Scope', 'Participant home region', (string) ($scope['participant_region_label'] ?? $scope['region_label'] ?? 'All participant regions'), 'Automated');
        $row('Scope', 'Event location region', (string) ($scope['event_region_label'] ?? 'All event regions'), 'Automated');
        $row('Scope', 'Selected grant tags', implode('; ', array_map('strval', (array) ($scope['grant_tag_names'] ?? []))) ?: 'All grant tags', 'Automated');
        foreach ((array) ($report['summary'] ?? []) as $metric => $value) {
            $source = in_array($metric, ['verified_attendance', 'target_people', 'target_events', 'volunteer_hours'], true) ? 'Staff-entered' : 'Automated';
            $row('Key results', self::human((string) $metric), (string) $value, $source);
        }
        foreach ((array) ($report['programs'] ?? []) as $label => $value) {
            $row('Programs', (string) $label . ' (events)', (string) ($value['events'] ?? 0), 'Automated');
            $row('Programs', (string) $label . ' (registrations)', (string) ($value['registrations'] ?? 0), 'Automated');
        }
        foreach ((array) ($report['grants'] ?? []) as $label => $value) {
            $row('Grant tags', (string) $label . ' (events)', (string) ($value['events'] ?? 0), 'Automated');
            $row('Grant tags', (string) $label . ' (registrations)', (string) ($value['registrations'] ?? 0), 'Automated');
        }
        foreach ((array) ($report['event_regions'] ?? []) as $value) {
            $row('Event regions', (string) ($value['label'] ?? '') . ' (events)', (string) ($value['events'] ?? 0), 'Automated');
            $row('Event regions', (string) ($value['label'] ?? '') . ' (registrations)', (string) ($value['registrations'] ?? 0), 'Automated');
        }
        foreach ((array) ($report['regions'] ?? []) as $value) {
            $row('Participant regions', (string) ($value['label'] ?? '') . ' (unique people)', (string) ($value['participants'] ?? 0), 'Automated');
            $row('Participant regions', (string) ($value['label'] ?? '') . ' (registrations)', (string) ($value['registrations'] ?? 0), 'Automated');
        }
        $threshold = (int) ($config['privacy_threshold'] ?? 3);
        $demographics = (array) ($report['demographics'] ?? []);
        $base = (int) ($demographics['base_participants'] ?? 0);
        $hasSourceBreakdown = (int) ($report['schema'] ?? 1) >= 2;
        $ageSource = $hasSourceBreakdown
            ? (int) ($demographics['age_profile_sources'] ?? 0) . ' current profiles; ' . (int) ($demographics['age_historical_sources'] ?? 0) . ' historical fallbacks'
            : 'Refresh this saved report to calculate answer sources';
        $attributeSource = $hasSourceBreakdown
            ? (int) ($demographics['attributes_profile_sources'] ?? 0) . ' current profiles; ' . (int) ($demographics['attributes_historical_sources'] ?? 0) . ' historical fallbacks'
            : 'Refresh this saved report to calculate answer sources';
        $row('Demographic coverage', 'Age responses', (int) ($demographics['age_answered'] ?? 0) . ' of ' . $base, $ageSource);
        $row('Demographic coverage', 'Demographic responses', (int) ($demographics['attributes_answered'] ?? 0) . ' of ' . $base, $attributeSource);
        foreach (['age_ranges' => 'Age', 'attributes' => 'Demographics', 'relationships' => 'Relationship status', 'heard_about_events' => 'Outreach source'] as $key => $section) {
            foreach ((array) ($report['demographics'][$key] ?? []) as $label => $value) {
                $display = $threshold > 0 && $key !== 'heard_about_events' && (int) $value > 0 && (int) $value < $threshold ? '<' . $threshold : (string) (int) $value;
                $row($section, (string) $label, $display, 'Automated aggregate');
            }
        }
        foreach ((array) ($report['outcomes']['questions'] ?? []) as $value) {
            $row('Survey outcomes', (string) ($value['label'] ?? ''), number_format((float) ($value['average'] ?? 0), 2) . ' / 5 (' . (int) ($value['responses'] ?? 0) . ' responses)', 'Automated');
        }
        foreach ((array) ($report['financials'] ?? []) as $metric => $value) {
            $display = str_ends_with((string) $metric, '_cents') ? number_format((int) $value / 100, 2, '.', '') : (string) $value;
            $row('Local ledger', self::human((string) $metric), $display, 'Automated');
        }
        foreach ((array) ($report['manual_financials'] ?? []) as $metric => $value) {
            $row('Grant finances', self::human((string) $metric), number_format((int) $value / 100, 2, '.', ''), 'Staff-entered');
        }
        foreach ((array) ($report['community'] ?? []) as $metric => $value) {
            if (is_array($value)) {
                foreach ($value as $label => $count) $row('Newsletter', (string) $label, (string) $count, 'Automated current snapshot');
            } else $row('Community', self::human((string) $metric), (string) $value, 'Automated current snapshot');
        }
        $longitudinal = (array) ($report['longitudinal'] ?? []);
        $row('Longitudinal', 'Granularity', (string) ($longitudinal['granularity'] ?? ''), 'Automated');
        foreach ((array) ($longitudinal['buckets'] ?? []) as $bucket) {
            $period = (string) ($bucket['label'] ?? $bucket['key'] ?? '');
            $row('Longitudinal ' . $period, 'Events', (string) ($bucket['events'] ?? 0), 'Automated');
            $row('Longitudinal ' . $period, 'Registrations', $this->longitudinalDisplay($bucket['registrations'] ?? 0), 'Automated privacy-safe aggregate');
            foreach ([
                'unique_participants' => 'Unique participants',
                'new_participants' => 'New participants',
                'returning_participants' => 'Returning participants',
                'unknown_newcomer_participants' => 'New/returning unknown',
            ] as $key => $label) {
                $row('Longitudinal ' . $period, $label, (string) ($bucket[$key]['display'] ?? '0'), 'Automated privacy-safe aggregate');
            }
            foreach ((array) ($bucket['programs'] ?? []) as $label => $value) {
                $row('Longitudinal programs ' . $period, (string) $label . ' (events)', (string) ($value['events'] ?? 0), 'Automated');
                $row('Longitudinal programs ' . $period, (string) $label . ' (registrations)', $this->longitudinalDisplay($value['registrations'] ?? 0), 'Automated privacy-safe aggregate');
            }
            foreach ((array) ($bucket['participant_regions'] ?? []) as $value) {
                $row('Longitudinal participant regions ' . $period, (string) ($value['label'] ?? ''), (string) ($value['participants']['display'] ?? '0'), 'Automated privacy-safe aggregate');
            }
            foreach (['age_ranges' => 'Longitudinal age', 'demographics' => 'Longitudinal demographics'] as $key => $section) {
                foreach ((array) ($bucket[$key] ?? []) as $label => $cell) {
                    $row($section . ' ' . $period, (string) $label, (string) ($cell['display'] ?? '0'), 'Automated privacy-safe aggregate');
                }
            }
        }
        foreach ((array) ($report['quality'] ?? []) as $note) $row('Data quality', 'Note', (string) $note, 'System disclosure');
    }

    /** @param resource $out */
    private function eventCsv($out, array $report): void
    {
        fputcsv($out, ['event_id', 'event', 'date', 'status', 'event_region_slug', 'event_region', 'program_tags', 'grant_tags', 'registrations', 'identified_unique_participants', 'unlinked_registrations', 'volunteer_interest_people', 'registration_fee_usd', 'survey_scores', 'survey_average'], ',', '"', '');
        foreach ((array) ($report['events'] ?? []) as $event) {
            $values = [
                (string) ($event['id'] ?? ''), (string) ($event['title'] ?? ''), (string) ($event['date'] ?? ''),
                (string) ($event['status'] ?? ''), (string) ($event['region_slug'] ?? ''),
                (string) ($event['region_label'] ?? 'Unassigned / needs review'),
                implode('; ', array_map('strval', (array) ($event['program_tags'] ?? []))),
                implode('; ', array_map('strval', (array) ($event['grant_tags'] ?? []))),
                (string) ($event['registrations'] ?? 0), (string) ($event['identified_unique_participants'] ?? 0),
                (string) ($event['unlinked_registrations'] ?? 0), (string) ($event['volunteer_interest_people'] ?? 0),
                number_format((int) ($event['registration_fee_cents'] ?? 0) / 100, 2, '.', ''),
                (string) ($event['survey_scores'] ?? 0), number_format((float) ($event['survey_average'] ?? 0), 2, '.', ''),
            ];
            fputcsv($out, array_map([ConstituentStore::class, 'safeCsv'], $values), ',', '"', '');
        }
    }

    private function guard(string $nonce, string $method = 'post'): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        if ($method === 'get') check_admin_referer($nonce);
        else check_admin_referer($nonce);
    }

    /** @return array<string, mixed>|null */
    private function loadPreview(Container $container): ?array
    {
        $userId = get_current_user_id();
        if ($userId < 1 || !$container->get('encryption')->available()) return null;
        $stored = get_transient('bg_grant_preview_' . $userId);
        if (!is_string($stored) || $stored === '') return null;
        $plain = $container->get('encryption')->decryptOrNull($stored, 'grant-preview|' . $userId);
        $decoded = is_string($plain) ? json_decode($plain, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private function storePreview(Container $container, array $preview): bool
    {
        $userId = get_current_user_id();
        $json = wp_json_encode($preview, JSON_UNESCAPED_SLASHES);
        if ($userId < 1 || !is_string($json)) return false;
        $encrypted = $container->get('encryption')->encrypt($json, 'grant-preview|' . $userId);
        if ($encrypted === '' || $encrypted === $json) return false;
        $key = 'bg_grant_preview_' . $userId;
        $stored = set_transient($key, $encrypted, self::PREVIEW_TTL);
        return $stored || hash_equals($encrypted, (string) get_transient($key));
    }

    private function redirect(int $reportId, string $notice): void
    {
        $args = ['page' => self::PAGE, 'notice' => $notice];
        if ($reportId > 0) $args['report'] = $reportId;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function notice(string $code): void
    {
        $messages = [
            'prepared' => ['success', 'The report preview was prepared from current BosqueGrande data.'],
            'saved' => ['success', 'The encrypted report snapshot was saved.'],
            'drafted' => ['success', 'Draft report language was added to the preview. Review it before saving.'],
            'narrative-saved' => ['success', 'Your report language was kept in the preview.'],
            'missing-preview' => ['error', 'The report preview expired. Prepare it again.'],
            'save-failed' => ['error', 'The report could not be saved. Check System Status and the encryption key.'],
            'draft-disabled' => ['warning', 'Grant report drafting is switched off under Assisted Tools.'],
            'draft-failed' => ['error', 'Report language could not be drafted. The deterministic report remains available.'],
        ];
        if (!isset($messages[$code])) return;
        echo '<div class="notice notice-' . esc_attr($messages[$code][0]) . '"><p>' . esc_html($messages[$code][1]) . '</p></div>';
    }

    private static function human(string $key): string
    {
        return ucwords(str_replace('_', ' ', preg_replace('/_cents$/', '', $key)));
    }

    private function styles(): void
    {
        ?>
        <style>
          .bg-grant-builder{max-width:1100px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:0;margin:16px 0;overflow:hidden}
          .bg-grant-builder-header{padding:20px 22px;background:#f6f7f7;border-bottom:1px solid #dcdcde}.bg-grant-builder-header h2{margin:0 0 6px}.bg-grant-builder-header p{margin:0;max-width:900px}
          .bg-grant-step{padding:20px 22px;border-bottom:1px solid #dcdcde}.bg-grant-step-heading{display:flex;align-items:flex-start;gap:12px}.bg-grant-step-heading>span{display:flex;align-items:center;justify-content:center;flex:0 0 30px;height:30px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700}.bg-grant-step-heading h3{margin:2px 0 3px;font-size:17px}.bg-grant-step-heading p{margin:0;color:#50575e}
          .bg-grant-grid,.bg-grant-text-grid,.bg-grant-columns{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:14px;margin:16px 0}.bg-grant-wide{grid-column:1/-1}
          .bg-grant-grid label,.bg-grant-text-grid label{display:flex;flex-direction:column;gap:5px;font-weight:600}.bg-grant-grid input,.bg-grant-grid select,.bg-grant-text-grid textarea{width:100%;max-width:none}.bg-optional-label{font-weight:400;color:#646970;font-size:12px}
          .bg-grant-scope-layout{display:grid;grid-template-columns:minmax(0,3fr) minmax(260px,2fr);gap:14px;margin-top:16px}.bg-grant-scope-layout fieldset{min-width:0;border:1px solid #c3c4c7;border-radius:6px;padding:14px}.bg-grant-scope-layout legend{font-weight:700;padding:0 4px}.bg-inline{display:block!important;font-weight:400!important;margin:7px 0}
          .bg-grant-picker{margin-top:12px}.bg-grant-search-label{display:flex;flex-direction:column;gap:5px;font-weight:600}.bg-grant-search-label input{width:100%}.bg-grant-checklist{max-height:290px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;background:#fff;margin:8px 0;padding:4px}.bg-grant-program-list{max-height:340px}.bg-grant-check-item{display:flex!important;flex-direction:row!important;align-items:flex-start;gap:8px!important;font-weight:400!important;padding:7px 8px;border-bottom:1px solid #f0f0f1}.bg-grant-check-item:last-child{border-bottom:0}.bg-grant-check-item:hover{background:#f6f7f7}.bg-grant-check-item input{flex:0 0 auto;margin-top:2px}.bg-grant-check-item[hidden]{display:none!important}
          .bg-grant-primary-action{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:20px 0 0}.bg-grant-primary-action span{color:#50575e}.bg-grant-optional{margin:0}.bg-grant-optional summary{cursor:pointer;padding:18px 22px;font-size:15px;background:#f6f7f7}.bg-grant-optional summary span{display:block;margin-top:3px;font-size:13px;font-weight:400;color:#50575e}.bg-grant-optional-body{padding:6px 22px 22px}
          .bg-grant-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:18px 0}.bg-grant-actions form{margin:0}
          .bg-grant-report{max-width:1100px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;margin:16px 0}.bg-grant-report>header{border-bottom:3px solid #2E7D4F;margin-bottom:20px}.bg-grant-report h2{margin-top:28px}
          .bg-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.bg-metric{background:#f3f8f3;border:1px solid #d9e5d9;border-radius:7px;padding:12px}.bg-metric span,.bg-metric small{display:block}.bg-metric strong{display:block;font-size:24px;margin:4px 0}.bg-metric small{color:#555}.bg-demographic-coverage{grid-template-columns:repeat(2,minmax(220px,360px));margin:14px 0}
          .bg-compact{max-width:700px;margin:10px 0}.bg-grant-columns>div{min-width:0}.bg-grant-narrative{max-width:900px;border-left:4px solid #2E7D4F;padding:4px 16px;background:#f8fbf8}
          .bg-longitudinal{margin:12px 0 18px}.bg-longitudinal-detail{margin:10px 0;border:1px solid #dcdcde;border-radius:6px;padding:0}.bg-longitudinal-detail summary{cursor:pointer;padding:11px 13px;background:#f6f7f7}.bg-longitudinal-detail table{border:0}
          @media(max-width:800px){.bg-grant-grid,.bg-grant-text-grid,.bg-grant-columns,.bg-grant-scope-layout,.bg-demographic-coverage{grid-template-columns:1fr}.bg-grant-wide{grid-column:auto}}
          @media print{#wpadminbar,#adminmenumain,#wpfooter,.notice,.bg-grant-no-print{display:none!important}html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}.wrap{margin:0!important}.bg-grant-report{border:0;padding:0;max-width:none}.bg-grant-report table{break-inside:avoid}.bg-grant-report h2{break-after:avoid}}
        </style>
        <?php
    }
}
