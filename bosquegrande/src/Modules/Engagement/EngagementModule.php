<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * Follow-up email automation, participation history, longitudinal
 * reporting, the organizer identity bridge, and the read-only accounting
 * area for the payment-detail capability.
 */
final class EngagementModule implements ModuleInterface
{
    public function name(): string
    {
        return 'engagement';
    }

    public function label(): string
    {
        return 'Follow-ups, history, and accounting views';
    }

    public function activate(Container $container): void
    {
        PaymentDetails::ensureCapabilities();
    }

    public function deactivate(Container $container): void
    {
        wp_clear_scheduled_hook(FollowupAutomation::CRON);
    }

    public function boot(Container $container): void
    {
        $container->set('engagement.events', fn() => new EventHistory());
        $container->set('engagement.history', fn(Container $c) => new HistoricalParticipation(
            $c->get('constituents.store'),
            $c->get('engagement.events')
        ));
        $container->set('engagement.longitudinal', fn(Container $c) => new LongitudinalReport(
            $c->get('constituents.store'),
            $c->get('engagement.history')
        ));
        $container->set('engagement.followups', fn(Container $c) => new FollowupAutomation($c, $c->get('engagement.history')));
        $container->set('engagement.organizer_identity', fn(Container $c) => new OrganizerIdentityBridge(
            $c->get('identity.session'),
            $c->get('constituents.store'),
            $c->get('organizers.repository')
        ));
        $container->set('engagement.payment_details', fn(Container $c) => new PaymentDetails($c->get('constituents.store')));
        $monthlyDonationsPage = new MonthlyDonationsPage($container);

        PaymentDetails::ensureCapabilities();
        $container->get('engagement.followups')->register();

        /** @var OrganizerIdentityBridge $identityBridge */
        $identityBridge = $container->get('engagement.organizer_identity');
        add_filter('bg_organizer_try_constituent_auth', static function (bool $authenticated) use ($identityBridge): bool {
            return $authenticated || $identityBridge->tryAuthenticate();
        }, 10, 1);
        add_action('init', static function () use ($identityBridge): void {
            if (is_user_logged_in()) $identityBridge->ensureIdentitySessionForCurrentUser();
        }, 45);

        add_filter('bg_ai_features', static function (array $features): array {
            $features['event_tagging'] = [
                'label' => 'Event classification',
                'description' => 'When the tagging rules leave an event without a region or interest, ask your provider to choose from your event vocabulary. Only the event title, description, and location are sent; constituent data never is.',
            ];
            return $features;
        });

        add_action('admin_menu', function () use ($monthlyDonationsPage): void {
            add_submenu_page('bosquegrande', 'Event Retagging', 'Event Retagging', 'manage_options', 'bosquegrande-history-tools', [$this, 'renderTools']);
            // Accountants intentionally do not receive edit_posts or broad
            // BosqueGrande administration. This dedicated top-level entry
            // exposes only the four read-only money views guarded by the
            // purpose-built payment-detail capability.
            add_menu_page(
                'BosqueGrande Accounting',
                'Accounting',
                PaymentDetails::CAP,
                'bosquegrande-accounting',
                [$this, 'renderAccounting'],
                'dashicons-chart-area',
                59
            );
            add_submenu_page(
                'bosquegrande-accounting',
                'Monthly Donations',
                'Monthly Donations',
                PaymentDetails::CAP,
                MonthlyDonationsPage::PAGE,
                [$monthlyDonationsPage, 'render']
            );
        }, 49);
        add_action('admin_post_bg_reconcile_monthly_donations', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_reconcile_monthly_donations');
            try {
                $result = $container->get('monthly_donation.enrollment')->reconcileRecentPayments(120, 300);
                $message = is_array($result)
                    ? (string) ($result['message'] ?? 'Monthly-donation reconciliation finished.')
                    : 'Monthly-donation reconciliation finished.';
                $noticeType = is_array($result) && empty($result['ok']) ? 'error' : 'success';
            } catch (\Throwable $e) {
                $container->get('logger')->error('payments', 'Monthly-donation reconciliation failed unexpectedly.', [
                    'error' => $e->getMessage(),
                ]);
                $message = 'Monthly-donation reconciliation could not finish. Review the Activity Log before trying again.';
                $noticeType = 'error';
            }
            wp_safe_redirect(add_query_arg([
                'page' => MonthlyDonationsPage::PAGE,
                'bg_notice' => $message,
                'bg_notice_type' => $noticeType,
            ], admin_url('admin.php')));
            exit;
        });
        add_action('admin_post_bg_history_retag', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_history_retag');
            $result = $container->get('engagement.events')->retagAll();
            $this->toolsRedirect('Ran the tagging rules over ' . (int) $result['scanned'] . ' events: '
                . (int) $result['tagged'] . ' tagged, ' . (int) $result['untagged'] . ' left untagged.');
        });
        add_action('admin_post_bg_history_assistant_tag', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_history_assistant_tag');
            $result = $container->get('engagement.events')->assistantTagBatch(25);
            $this->toolsRedirect('Asked the assistant about ' . (int) $result['attempted'] . ' unresolved event(s); '
                . (int) $result['tagged'] . ' received tags.');
        });
        add_action('admin_post_bg_history_assistant_reset', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_history_assistant_reset');
            $cleared = $container->get('engagement.events')->clearAssistantAttempts();
            $this->toolsRedirect('Cleared ' . (int) $cleared . ' retry marker(s). Those events can be sent to the assistant again.');
        });

        add_action('bg_constituent_profile', [$this, 'renderOrganizerHistory'], 25, 1);
    }

    public function renderTools(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) wp_unslash($_GET['bg_notice'])) : '';
        $form = static function (string $action, string $label, string $class = 'button'): void {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 10px 8px 0">';
            wp_nonce_field($action);
            echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
        };
        ?>
        <div class="wrap"><h1>Event Retagging</h1>
        <?php if ($notice !== '') : ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:900px">Run the <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-tagging')); ?>">tagging rules</a>
        over every current and imported historical event, for example after changing your vocabulary. Tags added by staff are never removed.
        The assistant option sends only an event's title, description, and location, for up to 25 still-unresolved events at a time,
        and can only choose from your vocabulary.</p>
        <p><strong>Last rules run:</strong> <?php echo esc_html((string) get_option('bg_history_last_retag', 'never')); ?><br>
        <strong>Last assistant batch:</strong> <?php echo esc_html((string) get_option('bg_history_last_assistant_retag', 'never')); ?></p>
        <?php
        $form('bg_history_retag', 'Run the tagging rules now', 'button button-primary');
        $form('bg_history_assistant_tag', 'Ask the assistant about the next 25');
        $form('bg_history_assistant_reset', 'Clear assistant retry markers', 'button button-link');
        ?>
        </div>
        <?php
    }

    public function renderAccounting(): void
    {
        if (!current_user_can(PaymentDetails::CAP)) return;
        $items = [
            ['Revenue and ledger', 'Review individual ledger transactions and expand donor/contact and event details.', 'bosquegrande-revenue'],
            ['Monthly Donations', 'Review paid monthly Stripe invoices, refunds, and QuickBooks status by month.', MonthlyDonationsPage::PAGE],
            ['Event Statistics', 'Review event registrations, dollar amounts, and accountant details.', 'bosquegrande-stats'],
            ['Event Money', 'Review cash and check tenders recorded through the organizer application.', 'bosquegrande-organizer-app'],
        ];
        echo '<div class="wrap"><h1>BosqueGrande Accounting</h1>';
        echo '<p>A read-only area for BosqueGrande Accountants. Each transaction has a Details control showing the person\'s name, contact information, and the event when there is one.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1000px">';
        foreach ($items as [$title, $description, $slug]) {
            $url = add_query_arg('page', $slug, admin_url('admin.php'));
            echo '<div class="card" style="max-width:none"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><p><a class="button button-primary" href="' . esc_url($url) . '">Open ' . esc_html($title) . '</a></p></div>';
        }
        echo '</div></div>';
    }

    public function renderOrganizerHistory(int $constituentId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_event_roles';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_post_id
             WHERE r.constituent_id = %d ORDER BY r.assigned_at DESC",
            $constituentId
        )) ?: [];
        if (!$rows) return;
        echo '<h2>Organizer and host history</h2><table class="widefat striped" style="max-width:800px"><thead><tr><th>Event</th><th>Role</th><th>Status</th><th>Assigned (UTC)</th><th>Ended (UTC)</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html((string) ($row->post_title ?: 'Event #' . $row->event_post_id)) . '</td><td>'
                . esc_html(ucfirst((string) $row->role_label)) . '</td><td>' . esc_html((string) $row->status) . '</td><td>'
                . esc_html((string) $row->assigned_at) . '</td><td>' . esc_html((string) ($row->ended_at ?: '-')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function toolsRedirect(string $message): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-history-tools', 'bg_notice' => rawurlencode($message)], admin_url('admin.php')));
        exit;
    }
}
