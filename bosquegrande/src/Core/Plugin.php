<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

use BosqueGrande\Modules\Auditor\AuditorModule;
use BosqueGrande\Modules\Constituents\ConstituentsModule;
use BosqueGrande\Modules\Correspondence\CorrespondenceModule;
use BosqueGrande\Modules\Revenue\RevenueModule;
use BosqueGrande\Modules\Engagement\EngagementModule;
use BosqueGrande\Modules\Connectors\ConnectorsModule;
use BosqueGrande\Modules\Insights\InsightsModule;
use BosqueGrande\Modules\Identity\IdentityModule;
use BosqueGrande\Modules\Imports\ImportModule;
use BosqueGrande\Modules\Intelligence\IntelligenceModule;
use BosqueGrande\Modules\Lgl\LglModule;
use BosqueGrande\Modules\Locations\LocationsModule;
use BosqueGrande\Modules\GatheringTree\GatheringTreeModule;
use BosqueGrande\Modules\GrantReporting\GrantReportingModule;
use BosqueGrande\Modules\Appearance\AppearanceModule;
use BosqueGrande\Modules\Exchange\ExchangeModule;
use BosqueGrande\Modules\Media\MediaModule;
use BosqueGrande\Modules\Migrator\MigratorModule;
use BosqueGrande\Modules\Notifications\NotificationsModule;
use BosqueGrande\Modules\OrganizerPortal\OrganizerPortalModule;
use BosqueGrande\Modules\OrganizerApp\OrganizerAppModule;
use BosqueGrande\Modules\Payments\PaymentsModule;
use BosqueGrande\Modules\Profile\ProfileModule;
use BosqueGrande\Modules\Registration\RegistrationModule;

if (!defined('ABSPATH')) exit;

/**
 * Wires the container, installs the schema on activation and version
 * changes, and registers domain modules in dependency order. The container
 * ids defined in wire() are the contract modules resolve against:
 * keys, encryption, settings, logger, schema, jobs, modules.
 */
final class Plugin
{
    private const SCHEMA_PROOF_OPTION = 'bg_schema_verified';

    private static ?Plugin $instance = null;
    private Container $container;
    private bool $booted = false;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->container = new Container();
        $this->wire();
    }

    public function container(): Container
    {
        return $this->container;
    }

    private function wire(): void
    {
        $c = $this->container;

        $c->set('keys', fn() => new KeyStore());
        $c->set('encryption', fn(Container $c) => new Encryption($c->get('keys')));
        $c->set('settings', fn(Container $c) => new Settings($c->get('encryption')));
        $c->set('logger', fn() => new Logger());
        $c->set('schema', fn() => new Schema());
        $c->set('jobs', fn() => new Jobs());
        $c->set('crypto.migrator', fn(Container $c) => new CryptoMigrator(
            $c->get('encryption'),
            $c->get('keys'),
            $c->get('settings'),
            $c->get('jobs'),
            $c->get('logger')
        ));
        $c->set('modules', function (Container $c) {
            $registry = new ModuleRegistry($c);
            // The calendar: bg_event, its taxonomies, and the public pages.
            $registry->add(new GatheringTreeModule());
            // Wording and palette. Boots early so every later module
            // resolves strings and colors through it.
            $registry->add(new AppearanceModule());
            // LGL client and verified constituent identity.
            $registry->add(new LglModule());
            $registry->add(new IdentityModule());
            // Event registration.
            $registry->add(new RegistrationModule());
            // Stripe payments, statistics, and refunds.
            $registry->add(new PaymentsModule());
            // Self-service profiles and demographic mapping.
            $registry->add(new ProfileModule());
            // Photo library and event-migration tools.
            $registry->add(new MediaModule());
            $registry->add(new MigratorModule());
            // Staged transfers, source adapters, mapping, and run history.
            $registry->add(new ExchangeModule());
            // Reminders and post-event surveys.
            $registry->add(new NotificationsModule());
            // The location taxonomy used for event filtering.
            $registry->add(new LocationsModule());
            // Site Audit. Reads served pages and proposes fixes with
            // evidence; the only automation is the reversible scrub list.
            $registry->add(new AuditorModule());
            // Assisted-tool providers, settings, and the shared service.
            $registry->add(new IntelligenceModule());
            // Constituent storage and administration.
            $registry->add(new ConstituentsModule());
            // Encrypted spreadsheet imports. Boots after constituents so
            // regional assignment and identity resolution are available.
            $registry->add(new ImportModule());
            // Organizer accounts, roster access, attendance, messages,
            // exports, and reviewed event proposals.
            $registry->add(new OrganizerPortalModule());
            // Newsletters, subscriber groups, and delivery.
            $registry->add(new CorrespondenceModule());
            // Money ledger and QuickBooks export.
            $registry->add(new RevenueModule());
            // Event history, follow-up mail, and organizer identity.
            $registry->add(new EngagementModule());
            // The mobile organizer app. Boots after the portal, subscriber
            // groups, and revenue ledger it reuses.
            $registry->add(new OrganizerAppModule());
            // Little Green Light synchronization.
            $registry->add(new ConnectorsModule());
            // Aggregate operational insights.
            $registry->add(new InsightsModule());
            // Grant reports over local aggregate data.
            $registry->add(new GrantReportingModule());
            return $registry;
        });
    }

    public function activate(): void
    {
        if (!$this->schemaReady(true)) {
            /** @var Schema $schema */
            $schema = $this->container->get('schema');
            wp_die(
                esc_html('BosqueGrande could not set up its database. ' . $schema->blockedReason()),
                'BosqueGrande database setup incomplete',
                ['back_link' => true]
            );
        }
        /** @var CryptoMigrator $crypto */
        $crypto = $this->container->get('crypto.migrator');
        $crypto->activate();
        /** @var ModuleRegistry $modules */
        $modules = $this->container->get('modules');
        $modules->activate();
        flush_rewrite_rules();
        // First-run: send the admin to the setup wizard once.
        SetupWizard::markForFirstRun();
        $this->log()->info('lifecycle', 'BosqueGrande activated.', ['version' => BG_VERSION]);
    }

    public function deactivate(): void
    {
        /** @var CryptoMigrator $crypto */
        $crypto = $this->container->get('crypto.migrator');
        $crypto->deactivate();
        /** @var ModuleRegistry $modules */
        $modules = $this->container->get('modules');
        $modules->deactivate();
        /** @var Jobs $jobs */
        $jobs = $this->container->get('jobs');
        $jobs->clearAll();
        wp_clear_scheduled_hook(Logger::PRUNE_HOOK);
        flush_rewrite_rules();
        // Deliberately non-destructive: no table drops, no option deletion.
        // Destructive cleanup belongs to uninstall.php behind a setting.
    }

    public function boot(): void
    {
        if ($this->booted) return;
        $this->booted = true;

        if (!$this->schemaReady()) {
            return;
        }

        /** @var CryptoMigrator $crypto */
        $crypto = $this->container->get('crypto.migrator');
        $crypto->boot();

        /** @var ModuleRegistry $modules */
        $modules = $this->container->get('modules');
        $modules->boot();

        add_action(Logger::PRUNE_HOOK, fn() => $this->log()->prune());
        if (!wp_next_scheduled(Logger::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Logger::PRUNE_HOOK);
        }

        // Migrated events stamp this LGL Event Code (settings-backed).
        add_filter('bg_lgl_event_code', function ($default) {
            /** @var Settings $settings */
            $settings = $this->container->get('settings');
            $configured = (string) $settings->get('lgl_event_code', '');
            return $configured !== '' ? $configured : $default;
        });

        // Demographic mapping layer (ADR-8): the LGL category name is a
        // setting; everything else reads it through this one filter.
        add_filter('bg_demographic_lgl_category', function ($default) {
            /** @var Settings $settings */
            $settings = $this->container->get('settings');
            $configured = (string) $settings->get('demographic_lgl_category', '');
            return $configured !== '' ? $configured : $default;
        });

        // The age range and relationship category names are settings too,
        // so an LGL account that names them differently needs no code.
        add_filter('bg_age_range_lgl_category', function ($default) {
            /** @var Settings $settings */
            $settings = $this->container->get('settings');
            $configured = (string) $settings->get('age_range_lgl_category', '');
            return $configured !== '' ? $configured : $default;
        });
        add_filter('bg_relationship_lgl_category', function ($default) {
            /** @var Settings $settings */
            $settings = $this->container->get('settings');
            $configured = (string) $settings->get('relationship_lgl_category', '');
            return $configured !== '' ? $configured : $default;
        });

        if (is_admin()) {
            (new Admin($this->container))->register();
            (new SectionNav($this->container))->register();
            (new Reinstaller($this->container))->register();
            (new SettingsPage($this->container))->register();
            (new SetupWizard())->boot($this->container);
        }

        do_action('bosquegrande_booted', $this->container);
    }

    /**
     * Install or verify the schema once per plugin version. Fails closed:
     * no module starts on an unverified database.
     */
    private function schemaReady(bool $force = false): bool
    {
        $proof = BG_VERSION . ':' . Schema::VERSION;
        if (!$force
            && (int) get_option('bg_db_version', 0) === Schema::VERSION
            && hash_equals($proof, (string) get_option(self::SCHEMA_PROOF_OPTION, ''))
        ) {
            return true;
        }
        /** @var Schema $schema */
        $schema = $this->container->get('schema');
        if ($schema->run()) {
            update_option(self::SCHEMA_PROOF_OPTION, $proof, false);
            $this->log()->info('schema', 'Database schema verified.', ['version' => Schema::VERSION]);
            return true;
        }
        delete_option(self::SCHEMA_PROOF_OPTION);
        if (is_admin()) {
            $reason = $schema->blockedReason();
            add_action('admin_notices', static function () use ($reason): void {
                echo '<div class="notice notice-error"><p><strong>BosqueGrande is paused:</strong> '
                    . esc_html($reason)
                    . '</p></div>';
            });
        }
        return false;
    }

    private function log(): Logger
    {
        return $this->container->get('logger');
    }
}
