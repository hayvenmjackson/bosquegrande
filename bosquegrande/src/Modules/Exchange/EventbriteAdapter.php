<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Eventbrite adapter. Talks to the API directly (list pages, raw) so
 * the staged record is the service's own JSON, not a reading of it.
 * Selection: the configured event-status filter plus every event that
 * needs automatic sales-closure reconciliation.
 */
final class EventbriteAdapter
{
    public function __construct(
        private Settings $settings,
        private StagingStore $staging,
        private Logger $logger,
    ) {
    }

    /** @return array{staged: int, fetched: int, errors: int, note: string} */
    public function stageRun(int $runId): array
    {
        $token = (string) $this->settings->get('eventbrite_token', '');
        $org   = (string) $this->settings->get('eventbrite_org_id', '');
        if ($token === '' || $org === '') {
            return ['staged' => 0, 'fetched' => 0, 'errors' => 1, 'note' => 'Eventbrite token or organization id not on file'];
        }
        $status = (string) $this->settings->get('exchange_eb_status', 'live');
        if (!in_array($status, ['live', 'started', 'ended', 'completed', 'all'], true)) $status = 'live';

        $fetched = 0;
        $staged  = 0;
        $errors  = 0;
        for ($page = 1; $page <= 1000; $page++) {
            $url = sprintf(
                'https://www.eventbriteapi.com/v3/organizations/%s/events/?status=all&order_by=start_desc&page=%d&expand=logo,venue,ticket_availability,ticket_classes',
                rawurlencode($org), $page
            );
            $r = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
                $this->logger->warn('exchange', 'Eventbrite list fetch failed.', [
                    'page' => $page,
                    'detail' => is_wp_error($r) ? $r->get_error_message() : (string) wp_remote_retrieve_response_code($r),
                ]);
                return [
                    'staged' => $staged,
                    'fetched' => $fetched,
                    'errors' => $errors + 1,
                    'note' => 'Eventbrite fetch failed on page ' . $page,
                ];
            }
            $body = json_decode((string) wp_remote_retrieve_body($r), true);
            $events = is_array($body) ? (array) ($body['events'] ?? []) : [];
            $fetched += count($events);
            foreach ($events as $e) {
                if (!is_array($e) || empty($e['id'])) continue;
                $matchesSelection = $status === 'all' || EventbriteSales::eventStatus($e) === $status;
                if (!$matchesSelection && !EventbriteSales::isClosed($e)) continue;
                if ($this->staging->stage($runId, 'eventbrite', (string) $e['id'], (string) wp_json_encode($e))) {
                    $staged++;
                } else {
                    $errors++;
                    $this->logger->warn('exchange', 'Eventbrite event could not be staged.', ['external_id' => (string) $e['id']]);
                }
            }
            if (empty($body['pagination']['has_more_items'])) break;
        }
        return [
            'staged' => $staged,
            'fetched' => $fetched,
            'errors' => $errors,
            'note' => $errors ? 'One or more Eventbrite records could not be staged' : '',
        ];
    }
}
