<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Notifications\NotificationsModule;

if (!defined('ABSPATH')) exit;

/** Branded reminder, survey, and verified-newcomer workflow. */
final class FollowupAutomation
{
    public const CRON = 'bg_followup_tick';
    private const OPT_REMINDER = 'bg_followup_auto_reminder';
    private const OPT_SURVEY = 'bg_followup_auto_survey';
    private const OPT_NEWCOMER = 'bg_followup_auto_newcomer';
    private const BATCH = 25;
    private const MAX_ATTEMPTS = 24;

    public function __construct(
        private Container $container,
        private HistoricalParticipation $history,
    ) {
    }

    public function register(): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_action('init', [$this, 'schedule'], 90);
        add_action(self::CRON, [$this, 'runAutomatic']);
        add_action('template_redirect', [$this, 'unsubscribeLanding'], 2);
        if (is_admin()) {
            add_action('admin_menu', function (): void {
                add_submenu_page(
                    'bosquegrande',
                    'Follow-up Messages',
                    'Follow-up Messages',
                    'manage_options',
                    'bosquegrande-followups',
                    [$this, 'renderAdmin']
                );
            }, 36);
            add_action('admin_post_bg_followup_settings', [$this, 'saveSettings']);
            add_action('admin_post_bg_followup_release', [$this, 'manualRelease']);
            add_action('admin_notices', [$this, 'dueNotice']);
        }
    }

    /**
     * Public follow-up wording. Event/profile/unsubscribe destinations and
     * delivery eligibility remain immutable and are inserted by the renderer.
     *
     * @param array<string,array<string,mixed>> $defaults
     * @return array<string,array<string,mixed>>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $group = 'Surveys & event notifications';
        $local = [
            'followup_greeting_named' => [
                'label' => 'Follow-up email: greeting when a first name is known',
                'text' => 'Hi {first},',
                'required_tokens' => ['first'],
            ],
            'followup_greeting_generic' => [
                'label' => 'Follow-up email: greeting when no first name is known',
                'text' => 'Hello,',
            ],
            'followup_reminder_subject' => [
                'label' => 'Follow-up reminder: subject',
                'text' => 'A reminder about {event}',
                'required_tokens' => ['event'],
            ],
            'followup_reminder_intro' => [
                'label' => 'Follow-up reminder: registration sentence',
                'text' => 'This is a warm reminder that you’re registered for {event} on {when}.',
                'required_tokens' => ['event', 'when'],
            ],
            'followup_reminder_closing' => [
                'label' => 'Follow-up reminder: closing',
                'text' => 'We look forward to seeing you there.',
            ],
            'followup_survey_subject' => [
                'label' => 'Follow-up survey: subject',
                'text' => 'Thank you for attending {event}',
                'required_tokens' => ['event'],
            ],
            'followup_survey_thanks' => [
                'label' => 'Follow-up survey: thank-you sentence',
                'text' => 'Thank you for attending {event}. We hope you felt welcome and enjoyed the event.',
                'required_tokens' => ['event'],
            ],
            'followup_survey_prompt' => [
                'label' => 'Follow-up survey: feedback invitation',
                'text' => 'Your brief feedback helps us improve future gatherings.',
            ],
            'followup_survey_button' => [
                'label' => 'Follow-up survey: button',
                'text' => 'Share Feedback',
            ],
            'followup_survey_duration' => [
                'label' => 'Follow-up survey: time note',
                'text' => 'The survey usually takes about two minutes.',
            ],
            'followup_newcomer_subject' => [
                'label' => 'Newcomer welcome: subject',
                'text' => 'Welcome to {org}',
            ],
            'followup_newcomer_intro' => [
                'label' => 'Newcomer welcome: opening',
                'text' => 'It was wonderful to welcome you to {event}. We hope this was the beginning of many good connections.',
                'required_tokens' => ['event'],
            ],
            'followup_newcomer_profile' => [
                'label' => 'Newcomer welcome: My Profile explanation',
                'text' => 'Setting up My Profile makes future event registration faster and gives you control over your contact information and newsletter subscriptions.',
            ],
            'followup_newcomer_profile_button' => [
                'label' => 'Newcomer welcome: My Profile button',
                'text' => 'My Profile',
            ],
            'followup_newcomer_unsubscribe_intro' => [
                'label' => 'Newcomer welcome: unsubscribe introduction',
                'text' => 'Prefer not to receive first-attendance follow-up messages?',
            ],
            'followup_newcomer_unsubscribe_link' => [
                'label' => 'Newcomer welcome: unsubscribe link',
                'text' => 'Unsubscribe here',
            ],
            'followup_unsubscribe_invalid_title' => [
                'label' => 'Follow-up unsubscribe: invalid-link heading',
                'text' => 'This link is not valid',
            ],
            'followup_unsubscribe_invalid_body' => [
                'label' => 'Follow-up unsubscribe: invalid-link explanation',
                'text' => 'Please use the complete link from the message.',
            ],
            'followup_unsubscribe_confirm_title' => [
                'label' => 'Follow-up unsubscribe: confirmation heading',
                'text' => 'Unsubscribe from follow-up messages?',
            ],
            'followup_unsubscribe_confirm_body' => [
                'label' => 'Follow-up unsubscribe: confirmation explanation',
                'text' => 'Confirm below to stop first-attendance follow-up messages. Your newsletter choices will not change.',
            ],
            'followup_unsubscribe_success_title' => [
                'label' => 'Follow-up unsubscribe: success heading',
                'text' => 'You’re unsubscribed',
            ],
            'followup_unsubscribe_success_body' => [
                'label' => 'Follow-up unsubscribe: success explanation',
                'text' => 'You will not receive first-attendance follow-up messages. Your newsletter choices are unchanged and can be managed in My Profile.',
            ],
            'followup_unsubscribe_button' => [
                'label' => 'Follow-up unsubscribe: confirmation button',
                'text' => 'Confirm unsubscribe',
            ],
            'followup_unsubscribe_skip' => [
                'label' => 'Follow-up unsubscribe: skip link',
                'text' => 'Skip to unsubscribe options',
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

    public function schedule(): void
    {
        $enabled = $this->auto('reminder') || $this->auto('survey') || $this->auto('newcomer');
        $next = wp_next_scheduled(self::CRON);
        if ($enabled && !$next) wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON);
        if (!$enabled && $next) wp_clear_scheduled_hook(self::CRON);
    }

    public function runAutomatic(): void
    {
        foreach (['reminder', 'survey', 'newcomer'] as $kind) {
            if (!$this->auto($kind)) continue;
            $result = $this->process($kind, self::BATCH);
            if (!empty($result['transport_failed'])) break;
        }
    }

    public function renderAdmin(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) wp_unslash($_GET['bg_notice'])) : '';
        $due = [];
        foreach (['reminder', 'survey', 'newcomer'] as $kind) $due[$kind] = count($this->candidateRows($kind, 500));
        ?>
        <div class="wrap"><h1>Follow-up Messages</h1>
        <?php if ($notice !== '') : ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:800px">BosqueGrande prepares three branded, personalized messages. Automatic sending is deliberately off until an administrator enables it. Manual release remains available for review-first operation.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_followup_settings'); ?>
            <input type="hidden" name="action" value="bg_followup_settings">
            <table class="form-table"><tbody>
                <tr><th scope="row">Event reminders</th><td><label><input type="checkbox" name="auto_reminder" value="1" <?php checked($this->auto('reminder')); ?>> Send automatically about 48 hours before the event, only when registration occurred at least 72 hours before the start.</label></td></tr>
                <tr><th scope="row">Post-event surveys</th><td><label><input type="checkbox" name="auto_survey" value="1" <?php checked($this->auto('survey')); ?>> Send automatically two days after attendance recorded by the organizer app.</label></td></tr>
                <tr><th scope="row">Newcomer welcome</th><td><label><input type="checkbox" name="auto_newcomer" value="1" <?php checked($this->auto('newcomer')); ?>> Send automatically four days after the event, and at least two days after the survey, only to verified first-time attendees.</label></td></tr>
            </tbody></table>
            <p><button class="button button-primary">Save sending settings</button></p>
        </form>
        <hr><h2>Messages due now</h2>
        <table class="widefat striped" style="max-width:900px"><thead><tr><th>Message</th><th>Eligible</th><th>Automatic</th><th>Manual action</th></tr></thead><tbody>
        <?php foreach ([
            'reminder' => 'Event reminders',
            'survey' => 'Post-event thank-you and survey',
            'newcomer' => 'Newcomer welcome and My Profile invitation',
        ] as $kind => $label) : ?>
            <tr><td><?php echo esc_html($label); ?></td><td><?php echo (int) $due[$kind]; ?></td><td><?php echo $this->auto($kind) ? 'On' : 'Off'; ?></td><td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Release one bounded <?php echo esc_js($kind); ?> batch now?');">
                    <?php wp_nonce_field('bg_followup_release_' . $kind); ?>
                    <input type="hidden" name="action" value="bg_followup_release"><input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
                    <button class="button" <?php disabled($due[$kind], 0); ?>>Release up to <?php echo (int) self::BATCH; ?></button>
                </form>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p class="description" style="max-width:900px">A mail transport refusal stops the batch. Accepted mail is stamped before the row can be selected again. Historical events never generate mail.</p>
        </div>
        <?php
    }

    public function saveSettings(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_followup_settings');
        update_option(self::OPT_REMINDER, !empty($_POST['auto_reminder']) ? '1' : '0', false);
        update_option(self::OPT_SURVEY, !empty($_POST['auto_survey']) ? '1' : '0', false);
        update_option(self::OPT_NEWCOMER, !empty($_POST['auto_newcomer']) ? '1' : '0', false);
        $this->schedule();
        $this->redirect('Sending settings saved.');
    }

    public function manualRelease(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        if (!in_array($kind, ['reminder', 'survey', 'newcomer'], true)) wp_die('Unknown message type.');
        check_admin_referer('bg_followup_release_' . $kind);
        $result = $this->process($kind, self::BATCH);
        $message = ucfirst($kind) . ' batch processed ' . (int) $result['processed'] . ' registration(s); '
            . (int) $result['accepted'] . ' message(s) were accepted by the mailer.';
        if (!empty($result['transport_failed'])) $message .= ' The batch stopped after a mail transport refusal.';
        $this->redirect($message);
    }

    public function dueNotice(): void
    {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if ($screen && $screen->id === 'bosquegrande_page_bosquegrande-followups') return;
        $counts = [];
        $total = 0;
        foreach (['reminder', 'survey', 'newcomer'] as $kind) {
            if ($this->auto($kind)) continue;
            $counts[$kind] = count($this->candidateRows($kind, 100));
            $total += $counts[$kind];
        }
        if ($total < 1) return;
        $url = admin_url('admin.php?page=bosquegrande-followups');
        echo '<div class="notice notice-info"><p><strong>BosqueGrande follow-up:</strong> '
            . (int) ($counts['reminder'] ?? 0) . ' event reminder(s), '
            . (int) ($counts['survey'] ?? 0) . ' survey message(s), and '
            . (int) ($counts['newcomer'] ?? 0) . ' newcomer welcome(s) are ready for review. '
            . '<a href="' . esc_url($url) . '">Review messages</a>.</p></div>';
    }

    /** @return array{processed:int,accepted:int,transport_failed:bool} */
    public function process(string $kind, int $limit): array
    {
        $rows = $this->candidateRows($kind, $limit);
        $result = ['processed' => 0, 'accepted' => 0, 'transport_failed' => false];
        foreach ($rows as $row) {
            $result['processed']++;
            if ($this->send($kind, $row)) $result['accepted']++;
            else {
                $lastError = (string) get_transient('bg_last_mail_error');
                if ($lastError !== '') {
                    $result['transport_failed'] = true;
                    break;
                }
            }
        }
        return $result;
    }

    /** @return list<object> */
    public function candidateRows(string $kind, int $limit = 500): array
    {
        global $wpdb;
        if (!in_array($kind, ['reminder', 'survey', 'newcomer'], true)) return [];
        $limit = max(1, min(500, $limit));
        $column = $kind . '_sent_at';
        $attempt = $kind . '_attempts';
        $hasAttempt = $kind !== 'newcomer' || $this->columnExists('bg_registrations', 'newcomer_attempts');
        $attemptSql = $hasAttempt ? "AND COALESCE(r.{$attempt},0) < " . self::MAX_ATTEMPTS : '';
        $surveyRequirement = $kind === 'newcomer' ? 'AND r.survey_sent_at IS NOT NULL' : '';
        // Bound the SQL candidate pool before the exact timestamp checks below.
        // Without this, years of old unsent registrations could occupy the
        // fixed query limit and prevent a currently due message from appearing.
        $today = new \DateTimeImmutable('today', wp_timezone());
        if ($kind === 'reminder') {
            $dateFloor = $today->modify('+1 day')->format('Y-m-d');
            $dateCeiling = $today->modify('+3 days')->format('Y-m-d');
        } elseif ($kind === 'survey') {
            $dateFloor = $today->modify('-31 days')->format('Y-m-d');
            $dateCeiling = $today->modify('-1 day')->format('Y-m-d');
        } else {
            // A welcome is timely only near the first event. Do not resurrect a
            // years-old backlog after an administrator first enables automation.
            $dateFloor = $today->modify('-31 days')->format('Y-m-d');
            $dateCeiling = $today->modify('-3 days')->format('Y-m-d');
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, d.meta_value AS event_date, tm.meta_value AS event_time,
                    a.status AS attendance_status, p.post_title, p.post_content,
                    COALESCE(i.match_status, '') AS intake_match_status
             FROM {$wpdb->prefix}bg_registrations r
             JOIN {$wpdb->posts} p ON p.ID = r.event_post_id AND p.post_type = 'bg_event' AND p.post_status = 'publish'
             JOIN {$wpdb->postmeta} d ON d.post_id = r.event_post_id AND d.meta_key = '_bg_start_date'
             LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = r.event_post_id AND tm.meta_key = '_bg_start_time'
             LEFT JOIN {$wpdb->prefix}bg_event_attendance a ON a.registration_id = r.id
             LEFT JOIN {$wpdb->prefix}bg_event_intakes i ON i.registration_id = r.id
             WHERE r.status IN ('confirmed','paid')
               AND r.tier NOT IN ('historical','eventbrite')
               AND r.{$column} IS NULL
               {$attemptSql}
               {$surveyRequirement}
               AND d.meta_value BETWEEN %s AND %s
               AND COALESCE(p.post_status,'') <> 'bg_historical'
             ORDER BY d.meta_value DESC, r.id ASC LIMIT 1000",
            $dateFloor,
            $dateCeiling
        )) ?: [];

        $now = time();
        $eligible = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event_post_id;
            if ((string) get_post_meta($eventId, '_bg_historical_import', true) === '1') continue;
            $start = $this->eventStart((string) $row->event_date, (string) ($row->event_time ?? ''));
            if (!$start) continue;
            $end = $start + (int) round((float) apply_filters('bg_event_duration_hours', 3.0, $eventId) * HOUR_IN_SECONDS);
            if ($kind === 'reminder') {
                $created = strtotime((string) ($row->created_at ?? '') . ' UTC') ?: 0;
                $until = $start - $now;
                if ($until >= 36 * HOUR_IN_SECONDS && $until <= 60 * HOUR_IN_SECONDS
                    && $created > 0 && $created <= $start - 72 * HOUR_IN_SECONDS
                ) $eligible[] = $row;
                continue;
            }
            $attendance = (string) ($row->attendance_status ?? '');
            if ($attendance === '') {
                $attendance = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}bg_event_attendance WHERE registration_id = %d",
                    (int) $row->id
                ));
            }
            if (!in_array($attendance, ['checked_in', 'attended'], true)) continue;
            if ($kind === 'survey' && $now >= $end + 2 * DAY_IN_SECONDS && $now <= $end + 30 * DAY_IN_SECONDS) {
                $eligible[] = $row;
                continue;
            }
            if ($kind === 'newcomer') {
                // Never classify an unresolved ambiguous door match as a
                // newcomer. Online registrations have no intake row and still
                // receive the normal historical/native newcomer evaluation.
                if ((string) ($row->intake_match_status ?? '') === 'ambiguous') continue;
                $surveySent = strtotime((string) ($row->survey_sent_at ?? '') . ' UTC') ?: 0;
                $constituentId = (int) ($row->bg_constituent_id ?? 0);
                if ($now < $end + 4 * DAY_IN_SECONDS || $surveySent < 1 || $now < $surveySent + 2 * DAY_IN_SECONDS) continue;
                if ($constituentId < 1 || $this->container->get('constituents.store')->latestConsent($constituentId, 'event_followup') === false) continue;
                if (!$this->history->isNewcomer($constituentId, (string) $row->event_date, $eventId)) continue;
                $eligible[] = $row;
            }
        }
        return array_slice($eligible, 0, $limit);
    }

    private function send(string $kind, object $row): bool
    {
        $registrationId = (int) ($row->id ?? 0);
        $receiptKey = 'bg_followup_mail_receipt_' . $kind . '_' . $registrationId;
        $receipt = (string) get_option($receiptKey, '');
        if ($receipt !== '') {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'bg_registrations',
                [$kind . '_sent_at' => $receipt],
                ['id' => $registrationId]
            );
            if ((string) $wpdb->get_var($wpdb->prepare(
                "SELECT {$kind}_sent_at FROM {$wpdb->prefix}bg_registrations WHERE id = %d",
                $registrationId
            )) !== '') delete_option($receiptKey);
            return true;
        }
        $claimKey = 'bg_followup_mail_claim_' . $kind . '_' . $registrationId;
        $claimOwner = OptionLease::acquire($claimKey, 15 * MINUTE_IN_SECONDS);
        if ($claimOwner === '') return false;
        register_shutdown_function(static function () use ($claimKey, $claimOwner): void {
            OptionLease::release($claimKey, $claimOwner);
        });

        $constituentId = (int) ($row->bg_constituent_id ?? 0);
        $record = $constituentId > 0 ? $this->container->get('constituents.store')->get($constituentId) : null;
        $email = is_array($record) ? sanitize_email((string) ($record['email'] ?? '')) : '';
        if (!is_email($email)) return $this->failure($row, $kind, 'no valid recipient email');
        $first = is_array($record) ? trim((string) ($record['first_name'] ?? '')) : '';
        $greeting = $first !== ''
            ? self::wordFormat('followup_greeting_named', 'Hi {first},', ['first' => $first], ['first'])
            : self::word('followup_greeting_generic', 'Hello,');
        $title = (string) ($row->post_title ?? get_the_title((int) $row->event_post_id));
        $logo = $this->logoUrl();
        $subject = '';
        $body = '';

        if ($kind === 'reminder') {
            $start = $this->eventStart((string) $row->event_date, (string) ($row->event_time ?? ''));
            $when = $start ? wp_date('l, F j \a\t g:i a', $start) : (string) $row->event_date;
            $description = wp_kses_post(wpautop((string) ($row->post_content ?? '')));
            $subject = self::wordFormat(
                'followup_reminder_subject',
                'A reminder about {event}',
                ['event' => $title],
                ['event']
            );
            $intro = self::wordFormat(
                'followup_reminder_intro',
                'This is a warm reminder that you’re registered for {event} on {when}.',
                ['event' => $title, 'when' => $when],
                ['event', 'when']
            );
            $body = '<p>' . esc_html($greeting) . '</p><p>' . esc_html($intro) . '</p>'
                . ($description !== '' ? '<div style="margin:20px 0;padding:18px;background:#f7f5fb;border-radius:10px">' . $description . '</div>' : '')
                . '<p>' . esc_html(self::word('followup_reminder_closing', 'We look forward to seeing you there.')) . '</p>';
        } elseif ($kind === 'survey') {
            $token = NotificationsModule::surveyToken((int) $row->id);
            if ($token === '') return $this->failure($row, $kind, 'survey link could not be signed');
            $link = add_query_arg('t', $token, home_url('/bg-survey/' . (int) $row->id . '/'));
            $subject = self::wordFormat(
                'followup_survey_subject',
                'Thank you for attending {event}',
                ['event' => $title],
                ['event']
            );
            $thanks = self::wordFormat(
                'followup_survey_thanks',
                'Thank you for attending {event}. We hope you felt welcome and enjoyed the event.',
                ['event' => $title],
                ['event']
            );
            $body = '<p>' . esc_html($greeting) . '</p><p>' . esc_html($thanks) . '</p><p>'
                . esc_html(self::word('followup_survey_prompt', 'Your brief feedback helps us improve future gatherings.')) . '</p>'
                . $this->button($link, self::word('followup_survey_button', 'Share Feedback'))
                . '<p style="font-size:13px;color:#6d6875">'
                . esc_html(self::word('followup_survey_duration', 'The survey usually takes about two minutes.')) . '</p>';
        } else {
            $profile = $this->profileUrl();
            $unsubscribe = $this->unsubscribeUrl($constituentId);
            $subject = self::word('followup_newcomer_subject', 'Welcome to {org}');
            $intro = self::wordFormat(
                'followup_newcomer_intro',
                'It was wonderful to welcome you to {event}. We hope this was the beginning of many good connections.',
                ['event' => $title],
                ['event']
            );
            $body = '<p>' . esc_html($greeting) . '</p><p>' . esc_html($intro) . '</p><p>'
                . esc_html(self::word('followup_newcomer_profile', 'Setting up My Profile makes future event registration faster and gives you control over your contact information and newsletter subscriptions.')) . '</p>'
                . $this->button($profile, self::word('followup_newcomer_profile_button', 'My Profile'))
                . '<p style="font-size:11px;color:#888;margin-top:28px">'
                . esc_html(self::word('followup_newcomer_unsubscribe_intro', 'Prefer not to receive first-attendance follow-up messages?'))
                . ' <a href="' . esc_url($unsubscribe) . '" style="color:#777">'
                . esc_html(self::word('followup_newcomer_unsubscribe_link', 'Unsubscribe here')) . '</a>.</p>';
        }

        delete_transient('bg_last_mail_error');
        $html = $this->wrap($logo, $body);
        $sent = wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) return $this->failure($row, $kind, 'mailer refused the message');
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'bg_registrations',
            [$kind . '_sent_at' => current_time('mysql', true)],
            ['id' => (int) $row->id]
        );
        if ($updated === false) {
            update_option($receiptKey, current_time('mysql', true), false);
            $this->container->get('logger')->error('engagement', 'Follow-up was accepted but its sent stamp failed.', ['kind' => $kind, 'registration' => (int) $row->id]);
        }
        OptionLease::release($claimKey, $claimOwner);
        return true;
    }

    private function failure(object $row, string $kind, string $reason): bool
    {
        global $wpdb;
        $column = $kind . '_attempts';
        if (!$this->columnExists('bg_registrations', $column)) return false;
        $attempts = (int) ($row->{$column} ?? 0) + 1;
        // Exhausted attempts remain visibly unsent. The attempt ceiling keeps
        // the row out of future batches without falsifying a sent timestamp;
        // in particular, a failed survey must never unlock a newcomer welcome.
        $data = [$column => $attempts];
        $wpdb->update($wpdb->prefix . 'bg_registrations', $data, ['id' => (int) $row->id]);
        $this->container->get('logger')->warn('engagement', 'Follow-up message was not sent.', [
            'kind' => $kind,
            'registration' => (int) $row->id,
            'attempt' => $attempts,
            'reason' => $reason,
        ]);
        return false;
    }

    public function unsubscribeLanding(): void
    {
        $isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $source = $isPost ? $_POST : $_GET;
        $id = isset($source['bg_followup_unsubscribe']) ? absint($source['bg_followup_unsubscribe']) : 0;
        $token = isset($source['token']) ? sanitize_text_field((string) wp_unslash($source['token'])) : '';
        if ($id < 1 || $token === '') return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        $expected = $this->sign('event-followup|' . $id);
        $valid = $expected !== '' && hash_equals($expected, $token);
        $ok = $valid;
        if ($isPost && $valid) {
            $ok = $this->container->get('constituents.store')
                ->recordConsent($id, 'event_followup', false, 'email_unsubscribe');
        }
        status_header($ok ? 200 : 403);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        $title = !$ok
            ? self::word('followup_unsubscribe_invalid_title', 'This link is not valid')
            : ($isPost
                ? self::word('followup_unsubscribe_success_title', 'You’re unsubscribed')
                : self::word('followup_unsubscribe_confirm_title', 'Unsubscribe from follow-up messages?'));
        $message = !$ok
            ? self::word('followup_unsubscribe_invalid_body', 'Please use the complete link from the message.')
            : ($isPost
                ? self::word('followup_unsubscribe_success_body', 'You will not receive first-attendance follow-up messages. Your newsletter choices are unchanged and can be managed in My Profile.')
                : self::word('followup_unsubscribe_confirm_body', 'Confirm below to stop first-attendance follow-up messages. Your newsletter choices will not change.'));
        ob_start();
        echo '<div role="region" aria-labelledby="bg-followup-unsubscribe-title" style="max-width:640px;margin:48px auto;padding:0 20px"><h1 id="bg-followup-unsubscribe-title">'
            . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>';
        if ($ok && !$isPost) {
            echo '<form method="post" action="' . esc_url(home_url('/')) . '">'
                . '<input type="hidden" name="bg_followup_unsubscribe" value="' . (int) $id . '">'
                . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
                . '<button type="submit" style="border:0;border-radius:999px;padding:11px 18px;background:#1F5130;color:#fff;font-weight:700;cursor:pointer">'
                . esc_html(self::word('followup_unsubscribe_button', 'Confirm unsubscribe')) . '</button></form>';
        }
        echo '</div>';
        $content = (string) ob_get_clean();
        $this->outputOwnedDocument($content, [
            'context' => 'unsubscribe',
            'body_class' => 'bg-followup-unsubscribe-page',
            'main_id' => 'bg-followup-unsubscribe-main',
            'skip_label' => self::word('followup_unsubscribe_skip', 'Skip to unsubscribe options'),
        ], home_url('/'));
        exit;
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
            // The confirmation form keeps its hidden token, while this runs
            // before later wp_head scripts can inspect the query string.
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

    private function unsubscribeUrl(int $constituentId): string
    {
        return add_query_arg([
            'bg_followup_unsubscribe' => $constituentId,
            'token' => $this->sign('event-followup|' . $constituentId),
        ], home_url('/'));
    }

    private function sign(string $payload): string
    {
        $key = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')->signingKey('followup-link');
        return $key !== '' ? hash_hmac('sha256', $payload, $key) : '';
    }

    private function profileUrl(): string
    {
        return function_exists('bgev_public_profile_url') ? bgev_public_profile_url() : home_url('/');
    }

    private function logoUrl(): string
    {
        return \BosqueGrande\Core\Organization::logoUrl();
    }

    private function wrap(string $logo, string $body): string
    {
        $name = \BosqueGrande\Core\Organization::name();
        $masthead = $logo !== ''
            ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '" style="max-width:180px;max-height:90px;width:auto;height:auto">'
            : '<div style="font-size:22px;font-weight:700;color:' . esc_attr(\BosqueGrande\Core\Organization::brandColor()) . '">' . esc_html($name) . '</div>';
        return '<!doctype html><html><body style="margin:0;background:#f4f6f3;color:#1f2a24;font-family:Arial,sans-serif">'
            . '<div style="max-width:640px;margin:0 auto;padding:28px 16px"><div style="background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(20,40,25,.08)">'
            . '<div style="text-align:center;margin-bottom:22px">' . $masthead . '</div>'
            . $body . '</div></div></body></html>';
    }

    private function button(string $url, string $label): string
    {
        return '<p style="text-align:center;margin:26px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:' . esc_attr(\BosqueGrande\Core\Organization::brandColor()) . ';color:#fff;text-decoration:none;font-weight:700;padding:13px 25px;border-radius:999px">' . esc_html($label) . '</a></p>';
    }

    private function eventStart(string $date, string $time): int|false
    {
        $time = trim($time) !== '' ? trim($time) : '18:00';
        try {
            $dt = new \DateTimeImmutable($date . ' ' . $time, wp_timezone());
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function auto(string $kind): bool
    {
        $option = match ($kind) {
            'reminder' => self::OPT_REMINDER,
            'survey' => self::OPT_SURVEY,
            default => self::OPT_NEWCOMER,
        };
        return (string) get_option($option, '0') === '1';
    }

    private function columnExists(string $suffix, string $column): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'bosquegrande-followups',
            'bg_notice' => rawurlencode($message),
        ], admin_url('admin.php')));
        exit;
    }
}
