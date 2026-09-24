<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Migrator;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * Event Adoption. The scan-and-adopt screen for stray event
 * posts left behind by earlier calendar plugins, plus the reversible
 * placeholder-date rescue for dateless drafts. Eventbrite events come
 * in through Data Transfer instead.
 */
final class MigratorModule implements ModuleInterface
{
    private const STATE = 'bg_migrator_state';   // Legacy import state; cleaned on deactivate.
    private const REVIEW = 'bg_migrator_review'; // Legacy review queue; cleaned on deactivate.

    public function name(): string
    {
        return 'migrator';
    }

    public function label(): string
    {
        return 'Event Adoption';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        if (is_admin()) {
            add_action('admin_menu', function () {
                add_submenu_page('bosquegrande', 'Adopt Events', 'Adopt Events',
                    'manage_options', 'bosquegrande-adopt', [$this, 'renderAdoptPage']);
            }, 40);
            add_action('admin_post_bg_event_adopt', [$this, 'runAdopt']);
            add_action('admin_post_bg_placeholder_dates', [$this, 'runPlaceholderDates']);
            add_action('admin_post_bg_placeholder_undo', [$this, 'runPlaceholderUndo']);
        }
    }

    public function deactivate(Container $container): void
    {
        delete_option(self::STATE);
        delete_option(self::REVIEW);
    }

    /* ── Adopt Events ────────────────────────────────────── */

    /**
     * Scan-and-adopt screen for stray event posts from earlier calendar
     * plugins (legacy mec-events archives above all). The scan is
     * read-only; nothing changes until the admin clicks Adopt.
     */
    public function renderAdoptPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $adopter = new EventAdopter();
        $strays  = $adopter->discover();
        $notice  = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        ?>
        <div class="wrap">
            <h1>Adopt Events</h1>
            <?php if ($notice): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <p style="max-width:720px">
                This tool finds event posts left behind by earlier calendar plugins
                and adopts them into the BosqueGrande calendar. Adoption converts each
                post in place: same post, same content, same photos, now a
                first-class calendar event. Strays with a detectable date are
                published with that date; strays with no date are adopted as drafts
                so they never disturb the calendar. Each adoption records where the
                post came from, so it can be reversed.
            </p>
            <?php $this->renderDatelessBox(); ?>
            <?php if (!$strays): ?>
                <p><strong>No stray event posts found.</strong> Everything the scan
                recognizes is already part of the calendar.</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bg_event_adopt'); ?>
                    <input type="hidden" name="action" value="bg_event_adopt">
                    <table class="widefat striped" style="max-width:980px">
                        <thead><tr>
                            <th style="width:28px"><input type="checkbox" checked
                                onchange="var s=this.checked;document.querySelectorAll('.bg-adopt-cb').forEach(function(c){c.checked=s;});"></th>
                            <th>Title</th><th>Source type</th><th>Status</th>
                            <th>Detected date</th><th>Time</th><th>Will become</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($strays as $s): ?>
                            <tr>
                                <td><input type="checkbox" class="bg-adopt-cb" name="ids[]"
                                    value="<?php echo (int) $s['id']; ?>" checked></td>
                                <td><?php echo esc_html($s['title'] !== '' ? $s['title'] : '(no title)'); ?>
                                    <span style="color:#787c82"> #<?php echo (int) $s['id']; ?></span></td>
                                <td><code><?php echo esc_html($s['type']); ?></code></td>
                                <td><?php echo esc_html($s['status']); ?></td>
                                <td><?php echo $s['date'] !== '' ? esc_html($s['date']) : '<span style="color:#b32d2e">none found</span>'; ?></td>
                                <td><?php echo esc_html($s['time']); ?></td>
                                <td><?php echo $s['date'] !== ''
                                    ? '<span style="color:#1F5130">Published event</span>'
                                    : 'Draft event (add a date, then publish)'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary button-hero"
                            onclick="return confirm('Adopt the selected posts into the BosqueGrande calendar? This converts them in place.');">
                            Adopt <?php echo count($strays); ?> selected event<?php echo count($strays) === 1 ? '' : 's'; ?>
                        </button>
                    </p>
                </form>
                <p style="color:#787c82;max-width:720px">
                    Past events show on the calendar month view by browsing to their
                    month, and on the pinboard and list views via the new Past toggle.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Dateless drafts rescue ─────────────────────────── */

    /** Drafts with no start date cannot render on the calendar. */
    private function datelessDrafts(): array
    {
        return get_posts([
            'post_type'      => 'bg_event',
            'post_status'    => 'draft',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_bg_start_date', 'compare' => 'NOT EXISTS'],
                ['key' => '_bg_start_date', 'value' => '', 'compare' => '='],
            ],
        ]);
    }

    /** Events currently published on a placeholder date. */
    private function placeholderEvents(): array
    {
        return get_posts([
            'post_type'      => 'bg_event',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_bg_placeholder_date', 'compare' => 'EXISTS']],
        ]);
    }

    private function renderDatelessBox(): void
    {
        $dateless     = $this->datelessDrafts();
        $placeholders = $this->placeholderEvents();
        $saturday     = date('Y-m-d', strtotime('next saturday'));
        if (!$dateless && !$placeholders) return;
        ?>
        <div class="card" style="max-width:980px;padding:12px 20px;margin-bottom:16px">
            <h2>Dateless drafts</h2>
            <?php if ($dateless): ?>
                <p style="max-width:720px">
                    <strong><?php echo count($dateless); ?> event(s)</strong> are held as
                    drafts because their source records carry no date, so the calendar
                    cannot place them. You can publish them now on a clearly marked
                    placeholder date (<strong><?php echo esc_html(date_i18n('l, F j, Y', strtotime($saturday))); ?></strong>)
                    for testing. Each one is stamped, listed below afterward, and fully
                    reversible with one click. Real dates entered later (by hand or by a
                    future source update) automatically replace the placeholder and are
                    never overwritten by it.
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
                    <?php wp_nonce_field('bg_placeholder_dates'); ?>
                    <input type="hidden" name="action" value="bg_placeholder_dates">
                    <button type="submit" class="button button-primary"
                        onclick="return confirm('Publish <?php echo count($dateless); ?> dateless draft(s) with the placeholder date <?php echo esc_js($saturday); ?>? These dates will be publicly visible until undone or corrected.');">
                        Publish <?php echo count($dateless); ?> with placeholder date
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($placeholders): ?>
                <p style="max-width:720px;margin-top:12px">
                    <strong><?php echo count($placeholders); ?> event(s)</strong> currently
                    carry a placeholder date. Undo returns them to dateless drafts
                    (events whose date was hand-corrected since keep their real date
                    and just lose the placeholder stamp).
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                    <?php wp_nonce_field('bg_placeholder_undo'); ?>
                    <input type="hidden" name="action" value="bg_placeholder_undo">
                    <button type="submit" class="button">Undo all placeholder dates</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Publish dateless drafts on next Saturday, stamped and reversible. */
    public function runPlaceholderDates(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_placeholder_dates');

        $saturday = date('Y-m-d', strtotime('next saturday'));
        $done = 0;
        foreach ($this->datelessDrafts() as $pid) {
            update_post_meta($pid, '_bg_start_date', $saturday);
            update_post_meta($pid, '_bg_placeholder_date', $saturday);
            wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
            $done++;
        }
        \BosqueGrande\Core\Plugin::instance()->container()->get('logger')
            ->info('migrator', 'Placeholder dates applied.', ['count' => $done, 'date' => $saturday]);
        $this->backToAdopt(sprintf('Published %d event(s) with placeholder date %s. Undo is available on this page.', $done, $saturday));
    }

    /** Revert placeholder-dated events to dateless drafts. */
    public function runPlaceholderUndo(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_placeholder_undo');

        $reverted = 0; $kept = 0;
        foreach ($this->placeholderEvents() as $pid) {
            $stamp   = (string) get_post_meta($pid, '_bg_placeholder_date', true);
            $current = (string) get_post_meta($pid, '_bg_start_date', true);
            if ($current === $stamp) {
                // Still the placeholder: clear it and re-draft.
                delete_post_meta($pid, '_bg_start_date');
                wp_update_post(['ID' => $pid, 'post_status' => 'draft']);
                $reverted++;
            } else {
                // The date was corrected since; keep it, just drop the stamp.
                $kept++;
            }
            delete_post_meta($pid, '_bg_placeholder_date');
        }
        \BosqueGrande\Core\Plugin::instance()->container()->get('logger')
            ->info('migrator', 'Placeholder dates undone.', ['reverted' => $reverted, 'kept_real_date' => $kept]);
        $this->backToAdopt(sprintf('Reverted %d event(s) to dateless drafts; %d kept their corrected real date.', $reverted, $kept));
    }

    private function backToAdopt(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-adopt', 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }

    /** Form handler: adopt the selected strays, then bounce back with counts. */
    public function runAdopt(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_event_adopt');

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $adopter = new EventAdopter();
        $res = $adopter->adopt($ids);

        /** @var Logger $logger */
        $logger = \BosqueGrande\Core\Plugin::instance()->container()->get('logger');
        $logger->info('migrator', 'Event adoption run.', $res);

        $notice = sprintf(
            'Adopted %d event(s): %d published with dates, %d saved as dateless drafts, %d skipped.',
            $res['adopted'], $res['published'], $res['drafted'], $res['skipped']
        );
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-adopt', 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }
}
