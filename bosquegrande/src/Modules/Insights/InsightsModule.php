<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Insights;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\Intelligence\IntelligenceModule;

if (!defined('ABSPATH')) exit;

/**
 * Insights: the numbers screen. Twelve months of growth, money, and
 * activity computed live from the tables that already exist (no new
 * schema, no snapshots to drift). Every figure is deterministic; the
 * one optional assisted automation touch is a month-in-review paragraph written from
 * these aggregates and nothing else.
 */
final class InsightsModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-insights';

    public function name(): string
    {
        return 'insights';
    }

    public function label(): string
    {
        return 'Insights (growth, money, activity)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_filter('bg_ai_features', function (array $features): array {
            $features['insights_narrative'] = [
                'label' => 'Month in review',
                'description' => 'A short written recap on the Insights screen, produced from the aggregate numbers shown there and nothing else.',
            ];
            return $features;
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Insights', 'Insights',
                'manage_options', self::PAGE, [$this, 'renderPage']);
        }, 11);

        add_action('admin_post_bg_insights_recap', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_insights_recap');
            $settings = $container->get('settings');
            if (IntelligenceModule::featureEnabled($settings, 'insights_narrative')) {
                $text = trim($container->get('ai')->complete(
                    'You write a short month-in-review for a small organization\'s staff from aggregate '
                    . 'numbers. One paragraph, plain and warm, no invented causes, no advice unless a number '
                    . 'clearly calls for it, never the word "kindly".',
                    self::digest(),
                    ['max_tokens' => 300]
                ));
                if ($text !== '') {
                    set_transient('bg_insights_recap', $text, DAY_IN_SECONDS);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
            exit;
        });
    }

    /* ── Aggregates ───────────────────────────────────────────────── */

    /** @return list<string> The last N month keys, oldest first. */
    public static function months(int $n = 12): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = gmdate('Y-m', strtotime(gmdate('Y-m-01') . " -{$i} months"));
        }
        return $out;
    }

    /** @return array<string, int> month => count of new active constituents. */
    public static function constituentsByMonth(): array
    {
        global $wpdb;
        return self::seriesFrom($wpdb->get_results(
             "SELECT SUBSTR(created_at, 1, 7) AS ym, COUNT(*) AS n
             FROM {$wpdb->prefix}bg_constituents WHERE status = 'active'
             GROUP BY SUBSTR(created_at, 1, 7)"
        ));
    }

    /** @return array<string, int> month => net revenue cents. */
    public static function revenueByMonth(): array
    {
        global $wpdb;
        return self::seriesFrom($wpdb->get_results(
            "SELECT SUBSTR(created_at, 1, 7) AS ym,
                    SUM(CASE WHEN kind = 'refund' THEN -amount_cents ELSE amount_cents END) AS n
             FROM {$wpdb->prefix}bg_payments
             GROUP BY SUBSTR(created_at, 1, 7)"
        ));
    }

    /** @return array<string, int> month => registrations created. */
    public static function registrationsByMonth(): array
    {
        global $wpdb;
        return self::seriesFrom($wpdb->get_results(
             "SELECT SUBSTR(created_at, 1, 7) AS ym, COUNT(*) AS n
             FROM {$wpdb->prefix}bg_registrations
             WHERE status IN ('confirmed', 'paid')
             GROUP BY SUBSTR(created_at, 1, 7)"
        ));
    }

    /** @return array<string, int> group name => active subscribers now. */
    public static function subscribersNow(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT g.name, COUNT(s.id) AS n FROM {$wpdb->prefix}bg_groups g
             LEFT JOIN {$wpdb->prefix}bg_subscriptions s ON s.group_id = g.id AND s.status = 'active'
             GROUP BY g.id, g.name ORDER BY g.name ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->name] = (int) $row->n;
        }
        return $out;
    }

    /** The aggregate digest the recap is written from. Numbers only. */
    public static function digest(): string
    {
        $months = self::months(2);
        $thisMonth = $months[1];
        $lastMonth = $months[0];
        $c = self::constituentsByMonth();
        $r = self::revenueByMonth();
        $g = self::registrationsByMonth();
        $subs = self::subscribersNow();
        $lines = [
            'Month: ' . $thisMonth . ' (compared with ' . $lastMonth . ')',
            sprintf('New constituent records: %d this month, %d last month.',
                $c[$thisMonth] ?? 0, $c[$lastMonth] ?? 0),
            sprintf('Net revenue: %s this month, %s last month.',
                \BosqueGrande\Core\Money::format((int) ($r[$thisMonth] ?? 0)), \BosqueGrande\Core\Money::format((int) ($r[$lastMonth] ?? 0))),
            sprintf('Event registrations: %d this month, %d last month.',
                $g[$thisMonth] ?? 0, $g[$lastMonth] ?? 0),
        ];
        if ($subs) {
            $parts = [];
            foreach ($subs as $name => $n) {
                $parts[] = $name . ': ' . $n;
            }
            $lines[] = 'Newsletter subscribers now: ' . implode('; ', $parts) . '.';
        }
        return implode("\n", $lines);
    }

    /** @param list<object>|null $rows @return array<string, int> */
    private static function seriesFrom(?array $rows): array
    {
        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(string) $row->ym] = (int) $row->n;
        }
        return $out;
    }

    /* ── Screen ───────────────────────────────────────────────────── */

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        $settings = $container->get('settings');
        $months = self::months(12);
        $constituents = self::constituentsByMonth();
        $revenue = self::revenueByMonth();
        $registrations = self::registrationsByMonth();
        $subs = self::subscribersNow();
        $recap = get_transient('bg_insights_recap');
        $noun = \BosqueGrande\Modules\Revenue\RevenueModule::noun();
        ?>
        <div class="wrap"><h1>Insights</h1>
        <p style="max-width:760px">The last twelve months, computed live from the ledger, the constituent
        store, and the registration table. Every number here is countable in its source screen.</p>

        <?php if (IntelligenceModule::featureEnabled($settings, 'insights_narrative')): ?>
            <?php if (is_string($recap) && $recap !== ''): ?>
                <p style="max-width:760px;background:#F3F8F3;border:1px solid #D9E5D9;border-radius:8px;padding:12px"><?php echo esc_html($recap); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px">
                <?php wp_nonce_field('bg_insights_recap'); ?>
                <input type="hidden" name="action" value="bg_insights_recap">
                <button class="button"><?php echo $recap ? 'Rewrite the month in review' : 'Write the month in review'; ?></button>
            </form>
        <?php endif; ?>

        <?php
        $this->series('New constituent records', $months, $constituents, static fn(int $n) => (string) $n);
        $this->series('Net ' . strtolower($noun) . ' and fees', $months, $revenue,
            static fn(int $n) => \BosqueGrande\Core\Money::format($n, 0));
        $this->series('Event registrations', $months, $registrations, static fn(int $n) => (string) $n);
        ?>

        <h2>Newsletter subscribers now</h2>
        <table class="widefat striped" style="max-width:520px">
            <thead><tr><th>Group</th><th>Active</th></tr></thead>
            <tbody>
            <?php if (!$subs) echo '<tr><td colspan="2">No groups yet.</td></tr>';
            foreach ($subs as $name => $n) {
                printf('<tr><td>%s</td><td>%d</td></tr>', esc_html($name), $n);
            } ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /** One labeled bar series rendered as widths, no JS. @param array<string, int> $data */
    private function series(string $title, array $months, array $data, callable $format): void
    {
        $max = 1;
        foreach ($months as $m) {
            $max = max($max, abs($data[$m] ?? 0));
        }
        echo '<h2>' . esc_html($title) . '</h2><table style="max-width:760px;width:100%;border-collapse:collapse">';
        foreach ($months as $m) {
            $n = $data[$m] ?? 0;
            $pct = (int) round(abs($n) / $max * 100);
            printf('<tr><td style="width:70px;padding:2px 8px 2px 0;color:#555">%s</td>'
                . '<td><div style="background:%s;height:16px;width:%d%%;min-width:2px;border-radius:3px"></div></td>'
                . '<td style="width:90px;padding:2px 0 2px 8px;text-align:right">%s</td></tr>',
                esc_html($m), $n < 0 ? '#B3574D' : '#2E7D4F', $pct, esc_html($format($n)));
        }
        echo '</table>';
    }
}
