<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\Settings;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Imports\ImportStore;
use BosqueGrande\Modules\Registration\RegistrationRepository;

if (!defined('ABSPATH')) exit;

/**
 * Administrator-initiated Eventbrite attendee -> site registration transfer.
 *
 * Deliberate boundaries:
 * - A transfer is possible only for a site event carrying one unambiguous
 *   Eventbrite event id. It never guesses from a title or date.
 * - Eventbrite attendee ids are durable, encrypted provenance keys. An order
 *   id is not sufficient because one order can contain several attendees.
 * - Eventbrite money is evidence, not a local payment. Imported registrations
 *   have amount zero and this class never writes the payments ledger.
 * - Eventbrite answers are retained as an encrypted registration snapshot.
 *   They do not overwrite BG's canonical personal answers, consents, or
 *   newsletter subscriptions.
 * - Only Eventbrite-created, unpaid rows are cancelled by a later Eventbrite
 *   cancellation. A native or paid site registration remains authoritative.
 */
final class EventbriteRegistrationImporter
{
    private const API_ROOT = 'https://www.eventbriteapi.com/v3/';
    private const SOURCE = 'eventbrite_api';
    private const TIER = 'eventbrite';
    private const MAX_PAGES = 1000;
    private const MAX_ORDERS = 10000;
    private const MAX_ATTENDEES = 25000;
    private const MAX_RESPONSE_BYTES = 5242880;
    private const MAX_TOTAL_RESPONSE_BYTES = 20971520;

    /** @var array<int,true> Registrations provisionally claimed by a dry run. */
    private array $previewClaimedRegistrations = [];

    public function __construct(
        private Settings $settings,
        private Encryption $encryption,
        private ConstituentStore $constituents,
        private RegistrationRepository $registrations,
        private ImportStore $imports,
        private Logger $logger,
    ) {
    }

    /** Return an operator-safe configuration defect, or null when ready. */
    public function configurationError(): ?string
    {
        if ($this->settings->secretUnreadable('eventbrite_token')) {
            return 'The saved Eventbrite token cannot be decrypted. Re-enter it in BosqueGrande Settings.';
        }
        if (trim((string) $this->settings->get('eventbrite_token', '')) === '') {
            return 'No readable Eventbrite private token is saved in BosqueGrande Settings.';
        }
        if (!preg_match('/^\d{1,32}$/', trim((string) $this->settings->get('eventbrite_org_id', '')))) {
            return 'No valid Eventbrite organization id is saved in BosqueGrande Settings.';
        }
        if (!$this->constituents->available()) {
            return 'Encrypted constituent storage is unavailable. No attendee data was transferred.';
        }
        if (!$this->imports->available()) {
            return 'Encrypted import provenance storage is unavailable. No attendee data was transferred.';
        }
        return null;
    }

    /**
     * Resolve the Eventbrite event on either side of the existing connector.
     * Exported site events use _bg_eb_event_id; imported Eventbrite events use
     * _bg_src/_bg_src_id and _bg_src_key. Conflicting ids are a hard stop.
     *
     * @return string|\WP_Error
     */
    public function linkedEventbriteId(int $eventPostId): string|\WP_Error
    {
        $post = get_post($eventPostId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event' || $post->post_status === 'trash') {
            return new \WP_Error('bg_eb_event_missing', 'The BosqueGrande event could not be found.');
        }

        $ids = [];
        $exported = trim((string) get_post_meta($eventPostId, '_bg_eb_event_id', true));
        if ($exported !== '') $ids[] = $exported;

        if ((string) get_post_meta($eventPostId, '_bg_src', true) === 'eventbrite') {
            $sourceId = trim((string) get_post_meta($eventPostId, '_bg_src_id', true));
            if ($sourceId !== '') $ids[] = $sourceId;
        }
        foreach ((array) get_post_meta($eventPostId, '_bg_src_key', false) as $sourceKey) {
            if (preg_match('/^eventbrite:(\d{1,32})$/', trim((string) $sourceKey), $match)) {
                $ids[] = $match[1];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        foreach ($ids as $id) {
            if (!preg_match('/^\d{1,32}$/', $id)) {
                return new \WP_Error('bg_eb_event_id_invalid', 'The linked Eventbrite event id is not valid.');
            }
        }
        if ($ids === []) {
            return new \WP_Error(
                'bg_eb_event_unlinked',
                'This site event is not linked to an Eventbrite event. Export it first or import the Eventbrite event through Data Transfer.'
            );
        }
        if (count($ids) !== 1) {
            return new \WP_Error(
                'bg_eb_event_conflict',
                'This site event carries conflicting Eventbrite ids. Staff must resolve the link before registrations can be transferred.'
            );
        }
        global $wpdb;
        $wpdb->last_error = '';
        $linkedPosts = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'bg_event' AND p.post_status <> 'trash'
               AND (
                    EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                            WHERE x.post_id = p.ID AND x.meta_key = '_bg_eb_event_id' AND x.meta_value = %s)
                    OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                               WHERE x.post_id = p.ID AND x.meta_key = '_bg_src_key' AND x.meta_value = %s)
                    OR (EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                WHERE x.post_id = p.ID AND x.meta_key = '_bg_src' AND x.meta_value = 'eventbrite')
                        AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} x
                                    WHERE x.post_id = p.ID AND x.meta_key = '_bg_src_id' AND x.meta_value = %s))
               )
             ORDER BY p.ID ASC LIMIT 2",
            $ids[0],
            'eventbrite:' . $ids[0],
            $ids[0]
        )) ?: [];
        if ($wpdb->last_error !== '') {
            return new \WP_Error(
                'bg_eb_event_link_check_failed',
                'BosqueGrande could not verify exclusive ownership of this Eventbrite link. No registrations were transferred.'
            );
        }
        $linkedPosts = array_values(array_unique(array_map('intval', $linkedPosts)));
        if (count($linkedPosts) > 1) {
            return new \WP_Error(
                'bg_eb_event_duplicate_link',
                'More than one site event carries this Eventbrite id. No registrations were transferred.'
            );
        }
        if (count($linkedPosts) !== 1 || $linkedPosts[0] !== $eventPostId) {
            return new \WP_Error(
                'bg_eb_event_link_changed',
                'This site event no longer exclusively owns the Eventbrite link. No registrations were transferred.'
            );
        }
        return $ids[0];
    }

    /**
     * Fetch every order before mutating BG, then reconcile each attendee.
     *
     * @return array{
     *   ok:bool,dry_run:bool,event_post_id:int,eventbrite_event_id:string,
     *   counts:array<string,int>,report:list<array<string,mixed>>,note:string
     * }|\WP_Error
     */
    public function syncEvent(int $eventPostId, bool $dryRun = false): array|\WP_Error
    {
        $this->previewClaimedRegistrations = [];
        $configurationError = $this->configurationError();
        if ($configurationError !== null) {
            return new \WP_Error('bg_eb_registration_configuration', $configurationError);
        }
        $eventbriteEventId = $this->linkedEventbriteId($eventPostId);
        if (is_wp_error($eventbriteEventId)) return $eventbriteEventId;
        if ((bool) apply_filters('bg_organizer_event_writes_locked', false, $eventPostId, 'eventbrite_registration')) {
            return new \WP_Error(
                'bg_eb_registration_closeout_locked',
                'This event is locked by its submitted closeout. Reopen the closeout before previewing or transferring Eventbrite registrations.',
                ['status' => 409]
            );
        }

        $syncLease = 'bg_eb_registration_sync_' . hash('sha256', $eventbriteEventId);
        $syncOwner = OptionLease::acquire($syncLease, 75 * MINUTE_IN_SECONDS);
        if ($syncOwner === '') {
            return new \WP_Error(
                'bg_eb_registration_busy',
                'A registration transfer for this Eventbrite event is already running. Wait for it to finish.',
                ['status' => 409]
            );
        }

        try {
            $verified = $this->verifyRemoteEvent($eventbriteEventId);
            if (is_wp_error($verified)) return $verified;

            $orders = $this->fetchOrders($eventbriteEventId);
            if (is_wp_error($orders)) return $orders;

            // A long, paginated API read must not outlive the local event
            // link that authorized it.
            $freshEventbriteId = $this->linkedEventbriteId($eventPostId);
            if (is_wp_error($freshEventbriteId)) return $freshEventbriteId;
            if (!hash_equals($eventbriteEventId, $freshEventbriteId)) {
                return new \WP_Error(
                    'bg_eb_event_link_changed',
                    'The Eventbrite link changed while registrations were being read. Nothing was transferred.'
                );
            }

            $counts = array_fill_keys(
                ['orders', 'attendees', 'created', 'matched', 'updated', 'cancelled', 'skipped', 'held', 'errors'],
                0
            );
            $counts['orders'] = count($orders);
            $report = [];
            $seen = [];

            foreach ($orders as $order) {
                foreach ($this->orderAttendees($order) as $attendee) {
                    $attendeeId = trim((string) ($attendee['id'] ?? ''));
                    if ($attendeeId !== '' && isset($seen[$attendeeId])) continue;
                    if ($attendeeId !== '') $seen[$attendeeId] = true;
                    $counts['attendees']++;

                    try {
                        $entry = $this->syncAttendee(
                            $eventPostId,
                            $eventbriteEventId,
                            $order,
                            $attendee,
                            $dryRun
                        );
                    } catch (\Throwable $e) {
                        $entry = [
                            'action' => 'errors',
                            'attendee_ref' => $this->attendeeRef($attendeeId),
                            'note' => 'The attendee could not be transferred safely.',
                        ];
                        $this->logger->error('exchange', 'An Eventbrite attendee transfer failed.', [
                            'event_post_id' => $eventPostId,
                            'eventbrite_event_id' => $eventbriteEventId,
                            'attendee_ref' => $this->attendeeRef($attendeeId),
                            'reason' => bg_substr($e->getMessage(), 0, 180),
                        ]);
                    }

                    $action = (string) ($entry['action'] ?? 'errors');
                    if (!array_key_exists($action, $counts)) $action = 'errors';
                    $counts[$action]++;
                    if (count($report) < 500) $report[] = $entry;
                }
            }

            $this->logger->info('exchange', $dryRun
                ? 'Eventbrite registration trial completed.'
                : 'Eventbrite registrations transferred.', [
                    'event_post_id' => $eventPostId,
                    'eventbrite_event_id' => $eventbriteEventId,
                    'counts' => $counts,
                    'dry_run' => $dryRun,
                ]);

            return [
                'ok' => $counts['errors'] === 0,
                'dry_run' => $dryRun,
                'event_post_id' => $eventPostId,
                'eventbrite_event_id' => $eventbriteEventId,
                'counts' => $counts,
                'report' => $report,
                'note' => count($report) < $counts['attendees']
                    ? 'The detailed report is limited to the first 500 attendees; all attendees were processed.'
                    : '',
            ];
        } finally {
            OptionLease::release($syncLease, $syncOwner);
        }
    }

    /** Verify the linked event belongs to the configured organization before reading attendees. */
    private function verifyRemoteEvent(string $eventbriteEventId): bool|\WP_Error
    {
        $token = trim((string) $this->settings->get('eventbrite_token', ''));
        $organizationId = trim((string) $this->settings->get('eventbrite_org_id', ''));
        $response = wp_remote_get(self::API_ROOT . 'events/' . rawurlencode($eventbriteEventId) . '/', [
            'timeout' => 25,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('bg_eb_event_network', 'The linked Eventbrite event could not be verified. Nothing was transferred.', ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($decoded)) {
            $message = match ($status) {
                401 => 'Eventbrite rejected the saved token. Re-enter it in BosqueGrande Settings.',
                403 => 'The Eventbrite account cannot read this event.',
                404 => 'The linked Eventbrite event was not found or is not visible to this account.',
                429 => 'Eventbrite temporarily rate-limited the registration transfer. Wait a few minutes and try again.',
                default => 'The linked Eventbrite event could not be verified. Nothing was transferred.',
            };
            return new \WP_Error('bg_eb_event_http', $message, ['status' => $status]);
        }
        $remoteId = trim((string) ($decoded['id'] ?? ''));
        $remoteOrganization = trim((string) ($decoded['organization_id'] ?? ''));
        if (!hash_equals($eventbriteEventId, $remoteId)
            || !preg_match('/^\d{1,32}$/', $remoteOrganization)
            || !hash_equals($organizationId, $remoteOrganization)
        ) {
            return new \WP_Error(
                'bg_eb_event_organization',
                'The linked Eventbrite event does not belong to the configured organization. Nothing was transferred.'
            );
        }
        return true;
    }

    /** @return list<array<string,mixed>>|\WP_Error */
    private function fetchOrders(string $eventbriteEventId): array|\WP_Error
    {
        $token = trim((string) $this->settings->get('eventbrite_token', ''));
        $orders = [];
        $attendeeCount = 0;
        $responseBytes = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = http_build_query([
                'status' => 'both',
                'expand' => 'attendees',
                'page' => $page,
                'page_size' => 100,
            ], '', '&', PHP_QUERY_RFC3986);
            $url = self::API_ROOT . 'events/' . rawurlencode($eventbriteEventId) . '/orders/?' . $query;
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'sslverify' => true,
                'limit_response_size' => self::MAX_RESPONSE_BYTES,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);
            if (is_wp_error($response)) {
                return new \WP_Error(
                    'bg_eb_orders_network',
                    'Eventbrite registrations could not be reached. Nothing was transferred.',
                    ['status' => 502]
                );
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $rawBody = (string) wp_remote_retrieve_body($response);
            $responseBytes += strlen($rawBody);
            if ($responseBytes > self::MAX_TOTAL_RESPONSE_BYTES) {
                return new \WP_Error(
                    'bg_eb_orders_response_limit',
                    'This Eventbrite event exceeds the safe response-size limit for one transfer. Nothing was transferred.'
                );
            }
            $decoded = json_decode($rawBody, true);
            if ($status !== 200 || !is_array($decoded)) {
                $detail = is_array($decoded)
                    ? sanitize_text_field((string) ($decoded['error_description'] ?? $decoded['error'] ?? ''))
                    : '';
                $message = match ($status) {
                    401 => 'Eventbrite rejected the saved token. Re-enter the private token in BosqueGrande Settings.',
                    403 => 'The Eventbrite account cannot read orders for this event. An organization owner must grant order/report access.',
                    404 => 'The linked Eventbrite event was not found or is not visible to the saved token.',
                    429 => 'Eventbrite temporarily rate-limited the registration transfer. Wait a few minutes and try again.',
                    default => 'Eventbrite did not return a usable registration list.',
                };
                if ($detail !== '' && !in_array($status, [401, 403], true)) {
                    $message .= ' ' . bg_substr($detail, 0, 180);
                }
                return new \WP_Error('bg_eb_orders_http', $message, ['status' => $status]);
            }

            if (!array_key_exists('orders', $decoded) || !is_array($decoded['orders'])
                || !isset($decoded['pagination']) || !is_array($decoded['pagination'])
                || !array_key_exists('has_more_items', $decoded['pagination'])
            ) {
                return new \WP_Error(
                    'bg_eb_orders_shape',
                    'Eventbrite returned an incomplete order page. Nothing was transferred.'
                );
            }

            foreach ($decoded['orders'] as $order) {
                if (!is_array($order)) {
                    return new \WP_Error(
                        'bg_eb_order_shape',
                        'Eventbrite returned a malformed order. Nothing was transferred.'
                    );
                }
                $orderEventId = trim((string) ($order['event_id'] ?? ''));
                $orderId = trim((string) ($order['id'] ?? ''));
                if (!preg_match('/^\d{1,32}$/', $orderId)
                    || !preg_match('/^\d{1,32}$/', $orderEventId)
                    || !hash_equals($eventbriteEventId, $orderEventId)
                ) {
                    return new \WP_Error(
                        'bg_eb_order_event_conflict',
                        'Eventbrite returned an order for a different event. Nothing was transferred.'
                    );
                }

                if (!array_key_exists('attendees', $order) || !is_array($order['attendees'])) {
                    return new \WP_Error(
                        'bg_eb_attendees_missing',
                        'Eventbrite did not expand the attendees for every order. Nothing was transferred.'
                    );
                }
                $expanded = $order['attendees'];
                if (array_key_exists('pagination', $expanded)) {
                    if (!is_array($expanded['pagination'])
                        || !array_key_exists('has_more_items', $expanded['pagination'])
                    ) {
                        return new \WP_Error(
                            'bg_eb_attendees_pagination_shape',
                            'Eventbrite returned malformed attendee pagination. Nothing was transferred.'
                        );
                    }
                    if (!empty($expanded['pagination']['has_more_items'])) {
                        return new \WP_Error(
                            'bg_eb_attendees_partial',
                            'An Eventbrite order contains more attendee pages than the expanded response supplied. Nothing was transferred.'
                        );
                    }
                }
                $expandedRows = array_key_exists('attendees', $expanded) ? $expanded['attendees'] : $expanded;
                if (!is_array($expandedRows)) {
                    return new \WP_Error(
                        'bg_eb_attendees_shape',
                        'Eventbrite returned a malformed attendee collection. Nothing was transferred.'
                    );
                }
                foreach ($expandedRows as $candidate) {
                    if (!is_array($candidate)) {
                        return new \WP_Error(
                            'bg_eb_attendee_shape',
                            'Eventbrite returned a malformed attendee record. Nothing was transferred.'
                        );
                    }
                }
                $attendees = $this->orderAttendees($order);
                foreach ($attendees as $attendee) {
                    $attendeeId = trim((string) ($attendee['id'] ?? ''));
                    $attendeeEventId = trim((string) ($attendee['event_id'] ?? ''));
                    $attendeeOrderId = trim((string) ($attendee['order_id'] ?? ''));
                    if (!preg_match('/^\d{1,32}$/', $attendeeId)
                        || !preg_match('/^\d{1,32}$/', $attendeeEventId)
                        || !hash_equals($eventbriteEventId, $attendeeEventId)
                        || !preg_match('/^\d{1,32}$/', $attendeeOrderId)
                        || !hash_equals($orderId, $attendeeOrderId)
                    ) {
                        return new \WP_Error(
                            'bg_eb_attendee_binding',
                            'Eventbrite returned an attendee whose event or order link is inconsistent. Nothing was transferred.'
                        );
                    }
                }
                $attendeeCount += count($attendees);
                $orders[] = $order;
                if (count($orders) > self::MAX_ORDERS || $attendeeCount > self::MAX_ATTENDEES) {
                    return new \WP_Error(
                        'bg_eb_orders_limit',
                        'This Eventbrite event exceeds the safe one-request transfer limit. Nothing was transferred.'
                    );
                }
            }
            if (empty($decoded['pagination']['has_more_items'])) return $orders;
        }

        return new \WP_Error(
            'bg_eb_orders_pagination',
            'Eventbrite returned more registration pages than the safety limit. Nothing was transferred.'
        );
    }

    /** @param array<string,mixed> $order @return list<array<string,mixed>> */
    private function orderAttendees(array $order): array
    {
        $raw = $order['attendees'] ?? [];
        if (is_array($raw) && isset($raw['attendees']) && is_array($raw['attendees'])) {
            $raw = $raw['attendees'];
        }
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, 'is_array'));
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attendee
     * @return array<string,mixed>
     */
    private function syncAttendee(
        int $eventPostId,
        string $eventbriteEventId,
        array $order,
        array $attendee,
        bool $dryRun
    ): array {
        $attendeeId = trim((string) ($attendee['id'] ?? ''));
        if (!preg_match('/^\d{1,32}$/', $attendeeId)) {
            return ['action' => 'held', 'attendee_ref' => '', 'note' => 'Eventbrite did not provide a durable attendee id.'];
        }

        $state = $this->attendeeState($order, $attendee);
        $linked = $this->imports->external(self::SOURCE, 'registration', $attendeeId);
        if ($linked) {
            return $this->reconcileLinked(
                $eventPostId,
                $eventbriteEventId,
                $order,
                $attendee,
                $attendeeId,
                $state,
                $linked,
                $dryRun
            );
        }
        if ($state === 'excluded') {
            return ['action' => 'skipped', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'Refunded or cancelled before first transfer.'];
        }
        if ($state !== 'active') {
            return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'Eventbrite returned an unrecognized attendee or order status.'];
        }

        $profile = $this->profile($attendee);
        if ($profile['first_name'] === '' || $profile['last_name'] === '') {
            return [
                'action' => 'held',
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'note' => 'Attendee-specific first and last name are incomplete; buyer details were not substituted.',
            ];
        }

        $readOnlyMatch = $this->resolvePerson($profile);
        if ($dryRun) {
            $existing = $readOnlyMatch > 0
                ? $this->latestActiveRegistration($eventPostId, $readOnlyMatch)
                : null;
            if ($existing && ((string) $existing->tier === 'historical'
                || $this->registrationHasExternalProvenance((int) $existing->id)
            )) {
                $existing = null;
            }
            if ($existing && in_array((string) $existing->status, ['confirmed', 'paid'], true)) {
                $existingId = (int) $existing->id;
                if (!isset($this->previewClaimedRegistrations[$existingId])) {
                    $this->previewClaimedRegistrations[$existingId] = true;
                    return [
                        'action' => 'matched',
                        'attendee_ref' => $this->attendeeRef($attendeeId),
                        'registration_id' => $existingId,
                        'note' => 'Trial: would link to the existing site registration.',
                    ];
                }
                // A native registration can represent only one Eventbrite
                // ticket. Later attendee ids for the same person need their
                // own zero-dollar registration, exactly as the live run does.
                $existing = null;
            }
            if ($existing) {
                return [
                    'action' => 'held',
                    'attendee_ref' => $this->attendeeRef($attendeeId),
                    'registration_id' => (int) $existing->id,
                    'note' => 'Trial: an unfinished site registration requires staff review.',
                ];
            }
            return [
                'action' => 'created',
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'note' => $readOnlyMatch > 0
                    ? 'Trial: would create a registration on the matched constituent.'
                    : 'Trial: would create a constituent and registration.',
            ];
        }

        // Match OneForm's email lease before touching the person or event.
        // This keeps a background transfer from interrupting a public form
        // after that visitor has already saved encrypted answers.
        $emailLease = '';
        $emailOwner = '';
        if ($profile['email'] !== '') {
            $blindIndex = $this->encryption->blindIndex(ConstituentStore::normEmail($profile['email']));
            if ($blindIndex === '') {
                return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'The attendee email could not be matched through encrypted identity storage.'];
            }
            $emailLease = 'bg_reg_email_' . hash('sha256', $blindIndex);
            $emailOwner = OptionLease::acquire($emailLease, 15 * MINUTE_IN_SECONDS);
            if ($emailOwner === '') {
                return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This email is already being processed by a site registration.'];
            }
        }

        $attendeeLease = 'bg_eb_attendee_' . hash('sha256', $attendeeId);
        $eventLockHeld = (bool) apply_filters(
            'bg_organizer_event_lock_acquire',
            true,
            $eventPostId,
            'eventbrite_registration'
        );
        if (!$eventLockHeld) {
            if ($emailOwner !== '') OptionLease::release($emailLease, $emailOwner);
            return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This event is being updated by another staff process.'];
        }
        if ((bool) apply_filters('bg_organizer_event_writes_locked', false, $eventPostId, 'eventbrite_registration')) {
            do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
            if ($emailOwner !== '') OptionLease::release($emailLease, $emailOwner);
            return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'The submitted event closeout must be reopened before registrations can change.'];
        }
        $attendeeOwner = OptionLease::acquire($attendeeLease, 15 * MINUTE_IN_SECONDS);
        if ($attendeeOwner === '') {
            do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
            if ($emailOwner !== '') OptionLease::release($emailLease, $emailOwner);
            return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This attendee is already being transferred.'];
        }

        $registrationLease = '';
        $registrationOwner = '';
        try {
            // The attendee may have been linked while this request waited.
            $linked = $this->imports->external(self::SOURCE, 'registration', $attendeeId);
            if ($linked) {
                return $this->reconcileLinked(
                    $eventPostId,
                    $eventbriteEventId,
                    $order,
                    $attendee,
                    $attendeeId,
                    $state,
                    $linked,
                    false,
                    true,
                    true
                );
            }

            $beforeIds = $profile['email'] !== ''
                ? $this->constituents->idsByEmail($profile['email'])
                : [];
            $constituentId = $this->ensurePerson($profile);
            if ($constituentId < 1) {
                throw new \RuntimeException('A safe constituent match or create was not possible.');
            }
            $newConstituent = !in_array($constituentId, $beforeIds, true) && $readOnlyMatch < 1;

            // Acquire the public OneForm's exact event/person lease before
            // opening the transaction. An option inserted inside this
            // transaction would not be visible to a concurrent OneForm.
            $registrationLease = 'bg_reg_submit_' . hash('sha256', $eventPostId . '|' . $constituentId);
            $registrationOwner = OptionLease::acquire($registrationLease, 15 * MINUTE_IN_SECONDS);
            if ($registrationOwner === '') {
                return [
                    'action' => 'held',
                    'attendee_ref' => $this->attendeeRef($attendeeId),
                    'note' => 'This person is already being registered for the site event.',
                ];
            }

            if (!$this->constituents->beginAtomic()) {
                throw new \RuntimeException('The database transaction could not be started.');
            }
            try {
                $existing = $this->latestActiveRegistration($eventPostId, $constituentId, true);
                // One Eventbrite attendee is one ticket registration. The
                // first attendee may attach to an already-confirmed native BG
                // row, but a second distinct attendee id for the same person
                // must retain its own registration/provenance row.
                if ($existing && ((string) $existing->tier === 'historical'
                    || $this->registrationHasExternalProvenance((int) $existing->id)
                )) {
                    $existing = null;
                }
                $created = false;
                if ($existing && in_array((string) $existing->status, ['confirmed', 'paid'], true)) {
                    $registrationId = (int) $existing->id;
                } elseif ($existing) {
                    $this->constituents->rollbackAtomic();
                    return [
                        'action' => 'held',
                        'attendee_ref' => $this->attendeeRef($attendeeId),
                        'registration_id' => (int) $existing->id,
                        'note' => 'An unfinished site registration requires staff review.',
                    ];
                } else {
                    $record = $this->constituents->get($constituentId) ?: [];
                    $registrationId = $this->registrations->insert([
                        'event_post_id' => $eventPostId,
                        'lgl_event_id' => null,
                        'lgl_constituent_id' => (int) ($record['lgl_constituent_id'] ?? 0) ?: null,
                        'bg_constituent_id' => $constituentId,
                        'tier' => self::TIER,
                        'amount_cents' => 0,
                        'status' => 'confirmed',
                        'volunteer_interest' => false,
                        'lgl_invitation_id' => null,
                        'is_new_constituent' => $newConstituent,
                    ]);
                    if ($registrationId < 1) throw new \RuntimeException('The site registration row could not be created.');
                    $created = true;
                    if (!$this->preserveCreatedAt($registrationId, $this->sourceCreatedAt($order, $attendee))) {
                        throw new \RuntimeException('The Eventbrite registration timestamp could not be preserved.');
                    }
                }

                if (!$this->saveProvenance(
                    $registrationId,
                    $eventbriteEventId,
                    $order,
                    $attendee,
                    $attendeeId
                )) throw new \RuntimeException('Encrypted Eventbrite provenance could not be saved.');

                if (!$this->saveAttendance($registrationId, $eventPostId, $attendee, $created)) {
                    throw new \RuntimeException('The initial attendance state could not be saved.');
                }
                if (!$this->constituents->commitAtomic()) {
                    throw new \RuntimeException('The registration transaction could not be committed.');
                }

                return [
                    'action' => $created ? 'created' : 'matched',
                    'attendee_ref' => $this->attendeeRef($attendeeId),
                    'registration_id' => $registrationId,
                    'constituent_id' => $constituentId,
                    'note' => $created
                        ? 'Eventbrite registration created; no local payment or consent was inferred.'
                        : 'Linked to an existing confirmed site registration; its local status and money were preserved.',
                ];
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                throw $e;
            }
        } finally {
            if ($registrationOwner !== '') OptionLease::release($registrationLease, $registrationOwner);
            OptionLease::release($attendeeLease, $attendeeOwner);
            do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
            if ($emailOwner !== '') OptionLease::release($emailLease, $emailOwner);
        }
    }

    /**
     * @param array<string,mixed> $order @param array<string,mixed> $attendee
     * @return array<string,mixed>
     */
    private function reconcileLinked(
        int $eventPostId,
        string $eventbriteEventId,
        array $order,
        array $attendee,
        string $attendeeId,
        string $state,
        object $linked,
        bool $dryRun,
        bool $leaseHeld = false,
        bool $eventLockHeld = false
    ): array {
        $registration = $this->registrations->find((int) $linked->entity_id);
        if (!$registration) {
            return [
                'action' => 'held',
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'note' => 'The Eventbrite provenance points to a missing site registration; staff repair is required.',
            ];
        }
        if ((int) $registration->event_post_id !== $eventPostId) {
            return [
                'action' => 'held',
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'registration_id' => (int) $registration->id,
                'note' => 'The Eventbrite attendee is already linked to a different site event; no transfer was made.',
            ];
        }
        if ($state === 'unknown') {
            return [
                'action' => 'held',
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'registration_id' => (int) $registration->id,
                'note' => 'Eventbrite returned an unrecognized attendee or order status.',
            ];
        }

        $eventbriteOwned = (string) $registration->tier === self::TIER
            && (int) $registration->amount_cents === 0
            && trim((string) ($registration->stripe_payment_intent ?? '')) === ''
            && (int) ($registration->lgl_gift_id ?? 0) === 0
            && (string) $registration->status !== 'paid'
            && !$this->hasBgFinancialEvidence((int) $registration->id);
        $attendanceConflict = $state === 'excluded'
            && $eventbriteOwned
            && $this->hasAffirmativeAttendance((int) $registration->id);
        $willCancel = $state === 'excluded'
            && $eventbriteOwned
            && !$attendanceConflict
            && (string) $registration->status === 'confirmed';
        $willReactivate = $state === 'active'
            && $eventbriteOwned
            && in_array((string) $registration->status, ['cancelled', 'refunded'], true);
        $statusConflict = $eventbriteOwned
            && !in_array((string) $registration->status, ['confirmed', 'paid', 'cancelled', 'refunded'], true);

        if ($dryRun) {
            return [
                'action' => ($attendanceConflict || $statusConflict) ? 'held' : ($willCancel ? 'cancelled' : ($willReactivate ? 'updated' : 'matched')),
                'attendee_ref' => $this->attendeeRef($attendeeId),
                'registration_id' => (int) $registration->id,
                'note' => $attendanceConflict
                    ? 'Trial: Eventbrite shows a cancellation, but recorded attendance requires staff review.'
                    : ($statusConflict
                    ? 'Trial: the site registration has an in-progress or exceptional status and would be held for review.'
                    : ($willCancel
                    ? 'Trial: would cancel the Eventbrite-owned site registration.'
                    : ($willReactivate
                        ? 'Trial: would reactivate the Eventbrite-owned site registration.'
                        : 'Trial: would refresh encrypted Eventbrite provenance only.'))),
            ];
        }

        $personLease = '';
        $personOwner = '';
        $constituentId = (int) ($registration->bg_constituent_id ?? 0);
        if ($constituentId > 0) {
            $personLease = 'bg_reg_submit_' . hash('sha256', $eventPostId . '|' . $constituentId);
            $personOwner = OptionLease::acquire($personLease, 15 * MINUTE_IN_SECONDS);
            if ($personOwner === '') {
                return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This person is already being registered for the site event.'];
            }
        }

        $eventLockAcquired = false;
        if (!$eventLockHeld) {
            $eventLockAcquired = (bool) apply_filters(
                'bg_organizer_event_lock_acquire',
                true,
                $eventPostId,
                'eventbrite_registration'
            );
            if (!$eventLockAcquired) {
                if ($personOwner !== '') OptionLease::release($personLease, $personOwner);
                return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This event is being updated by another staff process.'];
            }
        }
        if ((bool) apply_filters('bg_organizer_event_writes_locked', false, $eventPostId, 'eventbrite_registration')) {
            if ($eventLockAcquired) {
                do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
            }
            if ($personOwner !== '') OptionLease::release($personLease, $personOwner);
            return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'The submitted event closeout must be reopened before registrations can change.'];
        }

        $lease = '';
        $owner = '';
        if (!$leaseHeld) {
            $lease = 'bg_eb_attendee_' . hash('sha256', $attendeeId);
            $owner = OptionLease::acquire($lease, 15 * MINUTE_IN_SECONDS);
            if ($owner === '') {
                if ($eventLockAcquired) {
                    do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
                }
                if ($personOwner !== '') OptionLease::release($personLease, $personOwner);
                return ['action' => 'held', 'attendee_ref' => $this->attendeeRef($attendeeId), 'note' => 'This attendee is already being transferred.'];
            }
        }
        try {
            if (!$this->constituents->beginAtomic()) throw new \RuntimeException('The database transaction could not be started.');
            try {
                $freshLink = $this->imports->external(self::SOURCE, 'registration', $attendeeId);
                $registration = $freshLink
                    ? $this->registrationForUpdate((int) $freshLink->entity_id)
                    : null;
                if (!$registration || (int) $registration->event_post_id !== $eventPostId) {
                    $this->constituents->rollbackAtomic();
                    return [
                        'action' => 'held',
                        'attendee_ref' => $this->attendeeRef($attendeeId),
                        'note' => 'The Eventbrite provenance changed during transfer; no registration state was changed.',
                    ];
                }
                if ((int) ($registration->bg_constituent_id ?? 0) !== $constituentId) {
                    $this->constituents->rollbackAtomic();
                    return [
                        'action' => 'held',
                        'attendee_ref' => $this->attendeeRef($attendeeId),
                        'registration_id' => (int) $registration->id,
                        'note' => 'The constituent link changed during transfer; retry after the merge or identity update finishes.',
                    ];
                }

                // Recompute every destructive predicate only after the
                // attendee/event locks and row locks are held. A stale read
                // must never downgrade a paid, native, or attended record.
                $eventbriteOwned = (string) $registration->tier === self::TIER
                    && (int) $registration->amount_cents === 0
                    && trim((string) ($registration->stripe_payment_intent ?? '')) === ''
                    && (int) ($registration->lgl_gift_id ?? 0) === 0
                    && (string) $registration->status !== 'paid'
                    && !$this->hasBgFinancialEvidence((int) $registration->id, true);
                $attendanceStatus = $this->attendanceStatusForUpdate((int) $registration->id);
                $attendanceConflict = $state === 'excluded'
                    && $eventbriteOwned
                    && in_array($attendanceStatus, ['checked_in', 'attended'], true);
                $willCancel = $state === 'excluded'
                    && $eventbriteOwned
                    && !$attendanceConflict
                    && (string) $registration->status === 'confirmed';
                $willReactivate = $state === 'active'
                    && $eventbriteOwned
                    && in_array((string) $registration->status, ['cancelled', 'refunded'], true);
                $statusConflict = $eventbriteOwned
                    && !in_array((string) $registration->status, ['confirmed', 'paid', 'cancelled', 'refunded'], true);
                if ($statusConflict) {
                    $this->constituents->rollbackAtomic();
                    return [
                        'action' => 'held',
                        'attendee_ref' => $this->attendeeRef($attendeeId),
                        'registration_id' => (int) $registration->id,
                        'note' => 'The site registration has an in-progress or exceptional status; Eventbrite did not overwrite it.',
                    ];
                }

                $currentStatus = (string) $registration->status;
                if ($willCancel && !$this->setRegistrationStatusLocked((int) $registration->id, $currentStatus, 'cancelled')) {
                    throw new \RuntimeException('The Eventbrite cancellation could not be applied.');
                }
                if ($willReactivate && !$this->setRegistrationStatusLocked((int) $registration->id, $currentStatus, 'confirmed')) {
                    throw new \RuntimeException('The Eventbrite registration could not be reactivated.');
                }
                if (!$this->saveProvenance(
                    (int) $registration->id,
                    $eventbriteEventId,
                    $order,
                    $attendee,
                    $attendeeId
                )) throw new \RuntimeException('Encrypted Eventbrite provenance could not be refreshed.');
                if ($state === 'active' && !$this->saveAttendance(
                    (int) $registration->id,
                    $eventPostId,
                    $attendee,
                    false
                )) throw new \RuntimeException('Eventbrite check-in evidence could not be reconciled.');
                if (!$this->constituents->commitAtomic()) throw new \RuntimeException('The reconciliation transaction could not be committed.');
            } catch (\Throwable $e) {
                $this->constituents->rollbackAtomic();
                throw $e;
            }
        } finally {
            if ($owner !== '') OptionLease::release($lease, $owner);
            if ($eventLockAcquired) {
                do_action('bg_organizer_event_lock_release', $eventPostId, 'eventbrite_registration');
            }
            if ($personOwner !== '') OptionLease::release($personLease, $personOwner);
        }

        return [
            'action' => $attendanceConflict ? 'held' : ($willCancel ? 'cancelled' : ($willReactivate ? 'updated' : 'matched')),
            'attendee_ref' => $this->attendeeRef($attendeeId),
            'registration_id' => (int) $registration->id,
            'note' => $attendanceConflict
                ? 'Eventbrite cancellation was retained as encrypted provenance; recorded attendance was preserved for staff review.'
                : ($willCancel
                ? 'Eventbrite-owned registration cancelled; no local payment row was altered.'
                : ($willReactivate
                    ? 'Eventbrite-owned registration reactivated.'
                    : ($state === 'excluded' && !$eventbriteOwned
                        ? 'Eventbrite cancellation recorded as provenance; the independent native or paid site registration was preserved.'
                        : 'Encrypted Eventbrite provenance refreshed.'))),
        ];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $attendee */
    private function attendeeState(array $order, array $attendee): string
    {
        if ($this->truthy($attendee['cancelled'] ?? null) || $this->truthy($attendee['refunded'] ?? null)) {
            return 'excluded';
        }
        $attendeeStatus = $this->key($attendee['status'] ?? '');
        $orderStatus = $this->key($order['status'] ?? '');
        // Treat only documented, completed attendee outcomes as cancellation
        // evidence. Unknown or future statuses (including pending/denied
        // variants) must wait for staff review instead of changing a local row.
        if (in_array($attendeeStatus, [
            'cancelled', 'canceled', 'refunded', 'not_attending',
        ], true)) {
            return 'excluded';
        }
        // These orders have not reached a completed/settled registration.
        // They are neither active attendees nor cancellation evidence.
        if (in_array($orderStatus, ['abandoned', 'pending', 'unpaid'], true)) {
            return 'unknown';
        }
        // An order may contain several tickets. A partial refund or refund
        // request is order-level evidence only, and even a terminal order
        // state is held unless this attendee carries its own cancellation or
        // refund evidence. That prevents one order flag from choosing the
        // wrong ticket registration.
        if (in_array($orderStatus, [
            'cancelled', 'canceled', 'refunded', 'refunded_cancelled',
            'refunded_canceled', 'transferred', 'deleted', 'inactive', 'voided',
        ], true)) {
            return 'unknown';
        }
        if ($attendeeStatus === '' || in_array($attendeeStatus, [
            'attending', 'checked_in', 'guest_attending', 'guests_attending',
            'guest_attended', 'guests_attended', 'guest', 'active', 'placed',
        ], true)) return 'active';
        return 'unknown';
    }

    /** @param array<string,mixed> $attendee @return array<string,string> */
    private function profile(array $attendee): array
    {
        $profile = is_array($attendee['profile'] ?? null) ? $attendee['profile'] : [];
        $addresses = is_array($profile['addresses'] ?? null) ? $profile['addresses'] : [];
        $address = is_array($addresses['home'] ?? null)
            ? $addresses['home']
            : (is_array($profile['address'] ?? null) ? $profile['address'] : []);
        $email = strtolower(sanitize_email((string) ($profile['email'] ?? '')));
        if (!is_email($email)) $email = '';
        return [
            'first_name' => bg_substr(sanitize_text_field((string) ($profile['first_name'] ?? '')), 0, 100),
            'last_name' => bg_substr(sanitize_text_field((string) ($profile['last_name'] ?? '')), 0, 100),
            'email' => $email,
            'phone' => bg_substr(sanitize_text_field((string) (
                $profile['cell_phone'] ?? $profile['home_phone'] ?? $profile['phone'] ?? ''
            )), 0, 80),
            'address1' => bg_substr(sanitize_text_field((string) ($address['address_1'] ?? $address['address1'] ?? '')), 0, 190),
            'address2' => bg_substr(sanitize_text_field((string) ($address['address_2'] ?? $address['address2'] ?? '')), 0, 190),
            'city' => bg_substr(sanitize_text_field((string) ($address['city'] ?? '')), 0, 100),
            'state' => bg_substr(sanitize_text_field((string) ($address['region'] ?? $address['state'] ?? '')), 0, 100),
            'postal' => bg_substr(sanitize_text_field((string) ($address['postal_code'] ?? $address['postal'] ?? '')), 0, 32),
            'notes' => '',
        ];
    }

    /** @param array<string,string> $profile */
    private function resolvePerson(array $profile): int
    {
        $first = trim($profile['first_name']);
        $last = trim($profile['last_name']);
        if ($first === '' || $last === '') return 0;

        $email = $profile['email'];
        if ($email !== '') {
            $ids = $this->constituents->idsByEmail($email);
            if (count($ids) === 1) {
                $record = $this->constituents->get((int) $ids[0]);
                if ($record && hash_equals(
                    ConstituentStore::normName((string) $record['first_name'], (string) $record['last_name']),
                    ConstituentStore::normName($first, $last)
                )) return (int) $ids[0];
                return 0;
            }
            if (count($ids) > 1) return $this->constituents->disambiguateIds($ids, $profile);
        }

        $ids = $this->constituents->idsByName($first, $last);
        if ($ids === []) return 0;
        $resolved = $this->constituents->disambiguateIds($ids, $profile);
        if ($resolved < 1) return 0;
        $record = $this->constituents->get($resolved);
        return $record && ConstituentStore::identityScore($record, $profile) >= 3 ? $resolved : 0;
    }

    /** @param array<string,string> $profile */
    private function ensurePerson(array $profile): int
    {
        if ($profile['email'] !== '') {
            return $this->constituents->upsertByEmail($profile['email'], $profile);
        }
        $id = $this->resolvePerson($profile);
        if ($id < 1) return $this->constituents->create($profile);
        $current = $this->constituents->get($id);
        if (!$current) return 0;
        $merged = $current;
        $changed = false;
        foreach (ConstituentStore::FIELDS as $field) {
            if (trim((string) ($merged[$field] ?? '')) === '' && trim((string) ($profile[$field] ?? '')) !== '') {
                $merged[$field] = $profile[$field];
                $changed = true;
            }
        }
        return !$changed || $this->constituents->update($id, $merged) ? $id : 0;
    }

    /**
     * @param array<string,mixed> $order @param array<string,mixed> $attendee
     */
    private function saveProvenance(
        int $registrationId,
        string $eventbriteEventId,
        array $order,
        array $attendee,
        string $attendeeId
    ): bool {
        $answers = $this->answers($attendee);
        if (!$this->imports->mergeRegistrationAnswerNamespace(
            $registrationId,
            'eventbrite_answers',
            $answers,
            self::SOURCE
        )) return false;

        $ticket = is_array($attendee['ticket_class'] ?? null) ? $attendee['ticket_class'] : [];
        $costs = is_array($order['costs'] ?? null) ? $order['costs'] : [];
        $metadata = [
            'event_id' => $eventbriteEventId,
            'order_id' => bg_substr(sanitize_text_field((string) ($attendee['order_id'] ?? $order['id'] ?? '')), 0, 64),
            'ticket_class_id' => bg_substr(sanitize_text_field((string) ($attendee['ticket_class_id'] ?? $ticket['id'] ?? '')), 0, 64),
            'ticket_name' => bg_substr(sanitize_text_field((string) ($ticket['name'] ?? '')), 0, 190),
            'order_status' => bg_substr(sanitize_text_field((string) ($order['status'] ?? '')), 0, 64),
            'attendee_status' => bg_substr(sanitize_text_field((string) ($attendee['status'] ?? '')), 0, 64),
            'checked_in' => $this->checkedIn($attendee),
            'cancelled' => $this->truthy($attendee['cancelled'] ?? null),
            'refunded' => $this->truthy($attendee['refunded'] ?? null),
            'currency' => bg_substr(sanitize_text_field((string) ($order['costs']['gross']['currency'] ?? $order['currency'] ?? '')), 0, 12),
            'gross_order_evidence' => $this->moneyValue($costs['gross'] ?? null),
            'eventbrite_fee_evidence' => $this->moneyValue($costs['eventbrite_fee'] ?? null),
            'payment_fee_evidence' => $this->moneyValue($costs['payment_fee'] ?? null),
            'financial_posted_to_bg' => false,
            'consent_imported' => false,
            'subscriptions_imported' => false,
        ];
        return $this->imports->linkExternal(
            self::SOURCE,
            'registration',
            $registrationId,
            $attendeeId,
            0,
            $this->sourceCreatedAt($order, $attendee),
            $metadata
        );
    }

    /** @param array<string,mixed> $attendee @return list<array<string,mixed>> */
    private function answers(array $attendee): array
    {
        $out = [];
        foreach ((array) ($attendee['answers'] ?? []) as $answer) {
            if (!is_array($answer)) continue;
            $value = $answer['answer'] ?? '';
            if (is_array($value)) {
                $value = array_slice(array_values(array_map(
                    static fn($part): string => bg_substr(sanitize_text_field((string) $part), 0, 1000),
                    $value
                )), 0, 50);
            } else {
                $value = bg_substr(sanitize_textarea_field((string) $value), 0, 4000);
            }
            $out[] = [
                'question_id' => bg_substr(sanitize_text_field((string) ($answer['question_id'] ?? '')), 0, 64),
                'question' => bg_substr(sanitize_text_field((string) ($answer['question'] ?? '')), 0, 500),
                'type' => bg_substr(sanitize_key((string) ($answer['type'] ?? '')), 0, 48),
                'answer' => $value,
            ];
            if (count($out) >= 100) break;
        }
        return $out;
    }

    /** @param array<string,mixed> $attendee */
    private function saveAttendance(int $registrationId, int $eventPostId, array $attendee, bool $created): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_event_attendance';
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT status, updated_by, checked_in_at, revision FROM {$table} WHERE registration_id = %d FOR UPDATE",
            $registrationId
        ));
        $checkedIn = $this->checkedIn($attendee);
        if (!$current) {
            $now = current_time('mysql', true);
            return $wpdb->insert($table, [
                'registration_id' => $registrationId,
                'event_post_id' => $eventPostId,
                'status' => $checkedIn ? 'checked_in' : 'registered',
                'updated_by' => 0,
                'checked_in_at' => $checkedIn ? $now : null,
                'updated_at' => $now,
                'revision' => wp_generate_uuid4(),
            ]) !== false;
        }

        $currentStatus = (string) $current->status;
        if ($checkedIn) {
            if ($currentStatus === 'attended') return true;
            $target = 'checked_in';
        } elseif ($created || ((int) $current->updated_by === 0 && $currentStatus === 'checked_in')) {
            $target = 'registered';
        } else {
            // Never downgrade an organizer's affirmative local attendance.
            return true;
        }
        if ($target === $currentStatus) return true;

        $now = current_time('mysql', true);
        return $wpdb->update($table, [
            'status' => $target,
            'updated_by' => 0,
            'checked_in_at' => $target === 'checked_in'
                ? ((string) $current->checked_in_at !== '' ? (string) $current->checked_in_at : $now)
                : null,
            'updated_at' => $now,
            'revision' => wp_generate_uuid4(),
        ], [
            'registration_id' => $registrationId,
            'revision' => (string) $current->revision,
        ]) === 1;
    }

    /** @param array<string,mixed> $attendee */
    private function checkedIn(array $attendee): bool
    {
        if ($this->truthy($attendee['checked_in'] ?? null)) return true;
        return in_array($this->key($attendee['status'] ?? ''), [
            'checked_in', 'guest_attended', 'guests_attended',
        ], true);
    }

    private function hasAffirmativeAttendance(int $registrationId): bool
    {
        if ($registrationId < 1) return false;
        global $wpdb;
        $wpdb->last_error = '';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}bg_event_attendance WHERE registration_id = %d",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Local attendance evidence could not be verified safely.');
        }
        return in_array((string) $status, ['checked_in', 'attended'], true);
    }

    /**
     * Any local ledger or tender evidence makes the registration financially
     * BG-owned for cancellation purposes. The live path locks matching rows
     * while its registration transaction decides the remote status change.
     */
    private function hasBgFinancialEvidence(int $registrationId, bool $forUpdate = false): bool
    {
        if ($registrationId < 1) return false;
        global $wpdb;
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $wpdb->last_error = '';
        $paymentId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_payments
             WHERE registration_id = %d ORDER BY id ASC LIMIT 1{$lock}",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Local payment evidence could not be verified safely.');
        }
        if ((int) $paymentId > 0) return true;

        $wpdb->last_error = '';
        $tenderId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_event_tenders
             WHERE registration_id = %d ORDER BY id ASC LIMIT 1{$lock}",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Local tender evidence could not be verified safely.');
        }
        return (int) $tenderId > 0;
    }

    /** Lock and return the current registration inside the caller's transaction. */
    private function registrationForUpdate(int $registrationId): ?object
    {
        if ($registrationId < 1) return null;
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_registrations WHERE id = %d FOR UPDATE",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('The site registration could not be locked for safe reconciliation.');
        }
        return $row ?: null;
    }

    /** Read one person/event registration and fail closed on database uncertainty. */
    private function latestActiveRegistration(int $eventPostId, int $constituentId, bool $forUpdate = false): ?object
    {
        if ($eventPostId < 1 || $constituentId < 1) return null;
        global $wpdb;
        $wpdb->last_error = '';
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_registrations
             WHERE event_post_id = %d AND bg_constituent_id = %d
               AND status IN ('pending','processing','confirmed','paid','refund_pending')
             ORDER BY id DESC LIMIT 1{$lock}",
            $eventPostId,
            $constituentId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Existing site registration state could not be verified safely.');
        }
        return $row ?: null;
    }

    /** Update the row already locked by the caller and prove one transition occurred. */
    private function setRegistrationStatusLocked(int $registrationId, string $expected, string $target): bool
    {
        if ($registrationId < 1 || $expected === '' || $target === '' || hash_equals($expected, $target)) return false;
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'bg_registrations',
            ['status' => $target, 'updated_at' => current_time('mysql', true)],
            ['id' => $registrationId, 'status' => $expected]
        ) === 1;
    }

    /** Lock the attendance row/gap before deciding whether cancellation is safe. */
    private function attendanceStatusForUpdate(int $registrationId): string
    {
        if ($registrationId < 1) return '';
        global $wpdb;
        $wpdb->last_error = '';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}bg_event_attendance WHERE registration_id = %d FOR UPDATE",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Local attendance evidence could not be locked for safe reconciliation.');
        }
        return (string) $status;
    }

    /** Imported/job-owned rows must remain isolated from a second source's rollback lifecycle. */
    private function registrationHasExternalProvenance(int $registrationId): bool
    {
        if ($registrationId < 1) return false;
        global $wpdb;
        $wpdb->last_error = '';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_external_records
             WHERE entity_type = 'registration' AND entity_id = %d",
            $registrationId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Existing registration provenance could not be verified safely.');
        }
        return (int) $count > 0;
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $attendee */
    private function sourceCreatedAt(array $order, array $attendee): string
    {
        foreach ([$attendee['created'] ?? null, $order['created'] ?? null] as $raw) {
            if (!is_string($raw) || trim($raw) === '') continue;
            try {
                return (new \DateTimeImmutable(trim($raw)))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                continue;
            }
        }
        return '';
    }

    private function preserveCreatedAt(int $registrationId, string $createdAt): bool
    {
        if ($createdAt === '') return true;
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'bg_registrations',
            ['created_at' => $createdAt, 'updated_at' => $createdAt],
            ['id' => $registrationId]
        ) !== false;
    }

    private function moneyValue(mixed $money): ?string
    {
        if (is_array($money)) {
            $value = $money['major_value'] ?? $money['display'] ?? null;
        } else {
            $value = $money;
        }
        if (!is_string($value) && !is_numeric($value)) return null;
        $value = trim((string) $value);
        return preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value) ? $value : null;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1') return true;
        return is_string($value) && in_array(strtolower(trim($value)), ['true', 'yes'], true);
    }

    private function key(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) return '';
        return trim((string) preg_replace(
            '/_+/',
            '_',
            preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $value))) ?? ''
        ), '_');
    }

    /** Non-reversible reference safe for administrator reports and logs. */
    private function attendeeRef(string $attendeeId): string
    {
        return $attendeeId === '' ? '' : substr(hash('sha256', $attendeeId), 0, 12);
    }
}
