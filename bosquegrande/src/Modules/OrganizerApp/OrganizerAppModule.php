<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\OrganizerPortal\OrganizerAccess;

if (!defined('ABSPATH')) exit;

/** Mobile-first PWA for event check-in, walk-ins, money, privacy, and closeout. */
final class OrganizerAppModule implements ModuleInterface
{
    public function name(): string
    {
        return 'organizer_app';
    }

    public function label(): string
    {
        return 'Event Organizer App';
    }

    public function activate(Container $container): void
    {
        OrganizerAccess::ensureRole();
    }

    public function deactivate(Container $container): void
    {
        // Event workflow records and attendee audit history are durable.
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [OrganizerAppWording::class, 'defaults']);

        $container->set('organizer_app.repository', fn(Container $c) => new OrganizerAppRepository(
            $c->get('encryption')
        ));
        $container->set('organizer_app.service', fn(Container $c) => new OrganizerAppService(
            $c->get('organizer_app.repository'),
            $c->get('organizers.repository'),
            $c->get('organizers.access'),
            $c->get('constituents.store'),
            $c->get('constituents.regions'),
            $c->get('registration.repo'),
            $c->get('revenue.ledger'),
            $c->get('correspondence.groups'),
            $c->get('registration.monthly_donor'),
            $c->get('logger'),
            $c->get('engagement.history')
        ));

        /** @var OrganizerAccess $access */
        $access = $container->get('organizers.access');
        (new OrganizerAppRenderer($access))->register();

        $controller = new OrganizerAppRestController(
            $container->get('organizer_app.service'),
            $access
        );
        add_action('rest_api_init', [$controller, 'register']);
        add_filter('rest_post_dispatch', [OrganizerAppRestController::class, 'addNoStoreHeaders'], 10, 3);

        /** @var OrganizerAppRepository $repository */
        $repository = $container->get('organizer_app.repository');
        add_filter('bg_organizer_event_writes_locked', static function (bool $locked, int $eventId) use ($repository): bool {
            // Preserve the existing server-rendered portal if the app schema or
            // encryption migration is unavailable. The PWA itself fails closed,
            // but the legacy attendance workflow must not query missing tables.
            return $repository->available() ? ($locked || $repository->writeLocked($eventId)) : $locked;
        }, 10, 2);

        add_filter('bg_organizer_event_lock_acquire', static function (bool $allowed, int $eventId) use ($repository): bool {
            return $repository->available()
                ? ($allowed && $repository->acquireEventLock($eventId, 3))
                : $allowed;
        }, 10, 2);
        add_action('bg_organizer_event_lock_release', static function (int $eventId) use ($repository): void {
            if ($repository->available()) $repository->releaseEventLock($eventId);
        }, 10, 1);

        if (is_admin()) {
            (new OrganizerAppAdmin(
                $repository,
                $container->get('organizer_app.service'),
                $container->get('constituents.store'),
                $container->get('organizers.repository'),
                $container->get('engagement.payment_details')
            ))->register();
        }

        add_action('init', static function (): void {
            if ((string) get_option('bg_organizer_app_role_version', '') !== BG_VERSION) {
                OrganizerAccess::ensureRole();
                update_option('bg_organizer_app_role_version', BG_VERSION, false);
            }
        }, 41);
    }
}
