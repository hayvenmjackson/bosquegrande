<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

use BosqueGrande\Core\Container;

if (!defined('ABSPATH')) exit;

/**
 * The Duplicate Finder screen. Scan, review, mass merge.
 * Names and emails on this screen are decrypted at render time only,
 * for administrators only; the stored scan holds ids and scores.
 */
final class DedupScreen
{
    public const PAGE = 'bosquegrande-people-dedup';

    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Duplicate Finder', 'Duplicate Finder',
                'manage_options', self::PAGE, [$this, 'render']);
        }, 12);
        add_action('admin_post_bg_pdedup_scan', [$this, 'handleScan']);
        add_action('admin_post_bg_pdedup_merge', [$this, 'handleMerge']);
    }

    public function handleScan(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_pdedup_scan');
        $threshold = max(70, min(99, (int) ($_POST['threshold'] ?? DedupFinder::DEFAULT_THRESHOLD)));
        update_option(DedupFinder::THRESHOLD_OPTION, $threshold, false);
        $counts = $this->finder()->scan();
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'scanned' => $counts['records'],
            'found' => $counts['pairs'],
        ], admin_url('admin.php')));
        exit;
    }

    public function handleMerge(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_pdedup_merge');
        $pairs = [];
        foreach ((array) ($_POST['pairs'] ?? []) as $raw) {
            if (preg_match('/^(\d+):(\d+)$/', (string) $raw, $m)) {
                $pairs[] = [(int) $m[1], (int) $m[2]];
            }
        }
        $counts = $pairs ? $this->finder()->mergePairs($pairs) : ['merged' => 0, 'skipped' => 0];
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'merged' => $counts['merged'],
            'skipped' => $counts['skipped'],
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        $finder = $this->finder();
        $results = $finder->results();
        $threshold = (int) get_option(DedupFinder::THRESHOLD_OPTION, DedupFinder::DEFAULT_THRESHOLD);
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');

        echo '<div class="wrap"><h1>Duplicate Finder</h1>';
        if (isset($_GET['scanned'])) {
            printf('<div class="notice notice-success"><p>Scanned %d records; %d candidate pairs found.</p></div>',
                (int) $_GET['scanned'], (int) $_GET['found']);
        }
        if (isset($_GET['merged'])) {
            printf('<div class="notice notice-success"><p>Merged %d pairs; %d skipped (already merged, no longer active, or a record transfer failed).</p></div>',
                (int) $_GET['merged'], (int) $_GET['skipped']);
        }

        echo '<p style="max-width:760px">The scan compares every active record by shared email and by name similarity '
            . '(Levenshtein distance), with small boosts for a shared phone, postal code, or email name. '
            . 'A shared email, phone, or address is not proof of identity, so every pair starts unchecked. '
            . 'Nothing merges until you check it and press the button. Merging keeps the more complete record, fills its blanks '
            . 'from the other, unions tags, reassigns the timeline, and tombstones the folded record (nothing is deleted).</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0 1.6em">';
        wp_nonce_field('bg_pdedup_scan');
        echo '<input type="hidden" name="action" value="bg_pdedup_scan">';
        echo '<label>Confidence threshold <input type="number" name="threshold" min="70" max="99" value="' . esc_attr((string) $threshold) . '" style="width:70px"></label> ';
        echo '<button class="button button-primary">Scan for duplicates</button>';
        if ($results['at']) {
            echo ' <span style="color:#5A6A61">Last scan: ' . esc_html(gmdate('Y-m-d H:i', (int) $results['at'])) . ' UTC</span>';
        }
        echo '</form>';

        if (!$results['pairs']) {
            if ($results['at']) echo '<p><strong>No candidate pairs.</strong> Your records look clean.</p>';
            echo '</div>';
            return;
        }

        $high = array_filter($results['pairs'], static fn(array $p): bool => $p['score'] >= $threshold);
        $low = array_filter($results['pairs'], static fn(array $p): bool => $p['score'] < $threshold);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bg_pdedup_merge');
        echo '<input type="hidden" name="action" value="bg_pdedup_merge">';

        $this->renderBucket('Higher-confidence suggestions', $high, false, $store);
        $this->renderBucket('Needs your review', $low, false, $store);

        echo '<p><button class="button button-primary">Merge selected</button> '
            . '<span style="color:#5A6A61">The more complete record of each pair is kept automatically.</span></p>';
        echo '</form></div>';
    }

    /** @param array<int, array{a: int, b: int, score: int, reason: string}> $pairs */
    private function renderBucket(string $title, array $pairs, bool $checked, ConstituentStore $store): void
    {
        if (!$pairs) return;
        printf('<h2>%s <span style="color:#5A6A61;font-weight:400">(%d)</span></h2>', esc_html($title), count($pairs));
        echo '<table class="widefat striped" style="max-width:1040px;margin-bottom:1.6em"><thead><tr>'
            . '<th style="width:34px"><input type="checkbox" onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)"></th>'
            . '<th>Record</th><th>Possible duplicate</th><th style="width:70px">Score</th><th>Why</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($pairs as $p) {
            if (++$shown > 200) break;
            $a = $store->get((int) $p['a']);
            $b = $store->get((int) $p['b']);
            if (!$a || !$b || ($a['status'] ?? '') !== 'active' || ($b['status'] ?? '') !== 'active') continue;
            printf(
                '<tr><td><input type="checkbox" name="pairs[]" value="%d:%d"%s></td><td>%s</td><td>%s</td><td><strong>%d</strong></td><td>%s</td></tr>',
                (int) $p['a'], (int) $p['b'], $checked ? ' checked' : '',
                self::person($a), self::person($b), (int) $p['score'], esc_html((string) $p['reason'])
            );
        }
        echo '</tbody></table>';
    }

    /** @param array<string, string> $r */
    private static function person(array $r): string
    {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        return esc_html($name !== '' ? $name : '(no name)')
            . '<br><span style="color:#5A6A61">' . esc_html((string) ($r['email'] ?? '')) . '</span>';
    }

    private function finder(): DedupFinder
    {
        return $this->container->get('constituents.dedup');
    }
}
