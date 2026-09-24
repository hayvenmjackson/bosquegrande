<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Connectors;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ActivityLog;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\CoreFields;
use BosqueGrande\Modules\Constituents\ParticipationHistory;
use BosqueGrande\Modules\Lgl\ConstituentPresenter;
use BosqueGrande\Modules\Lgl\CategoryMatcher;

if (!defined('ABSPATH')) exit;

/**
 * LGL constituent sync, three postures set on the Connectors screen:
 *
 * push:   BosqueGrande is master. Local records create or update their LGL
 *         counterparts; LGL never writes back.
 * pull:   LGL is master. Remote records create locals or overwrite
 *         local fields wherever LGL has a value.
 * twoway: nothing writes automatically. Field-level differences are
 *         staged for review and a person applies each one in either
 *         direction. Automatic merging across systems spreads bad data.
 *
 * Matching order is always lgl_constituent_id, then the email blind
 * index. Every run lands a row in bg_transfer_runs, the Exchange
 * ledger, with accurate counts.
 *
 * $lgl is duck-typed (the LglClient surface: listConstituents,
 * findConstituentByEmail, createConstituent, updateConstituent) so
 * the harness can script it.
 */
final class LglSync
{
    public const POSTURES = ['off', 'push', 'pull', 'twoway'];
    public const CHUNK = 25;

    public function __construct(
        private ConstituentStore $store,
        private ActivityLog $activity,
        private object $lgl,
        private Logger $logger,
        private Encryption $encryption,
    ) {
    }

    /* ── Runs ledger ──────────────────────────────────────────────── */

    public function openRun(string $mode, string $trigger): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bg_transfer_runs', [
            'mode' => bg_substr($mode, 0, 8),
            'trig' => bg_substr($trigger, 0, 16),
            'sources' => 'lgl',
            'started_at' => current_time('mysql', true),
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** @param array<string, int> $counts */
    public function closeRun(int $runId, array $counts): bool
    {
        global $wpdb;
        $ok = $wpdb->update($wpdb->prefix . 'bg_transfer_runs', [
            'finished_at' => current_time('mysql', true),
            'staged' => (int) ($counts['staged'] ?? 0),
            'created' => (int) ($counts['created'] ?? 0),
            'updated' => (int) ($counts['updated'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'errors' => (int) ($counts['errors'] ?? 0),
        ], ['id' => $runId]);
        if ($ok === false) {
            $this->logger->error('connectors', 'LGL sync finished, but its audit ledger could not be finalized.', [
                'run' => $runId,
            ]);
            return false;
        }
        $this->logger->info('connectors', 'LGL sync run finished.', ['run' => $runId] + $counts);
        return true;
    }

    public function lastRun(): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}bg_transfer_runs WHERE sources = 'lgl' ORDER BY id DESC LIMIT 1"
        );
        return $row ?: null;
    }

    /* ── Pull (LGL is master) and two-way (stage only) ────────────── */

    /**
     * One page of remote constituents. Returns counts plus how many
     * remote rows were seen (0 means the pull is finished).
     * @return array<string, int>
     */
    public function pullChunk(int $offset, bool $stageOnly): array
    {
        global $wpdb;
        $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'staged' => 0, 'errors' => 0];
        if (!$this->store->available()) {
            $this->logger->error('connectors', 'Pull refused: encryption unavailable.');
            $counts['errors'] = 1;
            return $counts;
        }
        $remotes = (array) $this->lgl->listConstituents(self::CHUNK, $offset);
        if (method_exists($this->lgl, 'lastRequestSucceeded')
            && !$this->lgl->lastRequestSucceeded()) {
            $this->logger->error('connectors', 'LGL pull stopped because a constituent page could not be fetched.', [
                'offset' => $offset,
            ]);
            $counts['errors'] = 1;
            return $counts;
        }
        foreach ($remotes as $remote) {
            $counts['seen']++;
            $remoteId = (int) ($remote['id'] ?? 0);
            $fields = $this->mapRemote($remote);
            $remoteEmails = ConstituentPresenter::emails($remote);
            if ($remoteId === 0) {
                $counts['skipped']++;
                continue;
            }
            $localId = $this->matchLocal($remoteId, $remoteEmails, $fields);
            if ($localId < 0) {
                $counts['errors']++;
                continue;
            }
            if (!$localId) {
                if ($stageOnly) {
                    $counts['staged'] += $this->stageDiffs(0, $remoteId, [[
                        'field' => '__create__',
                        'local' => '',
                        'remote' => wp_json_encode($fields),
                    ]]);
                    continue;
                }
                $newId = $this->store->create($fields);
                if (!$newId) {
                    $counts['errors']++;
                    continue;
                }
                if (!$this->store->linkLgl($newId, $remoteId)) {
                    $this->store->deleteHard([$newId]);
                    $counts['errors']++;
                    continue;
                }
                if (!$this->store->syncEmails($newId, $remoteEmails, $fields['email'], 'lgl')) {
                    $this->store->deleteHard([$newId]);
                    $counts['errors']++;
                    continue;
                }
                if (!$this->applyRemoteCustomValues($newId, $remote)) {
                    $this->store->deleteHard([$newId]);
                    $counts['errors']++;
                    continue;
                }
                $this->activity->append($newId, 'import', 'Pulled from LGL.');
                $counts['created']++;
                // Listeners (newsletter opt-in sync) see the record.
                do_action('bg_lgl_pulled_constituent', $newId, $remote);
                continue;
            }
            $local = $this->store->get($localId);
            if (!$local) {
                $counts['errors']++;
                continue;
            }
            // Alternate addresses are additive identity aliases rather than
            // field-overwrite decisions. Import them even in review posture,
            // while retaining the local envelope email as primary there.
            if ($stageOnly && !$this->store->syncEmails(
                $localId,
                $remoteEmails,
                (string) ($local['email'] ?? ''),
                'lgl'
            )) {
                $counts['errors']++;
                continue;
            }
            $diffs = $this->diff($local, $fields);
            $customDiffs = $this->customDiffs($localId, $remote);
            $diffs = array_merge($diffs, $customDiffs);
            if (!$diffs) {
                if ($stageOnly && (int) ($local['lgl_constituent_id'] ?? 0) !== $remoteId) {
                    $counts['staged'] += $this->stageDiffs($localId, $remoteId, [[
                        'field' => '__link__',
                        'local' => '',
                        'remote' => (string) $remoteId,
                    ]]);
                    continue;
                }
                if (!$stageOnly) {
                    if (!$this->store->beginAtomic()) {
                        $counts['errors']++;
                        continue;
                    }
                    if (!$this->store->linkLgl($localId, $remoteId)
                        || !$this->store->syncEmails($localId, $remoteEmails, $fields['email'], 'lgl')
                        || !$this->store->commitAtomic()
                    ) {
                        $this->store->rollbackAtomic();
                        $counts['errors']++;
                        continue;
                    }
                    do_action('bg_lgl_pulled_constituent', $localId, $remote);
                }
                $counts['skipped']++;
                continue;
            }
            if ($stageOnly) {
                $counts['staged'] += $this->stageDiffs($localId, $remoteId, $diffs);
                continue;
            }
            // Pull posture: LGL wins wherever it has a value.
            if (!$this->store->beginAtomic()) {
                $counts['errors']++;
                continue;
            }
            if (!$this->store->linkLgl($localId, $remoteId)) {
                $this->store->rollbackAtomic();
                $counts['errors']++;
                continue;
            }
            $merged = $local;
            $customOk = true;
            foreach ($diffs as $d) {
                if (str_starts_with((string) $d['field'], 'custom:')) {
                    $customOk = $this->store->setValue(
                        $localId,
                        (int) substr((string) $d['field'], 7),
                        (string) $d['remote']
                    ) && $customOk;
                } else {
                    $merged[$d['field']] = $d['remote'];
                }
            }
            $coreOk = $this->store->update($localId, $merged);
            $emailOk = $coreOk
                && $this->store->syncEmails($localId, $remoteEmails, $fields['email'], 'lgl');
            if ($coreOk && $customOk && $emailOk) {
                if (!$this->store->commitAtomic()) {
                    $this->store->rollbackAtomic();
                    $counts['errors']++;
                    continue;
                }
                do_action('bg_lgl_pulled_constituent', $localId, $remote);
                $counts['updated']++;
            } else {
                $this->store->rollbackAtomic();
                $counts['errors']++;
            }
        }
        return $counts;
    }

    /* ── Push (BosqueGrande is master) ──────────────────────────────────────── */

    /**
     * One page of local constituents pushed out.
     * @return array<string, int> seen 0 means the push is finished.
     */
    public function pushChunk(int $offset): array
    {
        $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'staged' => 0, 'errors' => 0];
        $locals = $this->store->pageDecrypted($offset, self::CHUNK);
        foreach ($locals as $local) {
            $counts['seen']++;
            $counts[$this->pushLocal($local)]++;
        }
        return $counts;
    }

    /** @param list<int> $localIds @return array<string, int> */
    public function pushSelectedChunk(array $localIds, int $offset): array
    {
        $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'staged' => 0, 'errors' => 0];
        foreach (array_slice($this->cleanIds($localIds), max(0, $offset), self::CHUNK) as $localId) {
            $counts['seen']++;
            $local = $this->store->get($localId);
            if (!$local || ($local['status'] ?? '') !== 'active') {
                $counts['skipped']++;
                continue;
            }
            $counts[$this->pushLocal($local)]++;
        }
        return $counts;
    }

    /** @param list<int> $localIds @return array<string, int> */
    public function pullSelectedChunk(array $localIds, int $offset): array
    {
        $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'staged' => 0, 'errors' => 0];
        if (!$this->store->available()) {
            $counts['errors'] = 1;
            return $counts;
        }
        foreach (array_slice($this->cleanIds($localIds), max(0, $offset), self::CHUNK) as $localId) {
            $counts['seen']++;
            $local = $this->store->get($localId);
            if (!$local || ($local['status'] ?? '') !== 'active') {
                $counts['skipped']++;
                continue;
            }
            $remoteId = (int) ($local['lgl_constituent_id'] ?? 0);
            $remote = $remoteId > 0 ? $this->lgl->getConstituent($remoteId) : null;
            if ($remoteId === 0) {
                $match = $this->remoteMatchForLocal($local);
                if ($match['status'] === 'ambiguous') {
                    $counts['errors']++;
                    continue;
                }
                if ($match['status'] === 'none') {
                    $counts['skipped']++;
                    continue;
                }
                $remote = $match['record'];
                $remoteId = (int) ($remote['id'] ?? 0);
            }
            if (!is_array($remote) || $remoteId < 1) {
                $this->logger->error('connectors', 'Selected LGL pull could not fetch the linked remote constituent.', [
                    'constituent' => $localId,
                    'lgl_constituent' => $remoteId,
                ]);
                $counts['errors']++;
                continue;
            }
            if ($this->linkedPersonConflicts($local, $remote)) {
                $this->logger->error('connectors', 'Selected LGL pull refused because the saved link points to a different complete name.', [
                    'constituent' => $localId,
                    'lgl_constituent' => $remoteId,
                ]);
                $counts['errors']++;
                continue;
            }
            $result = $this->pullIntoLocal($localId, $local, $remote, $remoteId);
            $counts[$result]++;
        }
        return $counts;
    }

    /* ── Two-way review ───────────────────────────────────────────── */

    /** @return list<array{constituent_id: int, lgl_id: int, field: string, local: string, remote: string}> */
    public function diffs(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, constituent_id, lgl_id, field_key, payload_enc
             FROM {$wpdb->prefix}bg_lgl_diffs ORDER BY id ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $stored = (string) ($row->payload_enc ?? '');
            $context = $this->diffContext(
                (int) $row->constituent_id,
                (int) $row->lgl_id,
                (string) $row->field_key
            );
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) continue;
            $d = json_decode($plain, true);
            if (!is_array($d)) continue;
            if ($this->encryption->needsReencrypt($stored)) {
                $rewrapped = $this->encryption->encrypt($plain, $context);
                if ($rewrapped !== '') {
                    $wpdb->update(
                        $wpdb->prefix . 'bg_lgl_diffs',
                        ['payload_enc' => $rewrapped],
                        ['id' => (int) $row->id]
                    );
                }
            }
            $d['constituent_id'] = (int) $row->constituent_id;
            $d['lgl_id'] = (int) $row->lgl_id;
            $d['_id'] = (int) $row->id;
            $fingerprintPayload = wp_json_encode([
                (string) $d['_id'],
                (string) $d['constituent_id'],
                (string) $d['lgl_id'],
                (string) ($d['field'] ?? ''),
                (string) ($d['local'] ?? ''),
                (string) ($d['remote'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $d['_fingerprint'] = hash('sha256', is_string($fingerprintPayload) ? $fingerprintPayload : '');
            $out[] = $d;
        }
        return $out;
    }

    /** Apply one staged difference in the chosen direction, then drop it. */
    public function applyDiff(int $differenceId, string $direction, string $fingerprint): bool
    {
        if ($differenceId < 1 || !in_array($direction, ['local_to_remote', 'remote_to_local'], true)) return false;
        $diffs = $this->diffs();
        $d = null;
        foreach ($diffs as $candidate) {
            if ((int) ($candidate['_id'] ?? 0) === $differenceId) {
                $d = $candidate;
                break;
            }
        }
        if (!is_array($d)
            || $fingerprint === ''
            || !hash_equals((string) ($d['_fingerprint'] ?? ''), $fingerprint)
        ) return false;

        $claim = 'bg_lgl_diff_claim_' . $differenceId;
        $claimOwner = OptionLease::acquire($claim, 10 * MINUTE_IN_SECONDS);
        if ($claimOwner === '') return false;

        $ok = false;
        try {
            if (!$this->reviewDifferenceStillCurrent($d)) return false;
            if ($direction === 'remote_to_local') {
                if ($d['field'] === '__create__') {
                    $fields = json_decode((string) $d['remote'], true);
                    $newId = is_array($fields) ? $this->store->create($fields) : 0;
                    if ($newId) {
                        if (!$this->store->linkLgl($newId, (int) $d['lgl_id'])) {
                            $this->store->deleteHard([$newId]);
                            return false;
                        }
                        $fresh = $this->lgl->getConstituent((int) $d['lgl_id']);
                        if (is_array($fresh)) {
                            $freshFields = $this->mapRemote($fresh);
                            if (!$this->store->syncEmails(
                                $newId,
                                ConstituentPresenter::emails($fresh),
                                (string) ($freshFields['email'] ?? ''),
                                'lgl'
                            ) || !$this->applyRemoteCustomValues($newId, $fresh)) {
                                $this->store->deleteHard([$newId]);
                                return false;
                            }
                        }
                        $this->activity->append($newId, 'import', 'Created from reviewed LGL record.');
                        $ok = true;
                    }
                } elseif ($d['field'] === '__link__') {
                    $ok = $this->store->linkLgl((int) $d['constituent_id'], (int) $d['lgl_id']);
                } elseif (str_starts_with((string) $d['field'], 'custom:')) {
                    global $wpdb;
                    if (!$this->store->beginAtomic()) return false;
                    $ok = $this->store->setValue(
                        (int) $d['constituent_id'],
                        (int) substr((string) $d['field'], 7),
                        (string) $d['remote']
                    );
                    if ($ok) {
                        $ok = $this->store->linkLgl((int) $d['constituent_id'], (int) $d['lgl_id']);
                    }
                    if ($ok) {
                        $ok = $this->store->commitAtomic();
                        if (!$ok) $this->store->rollbackAtomic();
                    } else {
                        $this->store->rollbackAtomic();
                    }
                } else {
                    $local = $this->store->get((int) $d['constituent_id']);
                    if ($local) {
                        global $wpdb;
                        if (!$this->store->beginAtomic()) return false;
                        $local[$d['field']] = (string) $d['remote'];
                        $ok = $this->store->update((int) $d['constituent_id'], $local);
                        if ($ok) {
                            $ok = $this->store->linkLgl(
                                (int) $d['constituent_id'],
                                (int) $d['lgl_id']
                            );
                        }
                        if ($ok) {
                            $ok = $this->store->commitAtomic();
                            if (!$ok) $this->store->rollbackAtomic();
                        } else {
                            $this->store->rollbackAtomic();
                        }
                    }
                }
            } elseif ($direction === 'local_to_remote') {
                $local = $this->store->get((int) $d['constituent_id']);
                $remote = $this->lgl->getConstituent((int) $d['lgl_id']);
                if ($local !== null && is_array($remote) && str_starts_with((string) $d['field'], 'custom:')) {
                    $fieldId = (int) substr((string) $d['field'], 7);
                    $fragment = $this->customPayload((int) $d['constituent_id'], $remote, $fieldId);
                    $ok = $fragment !== []
                        && $this->lgl->updateConstituent((int) $d['lgl_id'], $fragment) !== null;
                    if ($ok) {
                        $ok = $this->store->linkLgl((int) $d['constituent_id'], (int) $d['lgl_id']);
                    }
                } elseif ($local !== null && is_array($remote) && !str_starts_with((string) $d['field'], '__')) {
                    $preserved = $this->mapRemote($remote);
                    $preserved[$d['field']] = (string) $d['local'];
                    $ok = $this->lgl->updateConstituent(
                        (int) $d['lgl_id'],
                        $this->payload($preserved)
                    ) !== null;
                    if ($ok) {
                        $ok = $this->store->linkLgl((int) $d['constituent_id'], (int) $d['lgl_id']);
                    }
                }
            }
            if ($ok) {
                if ($direction === 'remote_to_local' && (int) ($d['constituent_id'] ?? 0) > 0) {
                    do_action('bg_lgl_pulled_constituent', (int) $d['constituent_id'], []);
                }
                global $wpdb;
                if ($wpdb->delete(
                    $wpdb->prefix . 'bg_lgl_diffs',
                    ['id' => (int) ($d['_id'] ?? 0)]
                ) === false) {
                    $this->logger->error('connectors', 'An applied LGL difference could not be removed from the review queue.', [
                        'difference' => (int) ($d['_id'] ?? 0),
                    ]);
                    return false;
                }
            }
            return $ok;
        } finally {
            OptionLease::release($claim, $claimOwner);
        }
    }

    /**
     * Re-read both systems after the action claim. A reviewed snapshot must
     * still describe the current source and destination before either side is
     * changed; otherwise the office refreshes and reviews the new difference.
     *
     * @param array<string, mixed> $d
     */
    private function reviewDifferenceStillCurrent(array $d): bool
    {
        $localId = (int) ($d['constituent_id'] ?? 0);
        $lglId = (int) ($d['lgl_id'] ?? 0);
        $field = (string) ($d['field'] ?? '');
        if ($lglId < 1 || $field === '') return false;

        $remote = $this->lgl->getConstituent($lglId);
        if (!is_array($remote) || (int) ($remote['id'] ?? 0) !== $lglId) return false;
        $remoteFields = $this->mapRemote($remote);

        if ($field === '__create__') {
            $staged = json_decode((string) ($d['remote'] ?? ''), true);
            if (!is_array($staged)
                || $this->matchLocal($lglId, ConstituentPresenter::emails($remote), $remoteFields) !== 0
            ) return false;
            foreach ($staged as $key => $value) {
                if (!$this->sameReviewedValue((string) ($remoteFields[$key] ?? ''), (string) $value)) return false;
            }
            return true;
        }

        $local = $localId > 0 ? $this->store->get($localId) : null;
        if (!$local || ($local['status'] ?? '') !== 'active') return false;

        if ($field === '__link__') {
            return (int) ($local['lgl_constituent_id'] ?? 0) === 0
                && $this->diff($local, $remoteFields) === []
                && $this->customDiffs($localId, $remote) === [];
        }

        if (str_starts_with($field, 'custom:')) {
            $fieldId = (int) substr($field, 7);
            if ($fieldId < 1) return false;
            $localValues = $this->store->valuesFor($localId);
            $remoteValues = $this->remoteCustomValues($remote);
            return $this->sameReviewedValue((string) ($localValues[$fieldId] ?? ''), (string) ($d['local'] ?? ''))
                && $this->sameReviewedValue((string) ($remoteValues[$fieldId] ?? ''), (string) ($d['remote'] ?? ''));
        }

        if (!in_array($field, ConstituentStore::FIELDS, true)) return false;
        return $this->sameReviewedValue((string) ($local[$field] ?? ''), (string) ($d['local'] ?? ''))
            && $this->sameReviewedValue((string) ($remoteFields[$field] ?? ''), (string) ($d['remote'] ?? ''));
    }

    private function sameReviewedValue(string $current, string $staged): bool
    {
        return strcasecmp(trim($current), trim($staged)) === 0;
    }

    public function clearDiffs(): bool
    {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bg_lgl_diffs") !== false;
    }

    /* ── Internals ────────────────────────────────────────────────── */

    /** @param list<int> $ids @return list<int> */
    private function cleanIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string, mixed> $local */
    private function pushLocal(array $local): string
    {
        $localId = (int) ($local['id'] ?? 0);
        if ($localId < 1) return 'skipped';
        $remoteId = (int) ($local['lgl_constituent_id'] ?? 0);
        $remote = null;
        if ($remoteId > 0) {
            $remote = $this->lgl->getConstituent($remoteId);
            if (!is_array($remote)) {
                $this->logger->error('connectors', 'LGL push refused because the saved remote link could not be fetched.', [
                    'constituent' => $localId,
                    'lgl_constituent' => $remoteId,
                ]);
                return 'errors';
            }
            if ($this->linkedPersonConflicts($local, $remote)) {
                $this->logger->error('connectors', 'LGL push refused because the saved link points to a different complete name.', [
                    'constituent' => $localId,
                    'lgl_constituent' => $remoteId,
                ]);
                return 'errors';
            }
        } else {
            $match = $this->remoteMatchForLocal($local);
            if ($match['status'] === 'ambiguous') return 'errors';
            if ($match['status'] === 'found') {
                $remote = $match['record'];
                $remoteId = (int) ($remote['id'] ?? 0);
                if ($remoteId < 1 || !$this->store->linkLgl($localId, $remoteId)) return 'errors';
            }
        }
        $payload = $this->mergePayloads(
            $this->payload($local),
            $this->customPayload($localId, is_array($remote) ? $remote : null)
        );
        if ($remoteId > 0) {
            if ($this->lgl->updateConstituent($remoteId, $payload) === null) return 'errors';
            $this->activity->append($localId, 'update', 'Pushed local record to LGL.');
            return 'updated';
        }
        if (($local['email'] ?? '') === ''
            && trim((string) ($local['first_name'] ?? '') . (string) ($local['last_name'] ?? '')) === '') {
            return 'skipped';
        }
        $created = $this->lgl->createConstituent($payload);
        $createdId = is_array($created) ? (int) ($created['id'] ?? 0) : 0;
        if ($createdId < 1 || !$this->store->linkLgl($localId, $createdId)) return 'errors';
        $this->activity->append($localId, 'update', 'Pushed to LGL (record created there).');
        return 'created';
    }

    /** @param array<string, mixed> $local @return array{status:string,record:?array} */
    private function remoteMatchForLocal(array $local): array
    {
        $candidates = [];
        foreach ($this->store->emailsFor((int) ($local['id'] ?? 0)) as $email) {
            $found = method_exists($this->lgl, 'findConstituentsByEmail')
                ? (array) $this->lgl->findConstituentsByEmail($email)
                : array_filter([$this->lgl->findConstituentByEmail($email)], 'is_array');
            if (method_exists($this->lgl, 'lastRequestSucceeded')
                && !$this->lgl->lastRequestSucceeded()) {
                $this->logger->error('connectors', 'LGL identity search failed; sync refused to create or link a record from an incomplete result.', [
                    'constituent' => (int) ($local['id'] ?? 0),
                ]);
                return ['status' => 'ambiguous', 'record' => null];
            }
            foreach ($found as $candidate) {
                if (!is_array($candidate)) continue;
                $id = (int) ($candidate['id'] ?? 0);
                if ($id > 0) $candidates[$id] = $candidate;
            }
        }
        if (!$candidates) return ['status' => 'none', 'record' => null];
        foreach ($candidates as $id => $candidate) {
            $detail = method_exists($this->lgl, 'getConstituent') ? $this->lgl->getConstituent((int) $id) : null;
            if (method_exists($this->lgl, 'lastRequestSucceeded')
                && !$this->lgl->lastRequestSucceeded()) {
                $this->logger->error('connectors', 'LGL identity detail could not be fetched; sync refused to guess from a partial record.', [
                    'constituent' => (int) ($local['id'] ?? 0),
                    'lgl_constituent' => (int) $id,
                ]);
                return ['status' => 'ambiguous', 'record' => null];
            }
            if (is_array($detail)) $candidates[$id] = $detail;
        }
        $bestId = 0;
        $bestScore = 1;
        $tied = false;
        foreach ($candidates as $id => $candidate) {
            $score = ConstituentStore::identityScore($local, $this->mapRemote($candidate));
            if ($score > $bestScore) {
                $bestId = (int) $id;
                $bestScore = $score;
                $tied = false;
            } elseif ($score === $bestScore && $score >= 2) {
                $tied = true;
            }
        }
        if ($bestId > 0 && !$tied) return ['status' => 'found', 'record' => $candidates[$bestId]];

        $localName = ConstituentStore::normName((string) ($local['first_name'] ?? ''), (string) ($local['last_name'] ?? ''));
        $localComplete = trim((string) ($local['first_name'] ?? '')) !== '' && trim((string) ($local['last_name'] ?? '')) !== '';
        $allNamedDifferent = $localComplete;
        foreach ($candidates as $candidate) {
            $mapped = $this->mapRemote($candidate);
            $remoteComplete = trim($mapped['first_name']) !== '' && trim($mapped['last_name']) !== '';
            $remoteName = ConstituentStore::normName($mapped['first_name'], $mapped['last_name']);
            if (!$remoteComplete || hash_equals($localName, $remoteName)) {
                $allNamedDifferent = false;
                break;
            }
        }
        if ($allNamedDifferent) return ['status' => 'none', 'record' => null];
        $this->logger->warn('connectors', 'Shared email matched several possible LGL people; sync refused to guess.', [
            'constituent' => (int) ($local['id'] ?? 0),
            'matches' => count($candidates),
        ]);
        return ['status' => 'ambiguous', 'record' => null];
    }

    /** @param array<string, mixed> $local @param array<string, mixed> $remote */
    private function linkedPersonConflicts(array $local, array $remote): bool
    {
        $mapped = array_key_exists('address1', $remote) && array_key_exists('postal', $remote)
            ? $remote
            : $this->mapRemote($remote);
        $localComplete = trim((string) ($local['first_name'] ?? '')) !== ''
            && trim((string) ($local['last_name'] ?? '')) !== '';
        $remoteComplete = trim($mapped['first_name']) !== '' && trim($mapped['last_name']) !== '';
        return $localComplete && $remoteComplete
            && ConstituentStore::identityScore($local, $mapped) < 2;
    }

    /** @param array<string, mixed> $local @param array<string, mixed> $remote */
    private function pullIntoLocal(int $localId, array $local, array $remote, int $remoteId = 0): string
    {
        global $wpdb;
        $fields = $this->mapRemote($remote);
        $remoteEmails = ConstituentPresenter::emails($remote);
        $diffs = $this->diff($local, $fields);
        $customDiffs = $this->customDiffs($localId, $remote);
        if (!$this->store->beginAtomic()) return 'errors';
        if ($remoteId > 0 && !$this->store->linkLgl($localId, $remoteId)) {
            $this->store->rollbackAtomic();
            return 'errors';
        }
        $merged = $local;
        foreach ($diffs as $d) $merged[$d['field']] = $d['remote'];
        $coreOk = !$diffs || $this->store->update($localId, $merged);
        $customOk = true;
        foreach ($customDiffs as $d) {
            $customOk = $this->store->setValue(
                $localId,
                (int) substr((string) $d['field'], 7),
                (string) $d['remote']
            ) && $customOk;
        }
        $emailOk = $coreOk && $this->store->syncEmails($localId, $remoteEmails, $fields['email'], 'lgl');
        if (!$coreOk || !$customOk || !$emailOk) {
            $this->store->rollbackAtomic();
            return 'errors';
        }
        if (!$this->store->commitAtomic()) {
            $this->store->rollbackAtomic();
            return 'errors';
        }
        do_action('bg_lgl_pulled_constituent', $localId, $remote);
        if (!$diffs && !$customDiffs) return 'skipped';
        $this->activity->append($localId, 'update', 'Pulled selected LGL record into BosqueGrande.');
        return 'updated';
    }

    /** @param array<string, mixed> $remote @return array<string, string> */
    private function mapRemote(array $remote): array
    {
        $p = ConstituentPresenter::prefill($remote);
        return [
            'first_name' => $p['first_name'], 'last_name' => $p['last_name'],
            'email' => $p['email'], 'phone' => $p['phone'],
            'address1' => $p['street'], 'address2' => $p['address2'],
            'city' => $p['city'], 'state' => $p['state'], 'postal' => $p['zip'],
            // LGL calls its native notes field Background Info.
            'notes' => $p['notes'],
        ];
    }

    /** @param array<string, string> $local @return array<string, mixed> LGL write payload. */
    private function payload(array $local): array
    {
        $payload = [
            'first_name' => $local['first_name'],
            'last_name' => $local['last_name'],
        ];
        if ($local['email'] !== '') {
            $payload['email_addresses'] = [['address' => $local['email'], 'is_preferred' => true]];
        }
        if ($local['phone'] !== '') {
            $payload['phone_numbers'] = [['number' => $local['phone'], 'is_preferred' => true]];
        }
        if ($local['address1'] . $local['address2'] . $local['city'] . $local['state'] . $local['postal'] !== '') {
            $street = trim($local['address1']);
            if (trim($local['address2']) !== '') {
                $street .= ($street !== '' ? "\n" : '') . trim($local['address2']);
            }
            $payload['street_addresses'] = [[
                // LGL exports a multi-line street value consistently;
                // joining here preserves BG's second address line even
                // on accounts whose API omits a separate street2 key.
                'street' => $street, 'city' => $local['city'],
                'state' => $local['state'], 'postal_code' => $local['postal'],
                'is_preferred' => true,
            ]];
        }
        if (trim($local['notes']) !== '') {
            // The constituent API exposes Background Info through the
            // reserved background_info custom attribute.
            $payload['custom_attrs'] = [[
                'key' => 'background_info',
                'value' => $local['notes'],
            ]];
        }
        return $payload;
    }

    /**
     * Merge constituent payload fragments without dropping related
     * objects that share the categories/custom_attrs container keys.
     *
     * @param array<string, mixed> ...$parts
     * @return array<string, mixed>
     */
    private function mergePayloads(array ...$parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            foreach ($part as $key => $value) {
                if (in_array($key, ['categories', 'custom_attrs'], true) && is_array($value)) {
                    $out[$key] = array_merge((array) ($out[$key] ?? []), $value);
                    continue;
                }
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return list<array{field: string, local: string, remote: string}> */
    private function customDiffs(int $localId, array $remote): array
    {
        $localValues = $this->store->valuesFor($localId);
        $remoteValues = $this->remoteCustomValues($remote);
        $out = [];
        foreach ($remoteValues as $fieldId => $remoteValue) {
            $localValue = (string) ($localValues[$fieldId] ?? '');
            if ($remoteValue !== '' && strcasecmp($remoteValue, $localValue) !== 0) {
                $out[] = [
                    'field' => 'custom:' . $fieldId,
                    'local' => $localValue,
                    'remote' => (string) $remoteValue,
                ];
            }
        }
        return $out;
    }

    private function applyRemoteCustomValues(int $localId, array $remote): bool
    {
        $ok = true;
        foreach ($this->remoteCustomValues($remote) as $fieldId => $value) {
            if ($value !== '') {
                $ok = $this->store->setValue($localId, (int) $fieldId, $value) && $ok;
            }
        }
        return $ok;
    }

    /**
     * @return array<int, array{key: string, name: string, value: string}>
     */
    private function localCustomValues(int $localId): array
    {
        $values = $this->store->valuesFor($localId);
        $out = [];
        foreach ($this->store->fieldDefinitions() as $def) {
            $id = (int) $def->id;
            $value = (string) ($values[$id] ?? '');
            if ($value === '') continue;
            $out[$id] = [
                'key' => (string) $def->field_key,
                'name' => $this->lglCustomName((string) $def->field_key, (string) $def->label),
                'value' => $value,
            ];
        }
        return $out;
    }

    /** @return array<int, string> field id => value */
    private function remoteCustomValues(array $remote): array
    {
        // These two fields drive first-class filters, so a pull must not
        // depend on staff having applied the optional full LGL field profile.
        $this->store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        $this->store->ensureField(CoreFields::PAST_EVENTS, ParticipationHistory::LABEL, false);
        $attrs = array_values(array_filter((array) ($remote['custom_attrs'] ?? []), 'is_array'));
        $cats = array_values(array_filter((array) ($remote['categories'] ?? []), 'is_array'));
        $out = [];
        foreach ($this->store->fieldDefinitions() as $def) {
            $key = (string) $def->field_key;
            $name = $this->lglCustomName($key, (string) $def->label);
            $value = $key === CoreFields::PAST_EVENTS
                ? ParticipationHistory::remoteValue($remote)
                : ($this->nativeProfileValue($key, $remote) ?? '');
            if ($value === '') {
                $attr = CategoryMatcher::matchAttr($attrs, $name);
                if ($attr) {
                    $value = trim((string) ($attr['value'] ?? $attr['description'] ?? ''));
                } else {
                    $cat = CategoryMatcher::match($cats, $name);
                    if ($cat) {
                        $parts = [];
                        foreach ((array) ($cat['keywords'] ?? []) as $kw) {
                            $part = trim((string) (is_array($kw) ? ($kw['name'] ?? '') : $kw));
                            if ($part !== '') $parts[] = $part;
                        }
                        $value = implode('; ', $parts);
                    }
                }
            }
            if ($key === CoreFields::MONTHLY_DONOR) {
                $value = CoreFields::normalizeMonthlyDonor($value);
            }
            if ($value !== '') $out[(int) $def->id] = $value;
        }
        return $out;
    }

    /**
     * Map the fields LGL documents directly on a constituent into the
     * corresponding BosqueGrande profile fields. Null means that the
     * field has no native API mapping, so custom attributes/categories
     * remain eligible as the account-specific fallback.
     */
    private function nativeProfileValue(string $key, array $remote): ?string
    {
        return match ($key) {
            'constituent_type' => array_key_exists('is_org', $remote)
                ? (!empty($remote['is_org']) ? 'Organization' : 'Individual')
                : null,
            'middle_name' => $this->remoteScalar($remote, 'middle_name'),
            'addressee' => $this->remoteScalar($remote, 'addressee'),
            'alt_addressee' => $this->remoteScalar($remote, 'alt_addressee'),
            'salutation' => $this->remoteScalar($remote, 'salutation'),
            'alt_salutation' => $this->remoteScalar($remote, 'alt_salutation'),
            'marital_status' => $this->remoteScalar($remote, 'marital_status_name'),
            'spouse_partner_name' => $this->remoteScalar($remote, 'spouse_name'),
            'nicknames' => $this->remoteScalar($remote, 'nick_name'),
            'annual_report_name' => $this->remoteScalar($remote, 'annual_report_name'),
            'honorary_name' => $this->remoteScalar($remote, 'honorary_name'),
            'assistant_name' => $this->remoteScalar($remote, 'assistant_name'),
            'birthday' => $this->remoteScalar($remote, 'birthday'),
            'employer_organization' => $this->remoteScalar($remote, 'org_name'),
            'job_title' => $this->remoteScalar($remote, 'job_title'),
            'anonymous' => $this->remoteBoolean($remote, 'is_anon'),
            'is_deceased' => $this->remoteBoolean($remote, 'is_deceased'),
            'relationship_s_summary' => $this->relationshipSummary($remote),
            'date_added' => $this->remoteScalar($remote, 'date_added'),
            'created_date' => $this->remoteScalar($remote, 'created_at'),
            'updated_date' => $this->remoteScalar($remote, 'updated_at'),
            'primary_group' => $this->groupNames($remote, true),
            'groups' => $this->groupNames($remote, false),
            'url' => $this->preferredWebAddress($remote),
            default => null,
        };
    }

    private function remoteScalar(array $remote, string $key): ?string
    {
        return array_key_exists($key, $remote) ? trim((string) $remote[$key]) : null;
    }

    private function remoteBoolean(array $remote, string $key): ?string
    {
        return array_key_exists($key, $remote) ? (!empty($remote[$key]) ? 'Yes' : 'No') : null;
    }

    private function groupNames(array $remote, bool $firstOnly): ?string
    {
        if (!array_key_exists('groups', $remote)) return null;
        $names = [];
        foreach ((array) $remote['groups'] as $group) {
            $name = is_array($group)
                ? trim((string) ($group['group_name'] ?? $group['name'] ?? ''))
                : trim((string) $group);
            if ($name === '') continue;
            $names[$name] = $name;
            if ($firstOnly) break;
        }
        return implode('; ', $names);
    }

    private function preferredWebAddress(array $remote): ?string
    {
        if (!array_key_exists('web_addresses', $remote)) return null;
        $first = '';
        foreach ((array) $remote['web_addresses'] as $item) {
            if (!is_array($item) || !empty($item['not_current'])) continue;
            $url = trim((string) ($item['url'] ?? $item['address'] ?? ''));
            if ($url === '') continue;
            if ($first === '') $first = $url;
            if (!empty($item['is_preferred'])) return $url;
        }
        return $first;
    }

    private function relationshipSummary(array $remote): ?string
    {
        if (!array_key_exists('relationships', $remote)) return null;
        $items = [];
        foreach ((array) $remote['relationships'] as $relationship) {
            if (!is_array($relationship)) continue;
            $name = trim((string) (
                $relationship['related_constituent_name']
                ?? $relationship['related_name']
                ?? $relationship['constituent_name']
                ?? ''
            ));
            $type = trim((string) (
                $relationship['relationship_name']
                ?? $relationship['relationship_type']
                ?? $relationship['description']
                ?? ''
            ));
            $summary = trim($name . ($name !== '' && $type !== '' ? ': ' : '') . $type);
            if ($summary !== '') $items[$summary] = $summary;
        }
        return implode('; ', $items);
    }

    /** @return array<string, mixed> */
    private function customPayload(int $localId, ?array $remote, ?int $onlyFieldId = null): array
    {
        $categories = [];
        foreach ($this->localCustomValues($localId) as $fieldId => $item) {
            if ($onlyFieldId !== null && $fieldId !== $onlyFieldId) continue;
            $values = ConstituentStore::splitList((string) $item['value']);
            if (!$values) continue;
            $categories[] = [
                'name' => $item['name'],
                'keywords' => array_map(static fn(string $value): array => ['name' => $value], $values),
            ];
        }
        return $categories ? $this->lgl->answerPayload($categories, $remote) : [];
    }

    private function lglCustomName(string $key, string $fallback): string
    {
        return match ($key) {
            'demographic' => (string) apply_filters('bg_demographic_lgl_category', 'Demographics'),
            'age_range' => (string) apply_filters('bg_age_range_lgl_category', 'Age Range'),
            'relationship_status' => (string) apply_filters('bg_relationship_lgl_category', 'Relationship Status'),
            default => $fallback,
        };
    }

    /** @param list<string> $emails @param array<string, string> $candidate */
    private function matchLocal(int $remoteId, array $emails, array $candidate): int
    {
        $byLink = $this->store->idsByLglId($remoteId);
        if (count($byLink) === 1) {
            $linkedId = (int) $byLink[0];
            $linked = $this->store->get($linkedId);
            if ($linked && $this->linkedPersonConflicts($linked, $candidate)) {
                $this->logger->error('connectors', 'LGL pull refused because the saved link points to a different complete name.', [
                    'constituent' => $linkedId,
                    'lgl_constituent' => $remoteId,
                ]);
                return -1;
            }
            return $linkedId;
        }
        if (count($byLink) > 1) {
            $this->logger->error('connectors', 'LGL pull refused because one remote id is linked to several active local records.', [
                'lgl_constituent' => $remoteId,
                'matches' => count($byLink),
            ]);
            return -1;
        }
        $ids = [];
        foreach ($emails as $email) {
            $ids = array_merge($ids, $this->store->idsByEmail((string) $email));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return 0;
        if (count($ids) === 1) {
            $record = $this->store->get((int) $ids[0]);
            $localComplete = $record
                && trim((string) ($record['first_name'] ?? '')) !== ''
                && trim((string) ($record['last_name'] ?? '')) !== '';
            $remoteComplete = trim($candidate['first_name'] ?? '') !== '' && trim($candidate['last_name'] ?? '') !== '';
            if ($localComplete && $remoteComplete
                && ConstituentStore::identityScore((array) $record, $candidate) < 2) {
                return 0;
            }
            return (int) $ids[0];
        }
        $bestId = $this->store->disambiguateIds($ids, $candidate);
        if (!$bestId) {
            $this->logger->warn('connectors', 'An LGL constituent matched several local records through a shared email; the pull was stopped for that person instead of guessing.', [
                'lgl_constituent' => $remoteId,
                'matches' => count($ids),
            ]);
            return -1;
        }
        return $bestId;
    }

    /**
     * @param array<string, string> $local @param array<string, string> $remote
     * @return list<array{field: string, local: string, remote: string}>
     * Only fields where LGL has a value that differs; a blank remote
     * never proposes erasing local data.
     */
    private function diff(array $local, array $remote): array
    {
        $out = [];
        foreach (ConstituentStore::FIELDS as $field) {
            $r = trim($remote[$field] ?? '');
            $l = trim($local[$field] ?? '');
            if ($r !== '' && strcasecmp($r, $l) !== 0) {
                $out[] = ['field' => $field, 'local' => $l, 'remote' => $r];
            }
        }
        return $out;
    }

    /** @param list<array{field: string, local: string, remote: string}> $diffs */
    private function stageDiffs(int $localId, int $remoteId, array $diffs): int
    {
        global $wpdb;
        $saved = 0;
        foreach ($diffs as $d) {
            $fieldKey = bg_substr((string) ($d['field'] ?? ''), 0, 96);
            $payload = wp_json_encode([
                'field' => $fieldKey,
                'local' => (string) ($d['local'] ?? ''),
                'remote' => (string) ($d['remote'] ?? ''),
            ]);
            $ciphertext = is_string($payload)
                ? $this->encryption->encrypt(
                    $payload,
                    $this->diffContext($localId, $remoteId, $fieldKey)
                )
                : '';
            if ($ciphertext === '' || $ciphertext === $payload) {
                $this->logger->error('connectors', 'LGL difference could not be encrypted for review.', [
                    'lgl_id' => $remoteId,
                    'field' => (string) ($d['field'] ?? ''),
                ]);
                continue;
            }
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}bg_lgl_diffs
                    (constituent_id, lgl_id, field_key, payload_enc, created_at)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    payload_enc = VALUES(payload_enc),
                    created_at = VALUES(created_at)",
                $localId,
                $remoteId,
                $fieldKey,
                $ciphertext,
                current_time('mysql', true)
            ));
            if ($result !== false) $saved++;
        }
        return $saved;
    }

    private function diffContext(int $localId, int $remoteId, string $field): string
    {
        return 'lgl-diff:' . max(0, $localId) . ':' . max(0, $remoteId) . ':' . sanitize_key($field);
    }
}
