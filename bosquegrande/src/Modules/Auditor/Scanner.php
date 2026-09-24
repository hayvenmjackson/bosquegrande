<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Auditor;


if (!defined('ABSPATH')) exit;

/**
 * The scanner behind the Site Audit. For each published event it fetches
 * the rendered public page (exactly what a visitor, and any page cache,
 * serves), runs free deterministic checks first (register button present,
 * pointing at the native route, route answering), then hands compact
 * digests to the model in small batches for the fuzzy class of problems
 * no pre-written check can enumerate: links to retired platforms, dates
 * that contradict the listing, placeholder or broken text.
 *
 * Findings are advisory. Nothing is changed anywhere except through the
 * review screen (or the optional, logged, whitelisted auto-scrub).
 */
final class Scanner
{
    public const META = '_bg_audit';

    /** Platforms that should never appear as a registration path. */
    public const RETIRED = ['eventbrite', 'tockify'];

    public function __construct()
    {
    }

    /** Every published event unless a caller explicitly supplies a cap. @return int[] */
    public function targets(int $cap = -1): array
    {
        $q = new \WP_Query(apply_filters('bg_audit_query_args', [
            'post_type'      => 'bg_event',
            'post_status'    => 'publish',
            'posts_per_page' => $cap > 0 ? $cap : -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]));
        return array_map('intval', $q->posts);
    }

    /** Audit one batch of events and store findings on each event. @param int[] $ids */
    public function auditBatch(array $ids, string $runId): void
    {
        $digests = [];
        $free    = [];
        foreach ($ids as $id) {
            $d = $this->digest((int) $id);
            if ($d === null) continue;
            $digests[$id] = $d;
            $free[$id]    = $this->deterministicChecks((int) $id, $d);
        }
        if (!$digests) return;

        $modelFindings = $this->modelPass($digests);

        foreach ($digests as $id => $d) {
            $findings = array_merge($free[$id] ?? [], $modelFindings[$id] ?? []);
            update_post_meta((int) $id, self::META, wp_json_encode([
                'run'      => $runId,
                'time'     => time(),
                'findings' => $findings,
            ]));
        }
    }

    /* ---------------------------------------------------------------
     * Digest: what the visitor actually receives
     * --------------------------------------------------------------- */

    /** @return array{title: string, date: string, url: string, text: string, links: array<int, string>, hosts: array<int, string>, reg_href: string, reg_status: int}|null */
    public function digest(int $id): ?array
    {
        $url = get_permalink($id);
        if (!$url) return null;

        $resp = wp_remote_get($url, ['timeout' => 12]);
        $html = is_wp_error($resp) ? '' : (string) wp_remote_retrieve_body($resp);

        // Collect every link the visitor can click.
        $links = [];
        if ($html !== '' && preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
            $links = array_values(array_unique($m[1]));
        }
        $hosts = [];
        foreach ($links as $l) {
            $h = strtolower((string) parse_url($l, PHP_URL_HOST));
            if ($h !== '') $hosts[] = $h;
        }
        $hosts = array_values(array_unique($hosts));

        // The register button the page actually serves.
        $regHref = '';
        if ($html !== '' && preg_match('/<a\b[^>]*class=["\'][^"\']*bgc-reg-btn[^"\']*["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*bgc-reg-btn/i', $html, $m)) {
            $regHref = $m[1];
        }

        // Does the native route answer?
        $native    = home_url('/bg-register/' . $id . '/');
        $regResp   = wp_remote_get($native, ['timeout' => 12, 'redirection' => 2]);
        $regStatus = is_wp_error($regResp) ? 0 : (int) wp_remote_retrieve_response_code($regResp);

        $text = trim((string) preg_replace('/\s+/', ' ',
            wp_strip_all_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $html))));
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 1500) : substr($text, 0, 1500);

        return [
            'title'      => (string) get_the_title($id),
            'date'       => (string) get_post_meta($id, '_bg_start_date', true),
            'url'        => (string) $url,
            'text'       => $text,
            'links'      => array_slice($links, 0, 40),
            'hosts'      => $hosts,
            'reg_href'   => $regHref,
            'reg_status' => $regStatus,
        ];
    }

    /* ---------------------------------------------------------------
     * Free findings: invariants, no tokens
     * --------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    private function deterministicChecks(int $id, array $d): array
    {
        $out = [];
        $native = (bool) apply_filters('bg_native_registration', true, $id);
        if ($native && $d['reg_href'] === '') {
            $out[] = $this->finding($id, 'missing_register', 'high',
                'No REGISTER button in the served page.', '', 'check');
        } elseif ($native && strpos($d['reg_href'], '/bg-register/') === false) {
            $host = strtolower((string) parse_url($d['reg_href'], PHP_URL_HOST));
            $out[] = $this->finding($id, 'legacy_link', 'high',
                'REGISTER button points at ' . $d['reg_href'], $host, 'check');
        }
        if ($native && $d['reg_status'] !== 200) {
            $out[] = $this->finding($id, 'missing_register', 'high',
                'Registration page answered HTTP ' . $d['reg_status'] . ' instead of 200.', '', 'check');
        }
        $expected = (string) ($d['date'] ?? '');
        $servedDates = $this->servedDates((string) ($d['text'] ?? ''), $expected);
        if ($expected !== '' && $servedDates !== [] && !in_array($expected, $servedDates, true)) {
            $out[] = $this->finding(
                $id,
                'date_contradiction',
                'high',
                'Event record says ' . $expected . ', but the served page shows '
                    . implode(', ', array_slice($servedDates, 0, 3)) . '.',
                '',
                'check'
            );
        }
        return $out;
    }

    /** @return list<string> Canonical full dates explicitly rendered on the page. */
    private function servedDates(string $text, string $expected): array
    {
        $dates = [];
        if (preg_match_all('/\b20\d{2}-\d{2}-\d{2}\b/', $text, $matches)) {
            foreach ($matches[0] as $raw) $dates[] = (string) $raw;
        }
        $year = preg_match('/^(20\d{2})-/', $expected, $m) ? (int) $m[1] : (int) gmdate('Y');
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December'
            . '|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
        if (preg_match_all('/\b(?:' . $months . ')\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*(20\d{2}))?\b/i', $text, $matches)) {
            foreach ($matches[0] as $raw) {
                $clean = (string) preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $raw);
                if (!preg_match('/20\d{2}/', $clean)) $clean .= ' ' . $year;
                try {
                    $dates[] = (new \DateTimeImmutable($clean, wp_timezone()))->format('Y-m-d');
                } catch (\Throwable $e) {
                    // Ignore a date-like fragment that PHP cannot parse.
                }
            }
        }
        return array_values(array_unique($dates));
    }

    private function finding(int $id, string $cat, string $sev, string $evidence, string $domain, string $source): array
    {
        return [
            'category' => $cat,
            'severity' => $sev,
            'evidence' => function_exists('mb_substr') ? mb_substr($evidence, 0, 200) : substr($evidence, 0, 200),
            'domain'   => $domain,
            'note'     => '',
            'source'   => $source,
        ];
    }

    /** @param array<int, array<string, mixed>> $digests @return array<int, array<int, array<string, mixed>>> */
    private function modelPass(array $digests): array
    {
        $out = [];
        foreach ($digests as $id => $digest) {
            foreach ((array) ($digest['hosts'] ?? []) as $host) {
                foreach (self::RETIRED as $retired) {
                    if (stripos((string) $host, $retired) !== false) {
                        $out[$id][] = $this->finding(
                            (int) $id,
                            'legacy_link',
                            'medium',
                            'Served page contains a link to retired platform ' . $host . '.',
                            (string) $host,
                            'content-check'
                        );
                    }
                }
            }
            $text = (string) ($digest['text'] ?? '');
            if (preg_match('/\b(lorem ipsum|coming soon|description goes here|tbd)\b/i', $text, $m)) {
                $out[$id][] = $this->finding(
                    (int) $id,
                    'placeholder_text',
                    'medium',
                    'Served page contains placeholder text: ' . $m[0],
                    '',
                    'content-check'
                );
            }
            if ($text === '') {
                $out[$id][] = $this->finding(
                    (int) $id,
                    'broken_text',
                    'high',
                    'The public event page returned no readable text.',
                    '',
                    'content-check'
                );
            }
        }
        return $out;
    }

}
