<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;
use BosqueGrande\Modules\Lgl\LglClient;

if (!defined('ABSPATH')) exit;

/**
 * Source adapters. Each fetches from its service, applies the record
 * selector, and stages the selected records verbatim. Interpretation
 * belongs to the Mapper; an adapter never reshapes a payload.
 */
final class LglAdapter
{
    public function __construct(
        private LglClient $client,
        private Settings $settings,
        private StagingStore $staging,
        private Logger $logger,
    ) {
    }

    /** @return array{staged: int, fetched: int, errors: int, note: string} */
    public function stageRun(int $runId): array
    {
        if (!$this->client->hasKey()) {
            return ['staged' => 0, 'fetched' => 0, 'errors' => 1, 'note' => 'LGL key not on file'];
        }

        $mode  = (string) $this->settings->get('exchange_lgl_selector_mode', 'code_eq');
        $value = trim((string) $this->settings->get('exchange_lgl_selector_value',
            (string) $this->settings->get('lgl_event_code', '')));
        if ($mode !== 'all' && $value === '') {
            return ['staged' => 0, 'fetched' => 0, 'errors' => 0, 'note' => 'No LGL event selector is set'];
        }

        $fetched = 0;
        $staged  = 0;
        $errors  = 0;
        for ($offset = 0; $offset < 100000; $offset += 100) {
            $items = $this->client->listEvents(100, $offset);
            if (!$items) {
                if (!$this->client->lastRequestSucceeded()) {
                    $errors++;
                    $this->logger->error('exchange', 'LGL event staging stopped because the remote page could not be read.', [
                        'offset' => $offset,
                    ]);
                }
                break;
            }
            $fetched += count($items);
            foreach ($items as $e) {
                if (!is_array($e) || empty($e['id'])) continue;
                if (!$this->matches($e, $mode, $value)) continue;
                if ($this->staging->stage($runId, 'lgl', (string) $e['id'], (string) wp_json_encode($e))) {
                    $staged++;
                } else {
                    $errors++;
                    $this->logger->warn('exchange', 'LGL event could not be staged.', ['external_id' => (string) $e['id']]);
                }
            }
            if (count($items) < 100) break;
        }
        $this->logger->info('exchange', 'LGL staging pass concluded.', ['fetched' => $fetched, 'staged' => $staged, 'selector' => $mode]);
        return [
            'staged' => $staged,
            'fetched' => $fetched,
            'errors' => $errors,
            'note' => $errors ? 'One or more LGL records could not be staged' : '',
        ];
    }

    /**
     * The selector keeps a sync from pulling every event in the CRM by
     * accident. Comparisons use the first non-empty field.
     * @param array<string, mixed> $e
     */
    private function matches(array $e, string $mode, string $value): bool
    {
        if ($mode === 'all') return true;
        if ($value === '') return false;
        $needle = strtolower($value);
        $pick = function (array $keys) use ($e): string {
            foreach ($keys as $k) {
                $v = $e[$k] ?? null;
                if (is_string($v) && trim($v) !== '') return strtolower(trim($v));
            }
            return '';
        };
        return match ($mode) {
            'code_prefix'   => strpos($pick(['code', 'short_code']), $needle) === 0,
            'campaign_eq'   => $pick(['campaign_name', 'campaign']) === $needle,
            'name_contains' => strpos($pick(['name', 'event_name']), $needle) !== false,
            default         => $pick(['code', 'short_code']) === $needle,
        };
    }
}
