<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Notifications;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\MailText;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Lgl\ConstituentPresenter;
use BosqueGrande\Modules\Lgl\LglClient;

if (!defined('ABSPATH')) exit;

/**
 * Notifications: administrator-released reminder and survey batches.
 * Nothing in this module sends from cron. Staff review the due counts and
 * explicitly release at most one bounded batch at a time; confirmations
 * and magic links remain separate transactional paths.
 *
 * A send is stamped only after the mailer accepts it. Failures are
 * counted and remain available for a later deliberate release up to
 * MAX_ATTEMPTS, then close with the surrender logged.
 *
 * The survey lives in the plugin: editable questions (BosqueGrande >
 * Survey Questions, one per line), standard 5-point Likert responses,
 * tokenized per-registration links, one response set per registration.
 * Results summarize on the Event Statistics page.
 */
final class NotificationsModule implements ModuleInterface
{
    private const CRON = 'bg_notifications_tick';
    private const QUESTIONS_OPTION = 'bg_survey_questions';
    private const MAX_ATTEMPTS = 24;
    private const SURVEY_LOOKBACK_DAYS = 7;
    private const MANUAL_BATCH_LIMIT = 25;
    private const MANUAL_MODE_OPTION = 'bg_notifications_manual_mode_353';

    /** One loud LGL-key warning per tick, not one per registration row. */
    private bool $keyWarned = false;
    /** Stops a released batch after the first transport-level refusal. */
    private bool $mailTransportFailed = false;

    public const LIKERT = [
        1 => 'Strongly disagree',
        2 => 'Disagree',
        3 => 'Neutral',
        4 => 'Agree',
        5 => 'Strongly agree',
    ];

    public function name(): string
    {
        return 'notifications';
    }

    public function label(): string
    {
        return 'Notifications (reminders and surveys)';
    }

    public function activate(Container $container): void
    {
        $this->addRewrite();
        if (isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite) {
            flush_rewrite_rules();
        }
        delete_option('bg_rewrites_flushed_244');
        if (!get_option(self::QUESTIONS_OPTION)) {
            update_option(self::QUESTIONS_OPTION, self::defaultQuestions(), false);
        }
        wp_clear_scheduled_hook(self::CRON);
        delete_option('bg_notifications_last_tick');
        update_option(self::MANUAL_MODE_OPTION, '1', false);
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);

        $settings = $container->get('settings');

        // Correspondence identity: who the site's messages come from.
        // Defaults are the site admin address and site name, so mail has
        // a live, watched sender until the wizard or the Correspondence
        // screen sets an explicit one.
        add_filter('wp_mail_from', function ($from) use ($settings) {
            $v = trim((string) $settings->get('mail_from_address', ''));
            if ($v === '') $v = (string) get_option('admin_email');
            return is_email($v) ? $v : $from;
        });
        add_filter('wp_mail_from_name', function ($name) use ($settings) {
            $v = trim((string) $settings->get('mail_from_name', ''));
            return MailText::line($v !== '' ? $v : (string) get_bloginfo('name'));
        });

        // Optional SMTP relay, through WordPress's own bundled mailer. The
        // relay host is furnished by the office in Settings; the software
        // itself names no mail host. When no host is configured, dispatch
        // proceeds exactly as before, through the platform's mail.
        add_action('phpmailer_init', function ($phpmailer) use ($settings): void {
            $reply = trim((string) $settings->get('mail_reply_to', ''));
            if ($reply !== '' && is_email($reply)) {
                $phpmailer->clearReplyTos();
                $phpmailer->addReplyTo($reply);
            }
            $host = trim((string) $settings->get('smtp_host', ''));
            if ($host === '') return;
            $phpmailer->isSMTP();
            $phpmailer->Host = $host;
            $phpmailer->Port = (int) $settings->get('smtp_port', 587);
            $secure = (string) $settings->get('smtp_secure', 'tls');
            $phpmailer->SMTPSecure = in_array($secure, ['tls', 'ssl'], true) ? $secure : '';
            $user = trim((string) $settings->get('smtp_username', ''));
            if ($user !== '') {
                $phpmailer->SMTPAuth = true;
                $phpmailer->Username = $user;
                $phpmailer->Password = (string) $settings->get('smtp_password', '');
            }
        });

        // Every dispatch failure is entered in the log with the mailer's
        // stated reason; "the message never arrived" becomes answerable.
        add_action('wp_mail_failed', function ($error) use ($container): void {
            $this->mailTransportFailed = true;
            $reason = is_wp_error($error) ? $error->get_error_message() : 'unknown';
            $container->get('logger')->warn('correspondence', 'Mail dispatch failed.', ['reason' => $reason]);
            // Held briefly so the test button can show the reason inline;
            // the SMTP error string is the diagnosis (auth dead, daily
            // limit, connection refused), and burying it helps no one.
            set_transient('bg_last_mail_error', bg_substr($reason, 0, 500), 30 * MINUTE_IN_SECONDS);
        });

        // WordPress can report that PHPMailer accepted a message without
        // proving inbox delivery, but recording the acceptance cleanly
        // separates transport failures from downstream spam/filtering.
        add_action('wp_mail_succeeded', function (array $mailData) use ($container): void {
            $to = $mailData['to'] ?? [];
            $count = is_array($to) ? count($to) : (trim((string) $to) === '' ? 0 : 1);
            $container->get('logger')->info('correspondence', 'Mailer accepted message for delivery.', [
                'recipients' => $count,
                'subject' => bg_substr((string) ($mailData['subject'] ?? ''), 0, 190),
            ]);
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Correspondence', 'Correspondence',
                'manage_options', 'bosquegrande-mail', function (): void {
                    $this->renderMailPage();
                });
        }, 45);
        add_action('admin_post_bg_mail_save', function () use ($container): void {
            $this->handleMailSave($container);
        });

        // The test dispatch: one button that answers "does the
        // mail work" with a demonstrable yes or a logged reason.
        add_action('admin_post_bg_mail_test', function () use ($container): void {
            if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
            check_admin_referer('bg_mail_test');
            $to = sanitize_email((string) ($_POST['test_address'] ?? ''));
            if (!is_email($to)) {
                $msg = 'That is not a valid email address.';
            } else {
                $sent = wp_mail(
                    $to,
                    'Test message from ' . get_bloginfo('name'),
                    "This is a test message sent from the website's Correspondence settings at "
                    . current_time('mysql') . " (site time).\n\nIf you're reading this, the "
                    . "site's email is working. No action needed."
                );
                $container->get('logger')->info('correspondence', 'Test message send finished.', [
                    'accepted' => (bool) $sent,
                ]);
                $reason = $sent ? '' : (string) get_transient('bg_last_mail_error');
                $msg = $sent
                    ? 'Test message sent to ' . $to . '. Check that inbox (and its spam folder). The send is recorded in the log below.'
                    : 'The mailer refused to send.' . ($reason !== '' ? ' Its stated reason: "' . $reason . '".' : ' The reason is recorded in the log below.');
            }
            wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-mail', 'bg_notice' => rawurlencode($msg)], admin_url('admin.php')));
            exit;
        });

        // Same boot-order fatal as Registration; wait for init.
        add_action('init', function (): void {
            $this->addRewrite();
        });

        add_filter('query_vars', function (array $vars): array {
            $vars[] = 'bg_survey';
            return $vars;
        });

        // Proactive mail is fail-closed. Remove every legacy hourly
        // event once after upgrade; a stale cron invocation is inert too.
        if ((string) get_option(self::MANUAL_MODE_OPTION, '') !== '1') {
            wp_clear_scheduled_hook(self::CRON);
            delete_option('bg_notifications_last_tick');
            update_option(self::MANUAL_MODE_OPTION, '1', false);
        }
        add_action(self::CRON, function () use ($container) {
            wp_clear_scheduled_hook(self::CRON);
            $container->get('logger')->warn('notifications',
                'A legacy scheduled notification run was blocked; reminders and surveys require manual release.');
        });

        add_action('template_redirect', function () use ($container) {
            $regId = (int) get_query_var('bg_survey');
            if (!$regId) return;
            $this->renderSurvey($container, $regId);
            exit;
        });

        add_action('rest_api_init', function () use ($container) {
            register_rest_route('bosquegrande/v1', '/survey', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Token-gated inside.
                'callback' => fn(\WP_REST_Request $req) => $this->submitSurvey($container, $req),
            ]);
        });

        if (is_admin()) {
            add_action('admin_menu', function () {
                add_submenu_page('bosquegrande', 'Surveys and Mail Release', 'Surveys and Mail Release',
                    'manage_options', 'bosquegrande-survey', [$this, 'renderQuestionsPage']);
            }, 35);
            add_action('admin_post_bg_save_survey', [$this, 'saveQuestions']);
            add_action('admin_post_bg_release_notifications', function () use ($container): void {
                $this->handleManualRelease($container);
            });
        }
    }

    public function deactivate(Container $container): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /**
     * Public survey and notification wording. Delivery facts, signed links,
     * scores, registration IDs, and timing rules remain controlled by code.
     *
     * @param array<string,array<string,mixed>> $defaults
     * @return array<string,array<string,mixed>>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $group = 'Surveys & event notifications';
        $local = [
            'notification_reminder_subject' => [
                'label' => 'Event reminder email: subject',
                'text' => 'See you tomorrow: {event}',
                'required_tokens' => ['event'],
            ],
            'notification_reminder_body' => [
                'label' => 'Event reminder email: message',
                'text' => "Hi,\n\nA quick reminder that you're registered for {event} on {when}.\n\nWe look forward to seeing you there!",
                'required_tokens' => ['event', 'when'],
            ],
            'notification_survey_subject' => [
                'label' => 'Event survey email: subject',
                'text' => 'How was {event}? (2 minutes)',
                'required_tokens' => ['event'],
            ],
            'notification_survey_body' => [
                'label' => 'Event survey email: message before the secure survey link',
                'text' => "Hi,\n\nThanks for coming to {event}! We'd love your quick feedback; it takes about two minutes and helps shape future events.",
                'required_tokens' => ['event'],
            ],
            'notification_survey_email_closing' => [
                'label' => 'Event survey email: closing after the secure survey link',
                'text' => 'Thank you!',
            ],
            'survey_region_aria' => [
                'label' => 'Survey page: accessible region name',
                'text' => 'Event survey',
            ],
            'survey_invalid_title' => [
                'label' => 'Survey page: invalid-link heading',
                'text' => 'Survey link not valid',
            ],
            'survey_invalid_body' => [
                'label' => 'Survey page: invalid-link explanation',
                'text' => 'This link may have been copied incompletely. Please use the link from your email.',
            ],
            'survey_received_title' => [
                'label' => 'Survey page: already-received heading',
                'text' => 'Already received',
            ],
            'survey_received_body' => [
                'label' => 'Survey page: already-received message',
                'text' => 'Thanks! Your feedback for this event is already in.',
            ],
            'survey_event_title' => [
                'label' => 'Survey page: event heading',
                'text' => 'How was {event}?',
                'required_tokens' => ['event'],
            ],
            'survey_instructions' => [
                'label' => 'Survey page: instructions',
                'text' => 'For each statement, pick the answer closest to your experience.',
            ],
            'survey_likert_1' => ['label' => 'Survey answer 1', 'text' => 'Strongly disagree'],
            'survey_likert_2' => ['label' => 'Survey answer 2', 'text' => 'Disagree'],
            'survey_likert_3' => ['label' => 'Survey answer 3', 'text' => 'Neutral'],
            'survey_likert_4' => ['label' => 'Survey answer 4', 'text' => 'Agree'],
            'survey_likert_5' => ['label' => 'Survey answer 5', 'text' => 'Strongly agree'],
            'survey_send_button' => [
                'label' => 'Survey page: submit button',
                'text' => 'Send feedback',
            ],
            'survey_sending_status' => [
                'label' => 'Survey page: sending status',
                'text' => 'Sending...',
            ],
            'survey_client_error' => [
                'label' => 'Survey page: connection error',
                'text' => 'Something went wrong. Please try again.',
            ],
            'survey_skip' => [
                'label' => 'Survey page: skip link',
                'text' => 'Skip to event survey',
            ],
            'survey_api_invalid' => [
                'label' => 'Survey response: invalid-link message',
                'text' => 'This survey link is not valid.',
            ],
            'survey_api_registration_missing' => [
                'label' => 'Survey response: registration-not-found message',
                'text' => 'Registration not found.',
            ],
            'survey_api_already_received' => [
                'label' => 'Survey response: already-received message',
                'text' => 'Thanks! Your feedback was already received.',
            ],
            'survey_api_incomplete' => [
                'label' => 'Survey response: incomplete-form message',
                'text' => 'Please answer every question before sending your feedback.',
            ],
            'survey_api_saving' => [
                'label' => 'Survey response: another-save-in-progress message',
                'text' => 'This survey is already being saved. Please wait a moment and try again.',
            ],
            'survey_api_repair_failed' => [
                'label' => 'Survey response: interrupted-save repair message',
                'text' => 'An earlier partial response could not be repaired. Please try again.',
            ],
            'survey_api_save_failed' => [
                'label' => 'Survey response: save-failed message',
                'text' => 'We could not safely save all of your answers. Please try again.',
            ],
            'survey_api_success' => [
                'label' => 'Survey response: success message',
                'text' => 'Thank you! Your feedback is in.',
            ],
        ];
        foreach ($local as &$definition) {
            $definition['group'] = $group;
        }
        unset($definition);
        return array_replace($defaults, $local);
    }

    private static function word(string $key, string $fallback): string
    {
        return function_exists('bg_str') ? \bg_str($key, $fallback) : $fallback;
    }

    /** @param array<string,scalar|null> $tokens */
    private static function wordFormat(string $key, string $fallback, array $tokens, array $required = []): string
    {
        if (function_exists('bg_str_format')) {
            return \bg_str_format($key, $fallback, $tokens, $required);
        }
        $replace = [];
        foreach ($tokens as $token => $value) {
            $replace['{' . $token . '}'] = (string) $value;
        }
        return strtr($fallback, $replace);
    }

    /** @return array<int,string> */
    private static function likertLabels(): array
    {
        $labels = [];
        foreach (self::LIKERT as $score => $fallback) {
            $labels[$score] = self::word('survey_likert_' . $score, $fallback);
        }
        return $labels;
    }

    /* ── Questions (editable) ─────────────────────────────────────── */

    /** @return array<string, string> key => label */
    public static function questions(): array
    {
        $stored = get_option(self::QUESTIONS_OPTION, []);
        return is_array($stored) && $stored ? $stored : self::defaultQuestions();
    }

    /** @return array<string, string> */
    private static function defaultQuestions(): array
    {
        return [
            'overall' => 'Overall, I was satisfied with this event.',
            'organized' => 'The event was well organized.',
            'welcome' => 'I felt welcome at this event.',
            'again' => 'I would attend an event like this again.',
            'recommend' => 'I would recommend these events to a friend.',
        ];
    }

    public function renderQuestionsPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $lines = [];
        foreach (self::questions() as $label) {
            $lines[] = $label;
        }
        ?>
        <div class="wrap"><h1>Surveys and Mail Release</h1>
        <?php if ($notice) : ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:720px">One statement per line. Attendees answer each on a five-point
        agree/disagree scale. Reminder and survey mail never goes out automatically. An administrator
        must review the due counts and release one batch below. Edits apply to surveys released after
        you save. Results appear on the Event Statistics page.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:640px">
          <?php wp_nonce_field('bg_save_survey'); ?>
          <input type="hidden" name="action" value="bg_save_survey">
          <textarea name="questions" rows="8" class="large-text"><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
          <p><button class="button button-primary">Save questions</button></p>
        </form>

        <?php
        if (defined('BG_VERSION') && version_compare(BG_VERSION, '3.98', '>=')) {
            echo '<hr style="margin:28px 0;max-width:760px"><div class="notice notice-info inline"><p><strong>Follow-up delivery:</strong> Use Follow-up Messages to review and release event reminders, surveys, and newcomer welcomes, or to manage their administrator-approved automatic delivery. <a href="' . esc_url(admin_url('admin.php?page=bosquegrande-followups')) . '">Open Follow-up Messages</a>.</p></div></div>';
            return;
        }
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        $reminders = $this->candidateRows('reminder', 500);
        $surveys = $this->candidateRows('survey', 500);
        ?>
        <hr style="margin:28px 0;max-width:760px">
        <h2>Manual mail release</h2>
        <p style="max-width:760px">Each release processes no more than <?php echo (int) self::MANUAL_BATCH_LIMIT; ?>
        eligible registrations. A mail transport refusal stops the batch immediately. Failed and
        unprocessed rows remain available for a later deliberate release.</p>
        <table class="widefat striped" style="max-width:760px"><thead><tr>
            <th>Message type</th><th>Eligible now</th><th>Action</th>
        </tr></thead><tbody>
            <tr><td>Event reminders</td><td><?php echo (int) count($reminders); ?></td><td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return confirm('Release one bounded reminder batch now?');">
                    <?php wp_nonce_field('bg_release_notifications_reminder'); ?>
                    <input type="hidden" name="action" value="bg_release_notifications">
                    <input type="hidden" name="kind" value="reminder">
                    <button class="button" <?php disabled(count($reminders), 0); ?>>Release reminders</button>
                </form>
            </td></tr>
            <tr><td>Post-event surveys</td><td><?php echo (int) count($surveys); ?></td><td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return confirm('Release one bounded survey batch now?');">
                    <?php wp_nonce_field('bg_release_notifications_survey'); ?>
                    <input type="hidden" name="action" value="bg_release_notifications">
                    <input type="hidden" name="kind" value="survey">
                    <button class="button" <?php disabled(count($surveys), 0); ?>>Release surveys</button>
                </form>
            </td></tr>
        </tbody></table>
        </div>
        <?php
    }

    public function saveQuestions(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_save_survey');
        $raw = (string) ($_POST['questions'] ?? '');
        $questions = [];
        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $i => $label) {
            $label = sanitize_text_field($label);
            $key = sanitize_key(substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), 0, 48)) ?: 'q' . ($i + 1);
            // Keep keys unique if two questions slug identically.
            while (isset($questions[$key])) $key .= '_';
            $questions[$key] = $label;
        }
        if (!$questions) $questions = self::defaultQuestions();
        update_option(self::QUESTIONS_OPTION, $questions, false);
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-survey', 'bg_notice' => rawurlencode('Questions saved.')],
            admin_url('admin.php')
        ));
        exit;
    }

    /* ── Manual notification release ─────────────────────────────── */

    private function handleManualRelease(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        if (defined('BG_VERSION') && version_compare(BG_VERSION, '3.98', '>=')) {
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande-followups'));
            exit;
        }
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        if (!in_array($kind, ['reminder', 'survey'], true)) wp_die('Unknown notification type.');
        check_admin_referer('bg_release_notifications_' . $kind);
        $result = $this->processManualBatch($container, $kind);
        $message = ucfirst($kind) . ' release processed ' . $result['processed']
            . ' registration(s); ' . $result['accepted'] . ' message(s) were accepted by the mailer.';
        if ($result['transport_failed']) {
            $message .= ' The batch stopped after the first mail transport refusal.';
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-survey', 'bg_notice' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }

    /** @return array{processed:int,accepted:int,transport_failed:bool} */
    private function processManualBatch(Container $container, string $kind): array
    {
        /** @var Logger $logger */
        $logger = $container->get('logger');
        $this->keyWarned = false;
        $this->mailTransportFailed = false;
        $rows = $this->candidateRows($kind, self::MANUAL_BATCH_LIMIT);
        $processed = 0;
        $accepted = 0;

        foreach ($rows as $r) {
            $processed++;
            $eventId = (int) $r->event_post_id;
            $date = (string) get_post_meta($eventId, '_bg_start_date', true);
            $time = (string) get_post_meta($eventId, '_bg_start_time', true) ?: '18:00';
            $start = $date !== '' ? strtotime($date . ' ' . $time . ' ' . wp_timezone_string()) : false;
            if (!$start) continue;
            $sent = $kind === 'reminder'
                ? $this->sendReminder($container, $r, $eventId, $start)
                : $this->sendSurvey($container, $r, $eventId);
            if ($sent) $accepted++;
            if ($this->mailTransportFailed) break;
        }
        update_option('bg_notifications_last_manual_release', time(), false);
        $logger->info('notifications', 'Manual notification batch processed.', [
            'kind' => $kind,
            'processed' => $processed,
            'accepted' => $accepted,
            'transport_failed' => $this->mailTransportFailed,
        ]);
        return [
            'processed' => $processed,
            'accepted' => $accepted,
            'transport_failed' => $this->mailTransportFailed,
        ];
    }

    /** @return array<int,object> */
    private function candidateRows(string $kind, int $limit): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'bg_registrations';
        $limit = max(1, min(500, $limit));

        $today = wp_date('Y-m-d');
        $tomorrow = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
        $surveyFloor = wp_date('Y-m-d', time() - self::SURVEY_LOOKBACK_DAYS * DAY_IN_SECONDS);
        $surveyCutoff = wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS);
        $condition = $kind === 'reminder'
            ? 'r.reminder_sent_at IS NULL AND d.meta_value BETWEEN %s AND %s'
            : 'r.survey_sent_at IS NULL AND d.meta_value BETWEEN %s AND %s';
        $from = $kind === 'reminder' ? $today : $surveyFloor;
        $to = $kind === 'reminder' ? $tomorrow : $surveyCutoff;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.* FROM {$t} r
             INNER JOIN {$wpdb->postmeta} d
                ON d.post_id = r.event_post_id AND d.meta_key = '_bg_start_date'
             WHERE r.status IN ('confirmed','paid')
               AND r.tier NOT IN ('historical','eventbrite')
               AND (r.bg_constituent_id IS NOT NULL OR r.lgl_constituent_id IS NOT NULL)
               AND ({$condition})
             ORDER BY d.meta_value ASC, r.id ASC LIMIT 500",
            $from,
            $to
        )) ?: [];

        $now = time();
        $eligible = [];
        foreach ($rows as $r) {
            $eid = (int) $r->event_post_id;
            if ((string) ($r->tier ?? '') === 'historical'
                || (string) get_post_meta($eid, '_bg_historical_import', true) === '1'
                || get_post_status($eid) === 'bg_historical'
            ) {
                continue;
            }
            $date = (string) get_post_meta($eid, '_bg_start_date', true);
            $time = (string) get_post_meta($eid, '_bg_start_time', true) ?: '18:00';
            $start = $date !== '' ? strtotime($date . ' ' . $time . ' ' . wp_timezone_string()) : false;
            if (!$start) continue;
            $durationHours = (float) apply_filters('bg_event_duration_hours', 3.0, $eid);
            $end = $start + (int) round($durationHours * HOUR_IN_SECONDS);
            if ($kind === 'reminder' && $start > $now && ($start - $now) <= DAY_IN_SECONDS) {
                $eligible[] = $r;
            } elseif ($kind === 'survey' && $now >= ($end + 2 * DAY_IN_SECONDS)) {
                $eligible[] = $r;
            }
        }
        return array_slice($eligible, 0, $limit);
    }

    private function sendReminder(Container $container, object $r, int $eventId, int $start): bool
    {
        if ($this->reconcileDeliveryReceipt($container, (int) $r->id, 'reminder', 'reminder_sent_at')) return false;
        $email = $this->recipientEmail(
            $container,
            (int) ($r->bg_constituent_id ?? 0),
            (int) ($r->lgl_constituent_id ?? 0)
        );
        if ($email === '') {
            $this->recordFailure($container, $r, 'reminder', 'no recipient address obtained from LGL');
            return false;
        }

        $title = get_the_title($eventId);
        $when = wp_date('l, F j \a\t g:i a', $start);
        $subject = self::wordFormat(
            'notification_reminder_subject',
            'See you tomorrow: {event}',
            ['event' => $title],
            ['event']
        );
        $body = self::wordFormat(
            'notification_reminder_body',
            "Hi,\n\nA quick reminder that you're registered for {event} on {when}.\n\nWe look forward to seeing you there!",
            ['event' => $title, 'when' => $when],
            ['event', 'when']
        );
        $sent = wp_mail(
            $email,
            $subject,
            rtrim($body) . "\n"
        );
        if (!$sent) {
            $this->mailTransportFailed = true;
            $this->recordFailure($container, $r, 'reminder', 'the mailer refused the dispatch');
            return false;
        }
        $this->recordDeliveryReceipt((int) $r->id, 'reminder');
        if ($this->stamp((int) $r->id, 'reminder_sent_at')) {
            delete_option($this->deliveryReceiptKey((int) $r->id, 'reminder'));
            $container->get('logger')->info('notifications', 'Reminder sent.', ['registration' => (int) $r->id]);
        } else {
            $container->get('logger')->error('notifications', 'Reminder was accepted by the mailer, but its durable sent stamp failed; a receipt will prevent duplicate delivery.', [
                'registration' => (int) $r->id,
            ]);
        }
        return true;
    }

    private function sendSurvey(Container $container, object $r, int $eventId): bool
    {
        if ($this->reconcileDeliveryReceipt($container, (int) $r->id, 'survey', 'survey_sent_at')) return false;
        $email = $this->recipientEmail(
            $container,
            (int) ($r->bg_constituent_id ?? 0),
            (int) ($r->lgl_constituent_id ?? 0)
        );
        if ($email === '') {
            $this->recordFailure($container, $r, 'survey', 'no recipient address obtained from LGL');
            return false;
        }

        $title = get_the_title($eventId);
        $surveyToken = self::surveyToken((int) $r->id);
        if ($surveyToken === '') {
            $this->recordFailure($container, $r, 'survey', 'the survey link could not be signed');
            return false;
        }
        $link = add_query_arg('t', $surveyToken,
            home_url('/bg-survey/' . (int) $r->id . '/'));
        $subject = self::wordFormat(
            'notification_survey_subject',
            'How was {event}? (2 minutes)',
            ['event' => $title],
            ['event']
        );
        $intro = self::wordFormat(
            'notification_survey_body',
            "Hi,\n\nThanks for coming to {event}! We'd love your quick feedback; it takes about two minutes and helps shape future events.",
            ['event' => $title],
            ['event']
        );
        $closing = self::word('notification_survey_email_closing', 'Thank you!');
        $sent = wp_mail(
            $email,
            $subject,
            rtrim($intro) . "\n\n" . $link . "\n\n" . rtrim($closing) . "\n"
        );
        if (!$sent) {
            $this->mailTransportFailed = true;
            $this->recordFailure($container, $r, 'survey', 'the mailer refused the dispatch');
            return false;
        }
        $this->recordDeliveryReceipt((int) $r->id, 'survey');
        if ($this->stamp((int) $r->id, 'survey_sent_at')) {
            delete_option($this->deliveryReceiptKey((int) $r->id, 'survey'));
            $container->get('logger')->info('notifications', 'Survey sent.', ['registration' => (int) $r->id]);
        } else {
            $container->get('logger')->error('notifications', 'Survey was accepted by the mailer, but its durable sent stamp failed; a receipt will prevent duplicate delivery.', [
                'registration' => (int) $r->id,
            ]);
        }
        return true;
    }

    /**
     * A failed send counts an attempt and the row is retried on later
     * ticks; only after MAX_ATTEMPTS (about a day of hourly ticks) is the
     * row closed, and that is recorded in the log.
     */
    private function recordFailure(Container $container, object $r, string $kind, string $reason): void
    {
        global $wpdb;
        $col = $kind . '_attempts';
        $attempts = (int) ($r->{$col} ?? 0) + 1;
        $updated = $wpdb->update(
            $wpdb->prefix . 'bg_registrations',
            [$col => $attempts],
            ['id' => (int) $r->id]
        );
        if ($updated === false) {
            $container->get('logger')->error('notifications', 'Notification failure attempt could not be recorded.', [
                'registration' => (int) $r->id,
                'kind' => $kind,
            ]);
            return;
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            if (!$this->stamp((int) $r->id, $kind . '_sent_at')) {
                $container->get('logger')->error('notifications', 'Abandoned notification could not be durably closed.', [
                    'registration' => (int) $r->id,
                    'kind' => $kind,
                ]);
                return;
            }
            $container->get('logger')->warn('notifications',
                ucfirst($kind) . ' abandoned after ' . $attempts . ' attempts (' . $reason
                . '). The row is closed; no message was delivered.',
                ['registration' => (int) $r->id]);
            return;
        }
        $container->get('logger')->warn('notifications',
            ucfirst($kind) . ' not dispatched (' . $reason . '); attempt ' . $attempts
            . ' of ' . self::MAX_ATTEMPTS . ', retrying on a later tick.',
            ['registration' => (int) $r->id]);
    }

    private function recipientEmail(Container $container, int $localId, int $lglId): string
    {
        if ($localId > 0) {
            $record = $container->get('constituents.store')->get($localId);
            $email = is_array($record) ? (string) ($record['email'] ?? '') : '';
            if (is_email($email)) return $email;
        }
        /** @var LglClient $lgl */
        $lgl = $container->get('lgl');
        if (!$lgl->hasKey()) {
            // An absent or unreadable LGL key takes every
            // notification down at once; say so loudly, once per tick.
            if (!$this->keyWarned) {
                $this->keyWarned = true;
                $container->get('logger')->error('notifications',
                    'No readable LGL key: recipient addresses cannot be fetched, so no notification mail can go out. Enter the key again under BosqueGrande Settings.');
            }
            return '';
        }
        if (!$lglId) return '';
        $c = $lgl->getConstituent($lglId);
        return $c ? ConstituentPresenter::prefill($c)['email'] : '';
    }

    private function stamp(int $regId, string $column): bool
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'bg_registrations',
            [$column => current_time('mysql', true)],
            ['id' => $regId]
        ) !== false;
    }

    private function deliveryReceiptKey(int $regId, string $kind): string
    {
        return 'bg_mail_receipt_' . $kind . '_' . $regId;
    }

    private function recordDeliveryReceipt(int $regId, string $kind): void
    {
        update_option($this->deliveryReceiptKey($regId, $kind), current_time('mysql', true), false);
    }

    /** A mailer-accepted receipt prevents a duplicate while its row stamp is repaired. */
    private function reconcileDeliveryReceipt(Container $container, int $regId, string $kind, string $column): bool
    {
        $key = $this->deliveryReceiptKey($regId, $kind);
        if ((string) get_option($key, '') === '') return false;
        if ($this->stamp($regId, $column)) {
            delete_option($key);
            $container->get('logger')->info('notifications', 'Recovered a previously delivered notification stamp.', [
                'registration' => $regId,
                'kind' => $kind,
            ]);
        } else {
            $container->get('logger')->error('notifications', 'A delivered notification remains protected from duplicate delivery while its row stamp is unavailable.', [
                'registration' => $regId,
                'kind' => $kind,
            ]);
        }
        return true;
    }

    /* ── Survey page ──────────────────────────────────────────────── */

    public static function surveyToken(int $regId): string
    {
        $key = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')->signingKey('survey-link');
        if ($key === '') return '';
        return substr(hash_hmac('sha256', 'survey|' . $regId, $key), 0, 20);
    }

    private function renderSurvey(Container $container, int $regId): void
    {
        global $wpdb;
        $token = isset($_GET['t']) ? sanitize_text_field((string) $_GET['t']) : '';
        $expected = self::surveyToken($regId);
        $valid = $expected !== '' && hash_equals($expected, $token);
        $row = $valid ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_registrations WHERE id = %d", $regId
        )) : null;
        $responseCount = $row ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_survey_responses WHERE registration_id = %d", $regId
        )) : 0;
        $done = $row && $responseCount >= count(self::questions());

        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        ob_start();
        echo '<div role="region" aria-label="' . esc_attr(self::word('survey_region_aria', 'Event survey')) . '" style="max-width:660px;margin:36px auto;padding:0 16px;font-family:Inter,-apple-system,sans-serif;color:#1F2A24">';
        echo '<style>.bg-sv{background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:26px 24px;position:relative;box-shadow:0 2px 10px rgba(31,42,36,.06)}'
            . '.bg-sv::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#1F5130 0%,#2E7D4F 70%,#C98A1B 100%)}'
            . '.bg-sv fieldset{border:1px solid #D9E5D9;border-radius:12px;padding:14px;margin:14px 0;background:#FBFDFB}'
            . '.bg-sv legend{font-weight:700;padding:0 8px;color:#1F5130}'
            . '.bg-sv input[type=radio]{accent-color:#1F5130}'
            . '.bg-sv label:has(input:focus-visible){outline:2px solid #2E7D4F;outline-offset:2px;border-radius:6px}</style>';
        echo '<div class="bg-sv">';

        if (!$row) {
            echo '<h1>' . esc_html(self::word('survey_invalid_title', 'Survey link not valid')) . '</h1><p>'
                . esc_html(self::word('survey_invalid_body', 'This link may have been copied incompletely. Please use the link from your email.'))
                . '</p>';
        } elseif ($done) {
            echo '<h1>' . esc_html(self::word('survey_received_title', 'Already received')) . '</h1><p>'
                . esc_html(self::word('survey_received_body', 'Thanks! Your feedback for this event is already in.'))
                . '</p>';
        } else {
            $title = get_the_title((int) $row->event_post_id);
            echo '<h1>' . esc_html(self::wordFormat(
                'survey_event_title',
                'How was {event}?',
                ['event' => $title],
                ['event']
            )) . '</h1>';
            echo '<p>' . esc_html(self::word('survey_instructions', 'For each statement, pick the answer closest to your experience.')) . '</p>';
            echo '<form id="bg-survey-form">';
            echo '<input type="hidden" name="reg" value="' . (int) $regId . '">';
            echo '<input type="hidden" name="t" value="' . esc_attr($token) . '">';
            foreach (self::questions() as $key => $label) {
                echo '<fieldset>';
                echo '<legend>' . esc_html($label) . '</legend>';
                echo '<div style="display:flex;flex-wrap:wrap;gap:12px">';
                foreach (self::likertLabels() as $score => $answer) {
                    echo '<label style="display:flex;align-items:center;gap:5px;font-size:.92rem">'
                        . '<input type="radio" name="q[' . esc_attr($key) . ']" value="' . (int) $score . '" required> '
                        . esc_html($answer) . '</label>';
                }
                echo '</div></fieldset>';
            }
            echo '<button type="submit" style="background:linear-gradient(135deg,#2A6B40,#1F5130);color:#fff;'
                . 'font-weight:700;border:0;border-radius:999px;padding:13px 28px;font-size:1rem;cursor:pointer;'
                . 'box-shadow:0 3px 10px rgba(31,81,48,.25)">' . esc_html(self::word('survey_send_button', 'Send feedback')) . '</button>';
            echo '<p id="bg-survey-msg" role="status" style="font-weight:600"></p></form>';
            $surveyEndpoint = wp_json_encode(esc_url_raw(rest_url('bosquegrande/v1/survey')));
            $sendingText = wp_json_encode(self::word('survey_sending_status', 'Sending...'));
            $clientErrorText = wp_json_encode(self::word('survey_client_error', 'Something went wrong. Please try again.'));
            echo '<script>
            (function(){
              var f=document.getElementById("bg-survey-form");
              var endpoint=' . $surveyEndpoint . ';
              f.addEventListener("submit",function(e){
                e.preventDefault();
                var msg=document.getElementById("bg-survey-msg");msg.textContent=' . $sendingText . ';
                fetch(endpoint,{method:"POST",body:new FormData(f),cache:"no-store",credentials:"same-origin"})
                  .then(function(r){return r.json();})
                  .then(function(d){msg.textContent=d.message||"";if(d.ok){f.querySelectorAll("fieldset,button").forEach(function(el){el.hidden=true;});}})
                  .catch(function(){msg.textContent=' . $clientErrorText . ';});
              });
            })();
            </script>';
        }
        echo '</div></div>';
        $content = (string) ob_get_clean();
        $this->outputOwnedDocument($content, [
            'context' => 'survey',
            'body_class' => 'bg-survey-page',
            'main_id' => 'bg-survey-main',
            'skip_label' => self::word('survey_skip', 'Skip to event survey'),
        ], home_url('/bg-survey/' . $regId . '/'));
    }

    /** Use BosqueGrande's public frame when available, retaining theme fallback. */
    private function outputOwnedDocument(string $content, array $args, string $cleanUrl = ''): void
    {
        $urlCleaner = null;
        if ($cleanUrl !== '') {
            $encodedUrl = (string) wp_json_encode($cleanUrl);
            $urlCleaner = static function () use ($encodedUrl): void {
                echo '<script id="bg-sensitive-url-cleaner">try{if(window.history&&window.history.replaceState){window.history.replaceState(null,"",' . $encodedUrl . ');}}catch(e){}</script>';
            };
            // Keep the token in the rendered form while removing it from the
            // browser URL before any later wp_head script can read it.
            add_action('wp_head', $urlCleaner, -PHP_INT_MAX);
        }
        try {
            if (function_exists('bgev_output_owned_document')) {
                \bgev_output_owned_document($content, $args);
                return;
            }
            get_header();
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-owned markup.
            get_footer();
        } finally {
            if ($urlCleaner !== null) {
                remove_action('wp_head', $urlCleaner, -PHP_INT_MAX);
            }
        }
    }

    private function submitSurvey(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $regId = (int) $req->get_param('reg');
        $token = (string) $req->get_param('t');
        $expected = $regId ? self::surveyToken($regId) : '';
        if (!$regId || $expected === '' || !hash_equals($expected, $token)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('survey_api_invalid', 'This survey link is not valid.')], 403);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_registrations WHERE id = %d", $regId
        ));
        if (!$row) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('survey_api_registration_missing', 'Registration not found.')], 404);
        }
        $questions = self::questions();
        $responseCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bg_survey_responses WHERE registration_id = %d", $regId
        ));
        if ($responseCount >= count($questions)) {
            return new \WP_REST_Response(['ok' => true, 'message' => self::word('survey_api_already_received', 'Thanks! Your feedback was already received.')], 200);
        }

        $answers = (array) $req->get_param('q');
        foreach ($questions as $key => $label) {
            $score = isset($answers[$key]) ? (int) $answers[$key] : 0;
            if ($score < 1 || $score > 5) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'message' => self::word('survey_api_incomplete', 'Please answer every question before sending your feedback.'),
                ], 400);
            }
        }

        $claimKey = 'bg_survey_claim_' . $regId;
        $claimOwner = OptionLease::acquire($claimKey, 15 * MINUTE_IN_SECONDS);
        if ($claimOwner === '') {
            $responseCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bg_survey_responses WHERE registration_id = %d",
                $regId
            ));
            $complete = $responseCount >= count($questions);
            return new \WP_REST_Response([
                'ok' => $complete,
                'message' => $complete
                    ? self::word('survey_api_already_received', 'Thanks! Your feedback was already received.')
                    : self::word('survey_api_saving', 'This survey is already being saved. Please wait a moment and try again.'),
            ], $complete ? 200 : 409);
        }
        register_shutdown_function(static function () use ($claimKey, $claimOwner): void {
            OptionLease::release($claimKey, $claimOwner);
        });

        // Repair a legacy interrupted partial set while this registration's
        // single-writer claim prevents deleting another request's answers.
        if ($responseCount > 0
            && $wpdb->delete($wpdb->prefix . 'bg_survey_responses', ['registration_id' => $regId]) === false
        ) {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::word('survey_api_repair_failed', 'An earlier partial response could not be repaired. Please try again.'),
            ], 500);
        }

        $wpdb->query('START TRANSACTION');
        $saved = 0;
        foreach ($questions as $key => $label) {
            $score = (int) $answers[$key];
            $ok = $wpdb->insert($wpdb->prefix . 'bg_survey_responses', [
                'registration_id' => $regId,
                'event_post_id' => (int) $row->event_post_id,
                'question_key' => $key,
                'response_key' => hash('sha256', $regId . '|' . $key),
                'score' => $score,
                'created_at' => current_time('mysql', true),
            ]);
            if (!$ok) {
                $wpdb->query('ROLLBACK');
                // Explicit cleanup also preserves all-or-nothing behavior on
                // hosts whose table engine does not support transactions.
                $wpdb->delete($wpdb->prefix . 'bg_survey_responses', ['registration_id' => $regId]);
                return new \WP_REST_Response([
                    'ok' => false,
                    'message' => self::word('survey_api_save_failed', 'We could not safely save all of your answers. Please try again.'),
                ], 500);
            }
            $saved++;
        }
        if ($wpdb->query('COMMIT') === false || $saved !== count($questions)) {
            $wpdb->delete($wpdb->prefix . 'bg_survey_responses', ['registration_id' => $regId]);
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::word('survey_api_save_failed', 'We could not safely save all of your answers. Please try again.'),
            ], 500);
        }
        $container->get('logger')->info('notifications', 'Survey response saved.', ['registration' => $regId]);
        return new \WP_REST_Response(['ok' => true, 'message' => self::word('survey_api_success', 'Thank you! Your feedback is in.')], 200);
    }

    private function addRewrite(): void
    {
        if (!isset($GLOBALS['wp_rewrite']) || !($GLOBALS['wp_rewrite'] instanceof \WP_Rewrite)) return;
        add_rewrite_rule('^bg-survey/([0-9]+)/?$', 'index.php?bg_survey=$matches[1]', 'top');
    }

    private function renderMailPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $settings = \BosqueGrande\Core\Plugin::instance()->container()->get('settings');
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        global $wpdb;
        $logTable = $wpdb->prefix . 'bg_jobs_log';
        $entries = $wpdb->get_results(
            "SELECT created_at, level, message, context FROM {$logTable}
             WHERE channel IN ('correspondence', 'identity', 'registration', 'payments', 'notifications') ORDER BY id DESC LIMIT 25"
        ) ?: [];
        ?>
        <div class="wrap">
            <h1>Correspondence</h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p style="max-width:780px">Everything about the site's outgoing email lives here: who messages
            are sent as, how they're sent, a test button to prove sending works, and a recent log of what
            went out and what was refused.</p>

            <?php
            $smtpHost = trim((string) $settings->get('smtp_host', ''));
            $smtpUser = trim((string) $settings->get('smtp_username', ''));
            $fromAddress = trim((string) $settings->get('mail_from_address', ''));
            $smtpUnreadable = $settings->secretUnreadable('smtp_password');
            $smtpPasswordSet = $settings->hasSecret('smtp_password') && !$smtpUnreadable;
            ?>
            <h2 class="title">Transport status</h2>
            <table class="widefat striped" style="max-width:780px"><tbody>
                <tr><th style="width:220px">Current route</th><td><?php echo $smtpHost !== ''
                    ? 'Authenticated relay: <code>' . esc_html($smtpHost) . '</code>'
                    : '<strong>Server/PHP mail</strong> (no authenticated relay configured)'; ?></td></tr>
                <tr><th>Relay credentials</th><td><?php echo $smtpHost === '' ? 'Not applicable'
                    : ($smtpUser !== '' && $smtpPasswordSet ? '<span style="color:#2A6B40;font-weight:700">Ready</span>'
                    : '<span style="color:#b32d2e;font-weight:700">Incomplete</span>'); ?></td></tr>
            </tbody></table>
            <?php if ($smtpUnreadable): ?>
                <div class="notice notice-error inline"><p><strong>The saved relay password cannot be decrypted.</strong>
                Re-enter it below before testing mail.</p></div>
            <?php elseif ($smtpHost !== '' && ($smtpUser === '' || !$smtpPasswordSet)): ?>
                <div class="notice notice-warning inline"><p><strong>The relay is incomplete.</strong>
                Enter both a username and relay/app password.</p></div>
            <?php elseif (strcasecmp($smtpHost, 'smtp.gmail.com') === 0 && $smtpUser !== '' && $fromAddress !== '' && strcasecmp($smtpUser, $fromAddress) !== 0): ?>
                <div class="notice notice-warning inline"><p><strong>Gmail sender mismatch:</strong>
                the authenticated account and From address differ. Use the same address unless the From address is a verified Gmail/Workspace alias.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_mail_save'); ?>
                <input type="hidden" name="action" value="bg_mail_save">
                <h2 class="title">Sender identity</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Messages are sent as</th>
                        <td><input type="text" name="mail_from_name" class="regular-text" placeholder="Your Organization"
                            value="<?php echo esc_attr((string) $settings->get('mail_from_name', '')); ?>">
                        <input type="email" name="mail_from_address" class="regular-text" placeholder="hello@yourdomain.org"
                            value="<?php echo esc_attr((string) $settings->get('mail_from_address', '')); ?>">
                        <p class="description">The name and address the site's messages come from.</p></td></tr>
                    <tr><th scope="row">Replies are directed to</th>
                        <td><input type="email" name="mail_reply_to" class="regular-text" placeholder="replies@yourdomain.org"
                            value="<?php echo esc_attr((string) $settings->get('mail_reply_to', '')); ?>">
                        <p class="description">Optional. Replies to automated messages are sent to this monitored mailbox.</p></td></tr>
                </table>

                <h2 class="title">How mail is sent</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Mail relay (optional)</th>
                        <td>
                            <input type="text" name="smtp_host" class="regular-text code" placeholder="smtp.example.com"
                                value="<?php echo esc_attr((string) $settings->get('smtp_host', '')); ?>">
                            <input type="number" name="smtp_port" style="width:90px" placeholder="587"
                                value="<?php echo esc_attr((string) $settings->get('smtp_port', 587)); ?>">
                            <select name="smtp_secure">
                                <option value="tls" <?php selected((string) $settings->get('smtp_secure', 'tls'), 'tls'); ?>>TLS</option>
                                <option value="ssl" <?php selected((string) $settings->get('smtp_secure', 'tls'), 'ssl'); ?>>SSL</option>
                                <option value="none" <?php selected((string) $settings->get('smtp_secure', 'tls'), 'none'); ?>>None</option>
                            </select><br>
                            <input type="text" name="smtp_username" class="regular-text" placeholder="Relay username" autocomplete="off"
                                value="<?php echo esc_attr((string) $settings->get('smtp_username', '')); ?>">
                            <input type="password" name="smtp_password" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo $settings->hasSecret('smtp_password') ? 'Password on file (leave blank to keep)' : 'Relay password'; ?>">
                            <p class="description">For Gmail/Google Workspace: host <code>smtp.gmail.com</code>, port <code>587</code>, TLS, the complete email address as username, and a 16-character Google App Password (not the normal account password). For a separate WordPress mail/OAuth plugin, leave these relay fields blank so the two systems do not compete. The password is stored encrypted and never shown again.</p>
                        </td></tr>
                    <tr><th scope="row">Daily bulk-send cap</th>
                        <td><input type="number" name="daily_send_cap" style="width:110px" min="0" placeholder="0"
                            value="<?php echo esc_attr((string) $settings->get('daily_send_cap', 0)); ?>">
                        <p class="description">0 means no cap. Personal and organizational mail relays enforce
                        account-specific daily limits and can suspend sending when a newsletter exceeds one. With a cap set,
                        newsletter delivery pauses at the cap and resumes by itself the next day; single
                        messages (confirmations, receipts, tests) are never held.</p></td></tr>
                </table>

                <h2 class="title">Keeping messages out of spam</h2>
                <p style="max-width:780px">Three things help, in order of impact. First, send from an address at
                your own domain, ideally through your own mail provider as the relay above; mail systems trust
                senders whose address matches where the mail really comes from. Second, keep the sending address
                consistent; changing it resets the reputation it has built. Third, ask regular recipients to add
                the address to their contacts once.</p>

                <p><button class="button button-primary">Save settings</button></p>
            </form>

            <h2 class="title">Send a test</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_mail_test'); ?>
                <input type="hidden" name="action" value="bg_mail_test">
                <input type="email" name="test_address" class="regular-text" placeholder="Send the test to this address" required>
                <button class="button">Send test message</button>
                <p class="description">The send and its outcome show up in the log below either way.</p>
            </form>

            <h2 class="title">Recent mail log</h2>
            <?php if ($entries): ?>
                <table class="widefat striped" style="max-width:960px">
                    <thead><tr><th>When (UTC)</th><th>Entry</th></tr></thead>
                    <tbody>
                    <?php foreach ($entries as $e): ?>
                        <tr><td style="white-space:nowrap"><?php echo esc_html((string) $e->created_at); ?></td>
                            <td><?php echo esc_html((string) $e->message); ?>
                            <?php if (!empty($e->context) && $e->context !== '[]'): ?><code style="font-size:11px"><?php echo esc_html((string) $e->context); ?></code><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <?php global $wpdb; $lt = $wpdb->prefix . 'bg_jobs_log';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lt)) !== $lt): ?>
                    <p><strong>The log table is missing.</strong> Deactivate and reactivate BosqueGrande to
                    rebuild it, then check System Status; sends are written to the server error log meanwhile.</p>
                <?php else: ?>
                    <p>No mail log entries yet. Send a test message above to make the first one.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handleMailSave(\BosqueGrande\Core\Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_mail_save');
        $settings = $container->get('settings');
        $settings->set('mail_from_name', sanitize_text_field((string) ($_POST['mail_from_name'] ?? '')));
        $settings->set('mail_from_address', sanitize_email((string) ($_POST['mail_from_address'] ?? '')));
        $settings->set('mail_reply_to', sanitize_email((string) ($_POST['mail_reply_to'] ?? '')));
        $settings->set('smtp_host', sanitize_text_field((string) ($_POST['smtp_host'] ?? '')));
        $settings->set('smtp_port', max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587))));
        $sec = (string) ($_POST['smtp_secure'] ?? 'tls');
        $settings->set('smtp_secure', in_array($sec, ['tls', 'ssl', 'none'], true) ? $sec : 'tls');
        $settings->set('smtp_username', sanitize_text_field((string) ($_POST['smtp_username'] ?? '')));
        $sp = (string) ($_POST['smtp_password'] ?? '');
        $secretSaved = $sp === '' || $settings->set('smtp_password', $sp);
        $settings->set('daily_send_cap', max(0, (int) ($_POST['daily_send_cap'] ?? 0)));
        $container->get('logger')->info('correspondence', 'Correspondence settings revised.', []);
        $notice = $secretSaved
            ? 'Correspondence settings saved.'
            : 'Mail settings were saved, but the relay password was refused because encrypted storage is unavailable.';
        wp_safe_redirect(add_query_arg(['page' => 'bosquegrande-mail', 'bg_notice' => rawurlencode($notice)], admin_url('admin.php')));
        exit;
    }
}
