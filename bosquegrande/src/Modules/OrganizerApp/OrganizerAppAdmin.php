<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerApp;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\OrganizerPortal\OrganizerRepository;
use BosqueGrande\Modules\Engagement\PaymentDetails;

if (!defined('ABSPATH')) exit;

/** Staff review queue for ambiguous walk-ins, closeouts, and corrections. */
final class OrganizerAppAdmin
{
    public const PAGE = 'bosquegrande-organizer-app';

    public function __construct(
        private OrganizerAppRepository $repository,
        private OrganizerAppService $service,
        private ConstituentStore $constituents,
        private OrganizerRepository $portal,
        private PaymentDetails $paymentDetails,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'bosquegrande',
                'Organizer App Review',
                'Organizer App Review',
                PaymentDetails::CAP,
                self::PAGE,
                [$this, 'render']
            );
        }, 36);
        add_action('admin_post_bg_app_review_intake', [$this, 'handleIntake']);
        add_action('admin_post_bg_app_review_closeout', [$this, 'handleCloseout']);
        add_action('admin_post_bg_app_void_tender', [$this, 'handleVoidTender']);
        add_action('admin_post_bg_app_export_operations', [$this, 'handleExport']);
    }

    public function render(): void
    {
        if (!current_user_can(PaymentDetails::CAP)) wp_die('Insufficient permissions.');
        $isAdmin = current_user_can('manage_options');
        echo '<div class="wrap"><h1>' . esc_html($isAdmin ? 'Event Organizer App' : 'Event Money') . '</h1>';
        $notice = sanitize_key((string) ($_GET['bg_app_notice'] ?? ''));
        $messages = [
            'intake_saved' => 'The intake review was updated.',
            'closeout_saved' => 'The event closeout was updated.',
            'tender_voided' => 'The payment was voided with a compensating ledger entry.',
            'failed' => 'The requested organizer-app action could not be completed.',
        ];
        if (isset($messages[$notice])) {
            echo '<div class="notice ' . ($notice === 'failed' ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>'
                . esc_html($messages[$notice]) . '</p></div>';
        }
        if ($isAdmin) {
            echo '<div class="notice notice-info inline"><p><strong>Organizer app:</strong> <a href="'
                . esc_url(OrganizerAppRenderer::url()) . '" target="_blank" rel="noopener">'
                . esc_html(OrganizerAppRenderer::url()) . '</a></p><p>The original shortcode portal remains available as a fallback. The app is online-first and never stores rosters or payment records for offline use.</p></div>';
        } else {
            echo '<div class="notice notice-info inline"><p>This accountant view is read-only and shows cash/check records with constituent and event details. Event intake, closeout decisions, and corrections remain administrator-only.</p></div>';
        }
        if (!$this->repository->available()) {
            echo '<div class="notice notice-error"><p>The organizer app tables are unavailable. Check the M027 migration and BosqueGrande System Status.</p></div></div>';
            return;
        }
        if ($isAdmin) {
            $this->renderExport();
            $this->renderIntakes();
            $this->renderCloseouts();
        }
        $this->renderTenders();
        echo '</div>';
    }

    private function renderExport(): void
    {
        $events = $this->portal->eventsForUser(get_current_user_id(), true);
        echo '<h2>Event operations export</h2>';
        echo '<p>Download a staff-only CSV of registrations, attendance state, intake completion, tender metadata, and closeout links. It excludes demographic answers, profile notes, mailing-list choices, check references, and payment credentials.</p>';
        if (!$events) {
            echo '<p>No published events are available to export.</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:24px">'
            . '<input type="hidden" name="action" value="bg_app_export_operations">'
            . wp_nonce_field('bg_app_export_operations', '_wpnonce', true, false)
            . '<label><span style="display:block;font-weight:600;margin-bottom:4px">Published event</span><select name="event_id" required style="min-width:360px;max-width:100%">'
            . '<option value="">Choose an event</option>';
        foreach ($events as $event) {
            $date = (string) get_post_meta((int) $event->ID, '_bg_start_date', true);
            echo '<option value="' . (int) $event->ID . '">'
                . esc_html(get_the_title($event) . ($date !== '' ? ' · ' . $date : ''))
                . '</option>';
        }
        echo '</select></label><button class="button button-secondary" type="submit">Download operations CSV</button></form>';
    }

    private function renderIntakes(): void
    {
        $rows = $this->repository->intakesForReview();
        echo '<h2>Event intakes requiring staff review</h2>';
        echo '<p>This queue includes ambiguous walk-ins and attendee-requested monthly-giving follow-up. Ambiguous people remain separate instead of being silently merged.</p>';
        if (!$rows) {
            echo '<p>No event intakes are awaiting review.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Constituent</th><th>Registration</th><th>Review reason</th><th>Organizer</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $person = $this->constituents->get((int) $row->constituent_id);
            $name = $person ? trim((string) $person['first_name'] . ' ' . (string) $person['last_name']) : '';
            $email = $person ? (string) $person['email'] : '';
            echo '<tr><td>' . esc_html((string) ($row->post_title ?: ('Event #' . (int) $row->event_post_id))) . '</td>'
                . '<td><strong>' . esc_html($name !== '' ? $name : ('Constituent #' . (int) $row->constituent_id)) . '</strong>'
                . ($email !== '' ? '<br><small>' . esc_html($email) . '</small>' : '')
                . '<br><small>Record ID ' . (int) $row->constituent_id . '</small></td>'
                . '<td>#' . (int) $row->registration_id . '</td><td><strong>'
                . esc_html($this->intakeReason((string) ($row->review_reason ?? ''), (string) ($row->match_status ?? ''))) . '</strong>'
                . ((int) ($row->monthly_interest_cents ?? 0) > 0
                    ? '<br><small>Requested ' . esc_html($this->money((int) $row->monthly_interest_cents)) . ' per month</small>'
                    : '')
                . '<br><small>Type: ' . esc_html(str_replace('_', ' ', (string) ($row->intake_type ?? 'walk_in'))) . '</small></td><td>'
                . esc_html((string) ($row->display_name ?: ('User #' . (int) $row->organizer_user_id))) . '</td><td>'
                . esc_html((string) $row->created_at) . ' UTC</td><td><div style="display:flex;gap:6px;flex-wrap:wrap">';
            foreach (['resolved' => 'Mark resolved', 'dismissed' => 'Dismiss'] as $decision => $label) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="bg_app_review_intake">'
                    . '<input type="hidden" name="intake_id" value="' . (int) $row->id . '">'
                    . '<input type="hidden" name="decision" value="' . esc_attr($decision) . '">'
                    . wp_nonce_field('bg_app_review_intake_' . (int) $row->id, '_wpnonce', true, false)
                    . '<button class="button ' . ($decision === 'resolved' ? 'button-primary' : '') . '" type="submit">'
                    . esc_html($label) . '</button></form>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderCloseouts(): void
    {
        $rows = $this->repository->closeoutsForReview();
        echo '<h2>Event closeouts</h2>';
        if (!$rows) {
            echo '<p>No event closeouts have been submitted.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Organizer</th><th>Expected</th><th>Counted</th><th>Variance and explanation</th><th>Status</th><th>Review</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $cashVariance = (int) $row->cash_variance_cents;
            $checkVariance = (int) $row->check_variance_cents;
            $explanation = $this->repository->closeoutExplanation($row);
            echo '<tr><td><strong>' . esc_html((string) ($row->post_title ?: ('Event #' . (int) $row->event_post_id))) . '</strong><br><small>Snapshot ' . esc_html(substr((string) $row->snapshot_hash, 0, 12)) . '…</small></td>'
                . '<td>' . esc_html((string) ($row->display_name ?: ('User #' . (int) $row->organizer_user_id))) . '</td>'
                . '<td>Cash ' . esc_html($this->money((int) $row->expected_cash_cents)) . '<br>Checks ' . esc_html($this->money((int) $row->expected_check_cents)) . '</td>'
                . '<td>Cash ' . esc_html($this->money((int) $row->counted_cash_cents)) . '<br>Checks ' . esc_html($this->money((int) $row->counted_check_cents))
                . '<br><small>Physical checks: ' . (int) ($row->check_count ?? 0) . '</small></td>'
                . '<td>Cash ' . esc_html($this->signedMoney($cashVariance)) . '<br>Checks ' . esc_html($this->signedMoney($checkVariance))
                . ($explanation !== '' ? '<p style="max-width:340px">' . nl2br(esc_html($explanation)) . '</p>' : '') . '</td>'
                . '<td><strong>' . esc_html(ucfirst((string) $row->status)) . '</strong><br><small>' . esc_html((string) $row->updated_at) . ' UTC</small></td><td>';
            if ((string) $row->status === 'submitted') {
                echo $this->closeoutForm((int) $row->id, 'accept', 'Accept', true)
                    . $this->closeoutForm((int) $row->id, 'reopen', 'Reopen', false);
            } elseif ((string) $row->status === 'accepted') {
                echo $this->closeoutForm((int) $row->id, 'reopen', 'Reopen', false);
            } else {
                echo '<span class="description">Organizer may edit and resubmit.</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderTenders(): void
    {
        $rows = $this->repository->recentTenders(150);
        echo '<h2>Recent cash and check records</h2>';
        echo '<p>Corrections are append-only. Voiding a record creates a refund row in the BosqueGrande ledger and keeps the original record for audit.</p>';
        if (!$rows) {
            echo '<p>No manual payments have been recorded.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Recorded</th><th>Event</th><th>Registration</th><th>Purpose</th><th>Method</th><th>Amount</th><th>Details</th><th>Status</th><th>Correction</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $detailPayment = (object) [
                'id' => (int) ($row->payment_id ?: $row->id),
                'registration_id' => (int) $row->registration_id,
                'constituent_id' => 0,
            ];
            $details = $this->paymentDetails->buttonForPayment($detailPayment, 'bg-tender-detail');
            echo '<tr><td>' . esc_html((string) $row->created_at) . ' UTC<br><small>'
                . esc_html((string) ($row->display_name ?: ('User #' . (int) $row->organizer_user_id))) . '</small></td><td>'
                . esc_html((string) ($row->post_title ?: ('Event #' . (int) $row->event_post_id))) . '</td><td>#'
                . (int) $row->registration_id . '</td><td>' . esc_html($row->purpose === 'event_fee' ? 'Registration payment' : 'Donation') . '</td><td>'
                . esc_html(ucfirst((string) $row->method)) . ((string) $row->check_reference_enc !== '' ? '<br><small>Reference recorded</small>' : '') . '</td><td>'
                . esc_html($this->money((int) $row->amount_cents)) . '</td><td>' . $details . '</td><td>' . esc_html(ucfirst((string) $row->status)) . '</td><td>';
            if ((string) $row->status === 'recorded' && current_user_can('manage_options')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="min-width:240px">'
                    . '<input type="hidden" name="action" value="bg_app_void_tender"><input type="hidden" name="tender_id" value="' . (int) $row->id . '">'
                    . wp_nonce_field('bg_app_void_tender_' . (int) $row->id, '_wpnonce', true, false)
                    . '<label class="screen-reader-text" for="bg-void-' . (int) $row->id . '">Correction reason</label>'
                    . '<input id="bg-void-' . (int) $row->id . '" type="text" name="reason" required maxlength="1000" placeholder="Required correction reason" style="width:100%;margin-bottom:5px">'
                    . '<button class="button" type="submit">Void with ledger correction</button></form>';
            } else {
                echo '<span class="description">' . (current_user_can('manage_options') ? 'No direct edits.' : 'Read-only.') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function handleIntake(): void
    {
        $this->requireAdmin();
        if (!$this->repository->available()) $this->redirect('failed');
        $intakeId = absint($_POST['intake_id'] ?? 0);
        check_admin_referer('bg_app_review_intake_' . $intakeId);
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        $intake = $this->repository->intake($intakeId);
        $ok = $intake && $this->repository->reviewIntake($intakeId, $decision, get_current_user_id());
        if ($ok) $this->portal->audit(get_current_user_id(), (int) $intake->event_post_id, (int) $intake->registration_id, 'intake_' . $decision, 'Intake #' . $intakeId);
        $this->redirect($ok ? 'intake_saved' : 'failed');
    }

    public function handleCloseout(): void
    {
        $this->requireAdmin();
        if (!$this->repository->available()) $this->redirect('failed');
        $closeoutId = absint($_POST['closeout_id'] ?? 0);
        check_admin_referer('bg_app_review_closeout_' . $closeoutId);
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        $reason = bg_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? ''))), 0, 180);
        if ($decision === 'reopen' && trim($reason) === '') $this->redirect('failed');
        $result = $this->service->reviewCloseout(
            get_current_user_id(),
            $closeoutId,
            $decision,
            $reason
        );
        $this->redirect($result === true ? 'closeout_saved' : 'failed');
    }

    public function handleVoidTender(): void
    {
        $this->requireAdmin();
        if (!$this->repository->available()) $this->redirect('failed');
        $tenderId = absint($_POST['tender_id'] ?? 0);
        check_admin_referer('bg_app_void_tender_' . $tenderId);
        $reason = sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? '')));
        $result = $this->service->voidTender(get_current_user_id(), $tenderId, $reason);
        $this->redirect($result === true ? 'tender_voided' : 'failed');
    }

    public function handleExport(): void
    {
        $this->requireAdmin();
        check_admin_referer('bg_app_export_operations');
        $eventId = absint($_POST['event_id'] ?? 0);
        if (!$this->repository->available() || !$this->portal->isPublishedEvent($eventId)) {
            wp_die('The selected published event is unavailable.', '', ['response' => 400]);
        }

        $eventTitle = get_the_title($eventId);
        $eventDate = (string) get_post_meta($eventId, '_bg_start_date', true);
        $eventRegion = RegionalGroups::label(RegionalGroups::eventRegion(
            $eventTitle,
            (string) get_post_meta($eventId, '_bg_location_text', true)
        ));
        $rosterByRegistration = [];
        foreach ($this->portal->roster($eventId) as $row) {
            $registrationId = (int) ($row['registration_id'] ?? 0);
            if ($registrationId < 1) continue;
            $withheld = !empty($row['contact_withheld']);
            $name = trim((string) ($row['name'] ?? ''));
            $rosterByRegistration[$registrationId] = [
                'label' => $withheld
                    ? 'Private attendee #' . $registrationId
                    : ($name !== '' ? $name : 'Attendee #' . $registrationId),
                'withheld' => $withheld ? 1 : 0,
            ];
        }

        $rows = $this->repository->eventOperationsRows($eventId);
        $this->portal->audit(
            get_current_user_id(),
            $eventId,
            0,
            'organizer_operations_exported',
            count($rows) . ' CSV rows'
        );

        $slug = sanitize_file_name(sanitize_title($eventTitle));
        if ($slug === '') $slug = 'event-' . $eventId;
        $filename = 'bosquegrande-operations-' . $eventId . '-' . $slug . '-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        if ($out === false) wp_die('The export stream could not be opened.', '', ['response' => 500]);
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'event_id', 'event_title', 'event_date', 'event_region',
            'registration_id', 'attendee_label', 'contact_withheld', 'constituent_id',
            'registration_status', 'registration_tier', 'registration_amount_cents',
            'attendance_status', 'attendance_updated_at', 'intake_type', 'match_status',
            'intake_review_status', 'private_answers_complete', 'contact_consent_complete',
            'monthly_interest_cents', 'tender_id', 'payment_id', 'operation_id',
            'tender_purpose', 'tender_method', 'tender_amount_cents', 'tender_status',
            'tender_organizer_user_id', 'tender_created_at', 'has_check_reference',
            'closeout_id', 'void_payment_id',
        ], ',', '"', '');
        foreach ($rows as $row) {
            $registrationId = (int) $row->registration_id;
            $person = $rosterByRegistration[$registrationId] ?? [
                'label' => 'Attendee #' . $registrationId,
                'withheld' => 0,
            ];
            fputcsv($out, [
                $eventId,
                $this->csvCell($eventTitle),
                $eventDate,
                $this->csvCell($eventRegion),
                $registrationId,
                $this->csvCell((string) $person['label']),
                (int) $person['withheld'],
                (int) $row->constituent_id,
                $this->csvCell((string) $row->registration_status),
                $this->csvCell((string) $row->registration_tier),
                (int) $row->registration_amount_cents,
                $this->csvCell((string) $row->attendance_status),
                (string) $row->attendance_updated_at,
                $this->csvCell((string) $row->intake_type),
                $this->csvCell((string) $row->match_status),
                $this->csvCell((string) $row->intake_review_status),
                (int) $row->private_answers_complete,
                (int) $row->contact_consent_complete,
                (int) $row->monthly_interest_cents,
                (int) $row->tender_id,
                (int) $row->payment_id,
                $this->csvCell((string) $row->operation_id),
                $this->csvCell((string) $row->tender_purpose),
                $this->csvCell((string) $row->tender_method),
                (int) $row->tender_amount_cents,
                $this->csvCell((string) $row->tender_status),
                (int) $row->tender_organizer_user_id,
                (string) $row->tender_created_at,
                (int) $row->has_check_reference,
                (int) $row->closeout_id,
                (int) $row->void_payment_id,
            ], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    private function closeoutForm(int $id, string $decision, string $label, bool $primary): string
    {
        $reason = $decision === 'reopen'
            ? '<label class="screen-reader-text" for="bg-reopen-' . $id . '">Reason for reopening</label>'
                . '<input id="bg-reopen-' . $id . '" type="text" name="reason" required maxlength="180" placeholder="Required reopening reason" style="display:block;width:230px;margin-bottom:5px">'
            : '';
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 5px 5px 0;vertical-align:top">'
            . '<input type="hidden" name="action" value="bg_app_review_closeout"><input type="hidden" name="closeout_id" value="' . $id . '">'
            . '<input type="hidden" name="decision" value="' . esc_attr($decision) . '">'
            . wp_nonce_field('bg_app_review_closeout_' . $id, '_wpnonce', true, false)
            . $reason
            . '<button class="button ' . ($primary ? 'button-primary' : '') . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    private function intakeReason(string $reason, string $matchStatus): string
    {
        return match ($reason) {
            'monthly_follow_up' => 'Monthly-giving follow-up requested',
            'ambiguous_match' => 'Ambiguous constituent match',
            default => $matchStatus === 'ambiguous' ? 'Ambiguous constituent match' : 'Staff review requested',
        };
    }

    private function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', ['response' => 403]);
    }

    private function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'bg_app_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private function money(int $cents): string
    {
        return \BosqueGrande\Core\Money::format($cents);
    }

    private function signedMoney(int $cents): string
    {
        if ($cents === 0) return '$0.00';
        return ($cents > 0 ? '+' : '−') . \BosqueGrande\Core\Money::format(abs($cents));
    }

    private function csvCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) return "'" . $value;
        return $value;
    }
}
