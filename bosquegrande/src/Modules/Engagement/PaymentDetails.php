<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\Revenue\QboTransactionRegion;

if (!defined('ABSPATH')) exit;

/** Accountant-only expandable identity and event context for dollar rows. */
final class PaymentDetails
{
    public const CAP = 'bg_view_payment_details';
    public const ROLE = 'bg_accountant';

    public function __construct(private ConstituentStore $store)
    {
    }

    public static function ensureCapabilities(): void
    {
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) $admin->add_cap(self::CAP);
        $role = get_role(self::ROLE);
        if (!$role) $role = add_role(self::ROLE, 'BosqueGrande Accountant', ['read' => true, self::CAP => true]);
        elseif (!$role->has_cap(self::CAP)) $role->add_cap(self::CAP);
    }

    public function buttonForPayment(object $payment, string $idPrefix = 'bg-payment-detail'): string
    {
        if (!current_user_can(self::CAP)) return '';
        $constituentId = (int) ($payment->constituent_id ?? 0);
        $registrationId = (int) ($payment->registration_id ?? 0);
        $eventId = 0;
        if ($registrationId > 0) {
            global $wpdb;
            $registration = $wpdb->get_row($wpdb->prepare(
                "SELECT event_post_id, bg_constituent_id, lgl_constituent_id FROM {$wpdb->prefix}bg_registrations WHERE id = %d",
                $registrationId
            ));
            if ($registration) {
                $eventId = (int) $registration->event_post_id;
                if ($constituentId < 1) $constituentId = (int) $registration->bg_constituent_id;
                if ($constituentId < 1 && (int) $registration->lgl_constituent_id > 0) {
                    $ids = $this->store->idsByLglId((int) $registration->lgl_constituent_id);
                    if (count($ids) === 1) $constituentId = (int) $ids[0];
                }
            }
        }
        return $this->button($constituentId, $eventId, $idPrefix . '-' . absint($payment->id ?? $registrationId));
    }

    public function buttonForRegistration(object $registration, string $idPrefix = 'bg-registration-detail'): string
    {
        if (!current_user_can(self::CAP)) return '';
        $constituentId = (int) ($registration->bg_constituent_id ?? 0);
        if ($constituentId < 1 && (int) ($registration->lgl_constituent_id ?? 0) > 0) {
            $ids = $this->store->idsByLglId((int) $registration->lgl_constituent_id);
            if (count($ids) === 1) $constituentId = (int) $ids[0];
        }
        return $this->button(
            $constituentId,
            (int) ($registration->event_post_id ?? 0),
            $idPrefix . '-' . absint($registration->id ?? 0)
        );
    }

    private function button(int $constituentId, int $eventId, string $id): string
    {
        $record = $constituentId > 0 ? $this->store->get($constituentId) : null;
        $event = $eventId > 0 ? get_the_title($eventId) : '';
        $grantTags = $eventId > 0 ? $this->grantTags($eventId) : [];
        $region = $this->constituentRegion($constituentId);
        $qboRegion = QboTransactionRegion::forRecord(is_array($record) ? $record : []);
        $name = is_array($record) ? trim((string) ($record['first_name'] ?? '') . ' ' . (string) ($record['last_name'] ?? '')) : '';
        $address = is_array($record) ? implode(', ', array_filter([
            trim((string) ($record['address1'] ?? '') . ' ' . (string) ($record['address2'] ?? '')),
            trim((string) ($record['city'] ?? '') . ', ' . (string) ($record['state'] ?? '') . ' ' . (string) ($record['postal'] ?? '')),
        ])) : '';
        $details = '<dl style="margin:8px 0 0;display:grid;grid-template-columns:max-content 1fr;gap:4px 12px">'
            . '<dt><strong>Name</strong></dt><dd>' . esc_html($name !== '' ? $name : 'Unavailable') . '</dd>'
            . '<dt><strong>Email</strong></dt><dd>' . esc_html((string) ($record['email'] ?? 'Unavailable')) . '</dd>'
            . '<dt><strong>Phone</strong></dt><dd>' . esc_html((string) ($record['phone'] ?? 'Unavailable')) . '</dd>'
            . '<dt><strong>Address</strong></dt><dd>' . esc_html($address !== '' ? $address : 'Unavailable') . '</dd>'
            . '<dt><strong>Regional group</strong></dt><dd>' . esc_html($region) . '</dd>'
            . '<dt><strong>QuickBooks Class</strong></dt><dd>' . esc_html(
                (string) $qboRegion['class'] !== '' ? (string) $qboRegion['class'] : 'Review required'
            ) . '</dd>'
            . '<dt><strong>Event</strong></dt><dd>' . esc_html($event !== '' ? $event : 'Not event-specific') . '</dd>'
            . '<dt><strong>Grant tags</strong></dt><dd>' . esc_html(
                $eventId < 1
                    ? 'Not event-specific'
                    : ($grantTags ? implode(', ', $grantTags) : 'None assigned')
            ) . '</dd>'
            . '</dl>';
        return '<button type="button" class="button button-small" aria-expanded="false" aria-controls="' . esc_attr($id) . '" onclick="var d=document.getElementById(this.getAttribute(\'aria-controls\'));var open=d.hidden;d.hidden=!open;this.setAttribute(\'aria-expanded\',open?\'true\':\'false\');">Details</button>'
            . '<div id="' . esc_attr($id) . '" hidden>' . $details . '</div>';
    }

    /** @return list<string> Human-readable Grant Tags in stable display order. */
    private function grantTags(int $eventId): array
    {
        $names = wp_get_post_terms($eventId, 'bg_grant_tag', ['fields' => 'names']);
        if (is_wp_error($names) || !is_array($names)) return [];
        $names = array_values(array_filter(array_map(
            static fn($name): string => trim((string) $name),
            $names
        ), static fn(string $name): bool => $name !== ''));
        natcasesort($names);
        return array_values($names);
    }

    /** Canonical saved home-region label only; never derives or exposes address evidence here. */
    private function constituentRegion(int $constituentId): string
    {
        if ($constituentId < 1) return 'Not linked to a constituent';
        global $wpdb;
        $slug = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT region_slug FROM {$wpdb->prefix}bg_constituent_regions WHERE constituent_id = %d",
            $constituentId
        ));
        $label = RegionalGroups::label($slug);
        return $label !== '' ? $label : 'Not assigned';
    }
}
