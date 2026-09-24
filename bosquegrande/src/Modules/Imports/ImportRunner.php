<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Imports;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Encryption;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Registration\RegistrationRepository;

if (!defined('ABSPATH')) exit;

/** Dry-run, commit, and guarded rollback engine for Import Studio. */
final class ImportRunner
{
    private const SOURCE = 'spreadsheet';

    public function __construct(
        private Container $container,
        private ImportStore $imports,
        private SpreadsheetReader $reader,
    ) {
    }

    /** @return array<string,mixed> */
    public function stage(int $jobId): array
    {
        $job = $this->imports->job($jobId);
        if (!$job || !in_array((string) $job->status, ['mapped', 'dry_run'], true)) {
            throw new \RuntimeException('This import is not ready for a dry run.');
        }
        $path = $this->imports->filePath($job);
        if ($path === '' || !is_readable($path)) throw new \RuntimeException('The private source file is unavailable. Upload it again.');
        $mapping = $this->imports->mapping($job);
        $missing = [];
        foreach (FieldMatcher::targets() as $key => $target) {
            if ($target['required'] && empty($mapping[$key]['columns'])) $missing[] = $target['label'];
        }
        if ($missing) throw new \RuntimeException('Confirm mappings for: ' . implode(', ', $missing) . '.');
        if (!$this->imports->clearRows($jobId)) throw new \RuntimeException('Existing dry-run rows could not be cleared.');

        $transformer = new RowTransformer(wp_timezone());
        $wanted = FieldMatcher::wantedColumns($mapping);
        $seenRegistrations = [];
        $answerSets = [];
        $eventSets = [];
        $staged = 0;
        $sourceRows = 0;
        foreach ($this->reader->rows($path, (string) $job->source_type, (string) $job->source_sheet, $wanted) as $rowNo => $cells) {
            $sourceRows++;
            $result = $transformer->transform($rowNo, $cells, $mapping);
            $payload = $result['payload'];
            $issues = $result['issues'];
            $identityKey = $this->identityKey((array) $payload['person'], $payload);
            $registrationKey = $this->registrationKey($payload);
            $state = 'ready_new';
            $fatal = array_intersect($issues, [
                'attendee_name_missing', 'event_id_missing', 'event_name_missing',
                'order_number_missing', 'order_date_invalid', 'event_date_invalid',
            ]);
            if ($fatal || $identityKey === '' || $registrationKey === '') {
                $state = 'invalid';
            } elseif (($payload['registration']['status'] ?? '') === 'excluded') {
                $state = 'excluded';
            } elseif (isset($seenRegistrations[$registrationKey])) {
                $state = 'duplicate';
                $issues[] = 'duplicate_source_registration';
            } elseif ($this->imports->external(self::SOURCE, 'registration', $registrationKey)) {
                $state = 'already_imported';
                $issues[] = 'external_registration_already_imported';
            } else {
                $seenRegistrations[$registrationKey] = true;
                $resolved = $this->resolvePerson((array) $payload['person']);
                $state = $resolved > 0 ? 'ready_match' : 'ready_new';
                if ($resolved < 0) {
                    $resolved = 0;
                    $issues[] = 'ambiguous_identity_separate_record';
                }
            }

            $rowId = $this->imports->stageRow(
                $jobId, $rowNo, $identityKey, $registrationKey, $state,
                $payload, $issues
            );
            if (!$rowId) throw new \RuntimeException('An encrypted source row could not be staged.');
            if (in_array($state, ['ready_new', 'ready_match'], true)) {
                $resolved = $this->resolvePerson((array) $payload['person']);
                if ($resolved > 0) $this->imports->setRowState($rowId, 'ready_match', ['constituent_id' => $resolved]);
                $this->collectConflictFingerprints($answerSets, $identityKey, $payload);
                $this->collectEventFingerprints($eventSets, (array) $payload['event']);
            }
            $staged++;
        }

        $crossConflicts = $this->crossConflictMap($answerSets);
        if ($crossConflicts) $this->dropCrossRowConflicts($jobId, $crossConflicts);
        $eventConflicts = $this->crossConflictMap($eventSets);
        if ($eventConflicts) $this->rejectEventConflicts($jobId, $eventConflicts);
        $counts = $this->imports->stateCounts($jobId);
        $issues = $this->imports->issueCounts($jobId);
        $summary = $this->imports->summary($job);
        $summary['counts'] = $counts;
        $summary['issues'] = $issues;
        $summary['source_rows'] = $sourceRows;
        $summary['staged_rows'] = $staged;
        $summary['conflict_policy'] = 'drop';
        $summary['financial_policy'] = 'source evidence only; never posted to the local ledger';
        $summary['attendance_policy'] = 'confirmed registration; no physical attendance inferred';
        $this->imports->updateJob($jobId, [
            'status' => 'dry_run',
            'total_rows' => $staged,
            'processed_rows' => 0,
            'created_count' => 0,
            'matched_count' => 0,
            'skipped_count' => (int) ($counts['duplicate'] ?? 0) + (int) ($counts['already_imported'] ?? 0) + (int) ($counts['excluded'] ?? 0),
            'failed_count' => (int) ($counts['invalid'] ?? 0),
        ], $summary);
        return $summary;
    }

    /** @return array{done:bool,processed:int,remaining:int,status:string} */
    public function commitBatch(int $jobId, int $limit = 25): array
    {
        $job = $this->imports->job($jobId);
        if (!$job || !in_array((string) $job->status, ['dry_run', 'committing', 'purge_failed'], true)) {
            throw new \RuntimeException('This import is not in a committable state.');
        }
        if ((string) $job->status === 'dry_run') $this->imports->updateJob($jobId, ['status' => 'committing']);
        $batch = $this->imports->rowsByState($jobId, ['ready_new', 'ready_match'], max(1, min(100, $limit)));
        $processed = 0;
        foreach ($batch as $item) {
            $row = $item['row'];
            try {
                $this->commitRow($job, $row, $item['payload']);
                $processed++;
            } catch (\Throwable $e) {
                $this->imports->setRowState((int) $row->id, 'failed', [], ['commit_failed']);
                $this->container->get('logger')->error('imports', 'A staged import row failed without exposing its values.', [
                    'job' => $jobId, 'source_row' => (int) $row->source_row,
                    'reason' => bg_substr($e->getMessage(), 0, 180),
                ]);
            }
        }

        $counts = $this->imports->stateCounts($jobId);
        $remaining = (int) ($counts['ready_new'] ?? 0) + (int) ($counts['ready_match'] ?? 0);
        $created = (int) ($counts['imported_new'] ?? 0);
        $matched = (int) ($counts['imported_match'] ?? 0);
        $failed = (int) ($counts['invalid'] ?? 0) + (int) ($counts['failed'] ?? 0);
        $skipped = (int) ($counts['duplicate'] ?? 0) + (int) ($counts['already_imported'] ?? 0) + (int) ($counts['excluded'] ?? 0);
        $fields = [
            'processed_rows' => $created + $matched,
            'created_count' => $created,
            'matched_count' => $matched,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
        ];
        $status = 'committing';
        if ($remaining === 0) {
            $fresh = $this->imports->job($jobId);
            $summary = $fresh ? $this->imports->summary($fresh) : [];
            $summary['counts'] = $counts;
            $summary['issues'] = $this->imports->issueCounts($jobId);
            if ($fresh && !$this->imports->purgeStaging($jobId, $fresh)) {
                $summary['staging_purge_failed'] = true;
                $fields['status'] = 'purge_failed';
                $this->imports->updateJob($jobId, $fields, $summary);
                throw new \RuntimeException('Records were committed, but private source cleanup failed. Correct filesystem permissions and use Resume cleanup.');
            }
            $status = $failed > 0 ? 'complete_errors' : 'complete';
            $fields['status'] = $status;
            $fields['completed_at'] = current_time('mysql', true);
            unset($summary['staging_purge_failed']);
            $this->imports->updateJob($jobId, $fields, $summary);
            // Person writes already run regional assignment synchronously.
            // Retain the standard backfill safety net as well, so a transient
            // write failure cannot silently remove an imported address from
            // regional reporting.
            update_option('bg_constituent_region_backfill_pending', 1, false);
            delete_transient('bg_constituent_region_backfill_queued');
            $this->container->get('logger')->info('imports', 'Spreadsheet import completed.', [
                'job' => $jobId, 'created_rows' => $created, 'matched_rows' => $matched,
                'skipped_rows' => $skipped, 'failed_rows' => $failed,
            ]);
        } else {
            $fields['status'] = 'committing';
            $this->imports->updateJob($jobId, $fields);
        }
        return ['done' => $remaining === 0, 'processed' => $processed, 'remaining' => $remaining, 'status' => $status];
    }

    /** @return array{done:bool,processed:int,remaining:int,conflicts:int} */
    public function rollbackBatch(int $jobId, int $limit = 25): array
    {
        $job = $this->imports->job($jobId);
        if (!$job || !in_array((string) $job->status, ['complete', 'complete_errors', 'rolling_back', 'rollback_conflicts'], true)) {
            throw new \RuntimeException('This import cannot be rolled back in its current state.');
        }
        $summary = $this->imports->summary($job);
        $priorConflicts = max(0, (int) ($summary['rollback_conflicts'] ?? 0));
        $this->imports->updateJob($jobId, ['status' => 'rolling_back']);
        $mutations = $this->imports->pendingMutations($jobId, max(1, min(100, $limit)));
        $processed = 0;
        $conflicts = 0;
        foreach ($mutations as $mutation) {
            $result = $this->revertMutation($mutation);
            if ($result === 'reverted') {
                $this->imports->markMutationReverted((int) $mutation->id);
                $processed++;
            } elseif ($result === 'already_absent') {
                $this->imports->markMutationReverted((int) $mutation->id);
                $processed++;
            } else {
                // Mark as acknowledged so one subsequently edited record
                // cannot deadlock the entire rollback queue.
                $this->imports->markMutationReverted((int) $mutation->id);
                $conflicts++;
            }
        }
        $totalConflicts = $priorConflicts + $conflicts;
        $summary['rollback_conflicts'] = $totalConflicts;
        $remaining = $this->imports->remainingMutations($jobId);
        if ($remaining === 0) {
            $status = $totalConflicts > 0
                ? 'rollback_conflicts' : 'rolled_back';
            $this->imports->updateJob($jobId, ['status' => $status, 'completed_at' => current_time('mysql', true)], $summary);
        } else {
            $this->imports->updateJob($jobId, ['status' => 'rolling_back'], $summary);
        }
        return ['done' => $remaining === 0, 'processed' => $processed, 'remaining' => $remaining, 'conflicts' => $totalConflicts];
    }

    private function commitRow(object $job, object $row, array $payload): void
    {
        $registrationKey = (string) $row->registration_key;
        $external = $this->imports->external(self::SOURCE, 'registration', $registrationKey);
        if ($external) {
            if ((int) $external->import_job_id === (int) $job->id) {
                if ($this->resumeOwnedRegistration($job, $row, $payload, $external)) return;
                throw new \RuntimeException('An interrupted registration could not be repaired automatically.');
            }
            if ($this->registrationSnapshot((int) $external->entity_id)) {
                $this->imports->setRowState((int) $row->id, 'already_imported', [], ['external_registration_already_imported']);
                return;
            }
            $this->imports->removeExternalForEntity('registration', (int) $external->entity_id);
        }
        $eventId = $this->ensureEvent((int) $job->id, (int) $row->id, (array) $payload['event']);
        if ($eventId < 1) throw new \RuntimeException('Historical event creation failed.');
        [$constituentId, $new] = $this->ensurePerson((int) $job->id, (int) $row->id, $payload);
        if ($constituentId < 1) throw new \RuntimeException('Constituent creation or matching failed.');

        /** @var RegistrationRepository $registrations */
        $registrations = $this->container->get('registration.repo');
        global $wpdb;
        $transaction = $wpdb->query('START TRANSACTION') !== false;
        $registrationId = $registrations->insertHistorical([
            'event_post_id' => $eventId,
            'bg_constituent_id' => $constituentId,
            'is_new_constituent' => $new,
        ], (string) ($payload['registration']['created_at'] ?? ''));
        if ($registrationId < 1) {
            if ($transaction) $wpdb->query('ROLLBACK');
            throw new \RuntimeException('Historical registration insert failed.');
        }
        $source = (array) $payload['source'];
        $metadata = [
            'event_id' => (string) ($source['event_id'] ?? ''),
            'order_number' => (string) ($source['order_number'] ?? ''),
            'ticket_type' => (string) ($source['ticket_type'] ?? ''),
            'order_type' => (string) ($source['order_type'] ?? ''),
            'total_paid_order_evidence' => $source['total_paid'] ?? null,
            'attendee_status' => (string) ($source['attendee_status'] ?? ''),
            'financial_posted' => false,
            'attendance_inferred' => false,
        ];
        if (!$this->imports->linkExternal(
            self::SOURCE, 'registration', $registrationId, $registrationKey,
            (int) $job->id, (string) $payload['registration']['created_at'], $metadata
        )) {
            if ($transaction) $wpdb->query('ROLLBACK');
            $wpdb->delete($wpdb->prefix . 'bg_registrations', ['id' => $registrationId], ['%d']);
            throw new \RuntimeException('Registration provenance could not be linked.');
        }
        $after = $this->registrationSnapshot($registrationId);
        if (!$this->imports->mutation(
            (int) $job->id, (int) $row->id, 'registration', $registrationId,
            'create', [], '', $this->hash($after)
        )) {
            if ($transaction) $wpdb->query('ROLLBACK');
            $this->imports->removeExternalForEntity('registration', $registrationId);
            $wpdb->delete($wpdb->prefix . 'bg_registrations', ['id' => $registrationId], ['%d']);
            throw new \RuntimeException('Historical registration rollback metadata could not be stored.');
        }
        if ($transaction && $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            $this->imports->removeExternalForEntity('registration', $registrationId);
            $wpdb->delete($wpdb->prefix . 'bg_import_mutations', [
                'job_id' => (int) $job->id,
                'import_row_id' => (int) $row->id,
                'entity_type' => 'registration',
                'entity_id' => $registrationId,
            ], ['%d', '%d', '%s', '%d']);
            $wpdb->delete($wpdb->prefix . 'bg_registrations', ['id' => $registrationId], ['%d']);
            throw new \RuntimeException('Historical registration transaction could not be committed.');
        }

        $answers = (array) ($payload['answers'] ?? []);
        if (!$this->imports->saveRegistrationAnswers($registrationId, $answers, (int) $job->id)) {
            throw new \RuntimeException('Historical answer snapshot could not be stored.');
        }
        if ($wpdb->replace($wpdb->prefix . 'bg_event_attendance', [
            'registration_id' => $registrationId,
            'event_post_id' => $eventId,
            'status' => 'registered',
            'updated_by' => 0,
            'checked_in_at' => null,
            'updated_at' => (string) $payload['registration']['created_at'],
        ]) === false) throw new \RuntimeException('Registration attendance state could not be initialized.');

        $this->imports->setRowState((int) $row->id, $new ? 'imported_new' : 'imported_match', [
            'constituent_id' => $constituentId,
            'event_post_id' => $eventId,
            'registration_id' => $registrationId,
        ]);
    }

    private function ensureEvent(int $jobId, int $rowId, array $event): int
    {
        $externalId = trim((string) ($event['external_id'] ?? ''));
        $linked = $this->imports->external(self::SOURCE, 'event', $externalId);
        if ($linked && get_post_type((int) $linked->entity_id) === 'bg_event' && get_post_status((int) $linked->entity_id) !== 'trash') {
            return (int) $linked->entity_id;
        }
        global $wpdb;
        $sourceKey = 'eventbrite:' . $externalId;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'bg_event'
             WHERE m.meta_key = '_bg_src_key' AND m.meta_value = %s
               AND p.post_status IN ('publish','bg_historical')
             ORDER BY CASE WHEN p.post_status = 'publish' THEN 0 ELSE 1 END, p.ID ASC LIMIT 1",
            $sourceKey
        ));
        if ($existing > 0 && get_post_type($existing) === 'bg_event' && get_post_status($existing) !== 'trash') {
            // Do not attach job-owned event lineage to a pre-existing event:
            // guarded rollback must never need to alter or delete that event.
            return $existing;
        }
        $postId = wp_insert_post([
            'post_type' => 'bg_event',
            'post_status' => 'bg_historical',
            'post_title' => (string) ($event['title'] ?? 'Historical event'),
            'post_content' => '',
        ], true);
        if (is_wp_error($postId) || (int) $postId < 1) return 0;
        $postId = (int) $postId;
        update_post_meta($postId, '_bg_start_date', (string) ($event['date'] ?? ''));
        update_post_meta($postId, '_bg_start_time', (string) ($event['time'] ?? ''));
        update_post_meta($postId, '_bg_src_key', $sourceKey);
        update_post_meta($postId, '_bg_historical_import', '1');
        update_post_meta($postId, '_bg_import_job', (string) $jobId);
        update_post_meta($postId, '_bg_summary', 'Archival historical event imported from a registration export; it is not a public calendar listing.');
        if (!$this->imports->mutation($jobId, $rowId, 'event', $postId, 'create', [], '', $this->hash($this->eventSnapshot($postId)))) {
            wp_trash_post($postId);
            return 0;
        }
        $eventSourceDate = (string) ($event['date'] ?? '');
        if ($eventSourceDate !== '') $eventSourceDate .= ' 00:00:00';
        if (!$this->imports->linkExternal(self::SOURCE, 'event', $postId, $externalId, $jobId, $eventSourceDate, [
            'event_date' => (string) ($event['date'] ?? ''), 'public_listing' => false,
        ])) {
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'bg_import_mutations', [
                'job_id' => $jobId,
                'import_row_id' => $rowId,
                'entity_type' => 'event',
                'entity_id' => $postId,
            ], ['%d', '%d', '%s', '%d']);
            wp_trash_post($postId);
            return 0;
        }
        return $postId;
    }

    /** Complete a same-job row interrupted after its atomic registration insert. */
    private function resumeOwnedRegistration(object $job, object $row, array $payload, object $external): bool
    {
        $registrationId = (int) $external->entity_id;
        $registration = $this->registrationSnapshot($registrationId);
        if (!$registration) return false;
        $eventId = (int) ($registration['event_post_id'] ?? 0);
        $constituentId = (int) ($registration['bg_constituent_id'] ?? 0);
        if ($eventId < 1 || $constituentId < 1) return false;
        global $wpdb;
        $mutationExists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_import_mutations
             WHERE job_id = %d AND import_row_id = %d AND entity_type = 'registration' AND entity_id = %d",
            (int) $job->id, (int) $row->id, $registrationId
        ));
        if ($mutationExists < 1 && !$this->imports->mutation(
            (int) $job->id, (int) $row->id, 'registration', $registrationId,
            'create', [], '', $this->hash($registration)
        )) return false;
        if (!$this->imports->saveRegistrationAnswers(
            $registrationId, (array) ($payload['answers'] ?? []), (int) $job->id
        )) return false;
        if ($wpdb->replace($wpdb->prefix . 'bg_event_attendance', [
            'registration_id' => $registrationId,
            'event_post_id' => $eventId,
            'status' => 'registered',
            'updated_by' => 0,
            'checked_in_at' => null,
            'updated_at' => (string) ($payload['registration']['created_at'] ?? current_time('mysql', true)),
        ]) === false) return false;
        $isNew = (int) ($registration['is_new_constituent'] ?? 0) === 1;
        return $this->imports->setRowState((int) $row->id, $isNew ? 'imported_new' : 'imported_match', [
            'constituent_id' => $constituentId,
            'event_post_id' => $eventId,
            'registration_id' => $registrationId,
        ]);
    }

    /** @return array{int,bool} */
    private function ensurePerson(int $jobId, int $rowId, array $payload): array
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        $person = (array) $payload['person'];
        $resolved = $this->resolvePerson($person);
        if ($resolved < 0) $resolved = 0;
        $new = $resolved < 1;
        if ($new) {
            $resolved = $store->create(array_map('strval', $person));
            if ($resolved < 1) return [0, false];
        }
        $before = $new ? [] : $this->constituentSnapshot($resolved);
        $changed = false;
        if (!$new) {
            $current = $store->get($resolved);
            if (!$current) return [0, false];
            $merged = $current;
            foreach (ConstituentStore::FIELDS as $field) {
                $incoming = trim((string) ($person[$field] ?? ''));
                if (trim((string) ($merged[$field] ?? '')) === '' && $incoming !== '') {
                    $merged[$field] = $incoming;
                    $changed = true;
                }
            }
            if ($changed && !$store->update($resolved, $merged)) return [0, false];
            $sourceEmail = strtolower(trim((string) ($person['email'] ?? '')));
            $currentEmail = strtolower(trim((string) ($current['email'] ?? '')));
            if (filter_var($sourceEmail, FILTER_VALIDATE_EMAIL) && $sourceEmail !== $currentEmail
                && !in_array($sourceEmail, $store->emailsFor($resolved), true)
            ) {
                if (!$store->addEmail($resolved, $sourceEmail, false, 'spreadsheet')) return [0, false];
                $changed = true;
            }
        }

        $incomingAnswers = (array) ($payload['answers'] ?? []);
        $currentAnswers = $store->personalAnswersFor($resolved);
        $missingAnswers = [];
        foreach (RowTransformer::ANSWER_FIELDS as $field) {
            $current = $currentAnswers[$field] ?? '';
            $incoming = $incomingAnswers[$field] ?? '';
            $currentEmpty = is_array($current) ? $current === [] : trim((string) $current) === '';
            $incomingPresent = is_array($incoming) ? $incoming !== [] : trim((string) $incoming) !== '';
            if ($currentEmpty && $incomingPresent) $missingAnswers[$field] = $incoming;
        }
        if ($missingAnswers && !$store->setPersonalAnswers($resolved, $missingAnswers)) return [0, false];
        if ($missingAnswers) $changed = true;

        $sharing = $payload['contact_sharing'] ?? null;
        if (is_bool($sharing) && $store->latestConsent($resolved, ConstituentStore::CONTACT_SHARING_PURPOSE) === null) {
            if (!$store->recordConsent($resolved, ConstituentStore::CONTACT_SHARING_PURPOSE, $sharing, 'import-job-' . $jobId)) return [0, false];
            $changed = true;
        }

        // Use the established regional engine. It preserves staff overrides
        // and applies the same deterministic postal/city rules used by normal
        // constituent edits and grant-report region filters.
        if (!$this->container->get('constituents.regions')->assign($resolved)) {
            update_option('bg_constituent_region_backfill_pending', 1, false);
            $this->container->get('logger')->warn('imports', 'Regional assignment was deferred for an imported constituent.', [
                'job' => $jobId, 'constituent_id' => $resolved,
            ]);
        }

        if ($new || $changed) {
            $after = $this->constituentSnapshot($resolved);
            if (!$this->imports->mutation(
                $jobId, $rowId, 'constituent', $resolved, $new ? 'create' : 'update',
                $before, $before ? $this->hash($before) : '', $this->hash($after)
            )) {
                if ($new) {
                    $store->anonymize($resolved);
                } else {
                    $store->update($resolved, array_map('strval', (array) ($before['fields'] ?? [])));
                    $store->replacePersonalAnswers($resolved, (array) ($before['answers'] ?? []));
                    $store->replaceAlternateEmails($resolved, (array) ($before['alternate_emails'] ?? []));
                    global $wpdb;
                    $wpdb->delete($wpdb->prefix . 'bg_consents', [
                        'constituent_id' => $resolved,
                        'purpose' => ConstituentStore::CONTACT_SHARING_PURPOSE,
                        'source' => 'import-job-' . $jobId,
                    ], ['%d', '%s', '%s']);
                }
                return [0, false];
            }
        }
        return [$resolved, $new];
    }

    /** Positive id = match; 0 = new; -1 = ambiguous, force separate. */
    private function resolvePerson(array $person): int
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        $first = trim((string) ($person['first_name'] ?? ''));
        $last = trim((string) ($person['last_name'] ?? ''));
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        $matchedByEmail = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        $ids = $matchedByEmail ? $store->idsByEmail($email) : [];
        if (!$ids && $first !== '' && $last !== '') {
            $matchedByEmail = false;
            $ids = $store->idsByName($first, $last);
        }
        if (!$ids) return 0;
        if (count($ids) === 1) {
            $existing = $store->get((int) $ids[0]);
            if (!$existing) return -1;
            $oldName = ConstituentStore::normName((string) $existing['first_name'], (string) $existing['last_name']);
            $newName = ConstituentStore::normName($first, $last);
            if ($oldName !== '' && $newName !== '' && !hash_equals($oldName, $newName)) return 0;
            $score = ConstituentStore::identityScore($existing, $person);
            if ($oldName !== '' && $newName !== '' && hash_equals($oldName, $newName)) {
                // A verified email plus exact full name is sufficient. A
                // name-only lookup also needs an exact phone or address;
                // identical names alone never merge two people.
                return $matchedByEmail || $score >= 3 ? (int) $ids[0] : 0;
            }
            return $matchedByEmail && $score >= 2 ? (int) $ids[0] : -1;
        }
        $resolved = $store->disambiguateIds($ids, $person);
        if ($resolved < 1) return -1;
        if (!$matchedByEmail) {
            $record = $store->get($resolved);
            if (!$record || ConstituentStore::identityScore($record, $person) < 3) return -1;
        }
        return $resolved;
    }

    private function identityKey(array $person, array $payload): string
    {
        /** @var Encryption $enc */
        $enc = $this->container->get('encryption');
        $name = ConstituentStore::normName((string) ($person['first_name'] ?? ''), (string) ($person['last_name'] ?? ''));
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        $phone = ConstituentStore::normPhone((string) ($person['phone'] ?? ''));
        $address = ConstituentStore::normAddress($person);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $material = 'email:' . $email . '|name:' . $name;
        } elseif ($phone !== '' || $address !== '') {
            $material = 'name:' . $name . '|phone:' . $phone . '|address:' . $address;
        } else {
            // Full-name-only identity is not safe enough to merge. Keep each
            // non-duplicate source registration separate and auditable.
            $source = (array) ($payload['source'] ?? []);
            $material = 'name:' . $name . '|source:' . implode('|', [
                (string) ($source['event_id'] ?? ''),
                (string) ($source['order_number'] ?? ''),
                (string) ($source['ticket_type'] ?? ''),
            ]);
        }
        return $name === '' ? '' : $enc->blindIndex($material);
    }

    private function registrationKey(array $payload): string
    {
        /** @var Encryption $enc */
        $enc = $this->container->get('encryption');
        $source = (array) ($payload['source'] ?? []);
        $person = (array) ($payload['person'] ?? []);
        $material = implode('|', [
            strtolower(trim((string) ($source['event_id'] ?? ''))),
            strtolower(trim((string) ($source['order_number'] ?? ''))),
            strtolower(trim((string) ($person['email'] ?? ''))),
            ConstituentStore::normName((string) ($person['first_name'] ?? ''), (string) ($person['last_name'] ?? '')),
            strtolower(trim((string) ($source['ticket_type'] ?? ''))),
        ]);
        return trim($material, '|') === '' ? '' : $enc->blindIndex($material);
    }

    private function collectConflictFingerprints(array &$sets, string $identityKey, array $payload): void
    {
        if ($identityKey === '') return;
        $answers = (array) ($payload['answers'] ?? []);
        foreach (RowTransformer::ANSWER_FIELDS as $field) {
            $value = $answers[$field] ?? '';
            $present = is_array($value) ? $value !== [] : trim((string) $value) !== '';
            if (!$present) continue;
            if (is_array($value)) {
                $value = array_values(array_unique(array_map('strval', $value)));
                sort($value, SORT_NATURAL | SORT_FLAG_CASE);
            }
            $sets[$identityKey][$field][$this->hash($value)] = true;
        }
        $person = (array) ($payload['person'] ?? []);
        foreach (['email', 'phone', 'address1', 'address2', 'city', 'state', 'postal'] as $field) {
            $value = trim((string) ($person[$field] ?? ''));
            if ($value === '') continue;
            $normalized = match ($field) {
                'email' => strtolower($value),
                'phone' => ConstituentStore::normPhone($value),
                'postal' => $this->normalizedPostal($value),
                default => FieldMatcher::normalize($value),
            };
            if ($normalized !== '') $sets[$identityKey]['person:' . $field][$this->hash($normalized)] = true;
        }
        if (is_bool($payload['contact_sharing'] ?? null)) {
            $value = (bool) $payload['contact_sharing'];
            $sets[$identityKey]['contact_sharing'][$this->hash($value)] = true;
        }
    }

    private function normalizedPostal(string $value): string
    {
        $postal = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', $value));
        if (preg_match('/^\d{5}/', $postal, $match)) return $match[0];
        return $postal;
    }

    private function collectEventFingerprints(array &$sets, array $event): void
    {
        $externalId = trim((string) ($event['external_id'] ?? ''));
        if ($externalId === '') return;
        foreach (['title', 'date', 'time'] as $field) {
            $value = strtolower(trim((string) ($event[$field] ?? '')));
            if ($value !== '') $sets[$externalId][$field][$this->hash($value)] = true;
        }
    }

    /** @return array<string,list<string>> identity key => conflicting fields */
    private function crossConflictMap(array $sets): array
    {
        $out = [];
        foreach ($sets as $identity => $fields) foreach ($fields as $field => $values) {
            if (count($values) > 1) $out[$identity][] = $field;
        }
        return $out;
    }

    /** @param array<string,list<string>> $conflicts */
    private function dropCrossRowConflicts(int $jobId, array $conflicts): void
    {
        $after = 0;
        while (true) {
            $rows = $this->imports->rowsByState($jobId, ['ready_new', 'ready_match'], 500, $after);
            if (!$rows) break;
            foreach ($rows as $item) {
                $row = $item['row'];
                $after = max($after, (int) $row->id);
                $fields = $conflicts[(string) $row->identity_key] ?? [];
                if (!$fields) continue;
                $payload = $item['payload'];
                $issues = array_filter(explode(',', (string) $row->issues));
                foreach ($fields as $field) {
                    if (str_starts_with($field, 'person:')) {
                        $personField = substr($field, 7);
                        $payload['person'][$personField] = '';
                        $issues[] = 'cross_row_conflict_dropped:person_' . $personField;
                        continue;
                    }
                    if ($field === 'contact_sharing') {
                        $payload['contact_sharing'] = null;
                        $issues[] = 'cross_row_conflict_dropped:contact_sharing';
                        continue;
                    }
                    $payload['answers'][$field] = in_array($field, ['demographics', 'heard', 'relationship'], true) ? [] : '';
                    if ($field === 'demographics') $payload['answers']['demographics_other'] = '';
                    if ($field === 'heard') $payload['answers']['heard_other'] = '';
                    $issues[] = 'cross_row_conflict_dropped:' . $field;
                }
                $this->imports->updateStagedRow((int) $row->id, $payload, array_values(array_unique($issues)));
            }
            if (count($rows) < 500) break;
        }
    }

    /** Required event identity cannot be safely blanked; reject affected rows. */
    private function rejectEventConflicts(int $jobId, array $conflicts): void
    {
        $after = 0;
        while (true) {
            $rows = $this->imports->rowsByState($jobId, ['ready_new', 'ready_match'], 500, $after);
            if (!$rows) break;
            foreach ($rows as $item) {
                $row = $item['row'];
                $after = max($after, (int) $row->id);
                $externalId = (string) ($item['payload']['event']['external_id'] ?? '');
                $fields = $conflicts[$externalId] ?? [];
                if (!$fields) continue;
                $issues = array_map(
                    static fn(string $field): string => 'event_conflict_rejected:' . $field,
                    $fields
                );
                $this->imports->setRowState((int) $row->id, 'invalid', [], $issues);
            }
            if (count($rows) < 500) break;
        }
    }

    /** @return array<string,mixed> */
    private function constituentSnapshot(int $id): array
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        $record = $store->get($id) ?: [];
        $fields = [];
        foreach (ConstituentStore::FIELDS as $field) $fields[$field] = (string) ($record[$field] ?? '');
        return [
            'fields' => $fields,
            'answers' => $store->personalAnswersFor($id),
            'alternate_emails' => $store->alternateEmailsFor($id),
            'contact_sharing' => $store->latestConsent($id, ConstituentStore::CONTACT_SHARING_PURPOSE),
        ];
    }

    /** @return array<string,mixed> */
    private function eventSnapshot(int $id): array
    {
        $post = get_post($id);
        if (!$post) return [];
        return [
            'title' => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'date' => (string) get_post_meta($id, '_bg_start_date', true),
            'time' => (string) get_post_meta($id, '_bg_start_time', true),
            'source' => (string) get_post_meta($id, '_bg_src_key', true),
            'historical' => (string) get_post_meta($id, '_bg_historical_import', true),
        ];
    }

    /** @return array<string,mixed> */
    private function registrationSnapshot(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_registrations WHERE id = %d", $id
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private function hash(mixed $value): string
    {
        return hash_hmac('sha256', (string) wp_json_encode($value), wp_salt('auth'));
    }

    private function revertMutation(object $mutation): string
    {
        $type = (string) $mutation->entity_type;
        $id = (int) $mutation->entity_id;
        $action = (string) $mutation->action;
        global $wpdb;
        if ($type === 'registration') {
            $current = $this->registrationSnapshot($id);
            if (!$current) return 'already_absent';
            if (!hash_equals((string) $mutation->after_hash, $this->hash($current))) return 'conflict';
            $wpdb->delete($wpdb->prefix . 'bg_event_attendance', ['registration_id' => $id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'bg_registration_answers', ['registration_id' => $id], ['%d']);
            $this->imports->removeExternalForEntity('registration', $id);
            return $wpdb->delete($wpdb->prefix . 'bg_registrations', ['id' => $id], ['%d']) !== false ? 'reverted' : 'conflict';
        }
        if ($type === 'event') {
            $current = $this->eventSnapshot($id);
            if (!$current || get_post_status($id) === 'trash') return 'already_absent';
            if (!hash_equals((string) $mutation->after_hash, $this->hash($current))) return 'conflict';
            $hasRegistrations = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bg_registrations WHERE event_post_id = %d", $id
            ));
            if ($hasRegistrations > 0) return 'conflict';
            $this->imports->removeExternalForEntity('event', $id);
            return wp_trash_post($id) ? 'reverted' : 'conflict';
        }
        if ($type === 'constituent') {
            /** @var ConstituentStore $store */
            $store = $this->container->get('constituents.store');
            $record = $store->get($id);
            if (!$record || ($record['status'] ?? '') !== 'active') return 'already_absent';
            $current = $this->constituentSnapshot($id);
            if (!hash_equals((string) $mutation->after_hash, $this->hash($current))) return 'conflict';
            if ($action === 'create') {
                $hasRegistrations = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bg_registrations WHERE bg_constituent_id = %d", $id
                ));
                if ($hasRegistrations > 0) return 'conflict';
                $this->imports->removeExternalForEntity('constituent', $id);
                return $store->anonymize($id) ? 'reverted' : 'conflict';
            }
            $before = $this->imports->mutationBefore($mutation);
            if (!$before || !$store->update($id, array_map('strval', (array) ($before['fields'] ?? [])))) return 'conflict';
            if (!$store->replacePersonalAnswers($id, (array) ($before['answers'] ?? []))) return 'conflict';
            if (!$store->replaceAlternateEmails($id, (array) ($before['alternate_emails'] ?? []))) return 'conflict';
            $wpdb->delete($wpdb->prefix . 'bg_consents', [
                'constituent_id' => $id,
                'purpose' => ConstituentStore::CONTACT_SHARING_PURPOSE,
                'source' => 'import-job-' . (int) $mutation->job_id,
            ], ['%d', '%s', '%s']);
            return 'reverted';
        }
        return 'conflict';
    }
}
