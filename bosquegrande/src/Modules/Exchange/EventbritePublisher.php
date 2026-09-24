<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Explicit, administrator-initiated Eventbrite draft creation and updates.
 *
 * Eventbrite owns organizer profiles, structured venues, tickets, images,
 * payout/refund policy, and publication. BosqueGrande deliberately maps only
 * the event data it can represent faithfully and never publishes an event.
 */
final class EventbritePublisher
{
    private const API_ROOT = 'https://www.eventbriteapi.com/v3/';
    private const LOCK_PREFIX = 'bg_eb_export_';
    private const RECOVERY_PREFIX = 'bg_eb_recovery_';
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_DESCRIPTION_BYTES = 50000;

    public function __construct(
        private Settings $settings,
        private Logger $logger,
    ) {
    }

    public function configured(): bool
    {
        return $this->token() !== '' && $this->organizationId() !== '';
    }

    public function configurationMessage(): string
    {
        if ($this->settings->secretUnreadable('eventbrite_token')) {
            return 'The saved Eventbrite token cannot be decrypted. Re-enter it in BosqueGrande Settings.';
        }
        if ($this->token() === '') {
            return 'No readable Eventbrite private token is saved.';
        }
        if ($this->organizationId() === '') {
            return 'Enter the numeric Eventbrite organization ID in BosqueGrande Settings.';
        }
        return '';
    }

    /**
     * Resolve both sides of the connector contract. Imported Eventbrite
     * events use _bg_src_key; events first sent from BosqueGrande use _bg_eb_event_id.
     * A conflict must never be guessed through because that could mutate the
     * wrong external event.
     *
     * @return string|\WP_Error
     */
    public function linkedEventId(int $postId): string|\WP_Error
    {
        $ids = [];
        $explicit = trim((string) get_post_meta($postId, '_bg_eb_event_id', true));
        if ($explicit !== '') $ids[] = $explicit;

        $source = trim((string) get_post_meta($postId, '_bg_src', true));
        $sourceId = trim((string) get_post_meta($postId, '_bg_src_id', true));
        if ($source === 'eventbrite' && $sourceId !== '') $ids[] = $sourceId;

        foreach ((array) get_post_meta($postId, '_bg_src_key', false) as $key) {
            if (preg_match('/^eventbrite:(\d+)$/', trim((string) $key), $match)) {
                $ids[] = $match[1];
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        foreach ($ids as $id) {
            if (!preg_match('/^\d+$/', $id)) {
                return new \WP_Error('eventbrite_link_invalid', 'This event contains an invalid Eventbrite link. Correct it before exporting.');
            }
        }
        if (count($ids) > 1) {
            return new \WP_Error('eventbrite_link_conflict', 'This event contains conflicting Eventbrite IDs. No external event was changed.');
        }
        $eventId = $ids[0] ?? '';
        if ($eventId !== '') {
            $unique = $this->assertUniqueEventLink($postId, $eventId);
            if (is_wp_error($unique)) return $unique;
        }
        return $eventId;
    }

    /** Refuse to let two site events mutate the same Eventbrite event. */
    private function assertUniqueEventLink(int $postId, string $eventId): bool|\WP_Error
    {
        if (!preg_match('/^\d+$/', $eventId)) {
            return new \WP_Error('eventbrite_link_invalid', 'This event contains an invalid Eventbrite link. Correct it before exporting.');
        }
        global $wpdb;
        $wpdb->last_error = '';
        $posts = $wpdb->get_col($wpdb->prepare(
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
            $eventId,
            'eventbrite:' . $eventId,
            $eventId
        )) ?: [];
        if ($wpdb->last_error !== '') {
            return new \WP_Error('eventbrite_link_check_failed', 'BosqueGrande could not verify Eventbrite link ownership. No external event was changed.');
        }
        $posts = array_values(array_unique(array_map('intval', $posts)));
        if (count($posts) > 1 || ($posts !== [] && !in_array($postId, $posts, true))) {
            return new \WP_Error(
                'eventbrite_link_duplicate',
                'Another site event carries this Eventbrite ID. No external event was changed.'
            );
        }
        return true;
    }

    /**
     * Read-only authentication and organization discovery.
     *
     * @return array{user_id: string, organizations: array<int, array{id: string, name: string}>, configured_found: bool}|\WP_Error
     */
    public function testConnection(): array|\WP_Error
    {
        $token = $this->token();
        if ($token === '') {
            return new \WP_Error('eventbrite_no_token', $this->configurationMessage());
        }

        $user = $this->request('GET', 'users/me/');
        if (is_wp_error($user)) return $user;
        $userId = trim((string) ($user['id'] ?? ''));
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return new \WP_Error('eventbrite_bad_identity', 'Eventbrite accepted the request but did not return a valid account identity.');
        }

        $organizations = [];
        for ($page = 1; $page <= 5 && count($organizations) < 200; $page++) {
            $body = $this->request('GET', 'users/me/organizations/?page=' . $page);
            if (is_wp_error($body)) return $body;
            foreach ((array) ($body['organizations'] ?? []) as $organization) {
                if (!is_array($organization)) continue;
                $id = trim((string) ($organization['id'] ?? ''));
                if (!preg_match('/^\d+$/', $id)) continue;
                $organizations[$id] = [
                    'id' => $id,
                    'name' => sanitize_text_field((string) ($organization['name'] ?? 'Unnamed organization')),
                ];
            }
            if (empty($body['pagination']['has_more_items'])) break;
        }

        $organizations = array_values($organizations);
        $configured = $this->organizationId();
        $found = $configured !== '' && in_array($configured, array_column($organizations, 'id'), true);
        if ($configured !== '' && !$found) {
            return new \WP_Error(
                'eventbrite_org_not_visible',
                'The token is valid, but the configured organization ID is not among the organizations this Eventbrite account can access.'
            );
        }

        return [
            'user_id' => $userId,
            'organizations' => $organizations,
            'configured_found' => $found,
        ];
    }

    /**
     * Create an Eventbrite draft, or update the already-linked Eventbrite
     * event without changing its publication, ticket, venue, or organizer.
     *
     * @return array{action: string, eb_id: string, url: string, warning: string}|\WP_Error
     */
    public function export(int $postId): array|\WP_Error
    {
        if (!$this->configured()) {
            return new \WP_Error('eventbrite_not_configured', $this->configurationMessage());
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event') {
            return new \WP_Error('eventbrite_event_missing', 'The BosqueGrande event could not be found.');
        }
        if (in_array($post->post_status, ['pending', 'trash', 'auto-draft'], true) || wp_is_post_revision($postId)) {
            return new \WP_Error('eventbrite_event_status', 'Only saved, office-approved events can be sent to Eventbrite.');
        }

        $lockKey = self::LOCK_PREFIX . $postId;
        $owner = OptionLease::acquire($lockKey, 120);
        if ($owner === '') {
            return new \WP_Error('eventbrite_busy', 'This event is already being sent to Eventbrite. Wait for that request to finish.', ['status' => 409]);
        }

        try {
            $post = get_post($postId);
            if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event') {
                return new \WP_Error('eventbrite_event_missing', 'The BosqueGrande event could not be found.');
            }
            if (in_array($post->post_status, ['pending', 'trash', 'auto-draft'], true) || wp_is_post_revision($postId)) {
                return new \WP_Error('eventbrite_event_status', 'Only saved, office-approved events can be sent to Eventbrite.');
            }

            $linked = $this->linkedEventId($postId);
            if (is_wp_error($linked)) return $linked;

            $recovery = $this->recovery($postId);
            if ($linked === '' && $recovery !== null) {
                $linked = $recovery['event_id'];
                $unique = $this->assertUniqueEventLink($postId, $linked);
                if (is_wp_error($unique)) return $unique;
                $linkResult = $this->storeLink($postId, $linked, $recovery['url'], $recovery['organization_id']);
                if (is_wp_error($linkResult)) return $linkResult;
            }

            $isCreate = $linked === '';
            $payload = $this->eventPayload($post, $isCreate);
            if (is_wp_error($payload)) return $payload;

            if (!$isCreate) {
                $verified = $this->verifyLinkedOrganization($postId, $linked);
                if (is_wp_error($verified)) return $verified;
            }

            // Recheck both local status and link immediately before the
            // external write; the remote organization lookup can take time.
            $post = get_post($postId);
            if (!$post instanceof \WP_Post || $post->post_type !== 'bg_event'
                || in_array($post->post_status, ['pending', 'trash', 'auto-draft'], true)
                || wp_is_post_revision($postId)
            ) {
                return new \WP_Error('eventbrite_event_status', 'The site event changed status before export. No Eventbrite write was attempted.');
            }
            $freshLinked = $this->linkedEventId($postId);
            if (is_wp_error($freshLinked)) return $freshLinked;
            if (!hash_equals($linked, $freshLinked)) {
                return new \WP_Error('eventbrite_link_changed', 'The Eventbrite link changed before export. No external event was changed.');
            }

            $path = $isCreate
                ? 'organizations/' . $this->organizationId() . '/events/'
                : 'events/' . $linked . '/';
            $response = $this->request('POST', $path, ['event' => $payload]);
            if (is_wp_error($response)) return $response;

            $eventId = trim((string) ($response['id'] ?? $linked));
            if (!preg_match('/^\d+$/', $eventId)) {
                return new \WP_Error('eventbrite_bad_response', 'Eventbrite did not return a valid event ID. Check Eventbrite Drafts before trying again.');
            }
            if (!$isCreate && !hash_equals($linked, $eventId)) {
                return new \WP_Error('eventbrite_id_changed', 'Eventbrite returned a different event ID than the linked record. The local link was not changed.');
            }

            $unique = $this->assertUniqueEventLink($postId, $eventId);
            if (is_wp_error($unique)) return $unique;

            $url = $this->eventbriteUrl((string) ($response['url'] ?? ''));
            if ($isCreate) {
                $savedRecovery = $this->storeRecovery($postId, $eventId, $url);
                if (!$savedRecovery) {
                    $direct = $this->storeLink($postId, $eventId, $url, $this->organizationId());
                    if (is_wp_error($direct)) {
                        return new \WP_Error(
                            'eventbrite_link_storage_failed',
                            sprintf('Eventbrite created draft %s, but BosqueGrande could not save its link. Do not click again; check Eventbrite and contact the site administrator.', $eventId)
                        );
                    }
                }
            }

            $linkResult = $this->storeLink($postId, $eventId, $url, $this->organizationId());
            if (is_wp_error($linkResult)) return $linkResult;
            delete_option(self::RECOVERY_PREFIX . $postId);

            $warning = $this->updateStructuredDescription($post, $eventId, $isCreate);
            $this->logger->info('eventbrite', $isCreate ? 'Eventbrite draft created.' : 'Eventbrite event updated.', [
                'post_id' => $postId,
                'eventbrite_event_id' => $eventId,
                'description_warning' => $warning !== '',
            ]);

            return [
                'action' => $isCreate ? 'Draft created' : 'Linked event updated',
                'eb_id' => $eventId,
                'url' => $url,
                'warning' => $warning,
            ];
        } finally {
            OptionLease::release($lockKey, $owner);
        }
    }

    /** @return array<string, mixed>|\WP_Error */
    private function eventPayload(\WP_Post $post, bool $isCreate): array|\WP_Error
    {
        $title = trim(wp_strip_all_tags((string) $post->post_title));
        if ($title === '') return new \WP_Error('eventbrite_title_missing', 'Add and save an event title before sending it to Eventbrite.');
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($titleLength > 75) {
            return new \WP_Error('eventbrite_title_long', 'Eventbrite event titles are limited to 75 characters. Shorten and save the event title first.');
        }

        $timezone = wp_timezone();
        $timezoneName = $timezone->getName();
        if (!preg_match('~^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+)+$~', $timezoneName) && $timezoneName !== 'UTC') {
            return new \WP_Error('eventbrite_timezone', 'Eventbrite needs a named time zone. In WordPress General Settings, choose a city such as New York instead of a UTC offset.');
        }

        $startDate = trim((string) get_post_meta($post->ID, '_bg_start_date', true));
        $startTime = $this->time24((string) get_post_meta($post->ID, '_bg_start_time', true));
        if ($startDate === '' || $startTime === '') {
            return new \WP_Error('eventbrite_start_missing', 'Add and save a valid start date and start time before sending this event to Eventbrite.');
        }
        $start = $this->localDateTime($startDate, $startTime, $timezone);
        if (!$start) return new \WP_Error('eventbrite_start_invalid', 'The saved event start date or time is invalid. Correct and save it first.');

        $endDateRaw = trim((string) get_post_meta($post->ID, '_bg_end_date', true));
        $endDate = $endDateRaw !== '' ? $endDateRaw : $startDate;
        $endTime = $this->time24((string) get_post_meta($post->ID, '_bg_end_time', true));
        if ($endTime === '') $endTime = $start->modify('+1 hour')->format('H:i');
        $end = $this->localDateTime($endDate, $endTime, $timezone);
        if (!$end) return new \WP_Error('eventbrite_end_invalid', 'The saved event end date or time is invalid. Correct and save it first.');
        if ($end <= $start && ($endDateRaw === '' || $endDate === $startDate)) $end = $end->modify('+1 day');
        if ($end <= $start) return new \WP_Error('eventbrite_end_before_start', 'The saved event ending must be later than its start. Correct and save it first.');
        if ($isCreate && $start <= new \DateTimeImmutable('now', $timezone)) {
            return new \WP_Error('eventbrite_start_past', 'Eventbrite only creates events with a future start. Check and save the event date and time first.');
        }

        $plain = trim(wp_strip_all_tags(strip_shortcodes((string) ($post->post_excerpt ?: $post->post_content)), true));
        $plain = preg_replace('/\s+/u', ' ', html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '';
        $location = trim((string) get_post_meta($post->ID, '_bg_location_text', true));
        if ($location !== '') $plain = trim($plain . ($plain !== '' ? "\n\n" : '') . 'Location: ' . $location);
        $summary = function_exists('bg_substr') ? \bg_substr($plain, 0, 140) : substr($plain, 0, 140);

        $payload = [
            'name' => ['html' => esc_html($title)],
            'summary' => $summary,
            'start' => [
                'timezone' => $timezoneName,
                'utc' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
            'end' => [
                'timezone' => $timezoneName,
                'utc' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
        if ($isCreate) $payload['currency'] = \BosqueGrande\Core\Money::code();
        return $payload;
    }

    private function updateStructuredDescription(\WP_Post $post, string $eventId, bool $isCreate): string
    {
        $html = $this->descriptionHtml($post);
        if ($html === '') {
            if ($isCreate && !$this->storePostMeta($post->ID, '_bg_eb_content_owned', '1')) {
                return 'The Eventbrite draft was created, but BosqueGrande could not save its description-ownership marker.';
            }
            return '';
        }
        if (strlen($html) > self::MAX_DESCRIPTION_BYTES) {
            return 'The Eventbrite draft was saved, but its full description exceeded the safe transfer size and was not replaced.';
        }

        $contentOwned = (string) get_post_meta($post->ID, '_bg_eb_content_owned', true) === '1';
        $current = $this->request('GET', 'events/' . $eventId . '/structured_content/?purpose=listing');
        if (is_wp_error($current)) {
            if (($isCreate || $contentOwned) && $current->get_error_code() === 'eventbrite_http_404') {
                $current = [];
            } else {
                return 'The Eventbrite event was saved, but its full description could not be checked and was left unchanged.';
            }
        }
        $currentVersion = max(0, (int) ($current['page_version_number'] ?? 0));
        $lastVersion = (int) get_post_meta($post->ID, '_bg_eb_content_version', true);
        $hash = hash('sha256', $html);
        $lastHash = (string) get_post_meta($post->ID, '_bg_eb_content_hash', true);

        if (!$isCreate) {
            if ($lastVersion <= 0 && !$contentOwned) {
                return 'The linked Eventbrite event predates safe description-version tracking, so its rich description was left unchanged.';
            }
            if ($currentVersion !== $lastVersion) {
                return 'The Eventbrite rich description changed after the last export, so it was preserved instead of overwritten.';
            }
            if ($lastHash !== '' && hash_equals($lastHash, $hash)) return '';
        }

        $nextVersion = max(1, $currentVersion + 1);
        $body = [
            'modules' => [[
                'type' => 'text',
                'data' => ['body' => ['type' => 'text', 'text' => $html]],
            ]],
            'purpose' => 'listing',
            // This publishes the description revision, not the Eventbrite event.
            'publish' => true,
        ];
        $saved = $this->request('POST', 'events/' . $eventId . '/structured_content/' . $nextVersion . '/', $body);
        if (is_wp_error($saved)) {
            return 'The Eventbrite event was saved, but its full description could not be updated. Its prior description was preserved.';
        }
        $storedVersion = (string) ((int) ($saved['page_version_number'] ?? $nextVersion));
        if (!$this->storePostMeta($post->ID, '_bg_eb_content_version', $storedVersion)
            || !$this->storePostMeta($post->ID, '_bg_eb_content_hash', $hash)
            || !$this->storePostMeta($post->ID, '_bg_eb_content_owned', '1')
        ) {
            return 'The Eventbrite description was updated, but BosqueGrande could not save its version marker. Later exports will preserve that description until the link is reviewed.';
        }
        return '';
    }

    private function descriptionHtml(\WP_Post $post): string
    {
        $html = function_exists('do_blocks') ? do_blocks((string) $post->post_content) : (string) $post->post_content;
        $html = strip_shortcodes($html);
        $html = str_ireplace(['<strong', '</strong>', '<i>', '</i>'], ['<b', '</b>', '<em>', '</em>'], $html);
        $allowed = [
            'aside' => [], 'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [],
            'h1' => [], 'h2' => [], 'h3' => [], 'p' => [], 'b' => [], 'em' => [],
            'a' => ['href' => true, 'title' => true],
        ];
        $html = trim((string) wp_kses($html, $allowed, ['http', 'https', 'mailto']));
        $location = trim((string) get_post_meta($post->ID, '_bg_location_text', true));
        if ($location !== '') {
            $html .= '<p><b>Location:</b> ' . esc_html($location) . '</p>';
        }
        return trim($html);
    }

    private function verifyLinkedOrganization(int $postId, string $eventId): bool|\WP_Error
    {
        $configured = $this->organizationId();
        $stored = trim((string) get_post_meta($postId, '_bg_eb_org_id', true));
        if ($stored !== '' && (!preg_match('/^\d+$/', $stored) || !hash_equals($configured, $stored))) {
            return new \WP_Error('eventbrite_org_conflict', 'This event is linked to a different Eventbrite organization. No external event was changed.');
        }

        // Re-read the remote event on every update. A locally stored
        // organization marker is evidence about the prior link, not authority
        // to mutate a different event if its id is later changed or corrupted.
        $remote = $this->request('GET', 'events/' . $eventId . '/');
        if (is_wp_error($remote)) return $remote;
        $remoteId = trim((string) ($remote['id'] ?? ''));
        $remoteOrg = trim((string) ($remote['organization_id'] ?? ''));
        if (!preg_match('/^\d+$/', $remoteId) || !hash_equals($eventId, $remoteId)
            || !preg_match('/^\d+$/', $remoteOrg) || !hash_equals($configured, $remoteOrg)
        ) {
            return new \WP_Error('eventbrite_org_conflict', 'The linked Eventbrite event does not belong to the configured organization. No external event was changed.');
        }
        if (!$this->storePostMeta($postId, '_bg_eb_org_id', $configured)) {
            return new \WP_Error('eventbrite_org_storage', 'The Eventbrite organization was verified, but BosqueGrande could not save that verification. No external event was changed.');
        }
        return true;
    }

    /** @return array<string, mixed>|\WP_Error */
    private function request(string $method, string $path, ?array $payload = null): array|\WP_Error
    {
        $token = $this->token();
        if ($token === '') return new \WP_Error('eventbrite_no_token', $this->configurationMessage());
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            return new \WP_Error('eventbrite_method', 'Unsupported Eventbrite request method.');
        }

        $args = [
            'method' => $method,
            'timeout' => 25,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
            ],
        ];
        if ($payload !== null) $args['body'] = wp_json_encode($payload);

        $response = wp_remote_request(self::API_ROOT . ltrim($path, '/'), $args);
        if (is_wp_error($response)) {
            $message = $method === 'POST'
                ? 'Eventbrite did not return a usable response. A write can finish before a timeout, so check Eventbrite Drafts before trying again.'
                : 'Eventbrite could not be reached. Try the read-only connection test again later.';
            return new \WP_Error('eventbrite_transport', $message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('eventbrite_http_' . $code, $this->httpErrorMessage($code, is_array($decoded) ? $decoded : []), ['status' => $code]);
        }
        if (!is_array($decoded)) {
            return new \WP_Error('eventbrite_bad_json', 'Eventbrite returned an unreadable response. No local link was changed.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $body */
    private function httpErrorMessage(int $code, array $body): string
    {
        if ($code === 400) {
            $detail = sanitize_text_field((string) ($body['error_description'] ?? 'Eventbrite rejected one or more event fields.'));
            $detail = function_exists('bg_substr') ? \bg_substr($detail, 0, 240) : substr($detail, 0, 240);
            return $detail !== '' ? 'Eventbrite rejected the event: ' . $detail : 'Eventbrite rejected one or more event fields.';
        }
        if ($code === 401) return 'Eventbrite rejected the private token. Create or copy a current Private Token and save it again.';
        if ($code === 403) return 'The Eventbrite account lacks permission for this organization or event. Check its organization role.';
        if ($code === 404) return 'The configured Eventbrite organization or linked event was not found. BosqueGrande did not create a replacement.';
        if ($code === 429) return 'Eventbrite has temporarily rate-limited this integration. Wait before trying again.';
        if ($code >= 500) return 'Eventbrite is temporarily unavailable. Because a write may already have completed, check Eventbrite before trying again.';
        return 'Eventbrite returned an unexpected response (HTTP ' . $code . ').';
    }

    /** @return array{event_id: string, url: string, organization_id: string}|null */
    private function recovery(int $postId): ?array
    {
        $value = get_option(self::RECOVERY_PREFIX . $postId, null);
        if (!is_array($value)) return null;
        $eventId = trim((string) ($value['event_id'] ?? ''));
        $organizationId = trim((string) ($value['organization_id'] ?? ''));
        $savedPost = (int) ($value['post_id'] ?? 0);
        if ($savedPost !== $postId || !preg_match('/^\d+$/', $eventId)
            || !hash_equals($this->organizationId(), $organizationId)
        ) return null;
        return [
            'event_id' => $eventId,
            'url' => $this->eventbriteUrl((string) ($value['url'] ?? '')),
            'organization_id' => $organizationId,
        ];
    }

    private function storeRecovery(int $postId, string $eventId, string $url): bool
    {
        $key = self::RECOVERY_PREFIX . $postId;
        $value = [
            'post_id' => $postId,
            'event_id' => $eventId,
            'url' => $url,
            'organization_id' => $this->organizationId(),
            'created_at' => time(),
        ];
        return update_option($key, $value, false) || get_option($key, null) === $value;
    }

    /** @return bool|\WP_Error */
    private function storeLink(int $postId, string $eventId, string $url, string $organizationId): bool|\WP_Error
    {
        if (!$this->storePostMeta($postId, '_bg_eb_event_id', $eventId)) {
            return new \WP_Error('eventbrite_link_storage_failed', sprintf(
                'Eventbrite event %s exists, but BosqueGrande could not save its ID. Do not click again; check Eventbrite and contact the site administrator.',
                $eventId
            ));
        }
        if (!$this->storePostMeta($postId, '_bg_eb_org_id', $organizationId)) {
            return new \WP_Error('eventbrite_org_storage_failed', 'The Eventbrite event ID was saved, but its organization marker was not. Review the link before updating again.');
        }
        if ($url !== '' && !$this->storePostMeta($postId, '_bg_eb_event_url', $url)) {
            return new \WP_Error('eventbrite_url_storage_failed', 'The Eventbrite event ID was saved, but its view link was not. The event will not be duplicated.');
        }
        if (!$this->storePostMeta($postId, '_bg_eb_last_exported', current_time('mysql'))) {
            return new \WP_Error('eventbrite_time_storage_failed', 'The Eventbrite event link was saved, but its export timestamp was not. The event will not be duplicated.');
        }
        return true;
    }

    private function storePostMeta(int $postId, string $key, string $value): bool
    {
        return update_post_meta($postId, $key, $value) !== false
            || (string) get_post_meta($postId, $key, true) === $value;
    }

    private function token(): string
    {
        return trim((string) $this->settings->get('eventbrite_token', ''));
    }

    private function organizationId(): string
    {
        $id = trim((string) $this->settings->get('eventbrite_org_id', ''));
        return preg_match('/^\d+$/', $id) ? $id : '';
    }

    private function eventbriteUrl(string $url): string
    {
        $url = esc_url_raw(trim($url), ['https']);
        if ($url === '') return '';
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return ($host === 'eventbrite.com' || str_ends_with($host, '.eventbrite.com')) ? $url : '';
    }

    private function time24(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        foreach (['H:i', 'H:i:s', 'g:i A', 'g A', 'h:i A', 'h:iA', 'g:iA'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, strtoupper($raw), wp_timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dt && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt->format('H:i');
            }
        }
        return '';
    }

    private function localDateTime(string $date, string $time, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return null;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $dt->format('Y-m-d H:i') === $date . ' ' . $time ? $dt : null;
    }
}
