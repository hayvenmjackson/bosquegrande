<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Reinstall. The red row at the bottom of the sidebar. It
 * resets the plugin to its first-run state: every stored credential
 * and every configuration option is removed and the setup wizard
 * relaunches. It never touches data. Constituents, events,
 * registrations, newsletters, subscriber groups, the suppression
 * list, and the payments ledger all remain exactly as they are, and
 * the encryption key stays in place so those records remain readable.
 *
 * The action is gated behind two confirmations, each a nonce-carrying
 * POST, so neither a stray click nor a pasted URL can trigger it.
 */
final class Reinstaller
{
    public const PAGE = 'bosquegrande-reinstall';

    /** Options removed by a reinstall. Tables are never touched. */
    private const RESET_OPTIONS = [
        'bosquegrande_settings',
        'bg_org_type',
        'bg_wording',
        'bg_calendar_settings',
        'bg_lgl_posture',
        'bg_exchange_schedule_applied',
        'bg_delete_on_uninstall',
        'bg_boot_failures',
        'bg_writeback_queue',
        'bg_session_pepper',
        'bg_wizard_done',
        'bg_qbo_sales_receipt_verified',
        'bg_schema_verified',
        'bg_registration_tiers',
        'bg_registration_questions',
        'bg_monthly_levels',
        'bg_qbo_region_classes',
        'bg_region_postal_rules',
        'bg_tagging_rules',
        'bg_followup_auto_reminder',
        'bg_followup_auto_survey',
        'bg_followup_auto_newcomer',
    ];

    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'bosquegrande',
                'Reinstall BosqueGrande',
                'Reinstall',
                'manage_options',
                self::PAGE,
                [$this, 'render']
            );
        });

        // The red row. Printed on every admin page, next to nothing.
        add_action('admin_head', function (): void {
            echo '<style>'
                . '#adminmenu .wp-submenu a[href$="page=' . self::PAGE . '"]{'
                . 'color:#f87171 !important;font-weight:600}'
                . '#adminmenu .wp-submenu a[href$="page=' . self::PAGE . '"]:hover,'
                . '#adminmenu .wp-submenu a[href$="page=' . self::PAGE . '"]:focus{'
                . 'color:#fca5a5 !important}'
                . '</style>';
        });

        add_action('admin_post_bg_reinstall_stage2', function (): void {
            if (!current_user_can('manage_options')) wp_die('Not allowed.');
            check_admin_referer('bg_reinstall_stage2');
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&stage=2'));
            exit;
        });

        add_action('admin_post_bg_reinstall_do', function (): void {
            if (!current_user_can('manage_options')) wp_die('Not allowed.');
            check_admin_referer('bg_reinstall_do');
            $this->reset();
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande-setup&bg-reinstalled=1'));
            exit;
        });

        add_action('admin_notices', function (): void {
            if (!current_user_can('manage_options')) return;
            if (!isset($_GET['bg-reinstalled'])) return;
            if (!isset($_GET['page']) || $_GET['page'] !== 'bosquegrande-setup') return;
            echo '<div class="notice notice-success"><p><strong>BosqueGrande has been reset.</strong> '
                . 'Credentials and configuration were removed; your data was not touched. '
                . 'Run through setup to reconnect your services.</p></div>';
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        $stage = isset($_GET['stage']) && $_GET['stage'] === '2' ? 2 : 1;

        echo '<div class="wrap bg-reinstall">';
        if ($stage === 1) {
            echo '<h1>Reinstall BosqueGrande</h1>';
            echo '<div class="bg-danger-box">';
            echo '<p class="bg-danger-lede">This will relaunch the setup wizard and remove the '
                . 'plugin configuration and saved credentials. WordPress roles and capabilities are unchanged.</p>';

            echo '<h2>What a reinstall removes</h2><ul>';
            foreach ([
                'Every stored credential: the LGL API key, Stripe keys, the mail relay password, the Eventbrite token, and all service provider keys',
                'All settings and switches: mail identity, assistance posture, feature toggles, appearance colors, and wording revisions',
                'The LGL sync posture and any transfer schedule',
                'Signed-in member sessions; every magic link session is invalidated',
                'Setup progress, so the wizard starts from the beginning',
            ] as $line) {
                printf('<li>%s</li>', esc_html($line));
            }
            echo '</ul>';

            echo '<h2>What stays exactly as it is</h2><ul>';
            foreach ([
                'Constituents, their tags, custom fields, consent records, and activity timelines',
                'Events, registrations, photos, and locations',
                'Newsletters, templates, subscriber groups, and the suppression list',
                'The payments ledger and the activity log',
                'The encryption key, so every stored record remains readable',
            ] as $line) {
                printf('<li>%s</li>', esc_html($line));
            }
            echo '</ul>';
            echo '<p>Constituent data is only ever removed if the plugin itself is deleted, '
                . 'and even then only when the delete-on-uninstall switch has been turned on.</p>';

            echo '<div class="bg-danger-actions">';
            printf(
                '<a class="button button-large" href="%s">Cancel</a>',
                esc_url(admin_url('admin.php?page=bosquegrande'))
            );
            printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field('bg_reinstall_stage2');
            echo '<input type="hidden" name="action" value="bg_reinstall_stage2">';
            echo '<button type="submit" class="button button-large bg-danger-btn">Yes</button>';
            echo '</form></div></div>';
        } else {
            echo '<h1>Are you SURE you want to Reinstall?</h1>';
            echo '<div class="bg-danger-box">';
            echo '<p class="bg-danger-lede">This is the final confirmation. API keys '
                . 'and plugin configuration will be scrubbed and the setup wizard will relaunch. '
                . 'WordPress roles and capabilities will not be changed. '
                . 'Your data stays untouched.</p>';
            echo '<div class="bg-danger-actions">';
            printf(
                '<a class="button button-large" href="%s">Cancel</a>',
                esc_url(admin_url('admin.php?page=bosquegrande'))
            );
            printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field('bg_reinstall_do');
            echo '<input type="hidden" name="action" value="bg_reinstall_do">';
            echo '<button type="submit" class="button button-large bg-danger-btn">Yes, reinstall</button>';
            echo '</form></div></div>';
        }
        echo '</div>';
    }

    /**
     * The reset itself: options only, tables never. The wizard's
     * first-run transient is not set here because the handler
     * redirects straight into the wizard.
     */
    private function reset(): void
    {
        $this->container->get('logger')->warn(
            'core',
            'Reinstall run: credentials and configuration removed by an administrator; data untouched.'
        );
        foreach (self::RESET_OPTIONS as $option) {
            delete_option($option);
        }
    }
}
