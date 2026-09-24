<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

use BosqueGrande\Core\Container;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Notifications\NotificationsModule;
use BosqueGrande\Modules\Registration\Tiers;
use BosqueGrande\Modules\Engagement\HistoricalParticipation;
use BosqueGrande\Modules\Engagement\PaymentDetails;

if (!defined('ABSPATH')) exit;

/**
 * Event Statistics (architecture 5.4): per-event registrations by tier,
 * revenue, new vs returning constituents, volunteer interest, the
 * registrations list with consent-aware contact details, the
 * one-button refund, and CSV export for grant reporting. This is a local
 * view over bg_registrations; LGL changes occur only through explicit sync.
 */
final class StatsPage
{
    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('bosquegrande', 'Event Statistics', 'Event Statistics',
                PaymentDetails::CAP, 'bosquegrande-stats', [$this, 'render']);
        }, 30);
        add_action('admin_post_bg_refund', [$this, 'refund']);
        add_action('admin_post_bg_stats_csv', [$this, 'csv']);
    }

    public function render(): void
    {
        if (!current_user_can(PaymentDetails::CAP)) return;
        global $wpdb;

        $eventId = isset($_GET['event']) ? (int) $_GET['event'] : 0;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';

        echo '<div class="wrap"><h1>Event Statistics</h1>';
        if ($notice) echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';

        if (!$eventId) {
            $this->renderEventList($wpdb);
            echo '</div>';
            return;
        }

        $this->renderEventDetail($wpdb, $eventId);
        echo '</div>';
    }

    private function renderEventList(\wpdb $wpdb): void
    {
        $rows = $wpdb->get_results(
            "SELECT r.event_post_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN r.status IN ('confirmed','paid') THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN r.status = 'paid' THEN r.amount_cents ELSE 0 END) AS revenue,
                    MAX(d.meta_value) AS event_date
             FROM {$wpdb->prefix}bg_registrations r
             LEFT JOIN (
                SELECT post_id, MAX(meta_value) AS meta_value
                FROM {$wpdb->postmeta} WHERE meta_key = '_bg_start_date' GROUP BY post_id
             ) d ON d.post_id = r.event_post_id
             GROUP BY r.event_post_id
             ORDER BY event_date DESC, r.event_post_id DESC LIMIT 1000"
        );
        if (!$rows) {
            echo '<p>No registrations yet. They will appear here as they come in.</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:860px"><thead><tr>
            <th>Event</th><th>Registrations</th><th>Revenue</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $url = add_query_arg(['page' => 'bosquegrande-stats', 'event' => (int) $r->event_post_id], admin_url('admin.php'));
            printf(
                '<tr><td>%s</td><td>%d</td><td>%s</td><td><a class="button" href="%s">Details</a></td></tr>',
                esc_html(get_the_title((int) $r->event_post_id) ?: 'Event #' . (int) $r->event_post_id),
                (int) $r->active,
                esc_html(\BosqueGrande\Core\Money::format((int) $r->revenue)),
                esc_url($url)
            );
        }
        echo '</tbody></table>';
    }

    private function renderEventDetail(\wpdb $wpdb, int $eventId): void
    {
        $t = $wpdb->prefix . 'bg_registrations';
        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE event_post_id = %d ORDER BY created_at DESC", $eventId
        ));

        $tiers = Tiers::all();
        $byTier = array_fill_keys(array_keys($tiers), 0);
        $revenue = 0;
        $newC = 0;
        $returning = 0;
        $newcomerUnknown = 0;
        $volunteer = 0;
        $eventDate = (string) get_post_meta($eventId, '_bg_start_date', true);
        $history = $this->container->has('engagement.history')
            ? $this->container->get('engagement.history')
            : null;
        foreach ($regs as $r) {
            if (!in_array($r->status, ['confirmed', 'paid'], true)) continue;
            $tierKey = trim((string) $r->tier) ?: 'unspecified';
            if (!isset($byTier[$tierKey])) $byTier[$tierKey] = 0;
            $byTier[$tierKey]++;
            if ($r->status === 'paid') $revenue += (int) $r->amount_cents;
            $newStatus = $this->newcomerStatus($r, $eventId, $eventDate, $history);
            if ($newStatus === 1) $newC++;
            elseif ($newStatus === 0) $returning++;
            else $newcomerUnknown++;
            if ((int) $r->volunteer_interest === 1) $volunteer++;
        }

        $back = add_query_arg(['page' => 'bosquegrande-stats'], admin_url('admin.php'));
        $csv = wp_nonce_url(add_query_arg(
            ['action' => 'bg_stats_csv', 'event' => $eventId],
            admin_url('admin-post.php')
        ), 'bg_stats_csv');

        echo '<p><a href="' . esc_url($back) . '">&larr; All events</a></p>';
        echo '<h2>' . esc_html(get_the_title($eventId) ?: 'Event #' . $eventId) . '</h2>';

        echo '<table class="widefat striped" style="max-width:520px"><tbody>';
        foreach ($tiers as $key => $tier) {
            echo '<tr><td><strong>' . esc_html($tier['label']) . '</strong></td><td>' . (int) $byTier[$key] . '</td></tr>';
        }
        foreach (array_diff_key($byTier, $tiers) as $key => $count) {
            $label = $key === 'historical' ? 'Historical registration' : ucwords(str_replace(['_', '-'], ' ', (string) $key));
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . (int) $count . '</td></tr>';
        }
        echo '<tr><td><strong>Revenue (paid online)</strong></td><td>' . esc_html(\BosqueGrande\Core\Money::format((int) $revenue)) . '</td></tr>';
        echo '<tr><td><strong>First-time participants</strong></td><td>' . $newC . '</td></tr>';
        echo '<tr><td><strong>Returning participants</strong></td><td>' . $returning . '</td></tr>';
        if ($newcomerUnknown > 0) {
            echo '<tr><td><strong>New/returning unknown</strong></td><td>' . $newcomerUnknown . '</td></tr>';
        }
        echo '<tr><td><strong>Volunteer interest</strong></td><td>' . $volunteer . '</td></tr>';
        echo '</tbody></table>';
        if (current_user_can('manage_options')) {
            echo '<p><a class="button button-primary" href="' . esc_url($csv) . '">Export CSV (grant reporting)</a></p>';
        }

        $survey = $wpdb->get_results($wpdb->prepare(
            "SELECT question_key, COUNT(*) AS n, AVG(score) AS avg_score
             FROM {$wpdb->prefix}bg_survey_responses
             WHERE event_post_id = %d GROUP BY question_key",
            $eventId
        ), OBJECT_K);
        if ($survey) {
            echo '<h3>Survey results</h3>';
            echo '<table class="widefat striped" style="max-width:640px"><thead><tr>'
                . '<th>Statement</th><th>Responses</th><th>Average (1 to 5)</th></tr></thead><tbody>';
            foreach (NotificationsModule::questions() as $key => $label) {
                if (!isset($survey[$key])) continue;
                printf(
                    '<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
                    esc_html($label),
                    (int) $survey[$key]->n,
                    esc_html(number_format((float) $survey[$key]->avg_score, 2))
                );
            }
            echo '</tbody></table>';
        }

        echo '<h3>Registrations</h3>';
        if (!$regs) {
            echo '<p>None yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>
            <th>ID</th><th>Registrant and contact</th><th>Tier</th><th>Status</th><th>Amount</th><th>Local / LGL IDs</th><th>Date (UTC)</th><th></th>
        </tr></thead><tbody>';
        foreach ($regs as $r) {
            $contact = $this->registrationContact($r);
            $person = $this->registrationContactHtml($contact);
            $constituentIds = $contact['withheld']
                ? 'Local: none / LGL: none'
                : sprintf(
                    'Local %s / LGL %s',
                    !empty($r->bg_constituent_id) ? (string) (int) $r->bg_constituent_id : 'none',
                    !empty($r->lgl_constituent_id) ? (string) (int) $r->lgl_constituent_id : 'none'
                );
            $refundable = current_user_can('manage_options') && in_array($r->status, ['confirmed', 'paid'], true);
            $btn = '';
            if ($refundable) {
                $label = $r->status === 'paid' ? 'Refund and cancel' : 'Cancel';
                $btn = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'Refund/cancel this registration locally? Historical LGL artifacts, if any, must be reconciled through the explicit sync workflow.\')">'
                    . wp_nonce_field('bg_refund_' . (int) $r->id, '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="bg_refund">'
                    . '<input type="hidden" name="reg" value="' . (int) $r->id . '">'
                    . '<input type="hidden" name="event" value="' . (int) $eventId . '">'
                    . '<button class="button" type="submit">' . esc_html($label) . '</button></form>';
            }
            $details = $this->container->has('engagement.payment_details')
                ? $this->container->get('engagement.payment_details')->buttonForRegistration($r, 'bg-event-stats-detail')
                : '';
            $actions = '<div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">' . $details . $btn . '</div>';
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $r->id,
                $person,
                esc_html($r->tier),
                esc_html($r->status),
                esc_html(\BosqueGrande\Core\Money::format((int) $r->amount_cents)),
                esc_html($constituentIds),
                esc_html($r->created_at),
                $actions
            );
        }
        echo '</tbody></table>';
    }

    public function refund(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $regId = isset($_POST['reg']) ? (int) $_POST['reg'] : 0;
        check_admin_referer('bg_refund_' . $regId);
        $result = PaymentsModule::refundRegistration($this->container, $regId);
        wp_safe_redirect(add_query_arg([
            'page' => 'bosquegrande-stats',
            'event' => isset($_POST['event']) ? (int) $_POST['event'] : 0,
            'bg_notice' => rawurlencode($result['message']),
        ], admin_url('admin.php')));
        exit;
    }

    public function csv(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_stats_csv');
        $eventId = isset($_GET['event']) ? (int) $_GET['event'] : 0;
        global $wpdb;
        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tier, status, amount_cents, bg_constituent_id, lgl_constituent_id,
                    volunteer_interest, is_new_constituent, created_at
             FROM {$wpdb->prefix}bg_registrations WHERE event_post_id = %d ORDER BY created_at",
            $eventId
        ), ARRAY_A);
        $eventDate = (string) get_post_meta($eventId, '_bg_start_date', true);
        $history = $this->container->has('engagement.history')
            ? $this->container->get('engagement.history')
            : null;

        $slug = sanitize_title(get_the_title($eventId) ?: 'event-' . $eventId);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="registrations-' . $slug . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'id', 'first_name', 'last_name', 'email', 'phone', 'address1', 'address2',
            'city', 'state', 'postal_code', 'contact_sharing', 'tier', 'status', 'amount_usd',
            'bg_constituent_id', 'lgl_constituent_id', 'volunteer_interest',
            'new_constituent', 'created_at_utc',
        ], ',', '"', '');
        foreach ($regs as $r) {
            $contact = $this->registrationContact((object) $r);
            $record = $contact['record'];
            $withheld = $contact['withheld'];
            $newStatus = $this->newcomerStatus((object) $r, $eventId, $eventDate, $history);
            $line = [
                (string) $r['id'],
                (string) ($record['first_name'] ?? ''),
                (string) ($record['last_name'] ?? ''),
                (string) ($record['email'] ?? ''),
                (string) ($record['phone'] ?? ''),
                (string) ($record['address1'] ?? ''),
                (string) ($record['address2'] ?? ''),
                (string) ($record['city'] ?? ''),
                (string) ($record['state'] ?? ''),
                (string) ($record['postal'] ?? ''),
                $withheld ? 'Withheld by registrant' : ($record ? 'Allowed' : 'Unavailable'),
                (string) $r['tier'],
                (string) $r['status'],
                number_format(((int) $r['amount_cents']) / 100, 2, '.', ''),
                $withheld ? '' : (string) ($r['bg_constituent_id'] ?? ''),
                $withheld ? '' : (string) ($r['lgl_constituent_id'] ?? ''),
                $withheld ? '' : (string) $r['volunteer_interest'],
                $withheld || $newStatus < 0 ? '' : (string) $newStatus,
                (string) $r['created_at'],
            ];
            fputcsv($out, array_map([ConstituentStore::class, 'safeCsv'], $line), ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /** @return array{withheld: bool, record: ?array} */
    private function registrationContact(object $registration): array
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        if (!$store->available()) return ['withheld' => false, 'record' => null];
        $localId = $this->registrationConstituentId($registration);
        if (!$localId) return ['withheld' => false, 'record' => null];
        if (!$store->contactSharingAllowed($localId)) {
            return ['withheld' => true, 'record' => null];
        }
        return ['withheld' => false, 'record' => $store->get($localId)];
    }

    private function newcomerStatus(
        object $registration,
        int $eventId,
        string $eventDate,
        mixed $history
    ): int {
        if (!$history instanceof HistoricalParticipation
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)
        ) return -1;
        $constituentId = $this->registrationConstituentId($registration);
        if ($constituentId < 1) return -1;
        return $history->newcomerStatus($constituentId, $eventDate, $eventId);
    }

    private function registrationConstituentId(object $registration): int
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        if (!$store->available()) return 0;
        $localId = (int) ($registration->bg_constituent_id ?? 0);
        if ($localId < 1 && (int) ($registration->lgl_constituent_id ?? 0) > 0) {
            $ids = $store->idsByLglId((int) $registration->lgl_constituent_id);
            $localId = count($ids) === 1 ? (int) $ids[0] : 0;
        }
        return $localId;
    }

    /** @param array{withheld: bool, record: ?array} $contact */
    private function registrationContactHtml(array $contact): string
    {
        if ($contact['withheld']) {
            return '<strong>Anonymous registration</strong><br><span class="description">Personal information withheld by registrant.</span>';
        }
        $record = $contact['record'];
        if (!$record) return '<span class="description">Identity unavailable</span>';
        $parts = [];
        $name = trim((string) ($record['first_name'] ?? '') . ' ' . (string) ($record['last_name'] ?? ''));
        if ($name !== '') $parts[] = '<strong>' . esc_html($name) . '</strong>';
        $email = (string) ($record['email'] ?? '');
        if (is_email($email)) {
            $parts[] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        $phone = trim((string) ($record['phone'] ?? ''));
        if ($phone !== '') {
            $parts[] = '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
        }
        $street = array_values(array_filter([
            trim((string) ($record['address1'] ?? '')),
            trim((string) ($record['address2'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $cityState = implode(', ', array_values(array_filter([
            trim((string) ($record['city'] ?? '')),
            trim((string) ($record['state'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));
        $postal = trim((string) ($record['postal'] ?? ''));
        $locality = trim($cityState . ($cityState !== '' && $postal !== '' ? ' ' : '') . $postal);
        $address = array_merge($street, $locality !== '' ? [$locality] : []);
        if ($address) $parts[] = esc_html(implode(', ', $address));
        return $parts ? implode('<br>', $parts) : '<span class="description">No contact details on file</span>';
    }
}
