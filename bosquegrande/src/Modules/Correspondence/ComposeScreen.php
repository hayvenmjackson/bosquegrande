<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

use BosqueGrande\Core\Container;

if (!defined('ABSPATH')) exit;

/**
 * The compose screen behind "Newsletters" in the sidebar. One page:
 * subject, template,
 * audience, the WordPress editor (its Visual tab is the WYSIWYG, its
 * Text tab is the HTML editor), and two ways out: an explicit, confirmed
 * manual release or a save with no delivery.
 *
 * Storage is the existing bg_newsletter post type, so nothing about
 * delivery, the archive, or past newsletters changes: a composed
 * newsletter IS a newsletter post (draft until sent), the send path is
 * the same queue and batch worker, and the classic edit screen remains
 * available under Newsletter Archive.
 *
 * There is no unattended scheduling: every campaign needs a fresh
 * administrator authorization.
 */
final class ComposeScreen
{
    public const PAGE = 'bosquegrande-compose';
    public const SEND_AT_META = '_bg_nl_send_at';
    public const GROUP_META = '_bg_nl_group';
    public const SCHEDULE_JOB = 'nl_scheduled';

    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        if ((string) get_option('bg_newsletter_manual_mode_353', '') !== '1') {
            delete_post_meta_by_key(self::SEND_AT_META);
            update_option('bg_newsletter_manual_mode_353', '1', false);
        }
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Newsletters', 'Newsletters',
                'manage_options', self::PAGE, [$this, 'render']);
        }, 13);
        add_action('admin_post_bg_compose_save', [$this, 'handleSave']);
    }

    /* ── Render ───────────────────────────────────────────────────── */

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        /** @var Groups $groups */
        $groups = $this->container->get('correspondence.groups');
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';

        $draft = null;
        $draftId = (int) ($_GET['draft'] ?? 0);
        if ($draftId) {
            $p = get_post($draftId);
            if ($p && $p->post_type === CorrespondenceModule::CPT) $draft = $p;
        }
        $subject = $draft ? $draft->post_title : '';
        $body = $draft ? $draft->post_content : '';
        $tplSelected = $draft ? (string) get_post_meta($draft->ID, Composer::TEMPLATE_META, true) : 'classic';
        $groupSelected = $draft ? (int) get_post_meta($draft->ID, self::GROUP_META, true) : 0;

        ?>
        <div class="wrap"><h1>Newsletters</h1>
        <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:780px">Write in the Visual tab or switch to Text for raw HTML, pick a template and an
        audience, then explicitly release it or save it for later. Scheduled and unattended
        newsletter delivery is disabled. Everything you compose here also lives in the
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . CorrespondenceModule::CPT)); ?>">Newsletter Archive</a>.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:920px">
            <?php wp_nonce_field('bg_compose_save'); ?>
            <input type="hidden" name="action" value="bg_compose_save">
            <input type="hidden" name="draft_id" value="<?php echo (int) ($draft->ID ?? 0); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label for="bg-c-subject">Subject</label></th>
                    <td><input type="text" id="bg-c-subject" name="subject" class="regular-text" style="width:100%"
                        value="<?php echo esc_attr($subject); ?>" required></td></tr>
                <tr><th><label for="bg-c-template">Template</label></th>
                    <td><select id="bg-c-template" name="template">
                        <?php foreach (Composer::templates() as $slug => $tpl): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($tplSelected, $slug); ?>>
                                <?php echo esc_html($tpl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=bosquegrande-nl-template')); ?>">Edit templates</a></td></tr>
                <tr><th><label for="bg-c-group">Send to</label></th>
                    <td><select id="bg-c-group" name="group_id">
                        <option value="0">Choose a subscriber group&hellip;</option>
                        <?php foreach ($groups->all() as $g): ?>
                            <option value="<?php echo (int) $g->id; ?>" <?php selected($groupSelected, (int) $g->id); ?>>
                                <?php echo esc_html($g->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Normal delivery: choose one subscriber group.</p></td></tr>
                <tr><th><label for="bg-c-test-recipients">Temporary test recipients</label></th>
                    <td><textarea id="bg-c-test-recipients" name="test_recipients" rows="3" class="large-text"
                        placeholder="alex@example.com, sam@example.com"></textarea>
                    <p class="description">Temporary test recipients are used for this send only and are not saved.
                        Enter up to <?php echo (int) Sender::TEST_RECIPIENT_LIMIT; ?> addresses separated by commas,
                        semicolons, spaces, or new lines. Test messages are sent individually. Choose either a group
                        or test recipients, not both. Direct tests can only use <strong>Send now</strong>.</p></td></tr>
            </table>
            <?php wp_editor($body, 'bg_compose_body', [
                'textarea_name' => 'body',
                'textarea_rows' => 16,
                'media_buttons' => true,
            ]); ?>
            <table class="form-table" role="presentation">
                <tr><th>Delivery</th><td>
                    <label style="display:block;margin-bottom:6px"><input type="radio" name="dispatch" value="save" checked>
                        Save for later (no email goes out)</label>
                    <label style="display:block;margin-bottom:10px"><input type="radio" name="dispatch" value="send">
                        <strong>Release now</strong></label>
                    <label style="display:block"><input type="checkbox" name="confirm_send" value="1">
                        I have reviewed the subject, content, and selected recipients and authorize this one newsletter release.</label>
                </td></tr>
            </table>
            <p><button class="button button-primary button-large">Continue</button></p>
        </form>

        <?php $this->renderDrafts((int) ($draft->ID ?? 0)); ?>
        </div>
        <?php
    }

    private function renderDrafts(int $currentId): void
    {
        $drafts = get_posts([
            'post_type' => CorrespondenceModule::CPT,
            'post_status' => 'draft',
            'numberposts' => 10,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        if (!$drafts) return;
        echo '<h2>Saved newsletters</h2><table class="widefat striped" style="max-width:920px"><thead><tr>'
            . '<th>Subject</th><th>Last edited</th><th></th></tr></thead><tbody>';
        foreach ($drafts as $p) {
            // Legacy scheduled rows remain hidden until their inert job clears the marker.
            if ((int) get_post_meta($p->ID, self::SEND_AT_META, true) >= time()) continue;
            printf('<tr><td>%s%s</td><td>%s</td><td><a class="button button-small" href="%s">Open in composer</a></td></tr>',
                esc_html($p->post_title !== '' ? $p->post_title : '(untitled)'),
                $p->ID === $currentId ? ' <em>(loaded)</em>' : '',
                esc_html(wp_date('M j, Y g:ia', (int) get_post_timestamp($p, 'modified'))),
                esc_url(admin_url('admin.php?page=' . self::PAGE . '&draft=' . $p->ID)));
        }
        echo '</tbody></table>';
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_compose_save');

        $subject = sanitize_text_field((string) wp_unslash((string) ($_POST['subject'] ?? '')));
        $body = wp_kses_post((string) wp_unslash((string) ($_POST['body'] ?? '')));
        $template = sanitize_key((string) ($_POST['template'] ?? 'classic'));
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $testRaw = trim((string) wp_unslash((string) ($_POST['test_recipients'] ?? '')));
        $parsedTests = Sender::parseTestRecipients($testRaw);
        $testRecipients = $parsedTests['valid'];
        $dispatch = in_array($_POST['dispatch'] ?? '', ['send', 'save'], true)
            ? (string) $_POST['dispatch'] : 'save';
        $draftId = (int) ($_POST['draft_id'] ?? 0);

        if ($subject === '') $this->back('A subject is required.');
        if ($dispatch !== 'save' && $testRaw !== '' && $parsedTests['invalid']) {
            $this->back(count($parsedTests['invalid']) . ' test address(es) were invalid. Correct them before sending.');
        }
        if ($dispatch !== 'save' && $parsedTests['overflow'] > 0) {
            $this->back('Direct testing is limited to ' . Sender::TEST_RECIPIENT_LIMIT . ' addresses per send.');
        }
        if ($dispatch === 'send' && $groupId > 0 && $testRecipients) {
            $this->back('Choose either a subscriber group or temporary test recipients, not both.');
        }
        if ($dispatch === 'send' && $groupId <= 0 && !$testRecipients) {
            $this->back('Choose a subscriber group or enter at least one temporary test recipient.');
        }
        if ($dispatch === 'send' && (string) ($_POST['confirm_send'] ?? '') !== '1') {
            $this->back('Manual release was not authorized. Review the newsletter and check the authorization box before sending.');
        }

        $postArr = [
            'post_type' => CorrespondenceModule::CPT,
            'post_status' => 'draft',
            'post_title' => $subject,
            'post_content' => $body,
        ];
        if ($draftId && get_post_type($draftId) === CorrespondenceModule::CPT) {
            $postArr['ID'] = $draftId;
            $newsletterId = (int) wp_update_post($postArr);
        } else {
            $newsletterId = (int) wp_insert_post($postArr);
        }
        if (!$newsletterId) $this->back('The newsletter could not be saved.');
        update_post_meta($newsletterId, Composer::TEMPLATE_META, $template);
        update_post_meta($newsletterId, self::GROUP_META, $groupId);
        update_post_meta($newsletterId, Composer::RAW_FLAG, '0'); // Composer content renders as blocks/HTML.

        if ($dispatch === 'send') {
            /** @var Sender $sender */
            $sender = $this->container->get('correspondence.sender');
            if ($testRecipients) {
                $result = $sender->testSendMany($newsletterId, $testRecipients);
                delete_post_meta($newsletterId, self::SEND_AT_META);
                $reason = $result['failed'] > 0 ? (string) get_transient('bg_last_mail_error') : '';
                $this->back($result['sent'] . ' test message(s) sent'
                    . ($result['failed'] ? ', ' . $result['failed'] . ' refused' : '')
                    . ($reason !== '' ? '. The mailer said: "' . $reason . '"' : '')
                    . '.');
            }
            update_post_meta($newsletterId, Sender::AUTH_META, '1');
            $result = $sender->queueNewsletter($newsletterId, $groupId);
            if (!$this->container->get('jobs')->enqueue(Sender::JOB, ['newsletter' => $newsletterId], 5)) {
                delete_post_meta($newsletterId, Sender::AUTH_META);
                $this->back('The recipients were queued, but WordPress could not schedule delivery. Check Site Health and WP-Cron.');
            }
            delete_post_meta($newsletterId, self::SEND_AT_META);
            $this->back($result['queued'] . ' queued for delivery'
                . ($result['suppressed'] ? ', ' . $result['suppressed'] . ' suppressed' : '') . '.');
        }

        $this->back('Saved. Find it under Saved newsletters below, or in the Newsletter Archive.');
    }

    /** Legacy scheduled jobs are deliberately inert in manual mode. */
    public static function runScheduled(Container $container, array $args): void
    {
        $newsletterId = (int) ($args['newsletter'] ?? 0);
        if ($newsletterId > 0) delete_post_meta($newsletterId, self::SEND_AT_META);
        $container->get('logger')->warn('correspondence', 'A legacy scheduled newsletter job was blocked; newsletter delivery requires manual release.', [
            'newsletter' => $newsletterId,
        ]);
    }

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE, 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }
}
