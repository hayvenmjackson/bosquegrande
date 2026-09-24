<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

if (!defined('ABSPATH')) exit;

/**
 * Duplicate detection, deterministic and explainable (Levenshtein
 * distance on names). Runs over the decrypted set, which the record cap
 * keeps affordable. Automation never decides a merge; the optional
 * assistant may write a recommendation for ambiguous pairs, and a person
 * confirms.
 *
 * Signals, strongest first: same email, same phone (7+ digits), close
 * name (edit distance <= 2 on names 7+ characters long).
 */
final class Deduper
{
    private const MAX_PAIRS = 200;

    /**
     * @param list<array<string, string>> $records Decrypted, each with id/first_name/last_name/email/phone.
     * @return list<array{a: int, b: int, reason: string}>
     */
    public function scan(array $records): array
    {
        $pairs = [];
        $seen = [];
        usort($records, static fn(array $a, array $b): int => strcmp(
            ConstituentStore::normName($a['first_name'] ?? '', $a['last_name'] ?? ''),
            ConstituentStore::normName($b['first_name'] ?? '', $b['last_name'] ?? '')
        ));
        $groups = [];
        foreach ($records as $i => $record) {
            $email = ConstituentStore::normEmail((string) ($record['email'] ?? ''));
            $phone = ConstituentStore::normPhone((string) ($record['phone'] ?? ''));
            if ($email !== '') $groups['e:' . $email][] = $i;
            if ($phone !== '') $groups['p:' . $phone][] = $i;
        }
        $candidates = [];
        foreach ($groups as $members) {
            $base = array_shift($members);
            foreach ($members as $member) $candidates[] = [$base, $member];
        }
        $n = count($records);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < min($n, $i + 12); $j++) {
                $candidates[] = [$i, $j];
            }
        }
        foreach ($candidates as [$i, $j]) {
            $reason = $this->compare($records[$i], $records[$j]);
            if ($reason === '') continue;
            $a = (int) $records[$i]['id'];
            $b = (int) $records[$j]['id'];
            $key = min($a, $b) . ':' . max($a, $b);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $pairs[] = ['a' => $a, 'b' => $b, 'reason' => $reason];
            if (count($pairs) >= self::MAX_PAIRS) return $pairs;
        }
        return $pairs;
    }

    /** @param array<string, string> $x @param array<string, string> $y */
    private function compare(array $x, array $y): string
    {
        $emailX = ConstituentStore::normEmail($x['email'] ?? '');
        $emailY = ConstituentStore::normEmail($y['email'] ?? '');
        if ($emailX !== '' && $emailX === $emailY) return 'same email';

        $phoneX = ConstituentStore::normPhone($x['phone'] ?? '');
        $phoneY = ConstituentStore::normPhone($y['phone'] ?? '');
        if ($phoneX !== '' && $phoneX === $phoneY) return 'same phone';

        $nameX = ConstituentStore::normName($x['first_name'] ?? '', $x['last_name'] ?? '');
        $nameY = ConstituentStore::normName($y['first_name'] ?? '', $y['last_name'] ?? '');
        if (strlen($nameX) >= 7 && strlen($nameY) >= 7) {
            if ($nameX === $nameY) return 'same name';
            if (abs(strlen($nameX) - strlen($nameY)) <= 2 && levenshtein($nameX, $nameY) <= 2) {
                return 'similar name';
            }
        }
        return '';
    }
}
