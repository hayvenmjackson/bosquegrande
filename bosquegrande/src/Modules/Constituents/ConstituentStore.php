<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\OptionLease;

if (!defined('ABSPATH')) exit;

/**
 * The constituent store: BosqueGrande's system of record for people.
 *
 * Storage invariants:
 * 1. PII lives ONLY inside an authenticated XChaCha20-Poly1305 envelope
 *    bound to its constituent id. No plaintext name, email, phone, or
 *    address column exists anywhere.
 * 2. Writes REFUSE when encryption is unavailable. Refuse and log,
 *    never fall back to plaintext.
 * 3. Equality lookup goes through blind indexes (HMAC of normalized
 *    values). Substring search and sorting decrypt in memory, which
 *    the record cap keeps cheap.
 * 4. Custom field values are encrypted uniformly, sensitive or not;
 *    the sensitive flag additionally excludes a field from CSV export
 *    and from any future assisted automation surface.
 * 5. Nothing is ever deleted. Anonymize (envelope emptied, indexes
 *    nulled, values removed, timeline scrubbed) or merge (secondary
 *    tombstoned) are the only exits; row counts stay accurate.
 */
final class ConstituentStore
{
    public const CONTACT_SHARING_PURPOSE = 'contact_sharing';

    public const FIELDS = [
        'first_name', 'last_name', 'email', 'phone',
        'address1', 'address2', 'city', 'state', 'postal', 'notes',
    ];

    /**
     * Form-backed answers live inside the same authenticated encrypted
     * envelope as the constituent's contact fields. The custom-value
     * table remains a compatibility surface for arbitrary fields and
     * older installs, but registration/profile correctness no longer
     * depends on a second table accepting several follow-up writes.
     */
    private const ANSWERS_KEY = '_bg_personal_answers';
    private const ANSWER_FIELDS = [
        'demographics', 'demographics_other', 'age_range',
        'relationship', 'heard', 'heard_other', 'promote',
    ];
    /**
     * Safety valve against a runaway import, NOT a licence limit.
     *
     * This was 2000, which a real membership database passes
     * quickly. Past it, create() refused, the registration handler got
     * back id 0, and every demographic and survey answer for every
     * subsequent registration was silently dropped while the form kept
     * insisting the answers were required. The ceiling is now high
     * enough not to be reached by accident and can be raised further
     * with the bg_constituent_cap filter.
     */
    private const DEFAULT_CAP = 250000;

    private ?bool $cryptoOverride;
    private ?bool $emailTableExists = null;
    private string $lastFieldError = '';
    private string $lastMergeError = '';
    private int $transactionDepth = 0;

    public function __construct(
        private Encryption $encryption,
        private Logger $logger,
        private ActivityLog $activity,
        ?bool $cryptoOverride = null,
    ) {
        $this->cryptoOverride = $cryptoOverride;
    }

    public function available(): bool
    {
        return $this->cryptoOverride ?? $this->encryption->available();
    }

    /** Nest-safe transaction boundary for multi-step store operations. */
    public function beginAtomic(): bool
    {
        global $wpdb;
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            return true;
        }
        if ($wpdb->query('START TRANSACTION') === false) return false;
        $this->transactionDepth = 1;
        return true;
    }

    public function commitAtomic(): bool
    {
        global $wpdb;
        if ($this->transactionDepth < 1) return false;
        $this->transactionDepth--;
        if ($this->transactionDepth > 0) return true;
        $committed = $wpdb->query('COMMIT') !== false;
        if (!$committed) $wpdb->query('ROLLBACK');
        return $committed;
    }

    public function rollbackAtomic(): void
    {
        global $wpdb;
        if ($this->transactionDepth > 0) $wpdb->query('ROLLBACK');
        $this->transactionDepth = 0;
    }

    public function cap(): int
    {
        return max(1, (int) apply_filters('bg_constituent_cap', self::DEFAULT_CAP));
    }

    public function count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_constituents WHERE status = 'active'"
        );
    }

    /* ── Normalization (blind-index inputs) ───────────────────────── */

    public static function normEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function normName(string $first, string $last): string
    {
        $joined = strtolower(trim($first) . ' ' . trim($last));
        return trim((string) preg_replace('/\s+/', ' ', $joined));
    }

    public static function normPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        return strlen($digits) >= 7 ? $digits : '';
    }

    /**
     * LGL exports multi-value cells with semicolons while many admin screens
     * historically documented commas. Accept both, plus pasted line breaks,
     * everywhere a constituent list field is parsed.
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[,;\r\n]+/', $value) ?: []
        ), static fn(string $part): bool => $part !== '')));
    }

    /** @param array<string, mixed> $record */
    public static function normAddress(array $record): string
    {
        $joined = strtolower(implode(' ', [
            (string) ($record['address1'] ?? $record['street'] ?? ''),
            (string) ($record['address2'] ?? ''),
            (string) ($record['city'] ?? ''),
            (string) ($record['state'] ?? ''),
            (string) ($record['postal'] ?? $record['zip'] ?? ''),
        ]));
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $joined));
    }

    /**
     * Count independent exact person traits. Email is deliberately absent:
     * it locates a household-sized candidate set but never proves which
     * person owns the record.
     *
     * @param array<string, mixed> $left @param array<string, mixed> $right
     */
    public static function identityScore(array $left, array $right): int
    {
        $leftFirst = strtolower(trim((string) ($left['first_name'] ?? '')));
        $rightFirst = strtolower(trim((string) ($right['first_name'] ?? '')));
        if ($leftFirst !== '' && $rightFirst !== '' && !hash_equals($leftFirst, $rightFirst)) {
            return 0;
        }
        $leftLast = strtolower(trim((string) ($left['last_name'] ?? '')));
        $rightLast = strtolower(trim((string) ($right['last_name'] ?? '')));
        if ($leftFirst !== '' && $rightFirst !== '' && $leftLast !== '' && $rightLast !== ''
            && !hash_equals(self::normName($leftFirst, $leftLast), self::normName($rightFirst, $rightLast))) {
            return 0;
        }
        $score = 0;
        foreach (['first_name', 'last_name'] as $key) {
            $a = strtolower(trim((string) ($left[$key] ?? '')));
            $b = strtolower(trim((string) ($right[$key] ?? '')));
            if ($a !== '' && $b !== '' && hash_equals($a, $b)) $score++;
        }
        $aPhone = self::normPhone((string) ($left['phone'] ?? ''));
        $bPhone = self::normPhone((string) ($right['phone'] ?? ''));
        if ($aPhone !== '' && $bPhone !== '' && hash_equals($aPhone, $bPhone)) $score++;
        $aAddress = self::normAddress($left);
        $bAddress = self::normAddress($right);
        if ($aAddress !== '' && $bAddress !== '' && hash_equals($aAddress, $bAddress)) $score++;
        return $score;
    }

    /**
     * Resolve a household-sized candidate set only when one record has at
     * least two independent exact person traits and no score tie.
     *
     * @param list<int> $ids @param array<string, mixed> $candidate
     */
    public function disambiguateIds(array $ids, array $candidate): int
    {
        $best = 0;
        $bestScore = 1;
        $tied = false;
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $record = $this->get($id);
            if (!$record || ($record['status'] ?? '') !== 'active') continue;
            $score = self::identityScore($record, $candidate);
            if ($score > $bestScore) {
                $best = $id;
                $bestScore = $score;
                $tied = false;
            } elseif ($score === $bestScore && $score >= 2) {
                $tied = true;
            }
        }
        return $tied ? 0 : $best;
    }

    /* ── Create, read, update ─────────────────────────────────────── */

    /**
     * @param array<string, string> $data Keys from self::FIELDS.
     * @return int New id, or 0 on refusal (reason logged).
     */
    public function create(array $data): int
    {
        global $wpdb;
        if (!$this->available()) {
            $this->logger->error('constituents', 'Create refused: encryption unavailable. Constituent data is never written in plaintext.');
            return 0;
        }
        if ($this->count() >= $this->cap()) {
            $this->logger->error('constituents', 'Create refused: the record cap is reached. Registrations are still accepted, but their answers cannot be stored.', ['cap' => $this->cap()]);
            update_option('bg_answer_store_failure', current_time('mysql', true), false);
            return 0;
        }
        $fields = $this->cleanFields($data);
        $json = wp_json_encode($fields);
        if (!is_string($json) || $json === '') return 0;
        $now = current_time('mysql', true);
        if (!$this->beginAtomic()) return 0;
        $ok = $wpdb->insert($wpdb->prefix . 'bg_constituents', [
            // The auto-increment id is needed for authenticated context. No
            // plaintext is placed here; the empty placeholder is replaced
            // before commit or the row is removed.
            'envelope' => '',
            'email_bidx' => $this->bidxOrNull(self::normEmail($fields['email'])),
            'name_bidx' => $this->bidxOrNull(self::normName($fields['first_name'], $fields['last_name'])),
            'phone_bidx' => $this->bidxOrNull(self::normPhone($fields['phone'])),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            $this->rollbackAtomic();
            $this->logger->warn('constituents', 'Create failed at the database.', []);
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        $ciphertext = $this->encryption->encrypt($json, $this->envelopeContext($id));
        $stored = $ciphertext !== '' && $wpdb->update(
            $wpdb->prefix . 'bg_constituents',
            ['envelope' => $ciphertext],
            ['id' => $id]
        ) !== false;
        $verified = $stored
            && $this->encryption->decryptOrNull($ciphertext, $this->envelopeContext($id)) === $json;
        $emailStored = $verified && (
            $fields['email'] === ''
            || $this->addEmail($id, $fields['email'], true, 'record')
        );
        if (!$verified || !$emailStored) {
            $this->rollbackAtomic();
            // Some hosts use a non-transactional table engine. Explicit
            // cleanup guarantees no empty half-record survives there either.
            $wpdb->delete($wpdb->prefix . 'bg_constituent_emails', ['constituent_id' => $id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'bg_constituents', ['id' => $id], ['%d']);
            $this->logger->error('constituents', 'Create refused: the authenticated envelope or email identity could not be committed.', [
                'database_error' => (string) $wpdb->last_error,
            ]);
            return 0;
        }
        if (!$this->commitAtomic()) return 0;
        $this->activity->append($id, 'create', 'Record created.');
        do_action('bg_constituent_saved', $id, 'create');
        return $id;
    }

    /** @param array<string, string> $data Full field set (missing keys become empty). */
    public function update(int $id, array $data): bool
    {
        global $wpdb;
        if (!$this->available()) {
            $this->logger->error('constituents', 'Update refused: encryption unavailable.');
            return false;
        }
        $row = $this->row($id);
        if (!$row || $row->status !== 'active') return false;
        $fields = $this->cleanFields($data);
        $decoded = $this->decodeEnvelope($row);
        if ($decoded === null) return false;
        $oldEmail = self::normEmail((string) ($decoded['email'] ?? ''));
        $answers = $this->cleanPersonalAnswers((array) ($decoded[self::ANSWERS_KEY] ?? []));
        if ($this->hasPersonalAnswers($answers)) {
            $fields[self::ANSWERS_KEY] = $answers;
        }
        $ciphertext = $this->encryption->encrypt(
            (string) wp_json_encode($fields),
            $this->envelopeContext($id)
        );
        if ($ciphertext === '') {
            $this->logger->error('constituents', 'Update refused: the encrypted envelope could not be produced.', ['constituent' => $id]);
            return false;
        }
        if (!$this->beginAtomic()) return false;
        $ok = $wpdb->update($wpdb->prefix . 'bg_constituents', [
            'envelope' => $ciphertext,
            'email_bidx' => $this->bidxOrNull(self::normEmail($fields['email'])),
            'name_bidx' => $this->bidxOrNull(self::normName($fields['first_name'], $fields['last_name'])),
            'phone_bidx' => $this->bidxOrNull(self::normPhone($fields['phone'])),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id]);
        $emailOk = $ok !== false && $this->syncRecordEmail(
            $id,
            $oldEmail,
            self::normEmail($fields['email'])
        );
        if ($ok === false || !$emailOk) {
            $this->rollbackAtomic();
            $this->logger->error('constituents', 'Update refused: the encrypted record and its email identities could not be committed together.', [
                'constituent' => $id,
                'database_error' => (string) $wpdb->last_error,
            ]);
            return false;
        }
        if (!$this->commitAtomic()) return false;
        $this->activity->append($id, 'update', 'Record updated.');
        do_action('bg_constituent_saved', $id, 'update');
        return true;
    }

    /**
     * The canonical form-answer read. No answer value is exposed until
     * the caller has already resolved a stable constituent id.
     *
     * @return array{
     *   demographics: list<string>, demographics_other: string,
     *   age_range: string, relationship: list<string>,
     *   heard: list<string>, heard_other: string, promote: string
     * }
     */
    public function personalAnswersFor(int $id): array
    {
        $empty = $this->cleanPersonalAnswers([]);
        if ($id < 1 || !$this->available()) return $empty;
        $row = $this->row($id);
        if (!$row || $row->status !== 'active') return $empty;
        $decoded = $this->decodeEnvelope($row);
        if ($decoded === null) return $empty;
        return $this->cleanPersonalAnswers((array) ($decoded[self::ANSWERS_KEY] ?? []));
    }

    /**
     * Merge non-empty form answers into the encrypted constituent
     * envelope and verify the round trip before reporting success.
     * Empty values deliberately preserve the current answer because a
     * masked form submits no values for an untouched on-file panel.
     *
     * @param array<string, mixed> $answers
     */
    public function setPersonalAnswers(int $id, array $answers, bool $onlyBlank = false): bool
    {
        global $wpdb;
        if ($id < 1 || !$this->available()) return false;
        $row = $this->row($id);
        if (!$row || $row->status !== 'active') return false;
        $decoded = $this->decodeEnvelope($row);
        if ($decoded === null) return false;

        $incoming = $this->cleanPersonalAnswers($answers);
        $current = $this->cleanPersonalAnswers((array) ($decoded[self::ANSWERS_KEY] ?? []));
        $filled = static fn(mixed $v): bool => (is_array($v) && $v !== []) || (!is_array($v) && $v !== '');
        $demographicsWereBlank = !$filled($current['demographics'] ?? []);
        foreach (self::ANSWER_FIELDS as $key) {
            $value = $incoming[$key];
            // An unverified form (someone typed this person's email) may
            // fill blank answers but never replace existing ones.
            if ($onlyBlank && $filled($current[$key] ?? '')) continue;
            if ($filled($value)) {
                $current[$key] = $value;
            }
        }
        if ($incoming['demographics'] !== [] && (!$onlyBlank || $demographicsWereBlank)) {
            $current['demographics_other'] = in_array('Other', $incoming['demographics'], true)
                ? $incoming['demographics_other']
                : '';
        }
        if ($incoming['heard'] !== []) {
            $current['heard_other'] = in_array('Other', $incoming['heard'], true)
                ? $incoming['heard_other']
                : '';
        }
        if (!$this->hasPersonalAnswers($current)) return true;

        $payload = $this->cleanFields($decoded);
        $payload[self::ANSWERS_KEY] = $current;
        $json = wp_json_encode($payload);
        $ciphertext = is_string($json)
            ? $this->encryption->encrypt($json, $this->envelopeContext($id))
            : '';
        if ($ciphertext === '' || $ciphertext === $json) {
            $this->logger->error('constituents', 'Personal-answer write refused: the encrypted envelope could not be produced.', [
                'constituent' => $id,
            ]);
            return false;
        }
        $ok = $wpdb->update($wpdb->prefix . 'bg_constituents', [
            'envelope' => $ciphertext,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id, 'status' => 'active']);
        if ($ok === false) {
            $this->logger->error('constituents', 'Encrypted personal answers could not be stored in the constituent envelope.', [
                'constituent' => $id,
                'database_error' => (string) $wpdb->last_error,
            ]);
            return false;
        }

        // Verify the round trip against exactly what was meant to be stored.
        $stored = $this->personalAnswersFor($id);
        foreach (self::ANSWER_FIELDS as $key) {
            if (($stored[$key] ?? null) !== $current[$key]) {
                $this->logger->error('constituents', 'Encrypted personal answers failed their post-write verification.', [
                    'constituent' => $id,
                    'answer_field' => $key,
                ]);
                return false;
            }
        }
        $this->activity->append($id, 'update', 'Personal answers updated.');
        return true;
    }

    /**
     * Exact answer replacement used only by an import rollback restoring a
     * verified before-image. Unlike setPersonalAnswers(), empty values clear
     * the imported answer. The authenticated envelope is still the only
     * storage location and the round trip is verified before success.
     * @param array<string,mixed> $answers
     */
    public function replacePersonalAnswers(int $id, array $answers): bool
    {
        global $wpdb;
        if ($id < 1 || !$this->available()) return false;
        $row = $this->row($id);
        if (!$row || $row->status !== 'active') return false;
        $decoded = $this->decodeEnvelope($row);
        if ($decoded === null) return false;
        $clean = $this->cleanPersonalAnswers($answers);
        $payload = $this->cleanFields($decoded);
        if ($this->hasPersonalAnswers($clean)) $payload[self::ANSWERS_KEY] = $clean;
        $json = wp_json_encode($payload);
        $cipher = is_string($json)
            ? $this->encryption->encrypt($json, $this->envelopeContext($id)) : '';
        if ($cipher === '' || $cipher === $json) return false;
        $ok = $wpdb->update($wpdb->prefix . 'bg_constituents', [
            'envelope' => $cipher,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id, 'status' => 'active']);
        if ($ok === false || $this->personalAnswersFor($id) !== $clean) {
            $this->logger->error('constituents', 'Exact personal-answer restore failed verification.', [
                'constituent' => $id,
            ]);
            return false;
        }
        $this->activity->append($id, 'update', 'Personal answers restored by import rollback.');
        return true;
    }

    /** @return array<string, string>|null Decrypted fields plus id/status metadata. */
    public function get(int $id): ?array
    {
        $row = $this->row($id);
        if (!$row) return null;
        return $this->decorate($row);
    }

    /** @return list<array<string, string>> Every active record, decrypted. Cap-scale by design. */
    public function allDecrypted(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}bg_constituents WHERE status = 'active' ORDER BY id ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $d = $this->decorate($row);
            if ($d !== null) $out[] = $d;
        }
        return $out;
    }

    /** @return list<array<string, string>> One bounded active page, decrypted. */
    public function pageDecrypted(int $offset, int $limit): array
    {
        global $wpdb;
        $offset = max(0, $offset);
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_constituents
             WHERE status = 'active' ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        )) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $d = $this->decorate($row);
            if ($d !== null) $out[] = $d;
        }
        return $out;
    }

    /**
     * Active ids whose primary or alternate email matches exactly. The
     * legacy column remains part of the lookup so upgraded records work
     * immediately; a successful legacy match lazily seeds the alias table.
     *
     * @return list<int>
     */
    public function idsByEmail(string $email): array
    {
        global $wpdb;
        $email = self::normEmail($email);
        $bidx = $this->bidxOrNull($email);
        if ($bidx === null) return [];

        $ids = [];
        if ($this->emailTableAvailable()) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT c.id
                 FROM {$wpdb->prefix}bg_constituent_emails e
                 JOIN {$wpdb->prefix}bg_constituents c ON c.id = e.constituent_id
                 WHERE e.email_bidx = %s AND e.login_enabled = 1
                   AND c.status = 'active'
                 ORDER BY c.id ASC",
                $bidx
            )) ?: [];
        }

        $legacy = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE email_bidx = %s AND status = 'active' ORDER BY id ASC",
            $bidx
        )) ?: [];
        foreach ($legacy as $id) {
            $id = (int) $id;
            $ids[] = $id;
            // The lookup value necessarily equals this record's legacy
            // primary index, so it is safe to use it for lazy backfill.
            if ($this->emailTableAvailable()) {
                $this->addEmail($id, $email, true, 'legacy');
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Exact full-name candidate lookup for imports whose attendee email is
     * absent or belongs to a different buyer. A name locates candidates; the
     * caller must still disambiguate with the independent-trait rules.
     * @return list<int>
     */
    public function idsByName(string $first, string $last): array
    {
        global $wpdb;
        $name = self::normName($first, $last);
        $bidx = $this->bidxOrNull($name);
        if ($bidx === null || trim($first) === '' || trim($last) === '') return [];
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE name_bidx = %s AND status = 'active' ORDER BY id ASC",
            $bidx
        )) ?: [];
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Exact normalized-phone candidates. A phone number locates candidates;
     * callers must still apply the independent-trait disambiguation rules.
     *
     * @return list<int>
     */
    public function idsByPhone(string $phone): array
    {
        global $wpdb;
        $phone = self::normPhone($phone);
        $bidx = $this->bidxOrNull($phone);
        if ($bidx === null || $phone === '') return [];
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE phone_bidx = %s AND status = 'active' ORDER BY id ASC",
            $bidx
        )) ?: [];
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Decrypt every login-enabled address for one constituent, primary
     * first. Records without address rows fall back to their envelope email.
     *
     * @return list<string>
     */
    public function emailsFor(int $id): array
    {
        global $wpdb;
        if ($id < 1 || !$this->available()) return [];
        $emails = [];
        if ($this->emailTableAvailable()) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, constituent_id, email_enc, email_bidx, is_primary
                 FROM {$wpdb->prefix}bg_constituent_emails
                 WHERE constituent_id = %d AND login_enabled = 1
                 ORDER BY is_primary DESC, id ASC",
                $id
            )) ?: [];
            foreach ($rows as $row) {
                $stored = (string) $row->email_enc;
                $context = $this->emailContext(
                    (int) $row->constituent_id,
                    (string) $row->email_bidx
                );
                $plain = $this->encryption->decryptOrNull($stored, $context);
                $email = is_string($plain) ? self::normEmail($plain) : '';
                if (!is_email($email)) {
                    $this->logger->error('constituents', 'An encrypted alternate email could not be read.', [
                        'constituent' => $id,
                        'email_row' => (int) $row->id,
                    ]);
                    continue;
                }
                if ($this->encryption->needsReencrypt($stored)) {
                    $rewrapped = $this->encryption->encrypt($email, $context);
                    if ($rewrapped !== '') {
                        $wpdb->update(
                            $wpdb->prefix . 'bg_constituent_emails',
                            ['email_enc' => $rewrapped, 'updated_at' => current_time('mysql', true)],
                            ['id' => (int) $row->id]
                        );
                    }
                }
                $emails[] = $email;
            }
        }
        if (!$emails) {
            $record = $this->get($id);
            $primary = self::normEmail((string) ($record['email'] ?? ''));
            if (is_email($primary)) {
                $emails[] = $primary;
                if ($this->emailTableAvailable()) {
                    $this->addEmail($id, $primary, true, 'legacy');
                }
            }
        }
        return array_values(array_unique($emails));
    }

    /** @return list<string> */
    public function alternateEmailsFor(int $id): array
    {
        $record = $this->get($id);
        $primary = self::normEmail((string) ($record['email'] ?? ''));
        return array_values(array_filter(
            $this->emailsFor($id),
            static fn(string $email): bool => $email !== $primary
        ));
    }

    /**
     * Add one encrypted exact-match alias. The same address may belong to
     * several constituents; uniqueness applies only inside one record.
     */
    public function addEmail(
        int $id,
        string $email,
        bool $primary = false,
        string $source = 'manual'
    ): bool {
        global $wpdb;
        $email = self::normEmail(sanitize_email($email));
        if ($id < 1 || !is_email($email) || !$this->available() || !$this->emailTableAvailable()) {
            return false;
        }
        $bidx = $this->bidxOrNull($email);
        if ($bidx === null) return false;
        $enc = $this->encryption->encrypt($email, $this->emailContext($id, $bidx));
        if ($enc === '') return false;
        $table = $wpdb->prefix . 'bg_constituent_emails';
        $now = current_time('mysql', true);

        if ($primary && $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_primary = 0, updated_at = %s
             WHERE constituent_id = %d AND is_primary = 1",
            $now,
            $id
        )) === false) {
            return false;
        }

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE constituent_id = %d AND email_bidx = %s LIMIT 1",
            $id,
            $bidx
        ));
        $values = [
            'email_enc' => $enc,
            'login_enabled' => 1,
            'source' => sanitize_key(bg_substr($source, 0, 32)),
            'updated_at' => $now,
        ];
        if ($existing) {
            // Re-adding an address as an alternate must never demote the
            // record's already-primary row (for example during a merge).
            if ($primary) $values['is_primary'] = 1;
            return $wpdb->update($table, $values, ['id' => $existing]) !== false;
        }
        $values['is_primary'] = $primary ? 1 : 0;
        $values['constituent_id'] = $id;
        $values['email_bidx'] = $bidx;
        $values['created_at'] = $now;
        return (bool) $wpdb->insert($table, $values);
    }

    /**
     * Add all current addresses supplied by a trusted connector while
     * preserving historic/profile aliases. The record's preferred email
     * is the only address marked primary.
     *
     * @param list<string> $emails
     */
    public function syncEmails(
        int $id,
        array $emails,
        string $primary = '',
        string $source = 'connector'
    ): bool {
        $primary = self::normEmail($primary);
        $clean = [];
        foreach ($emails as $email) {
            $email = self::normEmail(sanitize_email((string) $email));
            if (is_email($email)) $clean[$email] = $email;
        }
        if (is_email($primary)) $clean = [$primary => $primary] + $clean;
        if (!$clean) return true;

        global $wpdb;
        if (!$this->beginAtomic()) return false;
        if (is_email($primary) && !$this->addEmail($id, $primary, true, $source)) {
            $this->rollbackAtomic();
            return false;
        }
        foreach ($clean as $email) {
            if ($email === $primary) continue;
            if (!$this->addEmail($id, $email, false, $source)) {
                $this->rollbackAtomic();
                return false;
            }
        }
        if (!$this->commitAtomic()) return false;
        return true;
    }

    /**
     * Replace the administrator-visible alternate list. The canonical
     * envelope email is retained as primary and cannot be removed here.
     *
     * @param list<string> $emails
     */
    public function replaceAlternateEmails(int $id, array $emails): bool
    {
        global $wpdb;
        if ($id < 1 || !$this->emailTableAvailable()) return false;
        $record = $this->get($id);
        if (!$record || ($record['status'] ?? '') !== 'active') return false;
        $primary = self::normEmail((string) ($record['email'] ?? ''));
        $clean = [];
        foreach ($emails as $email) {
            $email = self::normEmail(sanitize_email((string) $email));
            if (is_email($email) && $email !== $primary) $clean[$email] = $email;
        }

        if (!$this->beginAtomic()) return false;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_emails
             WHERE constituent_id = %d AND is_primary = 0",
            $id
        ));
        if ($deleted === false) {
            $this->rollbackAtomic();
            return false;
        }
        if (is_email($primary) && !$this->addEmail($id, $primary, true, 'record')) {
            $this->rollbackAtomic();
            return false;
        }
        foreach ($clean as $email) {
            if (!$this->addEmail($id, $email, false, 'admin')) {
                $this->rollbackAtomic();
                return false;
            }
        }
        if (!$this->commitAtomic()) return false;
        return true;
    }

    /**
     * Active local records explicitly linked to a verified LGL
     * constituent. This is the preferred prefill bridge: unlike
     * ciphertext it is stable, and unlike an email fallback it does
     * not require LGL to be reachable during form rendering.
     *
     * @return list<int>
     */
    public function idsByLglId(int $lglId): array
    {
        global $wpdb;
        if ($lglId < 1) return [];
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE lgl_constituent_id = %d AND status = 'active' ORDER BY id ASC",
            $lglId
        ));
        return array_map('intval', $ids ?: []);
    }

    /**
     * Find by email or create; existing records gain only fields they
     * are missing (a registration never overwrites curated data).
     * @param array<string, string> $data
     * @return int Id, or 0 on refusal.
     */
    public function upsertByEmail(string $email, array $data = []): int
    {
        $email = self::normEmail($email);
        if (!is_email($email)) return 0;
        $blindIndex = $this->encryption->blindIndex($email);
        if ($blindIndex === '') return 0;

        // The email index is intentionally non-unique because two people may
        // share an inbox. Serialize only the lookup/create decision so two
        // public or background requests cannot both conclude that no record
        // exists and create the same person twice.
        $lock = 'bg_constituent_upsert_' . hash('sha256', $blindIndex);
        $owner = OptionLease::acquire($lock, 15 * MINUTE_IN_SECONDS);
        for ($attempt = 0; $owner === '' && $attempt < 4; $attempt++) {
            usleep(50000);
            $owner = OptionLease::acquire($lock, 15 * MINUTE_IN_SECONDS);
        }
        if ($owner === '') return 0;
        try {
            return $this->upsertByEmailUnlocked($email, $data);
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    /** @param array<string, string> $data */
    private function upsertByEmailUnlocked(string $email, array $data): int
    {
        $ids = $this->idsByEmail($email);
        if ($ids) {
            $clean = $this->cleanFields($data);
            $id = count($ids) === 1 ? (int) $ids[0] : $this->disambiguateIds($ids, $clean);
            if (count($ids) === 1) {
                $existing = $this->get($id);
                $oldName = $existing ? self::normName(
                    (string) ($existing['first_name'] ?? ''),
                    (string) ($existing['last_name'] ?? '')
                ) : '';
                $newName = self::normName($clean['first_name'], $clean['last_name']);
                if (trim((string) ($existing['first_name'] ?? '')) !== ''
                    && trim((string) ($existing['last_name'] ?? '')) !== ''
                    && trim($clean['first_name']) !== '' && trim($clean['last_name']) !== ''
                    && !hash_equals($oldName, $newName)) {
                    $data['email'] = $email;
                    $this->logger->info('constituents', 'A shared email carried a different complete name; a separate local record was created.');
                    return $this->create($data);
                }
                $oldFirst = strtolower(trim((string) ($existing['first_name'] ?? '')));
                $newFirst = strtolower(trim($clean['first_name']));
                if ($oldFirst !== '' && $newFirst !== '' && !hash_equals($oldFirst, $newFirst)
                    && trim($clean['last_name']) === '') {
                    $this->logger->warn('constituents', 'Email-only upsert refused because the supplied first name conflicts with the existing record and no last name was supplied.', [
                        'constituent' => $id,
                    ]);
                    return 0;
                }
            } elseif (!$id) {
                if (self::normName($clean['first_name'], $clean['last_name']) !== ''
                    && trim($clean['first_name']) !== '' && trim($clean['last_name']) !== '') {
                    $data['email'] = $email;
                    $this->logger->info('constituents', 'A shared email did not identify an existing person; a separate local record was created.', [
                        'matches' => count($ids),
                    ]);
                    return $this->create($data);
                }
                $this->logger->warn('constituents', 'Email lookup matched several people and lacked enough person-specific evidence; the upsert was refused.', [
                    'matches' => count($ids),
                ]);
                return 0;
            }
            if (!$id) return 0;
            $current = $this->get($id);
            if ($current === null) {
                $this->logger->error('constituents', 'Blind-index match could not be decrypted; upsert refused.', [
                    'constituent' => $id,
                ]);
                return 0;
            }
            $merged = $current;
            foreach ($this->cleanFields($data) as $key => $value) {
                if (($merged[$key] ?? '') === '' && $value !== '') $merged[$key] = $value;
            }
            $changed = array_diff_assoc(
                array_intersect_key($merged, array_flip(self::FIELDS)),
                array_intersect_key($current, array_flip(self::FIELDS))
            );
            if ($changed && !$this->update($id, $merged)) return 0;
            return $id;
        }
        $data['email'] = $email;
        return $this->create($data);
    }

    public function linkLgl(int $id, int $lglId): bool
    {
        global $wpdb;
        if ($id < 1 || $lglId < 1) return false;
        $conflict = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_constituents
             WHERE lgl_constituent_id = %d AND status = 'active' AND id <> %d LIMIT 1",
            $lglId,
            $id
        ));
        if ($conflict > 0) {
            $this->logger->error('constituents', 'LGL link refused: that LGL constituent already belongs to a different active record ID.', [
                'constituent' => $id,
                'conflicting_constituent' => $conflict,
                'lgl_constituent' => $lglId,
            ]);
            return false;
        }
        return $wpdb->update(
            $wpdb->prefix . 'bg_constituents',
            ['lgl_constituent_id' => $lglId],
            ['id' => $id]
        ) !== false;
    }

    public function unlinkLgl(int $id): bool
    {
        global $wpdb;
        if ($id < 1) return false;
        return $wpdb->update(
            $wpdb->prefix . 'bg_constituents',
            ['lgl_constituent_id' => null],
            ['id' => $id, 'status' => 'active']
        ) !== false;
    }

    /* ── Tags ─────────────────────────────────────────────────────── */

    /** @param list<string> $names Replaces the record's tag set. */
    public function setTags(int $id, array $names): bool
    {
        global $wpdb;
        $names = array_values(array_unique(array_filter(array_map(
            static fn($n) => bg_substr(trim((string) $n), 0, 64), $names
        ))));
        if (!$this->beginAtomic()) return false;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_ctag_links WHERE constituent_id = %d", $id
        ));
        if ($deleted === false) {
            $this->rollbackAtomic();
            return false;
        }
        foreach ($names as $name) {
            $tagId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bg_ctags WHERE name = %s", $name
            ));
            if (!$tagId) {
                if (!$wpdb->insert($wpdb->prefix . 'bg_ctags', ['name' => $name])) {
                    $this->rollbackAtomic();
                    return false;
                }
                $tagId = (int) $wpdb->insert_id;
            }
            if ($tagId) {
                if (!$wpdb->insert($wpdb->prefix . 'bg_ctag_links', [
                    'tag_id' => $tagId,
                    'constituent_id' => $id,
                ])) {
                    $this->rollbackAtomic();
                    return false;
                }
            }
        }
        if (!$this->commitAtomic()) return false;
        return true;
    }

    /** @return list<string> */
    public function tagsFor(int $id): array
    {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT t.name FROM {$wpdb->prefix}bg_ctags t
             JOIN {$wpdb->prefix}bg_ctag_links l ON l.tag_id = t.id
             WHERE l.constituent_id = %d ORDER BY t.name ASC",
            $id
        ));
        return $names ?: [];
    }

    /* ── Custom fields ────────────────────────────────────────────── */

    public function addFieldDefinition(string $key, string $label, bool $sensitive): bool
    {
        global $wpdb;
        $this->lastFieldError = '';
        $key = sanitize_key(bg_substr($key, 0, 48));
        if ($key === '' || $label === '') return false;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_cfields WHERE field_key = %s", $key
        ));
        if ($exists) return false;
        $ok = (bool) $wpdb->insert($wpdb->prefix . 'bg_cfields', [
            'field_key' => $key,
            'label' => bg_substr($label, 0, 190),
            'sensitive' => $sensitive ? 1 : 0,
            'created_at' => current_time('mysql', true),
        ]);
        if (!$ok) {
            $this->lastFieldError = (string) $wpdb->last_error;
            if ($this->lastFieldError === '') {
                $this->lastFieldError = 'The database insert returned false without an error message.';
            }
        }
        return $ok;
    }

    /** Field id by key, creating the definition when absent. 0 on refusal. */
    public function ensureField(string $key, string $label, bool $sensitive): int
    {
        global $wpdb;
        $key = sanitize_key(bg_substr($key, 0, 48));
        if ($key === '') return 0;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_cfields WHERE field_key = %s", $key
        ));
        if ($id) return $id;
        $created = $this->addFieldDefinition($key, $label, $sensitive);
        $insertError = $this->lastFieldError;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_cfields WHERE field_key = %s", $key
        ));
        if (!$id) {
            $detail = $insertError !== '' ? $insertError : (string) $wpdb->last_error;
            $this->logger->error(
                'constituents',
                'Custom field definition could not be created'
                    . ($detail !== '' ? ': ' . bg_substr($detail, 0, 180) : '.'),
                [
                    'field_key' => $key,
                    'insert_attempted' => $created,
                    'database_error' => $detail,
                ]
            );
        }
        return $id;
    }

    /** @return list<object> */
    public function fieldDefinitions(): array
    {
        global $wpdb;
        // SENSITIVE is a reserved SQL keyword. The unquoted identifier
        // made this read fail on MySQL 8 and compatible parsers even
        // though the definitions were present, so the UI misleadingly
        // rendered "None yet" and LGL alignment misreported its counts.
        $rows = $wpdb->get_results(
            "SELECT id, field_key, label, `sensitive` FROM {$wpdb->prefix}bg_cfields ORDER BY id ASC"
        );
        if (!is_array($rows)) {
            $detail = (string) $wpdb->last_error;
            $this->logger->error(
                'constituents',
                'Custom field definitions could not be read'
                    . ($detail !== '' ? ': ' . bg_substr($detail, 0, 180) : '.'),
                ['database_error' => $detail]
            );
            return [];
        }
        return $rows;
    }

    public function setValue(int $constituentId, int $fieldId, string $value): bool
    {
        global $wpdb;
        if (!$this->available()) {
            $this->logger->error('constituents', 'Custom value refused: encryption unavailable. Values are never written in plaintext.');
            return false;
        }
        if ($constituentId < 1 || $fieldId < 1) {
            $this->logger->error('constituents', 'Custom value refused: invalid constituent or field id.', [
                'constituent' => $constituentId,
                'field' => $fieldId,
            ]);
            return false;
        }
        $t = $wpdb->prefix . 'bg_cvalues';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT constituent_id FROM {$t} WHERE constituent_id = %d AND field_id = %d",
            $constituentId, $fieldId
        ));
        if ($value === '') {
            if ($exists !== null) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$t} WHERE constituent_id = %d AND field_id = %d",
                    $constituentId, $fieldId
                ));
                if ($deleted === false) {
                    $this->logger->error('constituents', 'Encrypted custom value could not be cleared.', [
                        'constituent' => $constituentId,
                        'field' => $fieldId,
                        'database_error' => (string) $wpdb->last_error,
                    ]);
                    return false;
                }
            }
            return true;
        }
        $context = $this->valueContext($constituentId, $fieldId);
        $enc = $this->encryption->encrypt($value, $context);
        if ($enc === '' || $enc === $value) {
            $this->logger->error('constituents', 'Custom value refused: an encrypted value could not be produced.', [
                'constituent' => $constituentId,
                'field' => $fieldId,
            ]);
            return false;
        }
        if ($exists !== null) {
            $ok = $wpdb->update($t, ['value_enc' => $enc],
                ['constituent_id' => $constituentId, 'field_id' => $fieldId]) !== false;
        } else {
            $ok = (bool) $wpdb->insert($t, [
                'constituent_id' => $constituentId,
                'field_id' => $fieldId,
                'value_enc' => $enc,
            ]);
        }
        if ($ok) {
            $personal = $this->personalPayloadForCustomField($fieldId, $value);
            if ($personal !== [] && !$this->setPersonalAnswers($constituentId, $personal)) {
                $ok = false;
            }
        }
        if (!$ok) {
            $this->logger->error('constituents', 'Encrypted custom value could not be stored.', [
                'constituent' => $constituentId,
                'field' => $fieldId,
                'database_error' => (string) $wpdb->last_error,
            ]);
        }
        return $ok;
    }

    /** @return array<int, string> field_id => decrypted value */
    public function valuesFor(int $constituentId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_id, value_enc
             FROM {$wpdb->prefix}bg_cvalues WHERE constituent_id = %d",
            $constituentId
        )) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $fieldId = (int) $row->field_id;
            $stored = (string) ($row->value_enc ?? '');
            $context = $this->valueContext($constituentId, $fieldId);
            $plain = $this->encryption->decryptOrNull($stored, $context);
            if ($plain === null) continue;
            $out[$fieldId] = $plain;
            // Lazy migration makes reads self-healing even when WP-Cron is
            // disabled. Failure leaves the original value intact.
            if ($plain !== '' && $this->encryption->needsReencrypt($stored)) {
                $rewrapped = $this->encryption->encrypt($plain, $context);
                if ($rewrapped !== '') {
                    $wpdb->update(
                        $wpdb->prefix . 'bg_cvalues',
                        ['value_enc' => $rewrapped],
                        ['constituent_id' => $constituentId, 'field_id' => $fieldId]
                    );
                }
            }
        }

        // Reserved form fields are synthesized from the authoritative
        // envelope. Explicit LGL Sync and custom-field screens therefore
        // see the current values even on an install whose old cvalues
        // table was missing or damaged.
        $answers = $this->personalAnswersFor($constituentId);
        $byKey = [];
        foreach ($this->fieldDefinitions() as $def) {
            $byKey[(string) $def->field_key] = (int) $def->id;
        }
        $derived = [
            'demographic' => $this->answerList($answers['demographics'], $answers['demographics_other']),
            'age_range' => $answers['age_range'],
            'relationship_status' => implode('; ', $answers['relationship']),
            'how_did_you_hear_about_this_event' => $this->answerList($answers['heard'], $answers['heard_other']),
            'promotion_consent' => $answers['promote'],
        ];
        foreach ($derived as $key => $value) {
            $fieldId = (int) ($byKey[$key] ?? 0);
            if ($fieldId > 0 && $value !== '') $out[$fieldId] = $value;
        }
        return $out;
    }

    /* ── Consents ─────────────────────────────────────────────────── */

    public function recordConsent(int $constituentId, string $purpose, bool $granted, string $source): bool
    {
        global $wpdb;
        if (!$this->beginAtomic()) return false;
        $ok = (bool) $wpdb->insert($wpdb->prefix . 'bg_consents', [
            'constituent_id' => $constituentId,
            'purpose' => bg_substr(sanitize_key($purpose), 0, 48),
            'granted' => $granted ? 1 : 0,
            'source' => bg_substr($source, 0, 48),
            'noted_at' => current_time('mysql', true),
        ]);
        if (!$ok || !$this->activity->append($constituentId, 'consent',
            ($granted ? 'Consent given: ' : 'Consent withdrawn: ') . $purpose)
        ) {
            $this->rollbackAtomic();
            return false;
        }
        return $this->commitAtomic();
    }

    /** @return list<object> */
    public function consentsFor(int $constituentId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT purpose, granted, source, noted_at FROM {$wpdb->prefix}bg_consents
             WHERE constituent_id = %d ORDER BY id DESC",
            $constituentId
        )) ?: [];
    }

    /** Latest recorded answer for one consent purpose, or null when unanswered. */
    public function latestConsent(int $constituentId, string $purpose): ?bool
    {
        global $wpdb;
        if ($constituentId < 1) return null;
        $purpose = bg_substr(sanitize_key($purpose), 0, 48);
        if ($purpose === '') return null;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT granted FROM {$wpdb->prefix}bg_consents
             WHERE constituent_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1",
            $constituentId,
            $purpose
        ));
        return $value === null ? null : (bool) (int) $value;
    }

    /** Existing profiles default to shareable until a person explicitly opts out. */
    public function contactSharingAllowed(int $constituentId): bool
    {
        return $this->latestConsent($constituentId, self::CONTACT_SHARING_PURPOSE) ?? true;
    }

    /** @param list<int> $constituentIds @return array<int, bool> */
    public function contactSharingMap(array $constituentIds): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $constituentIds))));
        $out = array_fill_keys($ids, true);
        $table = $wpdb->prefix . 'bg_consents';
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $query = $wpdb->prepare(
                "SELECT c.constituent_id, c.granted
                 FROM {$table} c
                 INNER JOIN (
                    SELECT constituent_id, MAX(id) AS latest_id
                    FROM {$table}
                    WHERE purpose = %s AND constituent_id IN ({$placeholders})
                    GROUP BY constituent_id
                 ) latest ON latest.latest_id = c.id",
                array_merge([self::CONTACT_SHARING_PURPOSE], $chunk)
            );
            foreach ($wpdb->get_results($query) ?: [] as $row) {
                $out[(int) $row->constituent_id] = (bool) (int) $row->granted;
            }
        }
        return $out;
    }

    /* ── Merge and anonymize ──────────────────────────────────────── */

    /** Human-readable reason for the most recent refused merge. */
    public function lastMergeError(): string
    {
        return $this->lastMergeError;
    }

    /**
     * Secondary folds into primary and is tombstoned.
     *
     * A programmatic caller may not collapse two different LGL links. An
     * administrator-confirmed merge may: the surviving local record keeps its
     * own LGL link and the released secondary link is retained in the merge
     * timeline metadata for reconciliation.
     */
    public function merge(int $primaryId, int $secondaryId, bool $confirmedIdentity = false): bool
    {
        global $wpdb;
        $this->lastMergeError = '';
        if ($primaryId === $secondaryId) {
            $this->lastMergeError = 'A record cannot be merged into itself.';
            return false;
        }
        $primary = $this->get($primaryId);
        $secondary = $this->get($secondaryId);
        if (!$primary || !$secondary || $primary['status'] !== 'active' || $secondary['status'] !== 'active') {
            $this->lastMergeError = 'Both records must still be active.';
            return false;
        }
        $primaryLgl = (int) ($primary['lgl_constituent_id'] ?? 0);
        $secondaryLgl = (int) ($secondary['lgl_constituent_id'] ?? 0);
        if ($primaryLgl > 0 && $secondaryLgl > 0 && $primaryLgl !== $secondaryLgl) {
            if ($confirmedIdentity) {
                $this->logger->warn('constituents', 'An administrator confirmed a merge across two LGL links. The surviving local record kept its existing LGL link.', [
                    'primary' => $primaryId,
                    'secondary' => $secondaryId,
                    'kept_lgl_constituent' => $primaryLgl,
                    'released_lgl_constituent' => $secondaryLgl,
                ]);
            } else {
                $this->lastMergeError = sprintf(
                    'The records link to different LGL people (%d and %d); an administrator must confirm which record ID survives.',
                    $primaryLgl,
                    $secondaryLgl
                );
                $this->logger->warn('constituents', 'Merge refused because the two local records are linked to different LGL people.', [
                    'primary' => $primaryId,
                    'secondary' => $secondaryId,
                ]);
                return false;
            }
        }
        if (!$this->beginAtomic()) {
            $this->lastMergeError = 'The database could not start a safe merge transaction.';
            return false;
        }
        $abort = function (string $message) use ($wpdb): bool {
            $this->rollbackAtomic();
            $this->lastMergeError = $message;
            $this->logger->error('constituents', 'Merge rolled back.', [
                'reason' => $message,
                'database_error' => (string) $wpdb->last_error,
            ]);
            return false;
        };
        // Primary wins; blanks fill from the secondary.
        $merged = $primary;
        foreach (self::FIELDS as $key) {
            if (($merged[$key] ?? '') === '' && ($secondary[$key] ?? '') !== '') {
                $merged[$key] = $secondary[$key];
            }
        }
        if (!$this->update($primaryId, $merged)) {
            return $abort('The surviving record could not be updated.');
        }
        if ((int) ($primary['lgl_constituent_id'] ?? 0) === 0
            && (int) ($secondary['lgl_constituent_id'] ?? 0) > 0
        ) {
            $movingLglId = (int) $secondary['lgl_constituent_id'];
            $released = $wpdb->update(
                $wpdb->prefix . 'bg_constituents',
                ['lgl_constituent_id' => null],
                ['id' => $secondaryId, 'status' => 'active']
            ) !== false;
            if (!$released || !$this->linkLgl($primaryId, $movingLglId)) {
                if ($released) {
                    $wpdb->update(
                        $wpdb->prefix . 'bg_constituents',
                        ['lgl_constituent_id' => $movingLglId],
                        ['id' => $secondaryId, 'status' => 'active']
                    );
                }
                return $abort('The LGL link could not be transferred to the surviving record.');
            }
        }
        foreach ($this->emailsFor($secondaryId) as $email) {
            if (!$this->addEmail($primaryId, $email, false, 'merge')) {
                return $abort('An email address could not be transferred.');
            }
        }

        // Custom values: fill only what the primary is missing.
        $primaryValues = $this->valuesFor($primaryId);
        foreach ($this->valuesFor($secondaryId) as $fieldId => $value) {
            if (($primaryValues[$fieldId] ?? '') === '' && $value !== '') {
                if (!$this->setValue($primaryId, $fieldId, $value)) {
                    return $abort('A custom field could not be transferred.');
                }
            }
        }
        if (!$this->setTags($primaryId, array_unique(array_merge(
            $this->tagsFor($primaryId), $this->tagsFor($secondaryId)
        )))) {
            return $abort('The combined tag list could not be stored.');
        }
        if (!$this->activity->reassign($secondaryId, $primaryId)) {
            return $abort('The activity history could not be transferred.');
        }
        // Preserve every relationship owned by the folded record. Resolve
        // subscription-key collisions explicitly: an opt-out is never lost
        // merely because it belongs to the record being folded.
        $subscriptionRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_subscriptions WHERE constituent_id = %d ORDER BY id ASC",
            $secondaryId
        ));
        if ($wpdb->last_error !== '') {
            return $abort('Newsletter subscriptions could not be read safely.');
        }
        foreach ($subscriptionRows ?: [] as $subscriptionRow) {
            $primarySubscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bg_subscriptions
                 WHERE group_id = %d AND constituent_id = %d LIMIT 1",
                (int) $subscriptionRow->group_id,
                $primaryId
            ));
            if ($wpdb->last_error !== '') {
                return $abort('A newsletter subscription collision could not be checked safely.');
            }
            if (!$primarySubscription) {
                $subscriptionMoved = $wpdb->update(
                    $wpdb->prefix . 'bg_subscriptions',
                    ['constituent_id' => $primaryId],
                    ['id' => (int) $subscriptionRow->id]
                );
                if ($subscriptionMoved === false) {
                    return $abort('A newsletter subscription could not be transferred.');
                }
                continue;
            }

            $primaryUnsubscribed = (string) $primarySubscription->status === 'unsubscribed';
            $secondaryUnsubscribed = (string) $subscriptionRow->status === 'unsubscribed';
            $secondaryOptOutWins = $secondaryUnsubscribed && (
                !$primaryUnsubscribed
                || strcmp((string) $subscriptionRow->updated_at, (string) $primarySubscription->updated_at) > 0
            );
            if ($secondaryOptOutWins) {
                $subscriptionUpdated = $wpdb->update(
                    $wpdb->prefix . 'bg_subscriptions',
                    [
                        'status' => 'unsubscribed',
                        'source' => (string) $subscriptionRow->source,
                        'updated_at' => (string) $subscriptionRow->updated_at,
                    ],
                    ['id' => (int) $primarySubscription->id]
                );
                if ($subscriptionUpdated === false) {
                    return $abort('A newsletter opt-out could not be preserved.');
                }
            }
            if ($wpdb->delete(
                $wpdb->prefix . 'bg_subscriptions',
                ['id' => (int) $subscriptionRow->id]
            ) === false) {
                return $abort('A duplicate newsletter subscription could not be folded safely.');
            }
        }

        $consentsMoved = $wpdb->update($wpdb->prefix . 'bg_consents', ['constituent_id' => $primaryId], ['constituent_id' => $secondaryId]);
        $registrationsMoved = $wpdb->update($wpdb->prefix . 'bg_registrations', ['bg_constituent_id' => $primaryId], ['bg_constituent_id' => $secondaryId]);
        $paymentsMoved = $wpdb->update($wpdb->prefix . 'bg_payments', ['constituent_id' => $primaryId], ['constituent_id' => $secondaryId]);
        $diffsMoved = $wpdb->update($wpdb->prefix . 'bg_lgl_diffs', ['constituent_id' => $primaryId], ['constituent_id' => $secondaryId]);
        $tokensMoved = $wpdb->update(
            $wpdb->prefix . 'bg_identity_tokens',
            ['lgl_constituent_id' => $primaryId],
            ['kind' => 'magic_bg', 'lgl_constituent_id' => $secondaryId]
        );
        if ($consentsMoved === false || $registrationsMoved === false
            || $paymentsMoved === false || $diffsMoved === false || $tokensMoved === false
        ) return $abort('One or more constituent relationships could not be transferred.');

        // These identity links arrived after the original merge workflow.
        // None has a constituent-based unique key, so each can be reassigned
        // without discarding its operational or import history.
        foreach ([
            'bg_import_rows',
            'bg_event_intakes',
            'bg_event_tenders',
            'bg_attendee_sessions',
            'bg_event_organizers',
        ] as $relationshipTable) {
            if ($wpdb->update(
                $wpdb->prefix . $relationshipTable,
                ['constituent_id' => $primaryId],
                ['constituent_id' => $secondaryId]
            ) === false) {
                return $abort('A newer constituent relationship could not be transferred.');
            }
        }

        // Organizer roles do have a unique event/constituent/role key. Move
        // non-conflicting rows; on a collision, keep the survivor's existing
        // row rather than silently escalating or replacing its role state.
        $foldedRoleCollisions = 0;
        $roleRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_event_roles WHERE constituent_id = %d ORDER BY id ASC",
            $secondaryId
        ));
        if ($wpdb->last_error !== '') {
            return $abort('Organizer roles could not be read safely.');
        }
        foreach ($roleRows ?: [] as $roleRow) {
            $existingRoleId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bg_event_roles
                 WHERE event_post_id = %d AND constituent_id = %d AND role_label = %s LIMIT 1",
                (int) $roleRow->event_post_id,
                $primaryId,
                (string) $roleRow->role_label
            ));
            if ($wpdb->last_error !== '') {
                return $abort('An organizer role collision could not be checked safely.');
            }
            if ($existingRoleId > 0) {
                $roleMoved = $wpdb->delete(
                    $wpdb->prefix . 'bg_event_roles',
                    ['id' => (int) $roleRow->id]
                );
                $foldedRoleCollisions++;
            } else {
                $roleMoved = $wpdb->update(
                    $wpdb->prefix . 'bg_event_roles',
                    ['constituent_id' => $primaryId],
                    ['id' => (int) $roleRow->id]
                );
            }
            if ($roleMoved === false) {
                return $abort('An organizer role could not be transferred safely.');
            }
        }

        $linkedUserIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value = %s",
            \BosqueGrande\Core\UserLink::key(),
            (string) $secondaryId
        )) ?: []);
        if ($wpdb->last_error !== '') {
            return $abort('Organizer identity links could not be read safely.');
        }
        $userLinksMoved = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = %s
             WHERE meta_key = %s AND meta_value = %s",
            (string) $primaryId,
            \BosqueGrande\Core\UserLink::key(),
            (string) $secondaryId
        ));
        if ($userLinksMoved === false) {
            return $abort('Organizer identity links could not be transferred.');
        }
        $sendRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_send_queue WHERE constituent_id = %d",
            $secondaryId
        )) ?: [];
        foreach ($sendRows as $sendRow) {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bg_send_queue
                 WHERE newsletter_id = %d AND constituent_id = %d LIMIT 1",
                (int) $sendRow->newsletter_id,
                $primaryId
            ));
            if ($existing) {
                $sendOk = $wpdb->delete($wpdb->prefix . 'bg_send_queue', ['id' => (int) $sendRow->id]);
            } else {
                $sendOk = $wpdb->update($wpdb->prefix . 'bg_send_queue', [
                    'constituent_id' => $primaryId,
                    'queue_key' => hash('sha256', (int) $sendRow->newsletter_id . '|' . $primaryId),
                ], ['id' => (int) $sendRow->id]);
            }
            if ($sendOk === false) return $abort('A newsletter queue relationship could not be transferred.');
        }
        // Derived classifications follow the surviving identity. Prefer the
        // survivor's regional row (it may be a staff override), but preserve
        // positive donor history from either side.
        $regionsCopied = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bg_constituent_regions
                (constituent_id, region_slug, assignment_source, location_hash, confidence, evidence, updated_at)
             SELECT %d, region_slug, assignment_source, location_hash, confidence, evidence, updated_at
             FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id = %d",
            $primaryId,
            $secondaryId
        ));
        $primaryDonorFact = $wpdb->get_row($wpdb->prepare(
            "SELECT has_history, source, checked_at FROM {$wpdb->prefix}bg_constituent_donor_facts
             WHERE constituent_id = %d",
            $primaryId
        ));
        $secondaryDonorFact = $wpdb->get_row($wpdb->prepare(
            "SELECT has_history, source, checked_at FROM {$wpdb->prefix}bg_constituent_donor_facts
             WHERE constituent_id = %d",
            $secondaryId
        ));
        if ($secondaryDonorFact && (!$primaryDonorFact || (int) $secondaryDonorFact->has_history === 1)) {
            $primaryHasHistory = $primaryDonorFact ? (int) $primaryDonorFact->has_history === 1 : false;
            $fact = [
                'has_history' => ($primaryHasHistory || (int) $secondaryDonorFact->has_history === 1) ? 1 : 0,
                'source' => bg_substr((string) $secondaryDonorFact->source, 0, 24),
                'checked_at' => (string) $secondaryDonorFact->checked_at,
            ];
            if ($primaryDonorFact) {
                $factSaved = $wpdb->update($wpdb->prefix . 'bg_constituent_donor_facts', $fact, ['constituent_id' => $primaryId]);
            } else {
                $factSaved = $wpdb->insert($wpdb->prefix . 'bg_constituent_donor_facts', ['constituent_id' => $primaryId] + $fact);
            }
            if ($factSaved === false) return $abort('Donor-history evidence could not be transferred.');
        }
        if ($regionsCopied === false) return $abort('Regional-group evidence could not be transferred.');
        $valuesRemoved = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_cvalues WHERE constituent_id = %d", $secondaryId
        ));
        $tagsRemoved = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_ctag_links WHERE constituent_id = %d", $secondaryId
        ));
        $emailsRemoved = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_emails WHERE constituent_id = %d", $secondaryId
        ));
        $regionsRemoved = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id = %d", $secondaryId
        ));
        $factsRemoved = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_donor_facts WHERE constituent_id = %d", $secondaryId
        ));
        if ($valuesRemoved === false || $tagsRemoved === false || $emailsRemoved === false
            || $regionsRemoved === false || $factsRemoved === false
        ) return $abort('The folded record could not be cleared safely.');
        $folded = $wpdb->update($wpdb->prefix . 'bg_constituents', [
            'envelope' => '',
            'email_bidx' => null,
            'name_bidx' => null,
            'phone_bidx' => null,
            'status' => 'merged',
            'lgl_constituent_id' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $secondaryId]);
        if ($folded === false) {
            return $abort('The folded record could not be closed.');
        }
        $meta = ['from' => $secondaryId];
        if ($primaryLgl > 0 && $secondaryLgl > 0 && $primaryLgl !== $secondaryLgl) {
            $meta['kept_lgl_constituent'] = $primaryLgl;
            $meta['released_lgl_constituent'] = $secondaryLgl;
        }
        if ($foldedRoleCollisions > 0) {
            $meta['folded_event_role_collisions'] = $foldedRoleCollisions;
        }
        if (!$this->activity->append($primaryId, 'merge', 'Duplicate record folded in.', $meta)) {
            return $abort('The merge audit entry could not be stored.');
        }
        if (!$this->commitAtomic()) return $abort('The merge transaction could not be committed.');
        foreach ($linkedUserIds as $linkedUserId) {
            clean_user_cache($linkedUserId);
        }
        return true;
    }

    /**
     * The erasure path: envelope emptied, indexes nulled, values
     * removed, timeline scrubbed, row kept as a tombstone.
     */
    /**
     * Permanent multi-table removal, for cleaning up a bad import.
     * Unlike anonymize (which tombstones), nothing is kept: values,
     * tag links, timeline, consents, and subscriptions all go. The
     * global suppression list is untouched: an opted-out address must
     * stay opted out even if its record is purged and re-imported.
     * @param int[] $ids @return int Constituent rows removed.
     */
    public function deleteHard(array $ids): int
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if (!$ids) return 0;
        if (!$this->beginAtomic()) return 0;
        $gone = 0;
        foreach (array_chunk($ids, 100) as $chunk) {
            $in = implode(',', $chunk);
            foreach ([
                "DELETE FROM {$wpdb->prefix}bg_cvalues WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_ctag_links WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_constituent_emails WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_constituent_donor_facts WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_activity WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_consents WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_subscriptions WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_send_queue WHERE constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_identity_tokens WHERE kind = 'magic_bg' AND lgl_constituent_id IN ({$in})",
                "DELETE FROM {$wpdb->prefix}bg_lgl_diffs WHERE constituent_id IN ({$in})",
                "UPDATE {$wpdb->prefix}bg_registrations SET bg_constituent_id = NULL WHERE bg_constituent_id IN ({$in})",
                "UPDATE {$wpdb->prefix}bg_payments SET constituent_id = NULL WHERE constituent_id IN ({$in})",
            ] as $sql) {
                if ($wpdb->query($sql) === false) {
                    $this->rollbackAtomic();
                    $this->logger->error('constituents', 'Permanent deletion rolled back after a database refusal.', [
                        'database_error' => (string) $wpdb->last_error,
                    ]);
                    return 0;
                }
            }
            $removed = $wpdb->query("DELETE FROM {$wpdb->prefix}bg_constituents WHERE id IN ({$in})");
            if ($removed === false) {
                $this->rollbackAtomic();
                return 0;
            }
            $gone += (int) $removed;
        }
        if (!$this->commitAtomic()) {
            $this->rollbackAtomic();
            return 0;
        }
        $this->logger->warn('constituents', sprintf('%d record(s) permanently deleted (bulk).', $gone));
        return $gone;
    }

    public function anonymize(int $id): bool
    {
        global $wpdb;
        $row = $this->row($id);
        if (!$row || $row->status !== 'active') return false;
        if (!$this->beginAtomic()) return false;
        $updated = $wpdb->update($wpdb->prefix . 'bg_constituents', [
            'envelope' => '',
            'email_bidx' => null,
            'name_bidx' => null,
            'phone_bidx' => null,
            'status' => 'anonymized',
            'lgl_constituent_id' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id]);
        if ($updated === false) {
            $this->rollbackAtomic();
            return false;
        }
        $values = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_cvalues WHERE constituent_id = %d", $id
        ));
        $tags = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_ctag_links WHERE constituent_id = %d", $id
        ));
        $emails = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_emails WHERE constituent_id = %d", $id
        ));
        $regions = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id = %d", $id
        ));
        $facts = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_constituent_donor_facts WHERE constituent_id = %d", $id
        ));
        $tokens = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_identity_tokens WHERE kind = 'magic_bg' AND lgl_constituent_id = %d", $id
        ));
        $diffs = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bg_lgl_diffs WHERE constituent_id = %d", $id
        ));
        if ($values === false || $tags === false || $emails === false || $regions === false || $facts === false
            || $tokens === false || $diffs === false
            || !$this->activity->scrub($id)
            || !$this->activity->append($id, 'anonymization', 'Record anonymized.')
        ) {
            $this->rollbackAtomic();
            $this->logger->error('constituents', 'Anonymization rolled back after a database refusal.', ['id' => $id]);
            return false;
        }
        if (!$this->commitAtomic()) {
            $this->rollbackAtomic();
            return false;
        }
        $this->logger->info('constituents', 'Record anonymized.', ['id' => $id]);
        return true;
    }

    /**
     * Recompute every blind index from the decrypted envelopes. Run
     * when the index key changes (the envelope decrypt chain survives
     * a key change; HMAC indexes cannot, so they are rebuilt instead).
     * The suppression list stores only indexes and no addresses, so it
     * cannot be rebuilt; that loss is logged honestly, never hidden.
     * @return int Rows rebuilt.
     */
    public function rebuildIndexes(): int
    {
        global $wpdb;
        if (!$this->available()) return 0;
        $rows = $wpdb->get_results(
            "SELECT id, envelope FROM {$wpdb->prefix}bg_constituents WHERE status = 'active' AND envelope != ''"
        ) ?: [];
        $rebuilt = 0;
        foreach ($rows as $row) {
            $plain = $this->encryption->decryptOrNull(
                (string) $row->envelope,
                $this->envelopeContext((int) $row->id)
            );
            $decoded = is_string($plain) ? json_decode($plain, true) : null;
            if (!is_array($decoded)) continue;
            $fields = $this->cleanFields($decoded);
            $ok = $wpdb->update($wpdb->prefix . 'bg_constituents', [
                'email_bidx' => $this->bidxOrNull(self::normEmail($fields['email'])),
                'name_bidx' => $this->bidxOrNull(self::normName($fields['first_name'], $fields['last_name'])),
                'phone_bidx' => $this->bidxOrNull(self::normPhone($fields['phone'])),
            ], ['id' => (int) $row->id]);
            if ($ok !== false) $rebuilt++;
        }
        if ($this->emailTableAvailable()) {
            $aliases = $wpdb->get_results(
                "SELECT id, constituent_id, email_enc, email_bidx
                 FROM {$wpdb->prefix}bg_constituent_emails ORDER BY id ASC"
            ) ?: [];
            foreach ($aliases as $alias) {
                $oldBidx = (string) $alias->email_bidx;
                $plain = $this->encryption->decryptOrNull(
                    (string) $alias->email_enc,
                    $this->emailContext((int) $alias->constituent_id, $oldBidx)
                );
                $email = is_string($plain) ? self::normEmail($plain) : '';
                $newBidx = is_email($email) ? $this->bidxOrNull($email) : null;
                if ($newBidx === null) continue;
                $enc = $this->encryption->encrypt(
                    $email,
                    $this->emailContext((int) $alias->constituent_id, $newBidx)
                );
                if ($enc === '') continue;
                $wpdb->update($wpdb->prefix . 'bg_constituent_emails', [
                    'email_enc' => $enc,
                    'email_bidx' => $newBidx,
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $alias->id]);
            }
        }
        return $rebuilt;
    }

    /* ── Internals ────────────────────────────────────────────────── */

    private function row(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_constituents WHERE id = %d", $id
        ));
        return $row ?: null;
    }

    /** @return array<string, string>|null */
    private function decorate(object $row): ?array
    {
        $fields = $this->decodeEnvelope($row);
        if ($fields === null) return null;
        $out = $this->cleanFields($fields);
        $out['id'] = (string) $row->id;
        $out['status'] = (string) $row->status;
        $out['lgl_constituent_id'] = (string) ($row->lgl_constituent_id ?? '');
        $out['created_at'] = (string) $row->created_at;
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function decodeEnvelope(object $row): ?array
    {
        if ((string) $row->envelope === '') return [];
        $stored = (string) $row->envelope;
        $context = $this->envelopeContext((int) $row->id);
        $plain = $this->encryption->decryptOrNull($stored, $context);
        $decoded = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($decoded)) {
            $this->logger->error('constituents', 'Envelope unreadable (key change?). Record skipped.', ['id' => (int) $row->id]);
            return null;
        }
        // Reads also finish migrations on sites where cron is unavailable.
        if ($this->encryption->needsReencrypt($stored)) {
            $rewrapped = $this->encryption->encrypt((string) $plain, $context);
            if ($rewrapped !== '') {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'bg_constituents',
                    ['envelope' => $rewrapped, 'updated_at' => current_time('mysql', true)],
                    ['id' => (int) $row->id]
                );
            }
        }
        return $decoded;
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    /**
     * Strip an Excel formula guard. Spreadsheet exports wrap
     * values like phone numbers and leading-zero ZIPs as ="2075550100"
     * so Excel keeps them as text; imported verbatim, the wrapper (or
     * its stray leading = once CSV parsing consumed the quotes) ended
     * up stored and displayed. One unwrap pass per value; the guard is
     * never nested.
     */
    public static function stripExcelGuard(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value[0] !== '=') return $value;
        $inner = trim(substr($value, 1));
        $len = strlen($inner);
        if ($len >= 2
            && ($inner[0] === '"' || $inner[0] === "'")
            && $inner[$len - 1] === $inner[0]
        ) {
            $inner = substr($inner, 1, $len - 2);
        }
        return trim($inner);
    }

    /** Prevent spreadsheet applications from executing exported text as a formula. */
    public static function safeCsv(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private function cleanFields(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $value = (string) ($data[$key] ?? '');
            $out[$key] = $key === 'notes'
                ? sanitize_textarea_field($value)
                : self::stripExcelGuard(sanitize_text_field($value));
        }
        $out['email'] = self::normEmail($out['email']);
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   demographics: list<string>, demographics_other: string,
     *   age_range: string, relationship: list<string>,
     *   heard: list<string>, heard_other: string, promote: string
     * }
     */
    private function cleanPersonalAnswers(array $data): array
    {
        $list = static function (mixed $value): array {
            if (!is_array($value)) return [];
            $out = [];
            foreach ($value as $item) {
                if (!is_scalar($item)) continue;
                $clean = sanitize_text_field((string) $item);
                if ($clean !== '') $out[] = $clean;
            }
            return array_values(array_unique($out));
        };
        return [
            'demographics' => $list($data['demographics'] ?? []),
            'demographics_other' => sanitize_text_field((string) ($data['demographics_other'] ?? '')),
            'age_range' => sanitize_text_field((string) ($data['age_range'] ?? '')),
            'relationship' => $list($data['relationship'] ?? []),
            'heard' => \BosqueGrande\Modules\Registration\SurveyFields::sanitizeHeard(
                $list($data['heard'] ?? [])
            ),
            'heard_other' => sanitize_text_field((string) ($data['heard_other'] ?? '')),
            'promote' => sanitize_text_field((string) ($data['promote'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $answers */
    private function hasPersonalAnswers(array $answers): bool
    {
        foreach (self::ANSWER_FIELDS as $key) {
            $value = $answers[$key] ?? '';
            if ((is_array($value) && $value !== []) || (!is_array($value) && $value !== '')) return true;
        }
        return false;
    }

    /** @param list<string> $values */
    private function answerList(array $values, string $other): string
    {
        if (in_array('Other', $values, true) && $other !== '') {
            $values[] = 'Other: ' . $other;
        }
        return implode('; ', $values);
    }

    /**
     * Keep explicit Data Transfer/LGL Sync writes to the five legacy
     * form-backed custom fields aligned with the envelope source of
     * truth. Arbitrary custom fields remain table-only.
     *
     * @return array<string, mixed>
     */
    private function personalPayloadForCustomField(int $fieldId, string $value): array
    {
        global $wpdb;
        $key = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT field_key FROM {$wpdb->prefix}bg_cfields WHERE id = %d",
            $fieldId
        ));
        if ($key === '') return [];

        return match ($key) {
            'demographic' => [
                'demographics' => \BosqueGrande\Modules\Registration\DemographicFields::sanitize(
                    \BosqueGrande\Modules\Registration\PrefillResolver::parseOptions(
                        $value,
                        \BosqueGrande\Modules\Registration\DemographicFields::options()
                    )
                ),
                'demographics_other' => $this->otherDetail($value),
            ],
            'age_range' => [
                'age_range' => \BosqueGrande\Modules\Registration\PrefillResolver::parseAge($value),
            ],
            'relationship_status' => [
                'relationship' => \BosqueGrande\Modules\Registration\SurveyFields::sanitizeRelationship(
                    \BosqueGrande\Modules\Registration\PrefillResolver::parseOptions(
                        $value,
                        \BosqueGrande\Modules\Registration\SurveyFields::relationshipOptions()
                    )
                ),
            ],
            'how_did_you_hear_about_this_event' => [
                'heard' => \BosqueGrande\Modules\Registration\SurveyFields::parseHeard($value),
                'heard_other' => $this->otherDetail($value),
            ],
            'promotion_consent' => [
                'promote' => \BosqueGrande\Modules\Registration\SurveyFields::sanitizePromote($value),
            ],
            default => [],
        };
    }

    private function otherDetail(string $value): string
    {
        if (!preg_match('/(?:^|[;,|])\s*Other\s*:\s*([^;,|]+)/i', $value, $match)) return '';
        return sanitize_text_field(trim((string) ($match[1] ?? '')));
    }

    private function syncRecordEmail(int $id, string $oldEmail, string $newEmail): bool
    {
        global $wpdb;
        if (!$this->emailTableAvailable()) return false;
        $oldEmail = self::normEmail($oldEmail);
        $newEmail = self::normEmail($newEmail);

        if (is_email($oldEmail) && $oldEmail !== $newEmail
            && !$this->addEmail($id, $oldEmail, false, 'previous-primary')
        ) {
            return false;
        }
        if (is_email($newEmail)) {
            return $this->addEmail($id, $newEmail, true, 'record');
        }
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bg_constituent_emails
             SET is_primary = 0, updated_at = %s
             WHERE constituent_id = %d",
            current_time('mysql', true),
            $id
        )) !== false;
    }

    private function emailTableAvailable(): bool
    {
        if ($this->emailTableExists !== null) return $this->emailTableExists;
        global $wpdb;
        $table = $wpdb->prefix . 'bg_constituent_emails';
        $this->emailTableExists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        ) === $table;
        return $this->emailTableExists;
    }

    private function bidxOrNull(string $normalized): ?string
    {
        $bidx = $this->encryption->blindIndex($normalized);
        return $bidx === '' ? null : $bidx;
    }

    private function envelopeContext(int $id): string
    {
        return 'constituent:' . max(0, $id);
    }

    private function emailContext(int $constituentId, string $bidx): string
    {
        return 'constituent-email:' . max(0, $constituentId) . ':' . $bidx;
    }

    private function valueContext(int $constituentId, int $fieldId): string
    {
        return 'cvalue:' . max(0, $constituentId) . ':' . max(0, $fieldId);
    }
}
