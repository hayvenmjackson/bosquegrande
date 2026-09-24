<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Run orchestration. Every run, whatever its trigger, is the same
 * two-phase shape: stage verbatim, then map from staging. A dry run
 * differs from a live run in exactly one boolean, and a rehearsal
 * differs only in where the bytes come from (the captured record
 * files instead of the wire).
 */
final class TransferRunner
{
    public function __construct(
        private StagingStore $staging,
        private TransferLedger $ledger,
        private Mapper $mapper,
        private LglAdapter $lgl,
        private EventbriteAdapter $eventbrite,
        private Logger $logger,
        private Encryption $encryption,
    ) {
    }

    /** @param string[] $sources @return array{run_id: int, counts: array<string, int>} */
    public function run(string $mode, string $trigger, array $sources): array
    {
        $dry = $mode !== 'live';
        $runId = $this->ledger->open($dry ? 'dry' : 'live', $trigger, implode(',', $sources));
        if ($runId < 1) {
            $this->logger->error('exchange', 'Transfer run could not open its audit ledger.');
            return ['run_id' => 0, 'counts' => $this->failedCounts()];
        }

        $notes = [];
        $sourceErrors = 0;
        foreach ($sources as $source) {
            $res = $source === 'eventbrite'
                ? $this->eventbrite->stageRun($runId)
                : $this->lgl->stageRun($runId);
            if ($res['note'] !== '') $notes[] = $source . ': ' . $res['note'];
            $sourceErrors += (int) ($res['errors'] ?? 0);
        }

        return $this->mapStaged($runId, $dry, $notes, $sourceErrors);
    }

    /**
     * Rehearsal: stage from the captured record files and run the
     * mapper dry. Turns Record Capture output into an acceptance test.
     * @return array{run_id: int, counts: array<string, int>}
     */
    public function rehearse(): array
    {
        $runId = $this->ledger->open('dry', 'rehearsal', 'captured-records');
        if ($runId < 1) {
            $this->logger->error('exchange', 'Rehearsal could not open its audit ledger.');
            return ['run_id' => 0, 'counts' => $this->failedCounts()];
        }
        $up = wp_get_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'bg-fixtures';
        $notes = [];
        $files = is_dir($dir) ? (glob(trailingslashit($dir) . '*.bgcap') ?: []) : [];
        if (!$files) $notes[] = 'no captured record files on file';
        $stageErrors = 0;

        foreach ($files as $path) {
            $name = basename($path);
            $body = $this->encryption->decrypt((string) file_get_contents($path), 'capture:' . $name);
            if ($body === '') {
                $notes[] = "$name: not decryptable with the current site key";
                continue;
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) { $notes[] = "$name: not decodable"; continue; }

            if (strpos($name, 'eventbrite-list') === 0) {
                foreach ((array) ($decoded['events'] ?? []) as $e) {
                    if (is_array($e) && !empty($e['id'])) {
                        if (!$this->staging->stage($runId, 'eventbrite', (string) $e['id'], (string) wp_json_encode($e))) {
                            $stageErrors++;
                        }
                    }
                }
            } elseif (strpos($name, 'eventbrite-event-') === 0) {
                if (!empty($decoded['id'])) {
                    if (!$this->staging->stage($runId, 'eventbrite', (string) $decoded['id'], (string) wp_json_encode($decoded))) {
                        $stageErrors++;
                    }
                }
            } elseif (strpos($name, 'lgl-events-') === 0) {
                foreach ((array) ($decoded['items'] ?? $decoded['data'] ?? []) as $e) {
                    if (is_array($e) && !empty($e['id'])) {
                        if (!$this->staging->stage($runId, 'lgl', (string) $e['id'], (string) wp_json_encode($e))) {
                            $stageErrors++;
                        }
                    }
                }
            } elseif (strpos($name, 'lgl-event-') === 0) {
                if (!empty($decoded['id'])) {
                    if (!$this->staging->stage($runId, 'lgl', (string) $decoded['id'], (string) wp_json_encode($decoded))) {
                        $stageErrors++;
                    }
                }
            }
        }
        if ($stageErrors) $notes[] = "$stageErrors captured record(s) could not be staged";

        return $this->mapStaged($runId, true, $notes, $stageErrors);
    }

    /** @return array{staged: int, created: int, updated: int, skipped: int, held: int, errors: int} */
    private function failedCounts(): array
    {
        return ['staged' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'held' => 0, 'errors' => 1];
    }

    /** mbstring-agnostic truncation for log and report strings. */
    private function cut(string $s, int $a, int $b): string
    {
        return function_exists('mb_substr') ? mb_substr($s, $a, $b) : substr($s, $a, $b);
    }

    /** @param string[] $notes @return array{run_id: int, counts: array<string, int>} */
    private function mapStaged(int $runId, bool $dry, array $notes, int $initialErrors = 0): array
    {
        $counts = [
            'staged' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'held' => 0,
            'errors' => max(0, $initialErrors),
        ];
        $report = [];
        $seen = [];

        foreach ($this->staging->rowsForRun($runId) as $row) {
            $counts['staged']++;
            $key = $row->source . ':' . $row->external_id;
            if (isset($seen[$key])) {
                // Later capture of the same record supersedes: rows are
                // ordered by insertion, so keep the last occurrence only.
            }
            $seen[$key] = $row;
        }

        foreach ($seen as $row) {
            try {
                $entry = $this->mapper->apply($row, $dry);
            } catch (\Throwable $e) {
                $entry = [
                    'source' => $row->source, 'external_id' => $row->external_id,
                    'action' => 'error', 'post_id' => 0,
                    'note' => $this->cut($e->getMessage(), 0, 160),
                ];
                $this->logger->warn('exchange', 'Mapper exception; record held, run continued.', [
                    'source' => $row->source, 'external_id' => $row->external_id,
                    'error' => $e->getMessage(),
                ]);
            }
            $bucket = match ((string) ($entry['action'] ?? 'error')) {
                'created' => 'created', 'updated' => 'updated', 'held' => 'held',
                'skipped' => 'skipped', default => 'errors',
            };
            $counts[$bucket]++;
            $report[] = $entry;
        }

        if ($notes) $report[] = ['action' => 'note', 'note' => implode('; ', $notes)];
        if (!$this->ledger->close($runId, $counts, $report)) {
            $counts['errors']++;
            $this->logger->error('exchange', 'Transfer concluded, but its audit ledger could not be finalized.', [
                'run' => $runId,
            ]);
        }
        $this->logger->info('exchange', ($dry ? 'Dry' : 'Live') . ' transfer run concluded.', ['run' => $runId] + $counts);
        return ['run_id' => $runId, 'counts' => $counts];
    }
}
