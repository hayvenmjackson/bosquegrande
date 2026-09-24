<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Lgl\ConstituentPresenter;

if (!defined('ABSPATH')) exit;

/**
 * Prefill resolver. A confirmed identity resolves against
 * BosqueGrande's encrypted constituent database. LGL identifiers are
 * retained only as stable links for deliberate Data Transfer/LGL Sync
 * runs; rendering a form never makes a CRM request.
 *
 * Rules:
 * - The local record is the system of record for every public form.
 * - Existing LGL-sourced tokens may follow a previously stored numeric
 *   link, but cannot fetch or mutate the remote constituent.
 * - A constituent id identifies exactly one person. Shared household emails and
 *   addresses never cause contact fields or answers to cross records.
 * - Person-level answers (demographic, age range, relationship) rest
 *   locally in sensitive encrypted custom fields since 2.2 and merge
 *   the same way. Sensitive fields stay excluded from CSV export and
 *   assisted features by the store's standing rule.
 * - Encryption never needs ciphertext comparison: the store decrypts
 *   only after a verified identity resolves to a stable local id.
 *   Without a usable site key, sensitive local prefill remains closed.
 *
 * Performance: one local constituent read plus its encrypted answer
 * envelope; no public-request network dependency.
 */
final class PrefillResolver
{
    public const FIELD_DEMO = 'demographic';
    public const FIELD_AGE = 'age_range';
    public const FIELD_REL = 'relationship_status';
    /* The event survey answers are kept HERE as well. They used
       to travel only to the CRM invitation note, which meant the form
       demanded an answer and then discarded it whenever that connector
       was absent, and would lose it entirely once the connector is
       retired. The key matches the CRM column so the two line up. */
    public const FIELD_HEARD = 'how_did_you_hear_about_this_event';
    public const FIELD_PROMOTE = 'promotion_consent';

    public function __construct(
        private ConstituentStore $store,
        private Logger $logger,
    ) {
    }

    /**
     * @param array{source: string, constituent_id: int|null, via_session?: bool} $identity
     * @return array{prefill: array<string, mixed>, has_profile: bool, is_member: bool, local_id: int, lgl_id: int}
     */
    public function resolve(array $identity): array
    {
        $source = (string) ($identity['source'] ?? '');
        $id = (int) ($identity['constituent_id'] ?? 0);
        $out = [
            'prefill' => ConstituentPresenter::prefill(null),
            'has_profile' => false,
            'is_member' => false,
            'local_id' => 0,
            'lgl_id' => 0,
        ];
        if (!$id || !in_array($source, ['bg', 'lgl'], true)) return $out;
        return $source === 'bg'
            ? $this->resolveLocalFirst($id, $out)
            : $this->resolveLglFirst($id, $out);
    }

    /** Local mode: the encrypted local record is authoritative. */
    private function resolveLocalFirst(int $localId, array $out): array
    {
        if (!$this->store->available()) return $out;
        $record = $this->store->get($localId);
        if (!$record || ($record['status'] ?? '') !== 'active') return $out;

        $prefill = $this->shapeLocal($localId, $record);
        $this->noteVerifiedMatch($localId, 'bg', $prefill);
        $out['has_profile'] = true;
        $out['local_id'] = $localId;
        $lglId = (int) ($record['lgl_constituent_id'] ?? 0);
        $out['lgl_id'] = $lglId;
        $out['prefill'] = $prefill;
        return $out;
    }

    /**
     * Compatibility for an existing LGL-sourced token/session. Follow
     * only the saved numeric link; never fetch the remote record during
     * a public request.
     */
    private function resolveLglFirst(int $lglId, array $out): array
    {
        $out['lgl_id'] = $lglId;
        $localId = 0;
        $record = null;
        $localPrefill = null;

        // A verified LGL id is already a trustworthy stable identifier.
        // Follow the explicit link before making any remote request;
        // the encrypted answer rows join to this local id.
        if ($this->store->available()) {
            $linked = $this->store->idsByLglId($lglId);
            $localId = count($linked) === 1 ? (int) $linked[0] : 0;
            if (count($linked) > 1) {
                $this->logger->error('registration', 'Prefill refused because one LGL id is linked to several active local records.', [
                    'lgl_constituent' => $lglId,
                    'matches' => count($linked),
                ]);
            }
            $record = $localId ? $this->store->get($localId) : null;
            if ($record && ($record['status'] ?? '') === 'active') {
                $localPrefill = $this->shapeLocal($localId, $record);
                $this->noteVerifiedMatch($localId, 'lgl', $localPrefill, $lglId);
                $out['has_profile'] = true;
                $out['local_id'] = $localId;
                $out['prefill'] = $localPrefill;
            }
        }
        return $out;
    }

    /**
     * Privacy-safe proof for administrators diagnosing prefill. It
     * records the matched ids and whether answer data exists, never an
     * answer value. Throttled per person/source to avoid noisy logs.
     *
     * @param array<string, mixed> $prefill
     */
    private function noteVerifiedMatch(int $localId, string $source, array $prefill, int $lglId = 0): void
    {
        $key = 'bg_pf_match_' . $source . '_' . $localId;
        if (get_transient($key) === '1') return;
        $this->logger->info('registration', 'Verified identity matched to an encrypted local profile for prefill.', [
            'source' => $source,
            'constituent' => $localId,
            'lgl_constituent' => $lglId ?: null,
            'answers_present' => !empty($prefill['demographics'])
                || (string) ($prefill['age_range'] ?? '') !== ''
                || !empty($prefill['relationship']),
        ]);
        set_transient($key, '1', DAY_IN_SECONDS);
    }

    /* ── Local record shaping ─────────────────────────────────────── */

    /** @param array<string, string> $record */
    private function shapeLocal(int $localId, array $record): array
    {
        $answers = $this->personalAnswers($localId);
        return [
            'first_name' => (string) ($record['first_name'] ?? ''),
            'last_name' => (string) ($record['last_name'] ?? ''),
            'email' => (string) ($record['email'] ?? ''),
            'phone' => (string) ($record['phone'] ?? ''),
            'street' => (string) ($record['address1'] ?? ''),
            'city' => (string) ($record['city'] ?? ''),
            'state' => (string) ($record['state'] ?? ''),
            'zip' => (string) ($record['postal'] ?? ''),
            'contact_sharing_allowed' => $this->store->contactSharingAllowed($localId),
            'demographics' => $answers['demographics'],
            'demographics_other' => $answers['demographics_other'],
            'age_range' => $answers['age_range'],
            'relationship' => $answers['relationship'],
        ];
    }

    /**
     * Person answers belong only to the verified constituent id. A shared email or
     * address is contact information, not permission to borrow another
     * person's demographics, age range, or relationship answers.
     * @return array{demographics: array<int, string>, demographics_other: string, age_range: string, relationship: array<int, string>}
     */
    private function personalAnswers(int $localId): array
    {
        return $this->answersFor($localId);
    }

    /** @return array{demographics: array<int, string>, demographics_other: string, age_range: string, relationship: array<int, string>} */
    private function answersFor(int $localId): array
    {
        $native = $this->store->personalAnswersFor($localId);
        $answers = [
            'demographics' => array_values(array_intersect(
                (array) $native['demographics'],
                DemographicFields::options()
            )),
            'demographics_other' => (string) $native['demographics_other'],
            'age_range' => self::parseAge((string) $native['age_range']),
            'relationship' => array_values(array_intersect(
                (array) $native['relationship'],
                SurveyFields::relationshipOptions()
            )),
        ];

        // Read legacy custom-value rows only to fill blanks. Once found,
        // migrate them lazily into the constituent envelope so later
        // reads no longer depend on the secondary table.
        $byKey = [];
        foreach ($this->store->fieldDefinitions() as $def) {
            $byKey[(string) $def->field_key] = (int) $def->id;
        }
        $values = $this->store->valuesFor($localId);
        $raw = static fn(string $key): string => (string) ($values[$byKey[$key] ?? -1] ?? '');
        $legacy = [
            'demographics' => self::parseOptions($raw(self::FIELD_DEMO), DemographicFields::options()),
            'demographics_other' => self::parseOtherDetail($raw(self::FIELD_DEMO)),
            'age_range' => self::parseAge($raw(self::FIELD_AGE)),
            'relationship' => self::parseOptions($raw(self::FIELD_REL), SurveyFields::relationshipOptions()),
        ];
        $merged = self::coalesce($answers, $legacy);
        if ($merged !== $answers) {
            $this->store->setPersonalAnswers($localId, $merged);
        }
        return $merged;
    }

    /* Coalesce */

    /**
     * Field-level merge: $primary wins every conflict, $secondary fills
     * blanks. Applies to contact strings and to person-answer arrays.
     */
    public static function coalesce(array $primary, array $secondary): array
    {
        foreach (['first_name', 'last_name', 'email', 'phone', 'street', 'city', 'state', 'zip', 'age_range', 'demographics_other'] as $key) {
            if ((string) ($primary[$key] ?? '') === '' && (string) ($secondary[$key] ?? '') !== '') {
                $primary[$key] = (string) $secondary[$key];
            }
        }
        foreach (['demographics', 'relationship'] as $key) {
            if (empty($primary[$key]) && !empty($secondary[$key])) {
                $primary[$key] = $secondary[$key];
            }
        }
        return $primary;
    }

    /**
     * Shared writer for registration and profile submissions: a fresh
     * statement overwrites what is on file; an empty submission never
     * erases anything (Tier 1 renders mask values, so an untouched
     * masked panel submits empty and must not wipe the record).
     * @param array<int, string> $demographics
     * @param array<int, string> $relationship
     * @return bool True when every requested encrypted value was stored.
     */
    public static function storePersonalAnswers(
        ConstituentStore $store,
        int $localId,
        array $demographics,
        string $demographicsOther,
        string $ageRange,
        array $relationship,
        array $heard = [],
        string $heardOther = '',
        string $promote = '',
        bool $onlyBlank = false
    ): bool {
        if ($localId < 1 || !$store->available()) return false;
        return $store->setPersonalAnswers($localId, [
            'demographics' => array_values($demographics),
            'demographics_other' => $demographicsOther,
            'age_range' => $ageRange,
            'relationship' => array_values($relationship),
            'heard' => array_values($heard),
            'heard_other' => $heardOther,
            'promote' => $promote,
        ], $onlyBlank);
    }

    /** Drop cached LGL data after a profile edit so the next render reads fresh. */
    public static function invalidate(int $localId, int $lglId): void
    {
        if ($localId > 0) {
            delete_transient('bg_pf_done_' . $localId);
            delete_transient('bg_pf_nolgl_' . $localId);
        }
        if ($lglId > 0) {
            delete_transient('bg_pf_lglr2_' . $lglId);
            delete_transient('bg_pf_don_' . $lglId);
        }
    }

    /* ── Stored-answer parsing ────────────────────────────────────── */

    /**
     * Read a stored answer back into canonical option labels. Values we
     * write ourselves round-trip on the '; ' join; CSV-imported free
     * text is matched longest-option-first with each match excised, so
     * 'Married' can never fire inside 'Long-Term Relationship (Not
     * Married)'. Boundary-guarded, case-insensitive. Output keeps
     * canonical order.
     * @param array<int, string> $options
     * @return array<int, string>
     */
    public static function parseOptions(string $raw, array $options): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $found = [];
        $byLength = $options;
        usort($byLength, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $rest = $raw;
        foreach ($byLength as $opt) {
            $pattern = '/(?<![A-Za-z0-9])' . preg_quote($opt, '/') . '(?![A-Za-z0-9])/i';
            if (preg_match($pattern, $rest, $m, PREG_OFFSET_CAPTURE)) {
                $found[$opt] = true;
                $rest = substr_replace($rest, '', (int) $m[0][1], strlen((string) $m[0][0]));
            }
        }
        return array_values(array_filter($options, static fn(string $o): bool => isset($found[$o])));
    }

    public static function parseAge(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        foreach (SurveyFields::ageRanges() as $opt) {
            if (strcasecmp($raw, $opt) === 0) return $opt;
        }
        $hit = self::parseOptions($raw, SurveyFields::ageRanges());
        // Historical LGL exports sometimes concatenate several people's
        // ages or unrelated field generations. Never guess one range
        // from an ambiguous source string.
        return count($hit) === 1 ? $hit[0] : '';
    }

    public static function parseOtherDetail(string $raw): string
    {
        if (!preg_match('/(?:^|[;,|])\s*Other\s*:\s*([^;,|]+)/i', $raw, $match)) return '';
        return sanitize_text_field(trim((string) ($match[1] ?? '')));
    }

}
