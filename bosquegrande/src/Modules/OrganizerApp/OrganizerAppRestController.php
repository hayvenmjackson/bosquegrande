<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Modules\OrganizerPortal\OrganizerAccess;

if (!defined('ABSPATH')) exit;

/** Authenticated REST surface for the mobile organizer application. */
final class OrganizerAppRestController
{
    private const NS = 'bosquegrande/v1';
    private const BASE = '/organizer-app';

    public function __construct(
        private OrganizerAppService $service,
        private OrganizerAccess $access,
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NS, self::BASE . '/bootstrap', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => fn(): bool|\WP_Error => $this->canUseApp(),
            'callback' => fn() => $this->response($this->service->bootstrap(get_current_user_id())),
        ]);
        register_rest_route(self::NS, self::BASE . '/events', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => fn(): bool|\WP_Error => $this->canUseApp(),
            'callback' => fn() => $this->response(['events' => $this->service->events(get_current_user_id())]),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'view'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->event(get_current_user_id(), (int) $request['event_id'])
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/roster', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'roster'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->roster(get_current_user_id(), (int) $request['event_id'])
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/attendance', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'check_in'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->attendance(
                    get_current_user_id(),
                    (int) $request['event_id'],
                    $this->data($request)
                )
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/attendee-lookup', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'walk_in'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->attendeeLookup(
                    get_current_user_id(),
                    (int) $request['event_id'],
                    $this->data($request)
                )
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/walk-ins', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'walk_in'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->createWalkIn(
                    get_current_user_id(),
                    (int) $request['event_id'],
                    $this->data($request)
                )
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/attendee-session', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'private_answers'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->issueAttendeeSession(
                    get_current_user_id(),
                    (int) $request['event_id'],
                    $this->data($request)
                )
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/tenders', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'tender'),
            'callback' => fn(\WP_REST_Request $request) => $this->response(
                $this->service->recordTender(
                    get_current_user_id(),
                    (int) $request['event_id'],
                    $this->data($request)
                )
            ),
        ]);
        register_rest_route(self::NS, self::BASE . '/events/(?P<event_id>\d+)/closeout', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'close_out'),
                'callback' => fn(\WP_REST_Request $request) => $this->response(
                    $this->service->closeout(get_current_user_id(), (int) $request['event_id'])
                ),
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => fn(\WP_REST_Request $request): bool|\WP_Error => $this->canEvent($request, 'close_out'),
                'callback' => fn(\WP_REST_Request $request) => $this->response(
                    $this->service->submitCloseout(
                        get_current_user_id(),
                        (int) $request['event_id'],
                        $this->data($request)
                    )
                ),
            ],
        ]);

        // One-time attendee handoff. The 256-bit token is the authorization;
        // the endpoint returns no existing profile values or contact details.
        register_rest_route(self::NS, self::BASE . '/attendee/(?P<token>[A-Fa-f0-9]{64})', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'permission_callback' => fn(): bool|\WP_Error => $this->appReady(),
                'callback' => fn(\WP_REST_Request $request) => $this->response(
                    $this->service->attendeeForm((string) $request['token'])
                ),
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => fn(): bool|\WP_Error => $this->appReady(),
                'callback' => fn(\WP_REST_Request $request) => $this->response(
                    $this->service->submitAttendee((string) $request['token'], $this->data($request))
                ),
            ],
        ]);
    }

    public static function addNoStoreHeaders(\WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request): \WP_HTTP_Response
    {
        if (str_starts_with($request->get_route(), '/' . self::NS . self::BASE)) {
            $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('X-Content-Type-Options', 'nosniff');
        }
        return $response;
    }

    private function canUseApp(): bool|\WP_Error
    {
        if (!$this->access->canUsePortal(get_current_user_id())) return false;
        return $this->appReady();
    }

    private function appReady(): bool|\WP_Error
    {
        return $this->service->available()
            ? true
            : new \WP_Error(
                'schema_unavailable',
                OrganizerAppWording::text('organizer_app_api_schema_unavailable', 'The organizer app database upgrade has not completed.'),
                ['status' => 503]
            );
    }

    private function canEvent(\WP_REST_Request $request, string $action): bool|\WP_Error
    {
        $ready = $this->appReady();
        if (is_wp_error($ready)) return $ready;
        return $this->access->canEvent(
            get_current_user_id(),
            absint($request['event_id'] ?? 0),
            $action
        );
    }

    /** @return array<string,mixed> */
    private function data(\WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        return is_array($json) ? $json : $request->get_params();
    }

    private function response(array|\WP_Error $result): \WP_REST_Response|\WP_Error
    {
        if (is_wp_error($result)) return $result;
        $response = new \WP_REST_Response(['ok' => true, 'data' => $result], 200);
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }
}
