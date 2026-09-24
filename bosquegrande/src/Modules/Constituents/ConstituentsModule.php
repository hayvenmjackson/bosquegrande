<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\MonthlyDonor;
use BosqueGrande\Modules\Registration\PrefillResolver;
use BosqueGrande\Modules\Registration\SurveyFields;

if (!defined('ABSPATH')) exit;

/**
 * Constituents: the CRM screens over the encrypted store, plus the
 * listeners that turn registrations and payments into a living
 * timeline. Every screen is manage_options: volunteers see events and
 * photos, never people data.
 */
final class ConstituentsModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-constituents';
    private const REGION_BACKFILL_JOB = 'constituent_region_backfill';
    private const DONOR_REFRESH_JOB = 'constituent_donor_refresh';

    /**
     * CSV header aliases, keyed by sanitize_key() output, so exports
     * from LGL and friends import without hand-renaming columns
     * ("First name" arrives as firstname, "Postal code" as postalcode).
     */
    private const CSV_ALIASES = [
        'first_name' => 'first_name', 'firstname' => 'first_name', 'first' => 'first_name',
        'last_name' => 'last_name', 'lastname' => 'last_name', 'last' => 'last_name', 'surname' => 'last_name',
        'email' => 'email', 'emailaddress' => 'email', 'e-mail' => 'email', 'email_address' => 'email',
        'phone' => 'phone', 'phonenumber' => 'phone', 'phone_number' => 'phone', 'mobile' => 'phone', 'telephone' => 'phone',
        'address1' => 'address1', 'address' => 'address1', 'street' => 'address1', 'streetaddress' => 'address1', 'street_address' => 'address1',
        'address2' => 'address2', 'address_2' => 'address2',
        'city' => 'city',
        'state' => 'state', 'province' => 'state', 'stateprovince' => 'state',
        'postal' => 'postal', 'postalcode' => 'postal', 'postal_code' => 'postal', 'zip' => 'postal', 'zipcode' => 'postal', 'zip_code' => 'postal',
        'notes' => 'notes',
        'tags' => 'tags', 'groups' => 'tags',
        'demographic' => 'demographic', 'demographics' => 'demographic', 'diversity' => 'demographic',
        'demographicsdiversity' => 'demographic', 'demographicdiversity' => 'demographic', 'diversitydemographics' => 'demographic',
        // A bare "Age" column is a numeric age, not the categorical "Age
        // Range" answer, so it is deliberately not an alias here.
        'agerange' => 'age_range', 'age_range' => 'age_range', 'agegroup' => 'age_range', 'agebracket' => 'age_range',
        'relationshipstatus' => 'relationship_status', 'relationship_status' => 'relationship_status', 'relationship' => 'relationship_status',
        'maritalstatus' => 'relationship_status', 'marital_status' => 'relationship_status',
        'howdidyouhearaboutthisevent' => 'heard',
        'promotionconsent' => 'promote',
        'monthlydonor' => 'monthly_donor', 'monthly_donor' => 'monthly_donor',
        'pastevents' => 'past_events', 'past_events' => 'past_events',
        'pasteventattendance' => 'past_events', 'attendedeventslist' => 'past_events',
        'attendedevents' => 'past_events', 'eventsattended' => 'past_events',
        'eventattendance' => 'past_events', 'eventhistory' => 'past_events',
    ];

    /** @param list<string> $rawHeader @return list<string> Canonical field per column ('' when unrecognized). */
    public static function mapCsvHeader(array $rawHeader): array
    {
        return array_map(static function ($header): string {
            $header = (string) $header;
            $mapped = self::CSV_ALIASES[sanitize_key($header)] ?? '';
            if ($mapped !== '') return $mapped;
            $normalized = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($header)));
            if (str_contains($normalized, 'monthlydonor')) return 'monthly_donor';
            foreach (ParticipationHistory::sourceNames() as $name) {
                $wanted = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($name)));
                if ($wanted !== '' && str_contains($normalized, $wanted)) return 'past_events';
            }
            return '';
        }, $rawHeader);
    }
    private const DUPES_OPTION = 'bg_dupe_pairs';

    public function name(): string
    {
        return 'constituents';
    }

    public function label(): string
    {
        return 'Constituents (people records)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->set('constituents.activity', fn(Container $c) => new ActivityLog($c->get('logger')));
        $container->set('constituents.store', fn(Container $c) => new ConstituentStore(
            $c->get('encryption'), $c->get('logger'), $c->get('constituents.activity')
        ));
        // The fuzzy duplicate finder and its mass-merge screen.
        $container->set('constituents.dedup', fn(Container $c) => new DedupFinder(
            $c->get('constituents.store'), $c->get('logger')
        ));
        $container->set('constituents.regions', fn(Container $c) => new RegionalGroups(
            $c->get('constituents.store'), $c->get('settings'), $c->get('ai'), $c->get('logger')
        ));
        $container->set('constituents.donor_status', fn(Container $c) => new DonorStatus(
            $c->get('constituents.store'), $c->get('logger')
        ));
        (new DedupScreen($container))->register();

        add_filter('bg_ai_features', function (array $features): array {
            $features['regional_matching'] = [
                'label' => 'Regional matching help',
                'description' => 'When postal, exact-town, and close-spelling rules cannot safely place a constituent, make a constrained judgment from city, state, and postal code. It can choose only an approved regional group; names, street addresses, emails, and demographic answers are never sent.',
            ];
            return $features;
        });

        // Address changes re-run only automatic assignments. A staff
        // override remains in force until the profile is returned to Auto.
        add_action('bg_constituent_saved', function ($id) use ($container): void {
            $container->get('constituents.regions')->assign((int) $id);
        }, 20, 1);
        add_action('bg_lgl_pulled_constituent', function ($id, $remote) use ($container): void {
            $container->get('constituents.regions')->assign((int) $id);
            if (is_array($remote)) {
                $container->get('constituents.donor_status')->learnFromRemote((int) $id, $remote);
            }
        }, 20, 2);

        $container->get('jobs')->register(self::REGION_BACKFILL_JOB, function () use ($container): void {
            delete_transient('bg_constituent_region_backfill_queued');
            $this->processRegionBatch($container, 50, true);
        });
        $container->get('jobs')->register(self::DONOR_REFRESH_JOB, function (array $args) use ($container): void {
            /** @var DonorStatus $donors */
            $donors = $container->get('constituents.donor_status');
            $offset = max(0, (int) ($args['offset'] ?? 0));
            $checked = max(0, (int) ($args['checked'] ?? 0));
            $errors = max(0, (int) ($args['errors'] ?? 0));
            $ids = $donors->linkedIds($offset, 5);
            foreach ($ids as $id) {
                $result = $donors->refreshOne($id, $container->get('lgl'));
                if ($result === null) $errors++;
                else $checked++;
            }
            if (count($ids) === 5) {
                $queued = $container->get('jobs')->enqueue(self::DONOR_REFRESH_JOB, [
                    'offset' => $offset + 5,
                    'checked' => $checked,
                    'errors' => $errors,
                ], 15);
                if ($queued) return;
                $errors++;
            }
            update_option('bg_donor_history_refresh_result', [
                'checked' => $checked,
                'errors' => $errors,
                'finished_at' => current_time('mysql', true),
            ], false);
            delete_transient('bg_donor_history_refresh_running');
            $container->get('logger')->info('constituents', 'LGL donation-history refresh finished.', [
                'checked' => $checked,
                'errors' => $errors,
            ]);
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Constituents', 'Constituents',
                'manage_options', self::PAGE, [$this, 'renderPage']);
        }, 12);

        // Index-key change detection is retained for host-directed recovery.
        // Normal data-key rotation deliberately keeps the separate index
        // key stable, so matching and suppression entries do not change.
        add_action('admin_init', function () use ($container): void {
            /** @var \BosqueGrande\Core\Encryption $encryption */
            $encryption = $container->get('encryption');
            if (!$encryption->available()) return;
            $fingerprint = $encryption->indexFingerprint();
            $stored = (string) get_option('bg_bidx_fingerprint', '');
            if ($stored === $fingerprint) return;
            if ($stored === '') {
                update_option('bg_bidx_fingerprint', $fingerprint, false);
                return;
            }
            $rebuilt = $container->get('constituents.store')->rebuildIndexes();
            update_option('bg_bidx_fingerprint', $fingerprint, false);
            $container->get('logger')->warn('constituents', sprintf(
                'The blind-index key changed during key recovery. %d constituent indexes were rebuilt automatically. Suppression-list entries made under the former key can no longer match and remain only as inert history.',
                $rebuilt
            ));
        });

        add_action('admin_init', function () use ($container): void {
            if ((int) get_option('bg_constituent_region_backfill_pending', 0) !== 1) return;
            if (get_transient('bg_constituent_region_backfill_queued')) return;
            set_transient('bg_constituent_region_backfill_queued', 1, 10 * MINUTE_IN_SECONDS);
            if (!$container->get('jobs')->enqueue(self::REGION_BACKFILL_JOB, [], 2)) {
                delete_transient('bg_constituent_region_backfill_queued');
            }
        });

        foreach (['save', 'anon', 'merge', 'dedupe', 'import', 'export', 'field_add', 'bulk_merge', 'bulk_delete', 'donor_refresh', 'region_refresh'] as $op) {
            add_action('admin_post_bg_const_' . $op, function () use ($container, $op): void {
                $method = 'handle' . str_replace(' ', '', ucwords(str_replace('_', ' ', $op)));
                $this->{$method}($container);
            });
        }

        // Registrations and payments write to the local record. Fired by
        // the Registration and Payments modules; the store fills only
        // blank fields, so curated data is never overwritten.
        add_action('bg_registration_completed', function ($email, $first, $eventTitle, $tier, $localId) use ($container): void {
            $this->onTouch($container, (string) $email, (string) $first,
                'registration', 'Registered: ' . $eventTitle . ' (' . $tier . ')', (int) $localId);
        }, 10, 5);
        add_action('bg_payment_completed', function ($email, $first, $eventTitle, $amountCents) use ($container): void {
            $this->onTouch($container, (string) $email, (string) $first,
                'payment', sprintf('Paid %s: %s', \BosqueGrande\Core\Money::format((int) $amountCents), $eventTitle), 0);
        }, 10, 4);
    }

    private function onTouch(Container $container, string $email, string $first, string $kind, string $summary, int $localId): void
    {
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        if (!$store->available() || !is_email($email)) return;
        $record = $localId > 0 ? $store->get($localId) : null;
        $id = $record && ($record['status'] ?? '') === 'active'
            ? $localId
            : $store->upsertByEmail($email, ['first_name' => $first]);
        if (!$id) return;
        $container->get('constituents.activity')->append($id, $kind, $summary);
    }

    /**
     * Process a bounded regional batch. The Constituents screen calls a small
     * batch too, so blocked WP-Cron can delay but can no longer prevent the
     * automatic classification from making progress.
     * @return array{processed:int,errors:int,remaining:bool,queued:bool}
     */
    private function processRegionBatch(Container $container, int $limit, bool $queueNext): array
    {
        /** @var RegionalGroups $regions */
        $regions = $container->get('constituents.regions');
        $ids = $regions->pendingIds($limit);
        $processed = 0;
        $errors = 0;
        foreach ($ids as $id) {
            if ($regions->assign($id)) $processed++;
            else $errors++;
        }
        $remaining = $regions->pendingIds(1) !== [];
        $queued = false;
        if ($remaining) {
            update_option('bg_constituent_region_backfill_pending', 1, false);
            // Do not create a self-sustaining failure loop when every write in
            // a batch failed. The admin screen will retry and show the error.
            if ($queueNext && $processed > 0) {
                set_transient('bg_constituent_region_backfill_queued', 1, 10 * MINUTE_IN_SECONDS);
                $queued = $container->get('jobs')->enqueue(self::REGION_BACKFILL_JOB, [], 10);
                if (!$queued) delete_transient('bg_constituent_region_backfill_queued');
            }
        } else {
            update_option('bg_constituent_region_backfill_pending', 0, false);
            delete_transient('bg_constituent_region_backfill_queued');
        }
        update_option('bg_constituent_region_refresh_result', [
            'processed' => $processed,
            'errors' => $errors,
            'remaining' => $remaining ? 1 : 0,
            'updated_at' => current_time('mysql', true),
        ], false);
        return compact('processed', 'errors', 'remaining', 'queued');
    }

    /* ── Screen routing ───────────────────────────────────────────── */

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';

        echo '<div class="wrap"><h1>Constituents';
        printf(' <a href="%s" class="page-title-action">Add New</a>',
            esc_url(admin_url('admin.php?page=' . self::PAGE . '&view=edit')));
        echo '</h1>';
        if ($notice) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        if (!$store->available()) {
            echo '<div class="notice notice-error"><p><strong>Encryption is unavailable on this server (libsodium missing), so constituent records cannot be created or edited.</strong> Constituent data is never stored unencrypted; ask your host to enable the sodium PHP extension.</p></div></div>';
            return;
        }
        $this->renderTabs($tab);
        if ($tab === 'fields') {
            $this->renderFields($store);
        } elseif ($tab === 'duplicates') {
            $this->renderDuplicates($store);
        } elseif ($tab === 'data-tools') {
            $this->renderDataTools($container);
        } elseif (($_GET['view'] ?? '') === 'edit') {
            $this->renderEdit($container, $store, (int) ($_GET['id'] ?? 0));
        } else {
            $this->renderList($container, $store);
        }
        echo '</div>';
    }

    private function renderTabs(string $active): void
    {
        $tabs = [
            '' => 'People',
            'duplicates' => 'Possible duplicates',
            'fields' => 'Custom fields',
            'data-tools' => 'Data Tools',
        ];
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            printf('<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::PAGE . ($key ? '&tab=' . $key : ''))),
                $active === $key ? 'nav-tab-active' : '',
                esc_html($label));
        }
        echo '</nav>';
    }

    /* ── List ─────────────────────────────────────────────────────── */

    private function renderList(Container $container, ConstituentStore $store): void
    {
        $monthlyFieldId = $store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        $store->ensureField(CoreFields::PAST_EVENTS, ParticipationHistory::LABEL, false);
        $searchInput = trim(sanitize_text_field((string) ($_GET['s'] ?? '')));
        $search = strtolower($searchInput);
        $donorFilter = sanitize_key((string) ($_GET['donor_status'] ?? ''));
        if (!in_array($donorFilter, [DonorStatus::MONTHLY, DonorStatus::PAST, DonorStatus::NEVER, DonorStatus::UNKNOWN], true)) {
            $donorFilter = '';
        }
        $regionFilter = sanitize_key((string) ($_GET['region'] ?? ''));
        if (!isset(RegionalGroups::regions()[$regionFilter])) $regionFilter = '';
        $membershipInput = $_GET['monthly_donor'] ?? '';
        $membershipFilter = is_scalar($membershipInput)
            ? sanitize_key((string) wp_unslash($membershipInput))
            : '';
        if (!in_array($membershipFilter, ['yes', 'no', 'unknown'], true)) $membershipFilter = '';
        $pastEventModeInput = $_GET['past_event_mode'] ?? 'at_least';
        $pastEventMode = is_scalar($pastEventModeInput)
            ? sanitize_key((string) wp_unslash($pastEventModeInput))
            : 'at_least';
        if (!in_array($pastEventMode, ['at_least', 'exactly', 'at_most'], true)) {
            $pastEventMode = 'at_least';
        }
        $pastEventInput = $_GET['past_event_count'] ?? '';
        $pastEventRaw = is_scalar($pastEventInput)
            ? trim(sanitize_text_field((string) wp_unslash($pastEventInput)))
            : '';
        $pastEventTarget = $pastEventRaw !== '' && ctype_digit($pastEventRaw)
            ? max(0, (int) $pastEventRaw)
            : null;
        /** @var RegionalGroups $regions */
        $regions = $container->get('constituents.regions');
        if ((int) get_option('bg_constituent_region_backfill_pending', 0) === 1
            || $regions->pendingIds(1) !== []
        ) {
            $this->processRegionBatch($container, 10, false);
        }
        $records = $store->allDecrypted();
        if ($search !== '') {
            $records = array_values(array_filter($records, static function (array $r) use ($search): bool {
                $haystack = strtolower(implode(' ', [
                    (string) ($r['id'] ?? ''),
                    (string) ($r['lgl_constituent_id'] ?? ''),
                    (string) ($r['first_name'] ?? ''),
                    (string) ($r['last_name'] ?? ''),
                    (string) ($r['email'] ?? ''),
                    (string) ($r['phone'] ?? ''),
                    (string) ($r['address1'] ?? ''),
                    (string) ($r['address2'] ?? ''),
                    (string) ($r['city'] ?? ''),
                    (string) ($r['state'] ?? ''),
                    (string) ($r['postal'] ?? ''),
                ]));
                return str_contains($haystack, $search);
            }));
        }
        /** @var DonorStatus $donors */
        $donors = $container->get('constituents.donor_status');
        $donorStatuses = $donors->statusesFor($records, $monthlyFieldId);
        $recordIds = array_map(static fn(array $r): int => (int) $r['id'], $records);
        $contactSharing = $store->contactSharingMap($recordIds);
        $regionMap = $regions->mapFor($recordIds);
        $pastEventCounts = PastEventCounts::forConstituents($recordIds, $store);
        $membershipValues = [];
        foreach ($recordIds as $recordId) {
            $values = $store->valuesFor($recordId);
            $monthly = $monthlyFieldId > 0
                ? CoreFields::normalizeMonthlyDonor($values[$monthlyFieldId] ?? '')
                : '';
            $membershipValues[$recordId] = $monthly === 'Yes'
                ? 'yes'
                : ($monthly === 'No' ? 'no' : 'unknown');
        }
        if ($donorFilter !== '') {
            $records = array_values(array_filter($records, static fn(array $r): bool =>
                ($donorStatuses[(int) $r['id']] ?? DonorStatus::UNKNOWN) === $donorFilter
            ));
        }
        if ($regionFilter !== '') {
            $records = array_values(array_filter($records, static fn(array $r): bool =>
                ($regionMap[(int) $r['id']] ?? '') === $regionFilter
            ));
        }
        if ($membershipFilter !== '') {
            $records = array_values(array_filter($records, static fn(array $r): bool =>
                ($membershipValues[(int) $r['id']] ?? 'unknown') === $membershipFilter
            ));
        }
        if ($pastEventTarget !== null) {
            $records = array_values(array_filter($records, static function (array $r) use (
                $pastEventCounts, $pastEventMode, $pastEventTarget
            ): bool {
                $count = (int) ($pastEventCounts[(int) $r['id']] ?? 0);
                if ($pastEventMode === 'exactly') return $count === $pastEventTarget;
                if ($pastEventMode === 'at_most') return $count <= $pastEventTarget;
                return $count >= $pastEventTarget;
            }));
        }
        usort($records, static fn(array $a, array $b): int =>
            strcmp(strtolower($a['last_name'] . $a['first_name']), strtolower($b['last_name'] . $b['first_name'])));

        $peopleUrl = static fn(array $args = []): string => add_query_arg(
            ['page' => self::PAGE] + $args,
            admin_url('admin.php')
        );
        $currentArgs = [];
        if ($search !== '') $currentArgs['s'] = $searchInput;
        if ($donorFilter !== '') $currentArgs['donor_status'] = $donorFilter;
        if ($regionFilter !== '') $currentArgs['region'] = $regionFilter;
        if ($membershipFilter !== '') $currentArgs['monthly_donor'] = $membershipFilter;
        if ($pastEventTarget !== null) {
            $currentArgs['past_event_mode'] = $pastEventMode;
            $currentArgs['past_event_count'] = (string) $pastEventTarget;
        }
        $filtersActive = $currentArgs !== [];
        $advancedActive = $donorFilter !== '' || $regionFilter !== ''
            || $membershipFilter !== '' || $pastEventTarget !== null;
        $quickActive = 'all';
        if ($search === '' && $regionFilter === '' && $membershipFilter === '' && $pastEventTarget === null) {
            $quickActive = match ($donorFilter) {
                DonorStatus::MONTHLY => 'monthly',
                DonorStatus::PAST => 'past',
                DonorStatus::NEVER => 'never',
                DonorStatus::UNKNOWN => 'review',
                default => 'all',
            };
        } elseif ($search === '' && $donorFilter === '' && $regionFilter === ''
            && $membershipFilter === '' && $pastEventTarget === 3 && $pastEventMode === 'at_least'
        ) {
            $quickActive = 'frequent';
        } else {
            $quickActive = '';
        }
        $removeUrl = static function (array $keys) use ($currentArgs, $peopleUrl): string {
            return $peopleUrl(array_diff_key($currentArgs, array_fill_keys($keys, true)));
        };
        $donorLabels = [
            DonorStatus::MONTHLY => 'Monthly donor',
            DonorStatus::PAST => 'Past donor',
            DonorStatus::NEVER => 'Never donated',
            DonorStatus::UNKNOWN => 'Not checked yet',
        ];
        $membershipLabels = ['yes' => 'Yes', 'no' => 'No', 'unknown' => 'Not recorded'];
        $eventModeLabels = ['at_least' => 'At least', 'exactly' => 'Exactly', 'at_most' => 'At most'];
        ?>
        <section class="bg-people-finder" aria-labelledby="bg-find-people-title">
            <h2 id="bg-find-people-title">Find people</h2>
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <div class="bg-people-search-row">
                    <label class="screen-reader-text" for="bg-people-search">Find a person</label>
                    <input id="bg-people-search" type="search" name="s" value="<?php echo esc_attr($searchInput); ?>"
                        placeholder="Name, email, phone, address, record ID, or LGL ID">
                    <button class="button button-primary">Search</button>
                </div>

                <div class="bg-quick-views" aria-label="Quick views">
                    <span class="bg-filter-label">Quick views</span>
                    <a class="button <?php echo $quickActive === 'all' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl()); ?>">All people</a>
                    <a class="button <?php echo $quickActive === 'monthly' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl(['donor_status' => DonorStatus::MONTHLY])); ?>">Monthly donors</a>
                    <a class="button <?php echo $quickActive === 'past' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl(['donor_status' => DonorStatus::PAST])); ?>">Past donors</a>
                    <a class="button <?php echo $quickActive === 'never' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl(['donor_status' => DonorStatus::NEVER])); ?>">Never donated</a>
                    <a class="button <?php echo $quickActive === 'review' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl(['donor_status' => DonorStatus::UNKNOWN])); ?>">Needs review</a>
                    <a class="button <?php echo $quickActive === 'frequent' ? 'is-active' : ''; ?>" href="<?php echo esc_url($peopleUrl(['past_event_mode' => 'at_least', 'past_event_count' => '3'])); ?>">3+ events</a>
                </div>

                <details class="bg-more-filters" <?php echo $advancedActive ? 'open' : ''; ?>>
                    <summary>More filters<?php echo $advancedActive ? ' (in use)' : ''; ?></summary>
                    <div class="bg-filter-grid">
                        <fieldset class="bg-filter-group">
                            <legend>Giving</legend>
                            <label>Current monthly status
                                <select name="monthly_donor">
                                    <option value="">Any status</option>
                                    <option value="yes" <?php selected($membershipFilter, 'yes'); ?>>Yes</option>
                                    <option value="no" <?php selected($membershipFilter, 'no'); ?>>No</option>
                                    <option value="unknown" <?php selected($membershipFilter, 'unknown'); ?>>Not recorded</option>
                                </select>
                            </label>
                            <label>Donation history
                                <select name="donor_status">
                                    <option value="">Any history</option>
                                    <option value="monthly" <?php selected($donorFilter, DonorStatus::MONTHLY); ?>>Monthly donor</option>
                                    <option value="past" <?php selected($donorFilter, DonorStatus::PAST); ?>>Donated in the past</option>
                                    <option value="never" <?php selected($donorFilter, DonorStatus::NEVER); ?>>No donations found</option>
                                    <option value="unknown" <?php selected($donorFilter, DonorStatus::UNKNOWN); ?>>Not checked yet</option>
                                </select>
                            </label>
                        </fieldset>
                        <fieldset class="bg-filter-group">
                            <legend>Participation</legend>
                            <span class="bg-filter-label">Past events attended</span>
                            <div class="bg-event-filter-row">
                                <select name="past_event_mode" aria-label="Past-event comparison">
                                    <option value="at_least" <?php selected($pastEventMode, 'at_least'); ?>>At least</option>
                                    <option value="exactly" <?php selected($pastEventMode, 'exactly'); ?>>Exactly</option>
                                    <option value="at_most" <?php selected($pastEventMode, 'at_most'); ?>>At most</option>
                                </select>
                                <input type="number" min="0" step="1" name="past_event_count"
                                    value="<?php echo esc_attr($pastEventTarget !== null ? (string) $pastEventTarget : ''); ?>"
                                    placeholder="Number" aria-label="Number of past events">
                            </div>
                            <p class="description">Counts distinct past site events and imported LGL Past Events. Duplicate titles count once.</p>
                        </fieldset>
                        <fieldset class="bg-filter-group">
                            <legend>Location</legend>
                            <label>Regional group
                                <select name="region">
                                    <option value="">Any region</option>
                                    <?php foreach (RegionalGroups::regions() as $slug => $label): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($regionFilter, $slug); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </fieldset>
                    </div>
                    <div class="bg-filter-actions">
                        <button class="button button-primary">Apply filters</button>
                        <?php if ($filtersActive): ?>
                            <a class="button" href="<?php echo esc_url($peopleUrl()); ?>">Clear all</a>
                        <?php endif; ?>
                    </div>
                </details>
            </form>
        </section>

        <?php if ($filtersActive): ?>
            <div class="bg-active-filters" aria-label="Active filters">
                <span class="bg-filter-label">Showing</span>
                <?php if ($search !== ''): ?><a href="<?php echo esc_url($removeUrl(['s'])); ?>">Search: <?php echo esc_html($searchInput); ?> <span aria-hidden="true">×</span></a><?php endif; ?>
                <?php if ($donorFilter !== ''): ?><a href="<?php echo esc_url($removeUrl(['donor_status'])); ?>">Donation history: <?php echo esc_html($donorLabels[$donorFilter]); ?> <span aria-hidden="true">×</span></a><?php endif; ?>
                <?php if ($membershipFilter !== ''): ?><a href="<?php echo esc_url($removeUrl(['monthly_donor'])); ?>">Monthly status: <?php echo esc_html($membershipLabels[$membershipFilter]); ?> <span aria-hidden="true">×</span></a><?php endif; ?>
                <?php if ($regionFilter !== ''): ?><a href="<?php echo esc_url($removeUrl(['region'])); ?>">Region: <?php echo esc_html(RegionalGroups::label($regionFilter)); ?> <span aria-hidden="true">×</span></a><?php endif; ?>
                <?php if ($pastEventTarget !== null): ?><a href="<?php echo esc_url($removeUrl(['past_event_mode', 'past_event_count'])); ?>">Past events: <?php echo esc_html($eventModeLabels[$pastEventMode] . ' ' . $pastEventTarget); ?> <span aria-hidden="true">×</span></a><?php endif; ?>
                <a class="bg-clear-filters" href="<?php echo esc_url($peopleUrl()); ?>">Clear all</a>
            </div>
        <?php endif; ?>

        <div class="bg-results-toolbar">
            <div><strong><?php echo count($records); ?> <?php echo count($records) === 1 ? 'person' : 'people'; ?> found</strong>
                <span><?php echo (int) $store->count(); ?> active records of a <?php echo (int) $store->cap(); ?> record cap</span></div>
            <details class="bg-column-chooser">
                <summary class="button">Choose columns</summary>
                <div>
                    <?php foreach ([
                        'bg_id' => 'Record ID', 'lgl_id' => 'LGL ID', 'name' => 'Name', 'email' => 'Email',
                        'phone' => 'Phone', 'monthly' => 'Monthly donor', 'donor_history' => 'Donation history',
                        'region' => 'Region', 'past_events' => 'Past events', 'tags' => 'Tags',
                        'added' => 'Date added', 'actions' => 'Actions',
                    ] as $column => $label): ?>
                        <label><input type="checkbox" data-bg-column-toggle="<?php echo esc_attr($column); ?>"
                            <?php checked(in_array($column, ['name', 'email', 'monthly', 'region', 'past_events', 'actions'], true)); ?>> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="var n=document.querySelectorAll('.bg-rec-cb:checked').length;if(!n){alert('Select at least one constituent first.');return false;}var a=event.submitter?event.submitter.value:'';if(a==='bg_const_bulk_delete')return confirm('Permanently delete '+n+' checked record(s)? This removes them entirely (values, tags, timeline, consents, subscriptions). Anonymize is the gentler per-record option.');return confirm((a==='bg_lgl_push_selected'?'Push':'Pull')+' '+n+' selected record(s) '+(a==='bg_lgl_push_selected'?'to':'from')+' LGL?');">
        <?php wp_nonce_field('bg_const_bulk_delete', '_bg_delete_nonce'); ?>
        <?php wp_nonce_field('bg_lgl_selected', '_bg_lgl_selected_nonce'); ?>
        <div class="bg-bulk-actions" id="bg-constituent-bulk-actions" hidden>
            <strong id="bg-constituent-selection-count">0 people selected</strong>
            <button class="button button-primary" name="action" value="bg_lgl_push_selected">Push selected to LGL</button>
            <button class="button button-primary" name="action" value="bg_lgl_pull_selected">Pull selected from LGL</button>
            <button class="button button-link-delete" name="action" value="bg_const_bulk_delete">Delete selected</button>
            <span>Only the checked record IDs are processed. Shared email addresses never combine people.</span>
        </div>
        <div class="bg-constituent-table-wrap">
        <table class="widefat striped bg-constituent-table" id="bg-constituent-table">
            <thead><tr>
                <th class="check-column"><input type="checkbox" id="bg-select-all-constituents" aria-label="Select all people shown"></th>
                <th data-bg-column="bg_id">Record ID</th>
                <th data-bg-column="lgl_id">LGL ID</th>
                <th data-bg-column="name">Name</th>
                <th data-bg-column="email">Email</th>
                <th data-bg-column="phone">Phone</th>
                <th data-bg-column="monthly">Monthly donor</th>
                <th data-bg-column="donor_history">Donation history</th>
                <th data-bg-column="region">Regional group</th>
                <th data-bg-column="past_events">Past events</th>
                <th data-bg-column="tags">Tags</th>
                <th data-bg-column="added">Added</th>
                <th data-bg-column="actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="13">No people<?php echo $filtersActive ? ' match the current search or filters' : ' have been added yet'; ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r):
                $id = (int) $r['id'];
                $status = $donorStatuses[$id] ?? DonorStatus::UNKNOWN;
                $region = $regionMap[$id] ?? '';
                $editUrl = admin_url('admin.php?page=' . self::PAGE . '&view=edit&id=' . $id);
            ?>
                <tr>
                    <th scope="row" class="check-column"><input type="checkbox" class="bg-rec-cb" name="ids[]" value="<?php echo $id; ?>" aria-label="Select <?php echo esc_attr(trim($r['first_name'] . ' ' . $r['last_name']) ?: 'this person'); ?>"></th>
                    <td data-bg-column="bg_id"><strong><?php echo $id; ?></strong></td>
                    <td data-bg-column="lgl_id"><?php echo (int) ($r['lgl_constituent_id'] ?? 0) ?: '-'; ?></td>
                    <td data-bg-column="name"><a href="<?php echo esc_url($editUrl); ?>">
                        <?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name']) ?: '(no name)'); ?></a>
                        <?php if (!($contactSharing[$id] ?? true)): ?>
                            <br><span style="display:inline-block;margin-top:3px;padding:1px 6px;border-radius:999px;background:#F3E8F7;color:#1F5130;font-size:11px;font-weight:700" title="Event organizers and other members receive no personal information; event registrations appear anonymously.">Private contact</span>
                        <?php endif; ?>
                    </td>
                    <td data-bg-column="email"><?php echo esc_html($r['email']); ?></td>
                    <td data-bg-column="phone"><?php echo esc_html($r['phone']); ?></td>
                    <td data-bg-column="monthly"><?php echo esc_html(match ($membershipValues[$id] ?? 'unknown') {
                        'yes' => 'Yes', 'no' => 'No', default => 'Not recorded',
                    }); ?></td>
                    <td data-bg-column="donor_history"><?php echo esc_html(DonorStatus::label($status)); ?></td>
                    <td data-bg-column="region"><?php echo esc_html(RegionalGroups::label($region) ?: '-'); ?></td>
                    <td data-bg-column="past_events"><?php echo (int) ($pastEventCounts[$id] ?? 0); ?></td>
                    <td data-bg-column="tags"><?php echo esc_html(implode(', ', $store->tagsFor($id))); ?></td>
                    <td data-bg-column="added"><?php echo esc_html((string) substr($r['created_at'], 0, 10)); ?></td>
                    <td data-bg-column="actions" class="bg-row-actions">
                        <a class="button button-small" href="<?php echo esc_url($editUrl); ?>">Open record</a>
                        <button class="button button-small button-link-delete" type="submit" form="bg-const-anon-<?php echo $id; ?>"
                            onclick="return confirm('Anonymize this record? The personal data is erased permanently; the timeline shape is kept.');">Anonymize</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </form>
        <?php foreach ($records as $r): $anonId = (int) ($r['id'] ?? 0); if ($anonId < 1) continue; ?>
            <form id="bg-const-anon-<?php echo $anonId; ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
                <?php wp_nonce_field('bg_const_anon_' . $anonId); ?>
                <input type="hidden" name="action" value="bg_const_anon">
                <input type="hidden" name="id" value="<?php echo $anonId; ?>">
            </form>
        <?php endforeach; ?>
        <script>
        (function(){
          var table=document.getElementById('bg-constituent-table');if(!table)return;
          var toggles=Array.prototype.slice.call(document.querySelectorAll('[data-bg-column-toggle]'));
          var defaults=['name','email','monthly','region','past_events','actions'];
          var known=toggles.map(function(toggle){return toggle.getAttribute('data-bg-column-toggle');});
          var shown=defaults.slice();
          try{
            var saved=JSON.parse(localStorage.getItem('bg_constituent_columns')||'null');
            if(Array.isArray(saved)){
              saved=saved.filter(function(column){return known.indexOf(column)!==-1;});
              if(saved.length){shown=saved;}
            }
          }catch(ignore){}
          function applyColumns(){
            table.querySelectorAll('[data-bg-column]').forEach(function(cell){
              cell.classList.toggle('is-hidden',shown.indexOf(cell.getAttribute('data-bg-column'))===-1);
            });
            toggles.forEach(function(toggle){
              toggle.checked=shown.indexOf(toggle.getAttribute('data-bg-column-toggle'))!==-1;
            });
          }
          toggles.forEach(function(toggle){
            toggle.addEventListener('change',function(){
              var column=toggle.getAttribute('data-bg-column-toggle');
              if(toggle.checked&&shown.indexOf(column)===-1){shown.push(column);}
              if(!toggle.checked){shown=shown.filter(function(item){return item!==column;});}
              try{localStorage.setItem('bg_constituent_columns',JSON.stringify(shown));}catch(ignore){}
              applyColumns();
            });
          });
          applyColumns();

          var master=document.getElementById('bg-select-all-constituents');
          var boxes=Array.prototype.slice.call(document.querySelectorAll('.bg-rec-cb'));
          var bulk=document.getElementById('bg-constituent-bulk-actions');
          var count=document.getElementById('bg-constituent-selection-count');
          function updateSelection(){
            var selected=boxes.filter(function(box){return box.checked;}).length;
            if(bulk){bulk.hidden=selected===0;}
            if(count){count.textContent=selected+' '+(selected===1?'person':'people')+' selected';}
            if(master){master.checked=boxes.length>0&&selected===boxes.length;master.indeterminate=selected>0&&selected<boxes.length;}
          }
          if(master){master.addEventListener('change',function(){boxes.forEach(function(box){box.checked=master.checked;});updateSelection();});}
          boxes.forEach(function(box){box.addEventListener('change',updateSelection);});
          updateSelection();
        })();
        </script>
        <?php
    }

    /* ── Data tools tab ──────────────────────────────────────────── */

    private function renderDataTools(Container $container): void
    {
        $regionRefresh = get_option('bg_constituent_region_refresh_result', []);
        $donorRefresh = get_option('bg_donor_history_refresh_result', []);
        $lglReady = $container->get('lgl')->hasKey();
        ?>
        <div class="bg-data-tools-heading">
            <h2>Data Tools</h2>
            <p>Use these tools when records need to be refreshed, recalculated, imported, or exported. Ordinary searching and filtering stay on the People tab.</p>
        </div>
        <div class="bg-data-tools-grid">
            <section class="bg-data-tool-card">
                <h3>Regional groups</h3>
                <p>Recheck automatically assigned and unresolved records using their current addresses. Manual staff choices are preserved.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bg_const_region_refresh'); ?>
                    <input type="hidden" name="action" value="bg_const_region_refresh">
                    <button class="button button-primary">Recalculate automatic regions</button>
                </form>
                <?php if (is_array($regionRefresh) && !empty($regionRefresh['updated_at'])): ?>
                    <p class="description">Last batch <?php echo esc_html((string) $regionRefresh['updated_at']); ?> UTC:
                    <?php echo (int) ($regionRefresh['processed'] ?? 0); ?> processed,
                    <?php echo (int) ($regionRefresh['errors'] ?? 0); ?> errors<?php echo !empty($regionRefresh['remaining']) ? '; more records are continuing in the background' : '; complete'; ?>.</p>
                <?php endif; ?>
            </section>

            <?php if ($lglReady): ?>
                <section class="bg-data-tool-card">
                    <h3>Participation and monthly status</h3>
                    <p>Pull the latest mapped constituent fields from LGL, including Past Events and Monthly Donor. BosqueGrande processes the refresh in identity-safe background batches.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Pull every LGL constituent into BosqueGrande now? This refreshes Past Events and Monthly Donor along with other nonblank mapped fields.');">
                        <?php wp_nonce_field('bg_lgl_pull_all'); ?>
                        <input type="hidden" name="action" value="bg_lgl_pull_all">
                        <button class="button button-primary">Refresh participation and monthly status</button>
                    </form>
                </section>

                <section class="bg-data-tool-card">
                    <h3>Donation history</h3>
                    <p>Check each linked LGL record for completed gifts. BosqueGrande keeps unchecked records marked Needs review instead of guessing that they never donated.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bg_const_donor_refresh'); ?>
                        <input type="hidden" name="action" value="bg_const_donor_refresh">
                        <button class="button button-primary" <?php disabled((bool) get_transient('bg_donor_history_refresh_running')); ?>>Refresh donation history</button>
                    </form>
                    <?php if (is_array($donorRefresh) && !empty($donorRefresh['finished_at'])): ?>
                        <p class="description">Last completed <?php echo esc_html((string) $donorRefresh['finished_at']); ?> UTC:
                        <?php echo (int) ($donorRefresh['checked'] ?? 0); ?> checked,
                        <?php echo (int) ($donorRefresh['errors'] ?? 0); ?> errors.</p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="bg-data-tool-card">
                    <h3>LGL refreshes</h3>
                    <p>Connect LGL under Settings before running participation, monthly-status, or donation-history refreshes.</p>
                </section>
            <?php endif; ?>

            <section class="bg-data-tool-card">
                <h3>CSV import and export</h3>
                <p>Import an LGL constituent export or download the current local records. Imports fill blank values and never overwrite populated fields.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('bg_const_import'); ?>
                    <input type="hidden" name="action" value="bg_const_import">
                    <label class="bg-file-label">Choose a CSV file<input type="file" name="csv" accept=".csv" required></label>
                    <div class="bg-tool-actions">
                        <button class="button button-primary">Import CSV</button>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bg_const_export'), 'bg_const_export')); ?>">Export CSV</a>
                    </div>
                </form>
                <p class="description">Common LGL headers are recognized as written. Multi-value cells may use commas or semicolons. Sensitive demographic fields remain excluded from exports and assisted features.</p>
            </section>
        </div>
        <?php
    }

    /* ── Edit / profile ───────────────────────────────────────────── */

    private function renderEdit(Container $container, ConstituentStore $store, int $id): void
    {
        $store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        $record = $id ? $store->get($id) : null;
        if ($id && !$record) {
            echo '<p>Record not found.</p>';
            return;
        }
        $r = $record ?? array_fill_keys(ConstituentStore::FIELDS, '');
        $contactSharingAllowed = $id ? $store->contactSharingAllowed($id) : true;
        $values = $id ? $store->valuesFor($id) : [];
        /** @var RegionalGroups $regionalGroups */
        $regionalGroups = $container->get('constituents.regions');
        if ($id) $regionalGroups->assign($id);
        $regional = $id ? $regionalGroups->for($id) : null;
        $regionChoice = ($regional['assignment_source'] ?? '') === 'manual'
            ? (($regional['region_slug'] ?? '') !== '' ? (string) $regional['region_slug'] : RegionalGroups::NONE)
            : RegionalGroups::AUTO;
        $labels = [
            'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email',
            'phone' => 'Phone', 'address1' => 'Address', 'address2' => 'Address 2',
            'city' => 'City', 'state' => 'State', 'postal' => 'Postal code',
        ];
        ?>
        <h2><?php echo $id ? 'Edit record' : 'New record'; ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:680px">
            <?php wp_nonce_field('bg_const_save'); ?>
            <input type="hidden" name="action" value="bg_const_save">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <table class="form-table" role="presentation">
                <?php if ($id): ?>
                    <tr><th scope="row">Record ID</th><td><strong><?php echo (int) $id; ?></strong>
                    <p class="description">Permanent identity for this person. It never changes and is never shared.</p></td></tr>
                    <tr><th scope="row">Contact sharing</th><td><strong><?php echo $contactSharingAllowed ? 'Allowed' : 'Do not share with event organizers'; ?></strong>
                    <p class="description"><?php echo $contactSharingAllowed
                        ? 'No profile opt-out is on file.'
                        : 'The person opted out in My Profile. Event-facing rosters, messages, and exports must show an anonymous registration and no personal information.'; ?></p></td></tr>
                <?php endif; ?>
                <tr><th scope="row">LGL constituent ID</th><td>
                    <input type="number" min="1" step="1" name="lgl_id" value="<?php echo esc_attr((string) ((int) ($r['lgl_constituent_id'] ?? 0) ?: '')); ?>">
                    <p class="description">Optional link to this same person in LGL. One LGL ID cannot belong to two active record IDs. Clear the box to unlink.</p></td></tr>
                <?php foreach ($labels as $key => $label): ?>
                    <tr><th scope="row"><?php echo esc_html($label); ?></th>
                        <td><input type="<?php echo $key === 'email' ? 'email' : 'text'; ?>" class="regular-text"
                            name="f[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($r[$key]); ?>"></td></tr>
                    <?php if ($key === 'email'): ?>
                        <tr><th scope="row">Alternate emails</th>
                            <td><textarea name="alternate_emails" rows="3" class="large-text"
                                placeholder="One address per line"><?php echo esc_textarea(
                                    implode("\n", $id ? $store->alternateEmailsFor($id) : [])
                                ); ?></textarea>
                            <p class="description">Each address can request a sign-in link for this same record. Newsletters still use only the primary email above.</p></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr><th scope="row">Regional group</th><td>
                    <select name="region_choice">
                        <option value="<?php echo esc_attr(RegionalGroups::AUTO); ?>" <?php selected($regionChoice, RegionalGroups::AUTO); ?>>Auto-assign from address</option>
                        <option value="<?php echo esc_attr(RegionalGroups::NONE); ?>" <?php selected($regionChoice, RegionalGroups::NONE); ?>>None (manual override)</option>
                        <?php foreach (RegionalGroups::regions() as $slug => $label): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($regionChoice, $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Current: <strong><?php echo esc_html(RegionalGroups::label((string) ($regional['region_slug'] ?? '')) ?: 'None'); ?></strong>.
                    Auto accepts abbreviated or spelled-out states, ZIP+4 or leading-zero ZIPs, and complete addresses stored in one field. It uses postal/address evidence first, then city and state, then close-spelling and optional regional matching help. A manual choice is never overwritten by an address update.
                    <?php if (!empty($regional['evidence'])): ?><br>Result detail: <?php echo esc_html((string) $regional['evidence']); ?><?php endif; ?></p></td></tr>
                <tr><th scope="row">Notes</th>
                    <td><textarea name="f[notes]" rows="3" class="large-text"><?php echo esc_textarea($r['notes']); ?></textarea></td></tr>
                <tr><th scope="row">Tags</th>
                    <td><input type="text" class="regular-text" name="tags"
                        value="<?php echo esc_attr(implode(', ', $id ? $store->tagsFor($id) : [])); ?>">
                    <p class="description">Comma or semicolon separated.</p></td></tr>
                <?php foreach ($store->fieldDefinitions() as $def): ?>
                    <tr><th scope="row"><?php echo esc_html($def->label); ?><?php echo $def->sensitive ? ' <span class="dashicons dashicons-lock" title="Sensitive: excluded from exports"></span>' : ''; ?></th>
                        <td>
                        <?php if ((string) $def->field_key === CoreFields::MONTHLY_DONOR):
                            $monthly = CoreFields::normalizeMonthlyDonor($values[(int) $def->id] ?? ''); ?>
                            <input type="hidden" name="cv[<?php echo (int) $def->id; ?>]" value="No">
                            <label class="bg-yesno-toggle"><input type="checkbox" name="cv[<?php echo (int) $def->id; ?>]" value="Yes" <?php checked($monthly === 'Yes'); ?>>
                                <span class="bg-yesno-track" aria-hidden="true"></span><span>Yes <small>(off = No)</small></span></label>
                            <p class="description">Yes/No only. Pull from LGL imports this when the LGL Forms setup maps recurring status to a “Monthly Donor” field or category. If LGL does not provide that mapping, set it here manually.</p>
                        <?php else: ?>
                            <input type="text" class="regular-text" name="cv[<?php echo (int) $def->id; ?>]"
                                value="<?php echo esc_attr($values[(int) $def->id] ?? ''); ?>">
                        <?php endif; ?>
                        </td></tr>
                <?php endforeach; ?>
            </table>
            <p><button class="button button-primary"><?php echo $id ? 'Save changes' : 'Create record'; ?></button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>">Back to list</a></p>
        </form>
        <?php
        if (!$id) return;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:680px;margin:10px 0 18px">
            <?php wp_nonce_field('bg_lgl_selected', '_bg_lgl_selected_nonce'); ?>
            <input type="hidden" name="ids[]" value="<?php echo (int) $id; ?>">
            <button class="button button-primary" name="action" value="bg_lgl_push_selected" onclick="return confirm('Push this local record to LGL?');">Push this record to LGL</button>
            <button class="button button-primary" name="action" value="bg_lgl_pull_selected" onclick="return confirm('Pull the linked or uniquely matched LGL record into this record ID?');">Pull this record from LGL</button>
        </form>
        <?php
        /** @var ActivityLog $activity */
        $activity = $container->get('constituents.activity');
        $consents = $store->consentsFor($id);
        if ($consents) {
            echo '<h2>Consents</h2><table class="widefat striped" style="max-width:680px"><thead><tr><th>Purpose</th><th>Status</th><th>Source</th><th>When (UTC)</th></tr></thead><tbody>';
            foreach ($consents as $c) {
                printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html((string) $c->purpose), $c->granted ? 'Given' : 'Withdrawn',
                    esc_html((string) $c->source), esc_html((string) $c->noted_at));
            }
            echo '</tbody></table>';
        }
        do_action('bg_constituent_profile', $id);
        echo '<h2>Timeline</h2><table class="widefat striped" style="max-width:680px"><thead><tr><th>When (UTC)</th><th>What</th></tr></thead><tbody>';
        $rows = $activity->for($id);
        if (!$rows) echo '<tr><td colspan="2">Nothing yet.</td></tr>';
        foreach ($rows as $row) {
            printf('<tr><td style="white-space:nowrap">%s</td><td><code>%s</code> %s</td></tr>',
                esc_html((string) $row->created_at), esc_html((string) $row->kind), esc_html((string) $row->summary));
        }
        echo '</tbody></table>';
    }

    /* ── Custom fields tab ────────────────────────────────────────── */

    private function renderFields(ConstituentStore $store): void
    {
        ?>
        <h2>Custom fields</h2>
        <p style="max-width:680px">Extra per-person fields. Values are always stored encrypted. Mark a field
        sensitive to also keep it out of CSV exports and away from any assisted feature.</p>
        <table class="widefat striped" style="max-width:680px">
            <thead><tr><th>Label</th><th>Key</th><th>Sensitive</th></tr></thead>
            <tbody>
            <?php $defs = $store->fieldDefinitions();
            if (!$defs) echo '<tr><td colspan="3">None yet.</td></tr>';
            foreach ($defs as $def) {
                printf('<tr><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                    esc_html($def->label), esc_html($def->field_key), $def->sensitive ? 'Yes' : 'No');
            } ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
            <?php wp_nonce_field('bg_const_field_add'); ?>
            <input type="hidden" name="action" value="bg_const_field_add">
            <input type="text" name="label" placeholder="Field label" required>
            <input type="text" name="field_key" placeholder="key_name" required>
            <label><input type="checkbox" name="sensitive" value="1"> Sensitive</label>
            <button class="button">Add field</button>
        </form>
        <?php
    }

    /* ── Duplicates tab ───────────────────────────────────────────── */

    private function renderDuplicates(ConstituentStore $store): void
    {
        $pairs = get_option(self::DUPES_OPTION, []);
        if (!is_array($pairs)) $pairs = [];
        ?>
        <h2>Duplicate review</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
            <?php wp_nonce_field('bg_const_dedupe'); ?>
            <input type="hidden" name="action" value="bg_const_dedupe">
            <button class="button button-primary">Scan for duplicates</button>
            <span class="description">Compares every active record for review. Shared email, phone, or address is not proof that two records are the same person. A human clicks every merge.</span>
        </form>
        <?php
        if (!$pairs) {
            echo '<p>No proposals. Run a scan.</p>';
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('Merge every checked pair? For each, the older record is kept and the newer one folds into it.');">
        <?php wp_nonce_field('bg_const_bulk_merge'); ?>
        <input type="hidden" name="action" value="bg_const_bulk_merge">
        <p>
            <button class="button button-primary">Merge checked pairs</button>
            <label style="margin-left:12px"><input type="checkbox" onclick="document.querySelectorAll('.bg-pair-cb').forEach(c=>c.checked=this.checked)"> Check all</label>
            <span class="description">Review names and record IDs carefully. Checked pairs merge oldest-record-first; the per-pair Keep buttons remain for choosing the other direction.</span>
        </p>
        <?php
        $individualMerges = [];
        echo '<table class="widefat striped"><thead><tr><th></th><th>Record A</th><th>Record B</th><th>Why</th><th>Action</th></tr></thead><tbody>';
        foreach ($pairs as $pair) {
            $a = $store->get((int) $pair['a']);
            $b = $store->get((int) $pair['b']);
            if (!$a || !$b || $a['status'] !== 'active' || $b['status'] !== 'active') continue;
            $labelA = trim($a['first_name'] . ' ' . $a['last_name']) . ' <' . $a['email'] . '>';
            $labelB = trim($b['first_name'] . ' ' . $b['last_name']) . ' <' . $b['email'] . '>';
            $why = esc_html((string) $pair['reason']);
            if (!empty($pair['advice'])) {
                $why .= '<br><em>' . esc_html((string) $pair['advice']) . '</em>';
            }
            $keepOld = min((int) $pair['a'], (int) $pair['b']);
            $foldNew = max((int) $pair['a'], (int) $pair['b']);
            $formA = 'bg-const-merge-' . (int) $pair['a'] . '-' . (int) $pair['b'];
            $formB = 'bg-const-merge-' . (int) $pair['b'] . '-' . (int) $pair['a'];
            $individualMerges[] = [(int) $pair['a'], (int) $pair['b'], $formA];
            $individualMerges[] = [(int) $pair['b'], (int) $pair['a'], $formB];
            printf('<tr><td><input type="checkbox" class="bg-pair-cb" name="pairs[]" value="%d:%d"></td><td>%s</td><td>%s</td><td>%s</td><td><button type="submit" class="button button-small" form="%s">Keep A</button> <button type="submit" class="button button-small" form="%s">Keep B</button></td></tr>',
                $keepOld, $foldNew,
                esc_html($labelA), esc_html($labelB), $why,
                esc_attr($formA), esc_attr($formB));
        }
        echo '</tbody></table></form>';
        foreach ($individualMerges as [$keep, $fold, $formId]) {
            echo '<form id="' . esc_attr($formId) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" hidden>';
            wp_nonce_field('bg_const_merge_' . $keep . '_' . $fold);
            echo '<input type="hidden" name="action" value="bg_const_merge">'
                . '<input type="hidden" name="keep" value="' . (int) $keep . '">'
                . '<input type="hidden" name="fold" value="' . (int) $fold . '"></form>';
        }
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    private function back(string $notice, string $suffix = ''): void
    {
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode($notice),
            admin_url('admin.php?page=' . self::PAGE . $suffix)));
        exit;
    }

    private function handleSave(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_save');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $id = (int) ($_POST['id'] ?? 0);
        $before = $id ? $store->get($id) : null;
        $fields = array_map('wp_unslash', (array) ($_POST['f'] ?? []));
        if ($id) {
            $ok = $store->update($id, $fields);
        } else {
            $id = $store->create($fields);
            $ok = $id > 0;
        }
        if (!$ok) $this->back('Not saved: encryption unavailable, the record cap is reached, or the record is closed.');
        $alternateEmails = preg_split(
            '/[\r\n,;]+/',
            (string) wp_unslash((string) ($_POST['alternate_emails'] ?? ''))
        ) ?: [];
        if (!$store->replaceAlternateEmails($id, $alternateEmails)) {
            $this->back('The core record was saved, but its alternate emails could not be committed. Please retry.', '&view=edit&id=' . $id);
        }
        $tags = ConstituentStore::splitList(
            (string) wp_unslash((string) ($_POST['tags'] ?? ''))
        );
        if (!$store->setTags($id, $tags)) {
            $this->back('The core record was saved, but its tags could not be committed. Please retry.', '&view=edit&id=' . $id);
        }
        foreach ((array) ($_POST['cv'] ?? []) as $fieldId => $value) {
            $cleanValue = sanitize_text_field((string) wp_unslash((string) $value));
            if (!$store->setValue($id, (int) $fieldId, $cleanValue)) {
                $this->back('The core record was saved, but a custom field could not be committed. Please retry.', '&view=edit&id=' . $id);
            }
        }
        $requestedLglId = max(0, (int) ($_POST['lgl_id'] ?? 0));
        $current = $store->get($id);
        $currentLglId = (int) ($current['lgl_constituent_id'] ?? 0);
        if ($requestedLglId !== $currentLglId) {
            $linked = $requestedLglId > 0
                ? $store->linkLgl($id, $requestedLglId)
                : $store->unlinkLgl($id);
            if (!$linked) {
                $this->back('The record was saved, but the LGL ID could not be linked. That LGL ID may already belong to another active local record.', '&view=edit&id=' . $id);
            }
        }
        /** @var RegionalGroups $regions */
        $regions = $container->get('constituents.regions');
        $regionChoice = (string) wp_unslash((string) ($_POST['region_choice'] ?? RegionalGroups::AUTO));
        if (!$regions->saveChoice($id, $regionChoice)) {
            $this->back('The record was saved, but its regional group could not be committed. Please retry.', '&view=edit&id=' . $id);
        }
        if ($container->has('registration.monthly_donor')) {
            /** @var MonthlyDonor $monthly */
            $monthly = $container->get('registration.monthly_donor');
            if ($before) {
                $monthly->clearCache(
                    (string) ($before['email'] ?? ''),
                    (int) ($before['lgl_constituent_id'] ?? 0)
                );
            }
            $saved = $store->get($id);
            if ($saved) {
                $monthly->clearCache(
                    (string) ($saved['email'] ?? ''),
                    (int) ($saved['lgl_constituent_id'] ?? 0)
                );
            }
        }
        $this->back('Saved.', '&view=edit&id=' . $id);
    }

    private function handleAnon(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('bg_const_anon_' . $id);
        $ok = $container->get('constituents.store')->anonymize($id);
        $this->back($ok ? 'Record anonymized.' : 'Nothing to anonymize.');
    }

    /** @param mixed $raw @return array<int, array{0:int,1:int}> Validated keep:fold id pairs. */
    public static function parsePairs($raw): array
    {
        $out = [];
        foreach ((array) $raw as $pair) {
            if (!is_string($pair) || !preg_match('/^(\d+):(\d+)$/', $pair, $m)) continue;
            $keep = (int) $m[1];
            $fold = (int) $m[2];
            if ($keep > 0 && $fold > 0 && $keep !== $fold) $out[] = [$keep, $fold];
        }
        return $out;
    }

    private function handleBulkMerge(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_bulk_merge');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $done = 0;
        $failed = 0;
        $lastError = '';
        foreach (self::parsePairs($_POST['pairs'] ?? []) as [$keep, $fold]) {
            if ($store->merge($keep, $fold, true)) {
                $done++;
            } else {
                $failed++;
                $lastError = $store->lastMergeError();
            }
        }
        $notice = sprintf('%d pair(s) merged; the older record ID was kept each time.', $done);
        if ($failed > 0) {
            $notice .= sprintf(' %d pair(s) could not be merged%s.',
                $failed,
                $lastError !== '' ? ': ' . $lastError : ''
            );
        }
        $this->back($notice, '&tab=duplicates');
    }

    private function handleBulkDelete(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_bulk_delete', '_bg_delete_nonce');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $gone = $ids ? $store->deleteHard($ids) : 0;
        $this->back(sprintf('%d record(s) permanently deleted.', $gone));
    }

    private function handleMerge(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $keep = (int) ($_POST['keep'] ?? 0);
        $fold = (int) ($_POST['fold'] ?? 0);
        check_admin_referer('bg_const_merge_' . $keep . '_' . $fold);
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $ok = $store->merge($keep, $fold, true);
        $this->back($ok ? 'Merged.' : 'Merge failed: ' . ($store->lastMergeError() ?: 'the records could not be combined.'), '&tab=duplicates');
    }

    private function handleDedupe(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_dedupe');
        // A full scan at the record cap is millions of cheap compares
        // plus optional advice calls; give it room instead of letting
        // PHP's default limit kill it halfway.
        if (function_exists('set_time_limit')) @set_time_limit(300);
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $pairs = (new Deduper())->scan($store->allDecrypted());
        // Optional advisory text for the ambiguous name-based
        // band when the feature is on. Advice only; every merge is a click.
        $settings = $container->get('settings');
        if (\BosqueGrande\Modules\Intelligence\IntelligenceModule::featureEnabled($settings, 'dedup_assist')) {
            $ai = $container->get('ai');
            $advised = 0;
            foreach ($pairs as $i => $pair) {
                if ($advised >= 15 || !str_contains((string) $pair['reason'], 'name')) continue;
                $a = $store->get((int) $pair['a']);
                $b = $store->get((int) $pair['b']);
                if (!$a || !$b) continue;
                $advice = \BosqueGrande\Modules\Intelligence\Helpers::dedupAdvice($ai, $a, $b, (string) $pair['reason']);
                if ($advice !== '') {
                    $pairs[$i]['advice'] = $advice;
                    $advised++;
                }
            }
        }
        update_option(self::DUPES_OPTION, $pairs, false);
        $this->back(count($pairs) . ' proposal(s) found.', '&tab=duplicates');
    }

    private function handleImport(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_import');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $monthlyFieldId = $store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        $pastEventsFieldId = $store->ensureField(
            CoreFields::PAST_EVENTS,
            ParticipationHistory::LABEL,
            false
        );
        $file = $_FILES['csv']['tmp_name'] ?? '';
        if ($file === '' || !is_uploaded_file($file)) $this->back('No file received.');
        $handle = fopen($file, 'r');
        if (!$handle) $this->back('The file could not be read.');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $header = self::mapCsvHeader(is_array($header) ? $header : []);
        if (!in_array('email', $header, true)) {
            fclose($handle);
            $this->back('The CSV needs a header row including an email column (Email, Email address, and similar all work).');
        }
        $imported = 0;
        $skipped = 0;
        $lines = 0;
        $answerCellsForReview = 0;
        // All three person-level answer columns import, not
        // only demographics; an LGL export's Age Range and Relationship
        // Status columns previously vanished, leaving imported records
        // with nothing for the prefill resolver to read locally.
        $answerFields = array_fill_keys(array_values(array_intersect(
            ['demographic', 'age_range', 'relationship_status', 'heard', 'promote'],
            $header
        )), true);
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lines++;
            $row = [];
            $rowEmails = [];
            foreach ($header as $i => $key) {
                if ($key === '') continue;
                $value = (string) ($line[$i] ?? '');
                if ($key === 'email') {
                    $candidate = ConstituentStore::normEmail(sanitize_email(
                        ConstituentStore::stripExcelGuard($value)
                    ));
                    if (is_email($candidate)) $rowEmails[$candidate] = $candidate;
                }
                // LGL exports may contain several columns that map to
                // the same local field. A later blank column must not
                // erase the populated value already selected.
                if (!isset($row[$key]) || ($row[$key] === '' && $value !== '')) {
                    $row[$key] = $value;
                }
            }
            $id = $store->upsertByEmail($row['email'] ?? '', [
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'phone' => $row['phone'] ?? '',
                'address1' => $row['address1'] ?? '',
                'address2' => $row['address2'] ?? '',
                'city' => $row['city'] ?? '',
                'state' => $row['state'] ?? '',
                'postal' => $row['postal'] ?? '',
                'notes' => $row['notes'] ?? '',
            ]);
            if (!$id) {
                $skipped++;
                continue;
            }
            $rowOk = true;
            if (!$store->syncEmails(
                $id,
                array_values($rowEmails),
                (string) ($row['email'] ?? ''),
                'csv'
            )) {
                $rowOk = false;
            }
            $existingValues = $store->valuesFor($id);
            $pastEvents = trim((string) ($row['past_events'] ?? ''));
            if ($pastEvents !== '' && $pastEventsFieldId > 0
                && trim((string) ($existingValues[$pastEventsFieldId] ?? '')) === ''
            ) {
                $rowOk = $store->setValue($id, $pastEventsFieldId, $pastEvents) && $rowOk;
            }
            $monthly = CoreFields::normalizeMonthlyDonor($row['monthly_donor'] ?? '');
            if ($monthly !== '' && $monthlyFieldId > 0
                && trim((string) ($existingValues[$monthlyFieldId] ?? '')) === ''
            ) {
                $rowOk = $store->setValue($id, $monthlyFieldId, $monthly) && $rowOk;
            }
            if ($answerFields) {
                $demographicRaw = ConstituentStore::stripExcelGuard(trim((string) ($row['demographic'] ?? '')));
                $ageRaw = ConstituentStore::stripExcelGuard(trim((string) ($row['age_range'] ?? '')));
                $relationshipRaw = ConstituentStore::stripExcelGuard(trim((string) ($row['relationship_status'] ?? '')));
                $heardRaw = ConstituentStore::stripExcelGuard(trim((string) ($row['heard'] ?? '')));
                $promoteRaw = ConstituentStore::stripExcelGuard(trim((string) ($row['promote'] ?? '')));

                $personal = [
                    'demographics' => DemographicFields::sanitize(
                        PrefillResolver::parseOptions($demographicRaw, DemographicFields::options())
                    ),
                    'demographics_other' => PrefillResolver::parseOtherDetail($demographicRaw),
                    'age_range' => PrefillResolver::parseAge($ageRaw),
                    'relationship' => SurveyFields::sanitizeRelationship(
                        PrefillResolver::parseOptions($relationshipRaw, SurveyFields::relationshipOptions())
                    ),
                    'heard' => SurveyFields::parseHeard($heardRaw),
                    'heard_other' => PrefillResolver::parseOtherDetail($heardRaw),
                    'promote' => SurveyFields::sanitizePromote($promoteRaw),
                ];
                foreach ([
                    [$demographicRaw, $personal['demographics']],
                    [$ageRaw, $personal['age_range']],
                    [$relationshipRaw, $personal['relationship']],
                    [$heardRaw, $personal['heard']],
                    [$promoteRaw, $personal['promote']],
                ] as [$rawAnswer, $normalizedAnswer]) {
                    $normalizedEmpty = is_array($normalizedAnswer)
                        ? $normalizedAnswer === []
                        : $normalizedAnswer === '';
                    if ($rawAnswer !== '' && $normalizedEmpty) $answerCellsForReview++;
                }
                if (array_filter($personal, static fn($value): bool =>
                    is_array($value) ? $value !== [] : $value !== ''
                )) {
                    $rowOk = $store->setPersonalAnswers($id, $personal);
                }
            }
            if (($row['tags'] ?? '') !== '') {
                $rowOk = $store->setTags($id, array_unique(array_merge(
                    $store->tagsFor($id), ConstituentStore::splitList((string) $row['tags'])
                ))) && $rowOk;
            }
            if (!$rowOk) {
                $skipped++;
                continue;
            }
            $container->get('constituents.regions')->assign($id);
            $container->get('constituents.activity')->append($id, 'import', 'CSV import.');
            $imported++;
        }
        fclose($handle);
        $container->get('logger')->info('constituents', 'CSV import finished.', [
            'imported' => $imported,
            'skipped' => $skipped,
            'answer_cells_for_review' => $answerCellsForReview,
        ]);
        $this->back(
            $imported . ' imported or updated, ' . $skipped
            . ' skipped (bad email, storage cap, encryption, or related-field write), '
            . $answerCellsForReview . ' non-empty answer cell(s) left unmapped for review.'
        );
    }

    private function handleExport(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_export');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        // Sensitive custom fields are excluded by rule; core fields export.
        $defs = array_values(array_filter($store->fieldDefinitions(), static fn($d) => !$d->sensitive));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename=constituents-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        $records = $store->allDecrypted();
        $contactSharing = $store->contactSharingMap(array_map(
            static fn(array $record): int => (int) $record['id'],
            $records
        ));
        fputcsv($out, array_merge(
            ConstituentStore::FIELDS,
            ['contact_sharing', 'tags'],
            array_map(static fn($d) => $d->field_key, $defs)
        ), ',', '"', '');
        foreach ($records as $r) {
            $id = (int) $r['id'];
            $values = $store->valuesFor($id);
            $line = [];
            foreach (ConstituentStore::FIELDS as $key) {
                $line[] = $r[$key];
            }
            $line[] = ($contactSharing[$id] ?? true)
                ? 'Allowed'
                : 'Do not share with event organizers';
            $line[] = implode(', ', $store->tagsFor($id));
            foreach ($defs as $def) {
                $line[] = $values[(int) $def->id] ?? '';
            }
            fputcsv($out, array_map([ConstituentStore::class, 'safeCsv'], array_map('strval', $line)), ',', '"', '');
        }
        fclose($out);
        $container->get('logger')->info('constituents', 'CSV export produced.', []);
        exit;
    }

    private function handleFieldAdd(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_field_add');
        $ok = $container->get('constituents.store')->addFieldDefinition(
            (string) wp_unslash((string) ($_POST['field_key'] ?? '')),
            sanitize_text_field((string) wp_unslash((string) ($_POST['label'] ?? ''))),
            !empty($_POST['sensitive'])
        );
        $this->back($ok ? 'Field added.' : 'Not added: the key is taken or invalid.', '&tab=fields');
    }

    private function handleDonorRefresh(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_donor_refresh');
        $lgl = $container->get('lgl');
        if (!$lgl->hasKey()) {
            $this->back('LGL is not connected. Add the API key under Settings first.');
        }
        if (get_transient('bg_donor_history_refresh_running')) {
            $this->back('The LGL donation-history refresh is already running.');
        }
        set_transient('bg_donor_history_refresh_running', 1, DAY_IN_SECONDS);
        $queued = $container->get('jobs')->enqueue(self::DONOR_REFRESH_JOB, [
            'offset' => 0,
            'checked' => 0,
            'errors' => 0,
        ], 2);
        if (!$queued) delete_transient('bg_donor_history_refresh_running');
        $this->back($queued
            ? 'LGL donation-history refresh started in small background batches. The donor filters will update as checks finish.'
            : 'WordPress could not schedule the donation-history refresh. Check Site Health and WP-Cron.');
    }

    private function handleRegionRefresh(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_const_region_refresh');
        /** @var RegionalGroups $regions */
        $regions = $container->get('constituents.regions');
        if ($regions->invalidateAutomatic() === null) {
            $this->back('Regional recalculation could not start because the automatic-assignment index could not be updated. Check the Activity Log for the database error.');
        }
        delete_transient('bg_constituent_region_backfill_queued');
        $result = $this->processRegionBatch($container, 25, true);
        $notice = sprintf(
            'Regional recalculation processed %d record(s) immediately; %d error(s).',
            $result['processed'],
            $result['errors']
        );
        $notice .= $result['remaining']
            ? ($result['queued']
                ? ' Remaining records are continuing in the background.'
                : ' More records remain; reopening Constituents will continue the work even if WP-Cron is unavailable.')
            : ' Recalculation is complete.';
        $this->back($notice);
    }
}
