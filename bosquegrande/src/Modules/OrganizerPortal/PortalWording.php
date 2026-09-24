<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerPortal;

if (!defined('ABSPATH')) exit;

/** Editable presentation copy for the public-facing organizer workspace. */
final class PortalWording
{
    /**
     * @param array<string,array<string,mixed>> $defaults
     * @return array<string,array<string,mixed>>
     */
    public static function defaults(array $defaults): array
    {
        $local = [
            'organizer_portal_title' => ['Organizer Portal: title', 'Organizer Portal'],
            'organizer_login_intro' => ['Organizer Portal: sign-in instructions', 'Sign in with the organizer account your organization gave you.'],
            'organizer_open_app' => ['Organizer Portal: open-app button', 'Open the Organizer App'],
            'organizer_reset_password' => ['Organizer Portal: reset-password link', 'Set or reset your password'],
            'organizer_database_unavailable' => ['Organizer Portal: database-unavailable notice', 'The organizer portal database update is not available yet. An administrator should check BosqueGrande System Status.'],
            'organizer_access_denied' => ['Organizer Portal: access-denied notice', 'This account does not have organizer access.'],
            'organizer_eyebrow' => ['Organizer Portal: small heading', 'BosqueGrande'],
            'organizer_welcome' => ['Organizer Portal: welcome line', 'Welcome, {name}.', ['name']],
            'organizer_navigation_aria' => ['Organizer Portal navigation: accessible label', 'Organizer navigation'],
            'organizer_nav_app' => ['Organizer Portal navigation: organizer app', 'Event Organizer App'],
            'organizer_nav_events' => ['Organizer Portal navigation: event list', 'My events'],
            'organizer_nav_account' => ['Organizer Portal navigation: account', 'Account settings'],
            'organizer_nav_sign_out' => ['Organizer Portal navigation: sign out', 'Sign out'],
            'organizer_all_events' => ['Organizer Portal dashboard: administrator heading', 'All events'],
            'organizer_assigned_events' => ['Organizer Portal dashboard: organizer heading', 'My assigned events'],
            'organizer_dashboard_intro' => ['Organizer Portal dashboard: introduction', 'Open an event to manage its registrants, attendance, messages, and proposed changes.'],
            'organizer_no_events' => ['Organizer Portal dashboard: no-events message', 'No events are assigned to this account yet.'],
            'organizer_event_status_published' => ['Organizer Portal event: published label', 'Published'],
            'organizer_date_not_set' => ['Organizer Portal event: missing-date label', 'Date not set'],
            'organizer_confirmed_count' => ['Organizer Portal event: confirmed-registration count', '{count} confirmed registrants', ['count']],
            'organizer_manage_event' => ['Organizer Portal event: manage button', 'Manage event'],
            'organizer_event_not_assigned' => ['Organizer Portal event: not-assigned notice', 'You are not assigned to that event.'],
            'organizer_event_missing' => ['Organizer Portal event: missing-event notice', 'That event no longer exists.'],
            'organizer_all_assigned_events' => ['Organizer Portal event: back link', 'All assigned events'],
            'organizer_view_public_event' => ['Organizer Portal event: public-event link', 'View public event'],
            'organizer_request_history' => ['Organizer Portal event: request-history heading', 'Change-request history'],
            'organizer_request_item' => ['Organizer Portal event: request-history row', '{type} request #{id}: {status}', ['type', 'id', 'status']],
            'organizer_roster_title' => ['Organizer Portal roster: heading', 'Registrant roster'],
            'organizer_roster_privacy' => ['Organizer Portal roster: privacy note', 'Contact information below belongs only to people registered for this event.'],
            'organizer_roster_export' => ['Organizer Portal roster: export button', 'Export this roster'],
            'organizer_roster_empty' => ['Organizer Portal roster: empty message', 'No confirmed registrations yet.'],
            'organizer_roster_name' => ['Organizer Portal roster: Name column', 'Name'],
            'organizer_roster_contact' => ['Organizer Portal roster: Contact column', 'Contact'],
            'organizer_roster_registration' => ['Organizer Portal roster: Registration column', 'Registration'],
            'organizer_roster_attendance' => ['Organizer Portal roster: Attendance column', 'Attendance'],
            'organizer_anonymous_registrant' => ['Organizer Portal roster: anonymous name', 'Anonymous registrant'],
            'organizer_identity_unavailable' => ['Organizer Portal roster: unavailable name', 'Identity unavailable'],
            'organizer_contact_withheld' => ['Organizer Portal roster: withheld contact', 'Withheld by registrant'],
            'organizer_attendance_aria' => ['Organizer Portal roster: attendance control accessible label', 'Attendance for {name}', ['name']],
            'organizer_save_attendance' => ['Organizer Portal roster: attendance save button', 'Save'],
            'organizer_contact_unavailable' => ['Organizer Portal roster: unavailable contact', 'Contact unavailable'],
            'organizer_no_contact_details' => ['Organizer Portal roster: no contact details', 'No contact details on file'],
            'organizer_message_title' => ['Organizer Portal message form: heading', 'Email registrants'],
            'organizer_message_privacy' => ['Organizer Portal message form: privacy note', 'Each person gets a separate message, so no one sees anyone else’s address.'],
            'organizer_message_recipients' => ['Organizer Portal message form: recipients label', 'Recipients'],
            'organizer_message_all' => ['Organizer Portal message form: all-recipients option', 'All registrants with an available email'],
            'organizer_message_subject' => ['Organizer Portal message form: subject label', 'Subject'],
            'organizer_message_body' => ['Organizer Portal message form: message label', 'Message'],
            'organizer_message_send' => ['Organizer Portal message form: submit button', 'Send message'],
            'organizer_create_title' => ['Organizer Portal event form: new-event heading', 'Propose a new event'],
            'organizer_edit_title' => ['Organizer Portal event form: change-event heading', 'Propose event changes'],
            'organizer_create_intro' => ['Organizer Portal event form: new-event explanation', 'The event is saved privately for staff review. It is not public until approved.'],
            'organizer_edit_intro' => ['Organizer Portal event form: change-event explanation', 'Your proposed changes remain private until staff approve them.'],
            'organizer_request_pending' => ['Organizer Portal event form: pending-request notice', 'A request for this event is already awaiting staff review.'],
            'organizer_field_title' => ['Organizer Portal event form: event-title label', 'Event title'],
            'organizer_field_start_date' => ['Organizer Portal event form: start-date label', 'Start date'],
            'organizer_field_start_time' => ['Organizer Portal event form: start-time label', 'Start time'],
            'organizer_start_time_placeholder' => ['Organizer Portal event form: start-time example', '7:00 PM'],
            'organizer_field_end_date' => ['Organizer Portal event form: end-date label', 'End date'],
            'organizer_field_end_time' => ['Organizer Portal event form: end-time label', 'End time'],
            'organizer_end_time_placeholder' => ['Organizer Portal event form: end-time example (keep {default_duration})', '8:00 PM (defaults to +{default_duration})', ['default_duration']],
            'organizer_field_description' => ['Organizer Portal event form: description label', 'Description'],
            'organizer_field_location' => ['Organizer Portal event form: location label', 'Location'],
            'organizer_field_interests' => ['Organizer Portal event form: interests label', 'Interests'],
            'organizer_field_registration_url' => ['Organizer Portal event form: external-registration label', 'External registration URL'],
            'organizer_registration_url_note' => ['Organizer Portal event form: external-registration note', '(optional; built-in registration remains the default)'],
            'organizer_field_image_url' => ['Organizer Portal event form: image label', 'Event image URL'],
            'organizer_optional_note' => ['Organizer Portal event form: optional note', '(optional)'],
            'organizer_submit_create' => ['Organizer Portal event form: new-event submit button', 'Submit new event for approval'],
            'organizer_submit_edit' => ['Organizer Portal event form: change submit button', 'Submit changes for approval'],
            'organizer_not_selected' => ['Organizer Portal event form: no-selection option', 'Not selected'],
            'organizer_multi_select_help' => ['Organizer Portal event form: multi-select help', 'Use Ctrl/Cmd to select more than one.'],
            'organizer_notice_attendance_saved' => ['Organizer Portal notice: attendance saved', 'Attendance saved.'],
            'organizer_notice_message_sent' => ['Organizer Portal notice: message sent', 'The message was sent.'],
            'organizer_notice_message_partial' => ['Organizer Portal notice: partial message delivery', 'Some messages could not be accepted by the mail server. Staff can review the BosqueGrande log.'],
            'organizer_notice_request_saved' => ['Organizer Portal notice: request submitted', 'Your change request was submitted for staff approval.'],
            'organizer_notice_event_saved' => ['Organizer Portal notice: event submitted', 'The new event was saved privately and submitted for staff approval.'],
            'organizer_notice_request_pending' => ['Organizer Portal notice: duplicate pending request', 'That event already has a request awaiting staff review.'],
            'organizer_notice_action_failed' => ['Organizer Portal notice: action failed', 'The requested action could not be completed.'],
            'organizer_notice_rate_limited' => ['Organizer Portal notice: message rate limit', 'Please wait before sending another event message.'],
            'organizer_permission_denied' => ['Organizer Portal: permission-denied response', 'Not allowed.'],
            'organizer_attendance_registered' => ['Organizer Portal attendance: registered', 'Registered'],
            'organizer_attendance_checked_in' => ['Organizer Portal attendance: checked in', 'Checked in'],
            'organizer_attendance_attended' => ['Organizer Portal attendance: attended', 'Attended'],
            'organizer_attendance_no_show' => ['Organizer Portal attendance: no-show', 'No-show'],
            'organizer_mail_signature' => ['Organizer message email: sender line', 'Sent by {sender}', ['sender']],
            'organizer_mail_context' => ['Organizer message email: event line', 'Regarding: {event}', ['event']],
            'organizer_sender_fallback' => ['Organizer message email: sender fallback', 'Event organizer'],
            'organizer_assignment_subject' => ['Organizer assignment email: subject', 'Event {role} access: {event}', ['role', 'event']],
            'organizer_assignment_body' => ['Organizer assignment email: message', 'You have been assigned {role} access for {event}.', ['role', 'event']],
            'organizer_assignment_password_note' => ['Organizer assignment email: new-account note', 'WordPress sent a separate secure password-setup message for your new account.'],
            'organizer_assignment_link_label' => ['Organizer assignment email: app-link label', 'Organizer app:'],
            'organizer_review_subject' => ['Organizer request decision email: subject', 'Event request {decision}: {event}', ['decision', 'event']],
            'organizer_review_body' => ['Organizer request decision email: message', 'Your event request #{id} was {decision}.', ['id', 'decision']],
            'organizer_review_link_label' => ['Organizer request decision email: portal-link label', 'Organizer Portal:'],
        ];

        foreach ($local as $key => $item) {
            $definition = [
                'label' => (string) $item[0],
                'text' => (string) $item[1],
                'group' => 'Organizer Portal',
            ];
            if (!empty($item[2]) && is_array($item[2])) {
                $definition['required_tokens'] = array_values(array_map('strval', $item[2]));
            }
            $defaults[$key] = $definition;
        }
        return $defaults;
    }

    public static function text(string $key, string $fallback): string
    {
        return function_exists('bg_str') ? \bg_str($key, $fallback) : $fallback;
    }

    /** @param array<string,scalar|null> $tokens */
    public static function format(string $key, string $fallback, array $tokens, array $required = []): string
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
}
