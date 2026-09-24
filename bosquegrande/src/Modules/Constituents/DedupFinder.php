<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * The duplicate finder. Scans the whole store for likely
 * duplicate people using exact email identity plus Levenshtein name
 * similarity, scores every candidate pair, and supports MASS merge so
 * nobody clicks a merge button four hundred times.
 *
 * Design rules:
 * - The scan persists ONLY ids, scores, and reasons. Names and emails
 *   are re-decrypted at render time, so no plaintext personal data
 *   ever rests in an option row (the store's standing promise).
 * - Nothing merges without a human checking each pair and pressing the
 *   button. A shared contact value can belong to spouses or housemates.
 * - Merging reuses ConstituentStore::merge exactly as the profile
 *   screen always has: the more complete record wins, blanks fill,
 *   tags union, the timeline is reassigned, and the folded record is
 *   tombstoned, never deleted.
 * - Pair chains (A~B, B~C) are safe: each merge re-checks that both
 *   records are still active and skips consumed ones.
 */
final class DedupFinder
{
    public const OPTION = 'bg_dupe_scan';
    public const THRESHOLD_OPTION = 'bg_dupe_threshold';
    public const DEFAULT_THRESHOLD = 88;
    private const MIN_SCORE = 70;
    private const MAX_PAIRS = 2000;

    public function __construct(
        private ConstituentStore $store,
        private Logger $logger,
    ) {
    }

    /** Run a full scan and persist the candidate list. @return array{pairs: int, records: int} */
    public function scan(): array
    {
        $records = $this->store->available() ? $this->store->allDecrypted() : [];
        $pairs = [];
        $seen = [];

        // Blocking keys keep this far from n-squared: exact email,
        // metaphone of the last name, and first-name initial + postal.
        $buckets = [];
        foreach ($records as $i => $r) {
            $keys = [];
            $email = ConstituentStore::normEmail((string) ($r['email'] ?? ''));
            if ($email !== '') $keys[] = 'e:' . $email;
            $last = strtolower(trim((string) ($r['last_name'] ?? '')));
            if ($last !== '') $keys[] = 'm:' . metaphone($last);
            $first = strtolower(trim((string) ($r['first_name'] ?? '')));
            $postal = preg_replace('/\s+/', '', (string) ($r['postal'] ?? ''));
            if ($first !== '' && $postal !== '') $keys[] = 'p:' . $first[0] . ':' . $postal;
            foreach (array_unique($keys) as $k) $buckets[$k][] = $i;
        }

        foreach ($buckets as $bucketKey => $members) {
            $n = count($members);
            if ($n < 2) continue;
            $candidatePairs = [];
            if ($n > 60 && str_starts_with((string) $bucketKey, 'e:')) {
                for ($x = 1; $x < $n; $x++) $candidatePairs[] = [0, $x];
            } elseif ($n > 60) {
                usort($members, function (int $a, int $b) use ($records): int {
                    return strcmp(
                        ConstituentStore::normName($records[$a]['first_name'] ?? '', $records[$a]['last_name'] ?? ''),
                        ConstituentStore::normName($records[$b]['first_name'] ?? '', $records[$b]['last_name'] ?? '')
                    );
                });
                for ($x = 0; $x < $n; $x++) {
                    for ($y = $x + 1; $y < min($n, $x + 12); $y++) $candidatePairs[] = [$x, $y];
                }
            } else {
                for ($x = 0; $x < $n; $x++) {
                    for ($y = $x + 1; $y < $n; $y++) $candidatePairs[] = [$x, $y];
                }
            }
            foreach ($candidatePairs as [$x, $y]) {
                    $a = $records[$members[$x]];
                    $b = $records[$members[$y]];
                    $ka = min((int) $a['id'], (int) $b['id']);
                    $kb = max((int) $a['id'], (int) $b['id']);
                    if (isset($seen["$ka:$kb"])) continue;
                    $seen["$ka:$kb"] = true;
                    [$score, $reason] = self::score($a, $b);
                    if ($score >= self::MIN_SCORE) {
                        $pairs[] = ['a' => $ka, 'b' => $kb, 'score' => $score, 'reason' => $reason];
                    }
            }
        }

        usort($pairs, static fn(array $p, array $q): int => $q['score'] <=> $p['score']);
        $pairs = array_slice($pairs, 0, self::MAX_PAIRS);
        update_option(self::OPTION, ['at' => time(), 'records' => count($records), 'pairs' => $pairs], false);
        $this->logger->info('constituents', 'Duplicate scan finished.', [
            'records' => count($records), 'candidate_pairs' => count($pairs),
        ]);
        return ['pairs' => count($pairs), 'records' => count($records)];
    }

    /**
     * Score one pair. Pure and static so it can be harness-tested.
     * @param array<string, string> $a @param array<string, string> $b
     * @return array{0: int, 1: string}
     */
    public static function score(array $a, array $b): array
    {
        $ea = ConstituentStore::normEmail((string) ($a['email'] ?? ''));
        $eb = ConstituentStore::normEmail((string) ($b['email'] ?? ''));
        if ($ea !== '' && $ea === $eb) {
            return [100, 'Identical email'];
        }

        $na = strtolower(trim(trim((string) ($a['first_name'] ?? '')) . ' ' . trim((string) ($a['last_name'] ?? ''))));
        $nb = strtolower(trim(trim((string) ($b['first_name'] ?? '')) . ' ' . trim((string) ($b['last_name'] ?? ''))));
        if (strlen($na) < 5 || strlen($nb) < 5) return [0, ''];

        $max = max(strlen($na), strlen($nb));
        $sim = (int) round(100 * (1 - levenshtein(substr($na, 0, 200), substr($nb, 0, 200)) / $max));
        if ($sim < 60) return [0, ''];

        $why = ["Names {$sim}% similar"];
        $score = $sim;

        $pa = preg_replace('/\D+/', '', (string) ($a['phone'] ?? ''));
        $pb = preg_replace('/\D+/', '', (string) ($b['phone'] ?? ''));
        if (strlen($pa) >= 7 && $pa === $pb) { $score += 8; $why[] = 'same phone'; }

        $za = preg_replace('/\s+/', '', (string) ($a['postal'] ?? ''));
        $zb = preg_replace('/\s+/', '', (string) ($b['postal'] ?? ''));
        if ($za !== '' && strcasecmp($za, $zb) === 0) { $score += 4; $why[] = 'same postal code'; }

        $la = $ea !== '' ? explode('@', $ea)[0] : '';
        $lb = $eb !== '' ? explode('@', $eb)[0] : '';
        if ($la !== '' && $la === $lb) { $score += 6; $why[] = 'same email name, different domain'; }

        return [min(99, $score), implode('; ', $why)];
    }

    /** @return array{at: int, records: int, pairs: list<array{a: int, b: int, score: int, reason: string}>} */
    public function results(): array
    {
        $r = get_option(self::OPTION, []);
        return is_array($r) && isset($r['pairs'])
            ? $r
            : ['at' => 0, 'records' => 0, 'pairs' => []];
    }

    /**
     * Mass merge. For each pair the PRIMARY is chosen here, by
     * completeness, regardless of submitted order; chains and repeats
     * skip safely because merge() refuses non-active records.
     * @param list<array{0: int, 1: int}> $pairs
     * @return array{merged: int, skipped: int}
     */
    public function mergePairs(array $pairs): array
    {
        $merged = 0;
        $skipped = 0;
        foreach ($pairs as [$x, $y]) {
            $a = $this->store->get((int) $x);
            $b = $this->store->get((int) $y);
            if (!$a || !$b || ($a['status'] ?? '') !== 'active' || ($b['status'] ?? '') !== 'active') {
                $skipped++;
                continue;
            }
            $primary = self::completeness($a) >= self::completeness($b) ? (int) $x : (int) $y;
            $secondary = $primary === (int) $x ? (int) $y : (int) $x;
            $this->store->merge($primary, $secondary, true) ? $merged++ : $skipped++;
        }
        if ($merged > 0) {
            // Consumed pairs drop out of the stored scan.
            $r = $this->results();
            $r['pairs'] = array_values(array_filter($r['pairs'], function (array $p): bool {
                $a = $this->store->get((int) $p['a']);
                $b = $this->store->get((int) $p['b']);
                return $a && $b && ($a['status'] ?? '') === 'active' && ($b['status'] ?? '') === 'active';
            }));
            update_option(self::OPTION, $r, false);
            $this->logger->info('constituents', 'Mass merge finished.', ['merged' => $merged, 'skipped' => $skipped]);
        }
        return ['merged' => $merged, 'skipped' => $skipped];
    }

    /** @param array<string, string> $r */
    private static function completeness(array $r): int
    {
        $score = 0;
        foreach (ConstituentStore::FIELDS as $key) {
            if (($r[$key] ?? '') !== '') $score++;
        }
        if ((int) ($r['lgl_constituent_id'] ?? 0) > 0) $score++;
        return $score;
    }
}
