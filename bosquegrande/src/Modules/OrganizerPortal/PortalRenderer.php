<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\OrganizerPortal;

use BosqueGrande\Modules\Locations\Taxonomy as LocationTaxonomy;

if (!defined('ABSPATH')) exit;

/** Theme-friendly front-end organizer dashboard. */
final class PortalRenderer
{
    public function __construct(
        private OrganizerRepository $repository,
        private OrganizerAccess $access,
    ) {
    }

    public function render(): string
    {
        wp_enqueue_style('bosquegrande-organizer-portal');
        $current = $this->currentUrl();
        if (!is_user_logged_in()) {
            $appUrl = $this->appUrl();
            return '<div class="bg-org bg-org-login"><h2>' . esc_html($this->w('organizer_portal_title', 'Organizer Portal')) . '</h2>'
                . '<p>' . esc_html($this->w('organizer_login_intro', 'Sign in with the organizer account your organization gave you.')) . '</p>'
                . '<p><a class="bg-org-button" href="' . esc_url($appUrl) . '">' . esc_html($this->w('organizer_open_app', 'Open the Organizer App')) . '</a></p>'
                . wp_login_form(['echo' => false, 'redirect' => $current, 'remember' => true])
                . '<p><a href="' . esc_url(wp_lostpassword_url($current)) . '">' . esc_html($this->w('organizer_reset_password', 'Set or reset your password')) . '</a></p>'
                . '</div>';
        }

        $userId = get_current_user_id();
        if (!$this->repository->available()) {
            return $this->notice($this->w('organizer_database_unavailable', 'The organizer portal database update is not available yet. An administrator should check BosqueGrande System Status.'), 'error');
        }
        if (!$this->access->canUsePortal($userId)) {
            return $this->notice($this->w('organizer_access_denied', 'This account does not have organizer access.'), 'error');
        }

        $eventId = absint($_GET['bg_organizer_event'] ?? 0);
        $html = '<div class="bg-org">' . $this->renderHeader();
        $html .= $this->queryNotice();
        if ($eventId > 0) {
            $html .= $this->renderEvent($userId, $eventId);
        } else {
            $html .= $this->renderDashboard($userId);
        }
        return $html . '</div>';
    }

    private function renderHeader(): string
    {
        $user = wp_get_current_user();
        $base = remove_query_arg(['bg_organizer_event', 'bg_org_notice'], $this->currentUrl());
        $welcome = $this->wf(
            'organizer_welcome',
            'Welcome, {name}.',
            ['name' => $user->display_name ?: $user->user_login],
            ['name']
        );
        return '<header class="bg-org-header"><div><span class="bg-org-eyebrow">' . esc_html($this->w('organizer_eyebrow', 'BosqueGrande')) . '</span>'
            . '<h1>' . esc_html($this->w('organizer_portal_title', 'Organizer Portal')) . '</h1><p>' . esc_html($welcome) . '</p></div>'
            . '<nav aria-label="' . esc_attr($this->w('organizer_navigation_aria', 'Organizer navigation')) . '"><a class="bg-org-app-nav-link" href="' . esc_url($this->appUrl()) . '">' . esc_html($this->w('organizer_nav_app', 'Event Organizer App')) . '</a>'
            . '<a href="' . esc_url($base) . '">' . esc_html($this->w('organizer_nav_events', 'My events')) . '</a>'
            . '<a href="' . esc_url(admin_url('profile.php')) . '">' . esc_html($this->w('organizer_nav_account', 'Account settings')) . '</a>'
            . '<a href="' . esc_url(wp_logout_url($base)) . '">' . esc_html($this->w('organizer_nav_sign_out', 'Sign out')) . '</a></nav></header>';
    }

    private function renderDashboard(int $userId): string
    {
        $admin = user_can($userId, 'manage_options');
        $events = $this->repository->eventsForUser($userId, $admin);
        $html = '<section class="bg-org-section"><div class="bg-org-section-head"><div><h2>'
            . esc_html($admin
                ? $this->w('organizer_all_events', 'All events')
                : $this->w('organizer_assigned_events', 'My assigned events')) . '</h2>'
            . '<p>' . esc_html($this->w('organizer_dashboard_intro', 'Open an event to manage its registrants, attendance, messages, and proposed changes.')) . '</p></div></div>';
        if (!$events) {
            $html .= '<div class="bg-org-empty">' . esc_html($this->w('organizer_no_events', 'No events are assigned to this account yet.')) . '</div>';
        } else {
            $html .= '<div class="bg-org-event-grid">';
            foreach ($events as $event) {
                $id = (int) $event->ID;
                if (!$this->access->canEvent($userId, $id, 'view')) continue;
                $date = (string) get_post_meta($id, '_bg_start_date', true);
                $time = function_exists('bgev_time_range')
                    ? bgev_time_range($id)
                    : (string) get_post_meta($id, '_bg_start_time', true);
                $url = add_query_arg('bg_organizer_event', $id, remove_query_arg('bg_org_notice', $this->currentUrl()));
                $count = $this->repository->registrationCount($id);
                $html .= '<article class="bg-org-event-card"><div class="bg-org-event-status">' . esc_html($this->w('organizer_event_status_published', 'Published')) . '</div><h3>'
                    . esc_html(get_the_title($id)) . '</h3><p>'
                    . esc_html(trim($date . ' ' . $time) ?: $this->w('organizer_date_not_set', 'Date not set')) . '</p><p>'
                    . esc_html($this->wf('organizer_confirmed_count', '{count} confirmed registrants', ['count' => $count], ['count'])) . '</p>'
                    . '<a class="bg-org-button" href="' . esc_url($url) . '">' . esc_html($this->w('organizer_manage_event', 'Manage event')) . '</a></article>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';
        if ($this->access->canCreate($userId)) $html .= $this->renderEventForm(null, true);
        return $html;
    }

    private function renderEvent(int $userId, int $eventId): string
    {
        if (!$this->access->canEvent($userId, $eventId, 'view')) {
            return $this->notice($this->w('organizer_event_not_assigned', 'You are not assigned to that event.'), 'error');
        }
        $event = get_post($eventId);
        if (!$event || $event->post_type !== 'bg_event') {
            return $this->notice($this->w('organizer_event_missing', 'That event no longer exists.'), 'error');
        }
        $base = remove_query_arg(['bg_organizer_event', 'bg_org_notice'], $this->currentUrl());
        $html = '<p><a class="bg-org-back" href="' . esc_url($base) . '">&larr; '
            . esc_html($this->w('organizer_all_assigned_events', 'All assigned events')) . '</a></p>';
        $heroTime = function_exists('bgev_time_range')
            ? bgev_time_range($eventId)
            : (string) get_post_meta($eventId, '_bg_start_time', true);
        $html .= '<section class="bg-org-event-hero"><div><span class="bg-org-event-status">'
            . esc_html($this->w('organizer_event_status_published', 'Published')) . '</span><h2>' . esc_html(get_the_title($eventId)) . '</h2><p>'
            . esc_html(trim((string) get_post_meta($eventId, '_bg_start_date', true) . ' ' . $heroTime)) . '</p></div>';
        $html .= '<a href="' . esc_url(get_permalink($eventId)) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html($this->w('organizer_view_public_event', 'View public event')) . '</a>';
        $html .= '</section>';

        $roster = $this->repository->roster($eventId);
        $html .= $this->renderRoster($userId, $eventId, $roster);
        if ($this->access->canEvent($userId, $eventId, 'message')) {
            $html .= $this->renderMessageForm($eventId, $roster);
        }
        if ($this->access->canEvent($userId, $eventId, 'propose')) {
            $html .= $this->renderEventForm($event, false);
            $history = $this->repository->requestsForEvent($eventId, $userId);
            if ($history) {
                $html .= '<section class="bg-org-section"><h3>' . esc_html($this->w('organizer_request_history', 'Change-request history')) . '</h3><ul class="bg-org-history">';
                foreach ($history as $request) {
                    $requestLabel = $this->wf(
                        'organizer_request_item',
                        '{type} request #{id}: {status}',
                        [
                            'type' => ucfirst((string) $request->request_type),
                            'id' => (int) $request->id,
                            'status' => ucfirst((string) $request->status),
                        ],
                        ['type', 'id', 'status']
                    );
                    $html .= '<li><strong>' . esc_html($requestLabel) . '</strong> <span>'
                        . esc_html((string) $request->created_at) . ' UTC</span></li>';
                }
                $html .= '</ul></section>';
            }
        }
        return $html;
    }

    /** @param list<array<string, mixed>> $roster */
    private function renderRoster(int $userId, int $eventId, array $roster): string
    {
        $html = '<section class="bg-org-section"><div class="bg-org-section-head"><div><h3>'
            . esc_html($this->w('organizer_roster_title', 'Registrant roster')) . '</h3>'
            . '<p>' . esc_html($this->w('organizer_roster_privacy', 'Contact information below belongs only to people registered for this event.')) . '</p></div>';
        if ($this->access->canEvent($userId, $eventId, 'export') && $roster) {
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="bg_org_export"><input type="hidden" name="event_id" value="' . $eventId . '">'
                . wp_nonce_field('bg_org_export_' . $eventId, '_wpnonce', true, false)
                . '<button class="bg-org-button bg-org-button-secondary" type="submit">'
                . esc_html($this->w('organizer_roster_export', 'Export this roster')) . '</button></form>';
        }
        $html .= '</div>';
        if (!$roster) return $html . '<div class="bg-org-empty">'
            . esc_html($this->w('organizer_roster_empty', 'No confirmed registrations yet.')) . '</div></section>';
        $canCheck = $this->access->canEvent($userId, $eventId, 'check_in');
        $html .= '<div class="bg-org-table-wrap"><table class="bg-org-table"><thead><tr><th>'
            . esc_html($this->w('organizer_roster_name', 'Name')) . '</th><th>'
            . esc_html($this->w('organizer_roster_contact', 'Contact')) . '</th><th>'
            . esc_html($this->w('organizer_roster_registration', 'Registration')) . '</th><th>'
            . esc_html($this->w('organizer_roster_attendance', 'Attendance')) . '</th></tr></thead><tbody>';
        foreach ($roster as $row) {
            $withheld = !empty($row['contact_withheld']);
            $name = $withheld ? $this->w('organizer_anonymous_registrant', 'Anonymous registrant') : trim((string) $row['name']);
            if ($name === '') $name = $this->w('organizer_identity_unavailable', 'Identity unavailable');
            $contact = $withheld
                ? '<span class="bg-org-muted">' . esc_html($this->w('organizer_contact_withheld', 'Withheld by registrant')) . '</span>'
                : $this->contactHtml($row);
            $html .= '<tr><td><strong>' . esc_html($name) . '</strong></td><td>' . $contact . '</td><td>'
                . esc_html((string) $row['tier']) . '<br><span class="bg-org-muted">'
                . esc_html((string) $row['created_at']) . ' UTC</span></td><td>';
            if ($canCheck) {
                $html .= '<form class="bg-org-attendance" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="bg_org_attendance"><input type="hidden" name="event_id" value="' . $eventId . '">'
                    . '<input type="hidden" name="registration_id" value="' . (int) $row['registration_id'] . '">'
                    . wp_nonce_field('bg_org_attendance_' . $eventId . '_' . (int) $row['registration_id'], '_wpnonce', true, false)
                    . '<select name="attendance_status" aria-label="' . esc_attr($this->wf(
                        'organizer_attendance_aria',
                        'Attendance for {name}',
                        ['name' => $name],
                        ['name']
                    )) . '">';
                foreach (OrganizerRepository::ATTENDANCE as $status) {
                    $html .= '<option value="' . esc_attr($status) . '" '
                        . selected((string) $row['attendance_status'], $status, false) . '>'
                        . esc_html($this->attendanceLabel($status)) . '</option>';
                }
                $html .= '</select><button type="submit">' . esc_html($this->w('organizer_save_attendance', 'Save')) . '</button></form>';
            } else {
                $html .= esc_html($this->attendanceLabel((string) $row['attendance_status']));
            }
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** @param array<string, mixed> $row */
    private function contactHtml(array $row): string
    {
        if (empty($row['contact_available'])) {
            return '<span class="bg-org-muted">' . esc_html($this->w('organizer_contact_unavailable', 'Contact unavailable')) . '</span>';
        }
        $parts = [];
        $email = (string) ($row['email'] ?? '');
        $phone = (string) ($row['phone'] ?? '');
        if (is_email($email)) {
            $parts[] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        if ($phone !== '') {
            $parts[] = '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
        }
        $street = array_values(array_filter([
            trim((string) ($row['address1'] ?? '')),
            trim((string) ($row['address2'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $cityState = trim(implode(', ', array_values(array_filter([
            trim((string) ($row['city'] ?? '')),
            trim((string) ($row['state'] ?? '')),
        ], static fn(string $value): bool => $value !== ''))));
        $locality = trim($cityState . ($cityState !== '' && (string) ($row['postal'] ?? '') !== '' ? ' ' : '')
            . trim((string) ($row['postal'] ?? '')));
        $address = array_merge($street, $locality !== '' ? [$locality] : []);
        if ($address) $parts[] = esc_html(implode(', ', $address));
        return $parts
            ? implode('<br>', $parts)
            : '<span class="bg-org-muted">' . esc_html($this->w('organizer_no_contact_details', 'No contact details on file')) . '</span>';
    }

    /** @param list<array<string, mixed>> $roster */
    private function renderMessageForm(int $eventId, array $roster): string
    {
        if (!$roster) return '';
        $html = '<section class="bg-org-section"><h3>' . esc_html($this->w('organizer_message_title', 'Email registrants')) . '</h3>'
            . '<p>' . esc_html($this->w('organizer_message_privacy', 'Each person gets a separate message, so no one sees anyone else’s address.')) . '</p>'
            . '<form class="bg-org-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="bg_org_message"><input type="hidden" name="event_id" value="' . $eventId . '">'
            . wp_nonce_field('bg_org_message_' . $eventId, '_wpnonce', true, false)
            . '<label>' . esc_html($this->w('organizer_message_recipients', 'Recipients')) . '<select name="recipient"><option value="all">'
            . esc_html($this->w('organizer_message_all', 'All registrants with an available email')) . '</option>';
        foreach ($roster as $row) {
            if (empty($row['contact_available']) || !is_email((string) $row['email'])) continue;
            $html .= '<option value="' . (int) $row['registration_id'] . '">'
                . esc_html(trim((string) $row['name']) ?: (string) $row['email']) . '</option>';
        }
        return $html . '</select></label><label>' . esc_html($this->w('organizer_message_subject', 'Subject')) . '<input type="text" name="subject" maxlength="160" required></label>'
            . '<label>' . esc_html($this->w('organizer_message_body', 'Message')) . '<textarea name="message" rows="7" maxlength="5000" required></textarea></label>'
            . '<button class="bg-org-button" type="submit">' . esc_html($this->w('organizer_message_send', 'Send message')) . '</button></form></section>';
    }

    private function renderEventForm(?\WP_Post $event, bool $create): string
    {
        $eventId = $event ? (int) $event->ID : 0;
        $pending = $eventId > 0 && $this->repository->hasPendingRequest($eventId, get_current_user_id());
        $title = $create ? '' : (string) $event->post_title;
        $content = $create ? '' : (string) $event->post_content;
        $value = static function (string $meta) use ($eventId): string {
            if (!$eventId) return '';
            if ($meta === '_bg_reg_url' && function_exists('get_metadata_raw')) {
                return (string) get_metadata_raw('post', $eventId, $meta, true);
            }
            return (string) get_post_meta($eventId, $meta, true);
        };
        $html = '<section class="bg-org-section"><h3>' . esc_html($create
                ? $this->w('organizer_create_title', 'Propose a new event')
                : $this->w('organizer_edit_title', 'Propose event changes')) . '</h3>'
            . '<p>' . esc_html($create
                ? $this->w('organizer_create_intro', 'The event is saved privately for staff review. It is not public until approved.')
                : $this->w('organizer_edit_intro', 'Your proposed changes remain private until staff approve them.')) . '</p>';
        if ($pending) return $html . '<div class="bg-org-notice">'
            . esc_html($this->w('organizer_request_pending', 'A request for this event is already awaiting staff review.')) . '</div></section>';
        $html .= '<form class="bg-org-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . ($create ? 'bg_org_create_event' : 'bg_org_propose') . '">'
            . '<input type="hidden" name="event_id" value="' . $eventId . '">'
            . wp_nonce_field(($create ? 'bg_org_create_event' : 'bg_org_propose_' . $eventId), '_wpnonce', true, false)
            . '<label>' . esc_html($this->w('organizer_field_title', 'Event title')) . '<input type="text" name="event_title" maxlength="200" required value="' . esc_attr($title) . '"></label>'
            . '<div class="bg-org-form-grid"><label>' . esc_html($this->w('organizer_field_start_date', 'Start date')) . '<input type="date" name="start_date" required value="' . esc_attr($value('_bg_start_date')) . '"></label>'
            . '<label>' . esc_html($this->w('organizer_field_start_time', 'Start time')) . '<input type="text" name="start_time" placeholder="' . esc_attr($this->w('organizer_start_time_placeholder', '7:00 PM')) . '" value="' . esc_attr($value('_bg_start_time')) . '"></label>'
            . '<label>' . esc_html($this->w('organizer_field_end_date', 'End date')) . '<input type="date" name="end_date" value="' . esc_attr($value('_bg_end_date')) . '"></label>'
            . '<label>' . esc_html($this->w('organizer_field_end_time', 'End time')) . '<input type="text" name="end_time" placeholder="' . esc_attr($this->wf(
                'organizer_end_time_placeholder',
                '8:00 PM (defaults to +{default_duration})',
                ['default_duration' => '1 hour'],
                ['default_duration']
            )) . '" value="' . esc_attr($value('_bg_end_time')) . '"></label></div>'
            . '<label>' . esc_html($this->w('organizer_field_description', 'Description')) . '<textarea name="description" rows="8" required>' . esc_textarea($content) . '</textarea></label>'
            . '<div class="bg-org-form-grid">' . $this->taxonomySelect('location_slug', $this->w('organizer_field_location', 'Location'), LocationTaxonomy::taxonomy(), $eventId)
            . $this->taxonomyMultiSelect('interest_slugs[]', $this->w('organizer_field_interests', 'Interests'), 'bg_tag', $eventId) . '</div>'
            . '<label>' . esc_html($this->w('organizer_field_registration_url', 'External registration URL')) . ' <span class="bg-org-muted">'
            . esc_html($this->w('organizer_registration_url_note', '(optional; built-in registration remains the default)')) . '</span>'
            . '<input type="url" name="registration_url" value="' . esc_attr($value('_bg_reg_url')) . '"></label>'
            . '<label>' . esc_html($this->w('organizer_field_image_url', 'Event image URL')) . ' <span class="bg-org-muted">'
            . esc_html($this->w('organizer_optional_note', '(optional)')) . '</span><input type="url" name="image_url" value="'
            . esc_attr($value('_bg_image')) . '"></label>'
            . '<button class="bg-org-button" type="submit">' . esc_html($create
                ? $this->w('organizer_submit_create', 'Submit new event for approval')
                : $this->w('organizer_submit_edit', 'Submit changes for approval')) . '</button></form></section>';
        return $html;
    }

    private function taxonomySelect(string $name, string $label, string $taxonomy, int $eventId): string
    {
        if (!taxonomy_exists($taxonomy)) return '';
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) return '';
        $assigned = $eventId ? wp_get_post_terms($eventId, $taxonomy, ['fields' => 'slugs']) : [];
        $assigned = is_wp_error($assigned) ? [] : array_map('strval', $assigned);
        $locationSlugs = $name === 'location_slug'
            ? array_keys(\BosqueGrande\Modules\GatheringTree\Vocabulary::regions())
            : [];
        $eligible = $locationSlugs ? array_values(array_intersect($assigned, $locationSlugs)) : $assigned;
        $current = $eligible ? (string) $eligible[0] : '';
        $html = '<label>' . esc_html($label) . '<select name="' . esc_attr($name) . '"><option value="">'
            . esc_html($this->w('organizer_not_selected', 'Not selected')) . '</option>';
        foreach ($terms as $term) {
            if ($locationSlugs && !in_array((string) $term->slug, $locationSlugs, true)) continue;
            $html .= '<option value="' . esc_attr((string) $term->slug) . '" '
                . selected($current, (string) $term->slug, false) . '>' . esc_html((string) $term->name) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function taxonomyMultiSelect(string $name, string $label, string $taxonomy, int $eventId): string
    {
        if (!taxonomy_exists($taxonomy)) return '';
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) return '';
        $assigned = $eventId ? wp_get_post_terms($eventId, $taxonomy, ['fields' => 'slugs']) : [];
        $assigned = is_wp_error($assigned) ? [] : array_map('strval', $assigned);
        $interestSlugs = array_keys(\BosqueGrande\Modules\GatheringTree\Vocabulary::interests());
        $html = '<label>' . esc_html($label) . '<select multiple size="6" name="' . esc_attr($name) . '">';
        foreach ($terms as $term) {
            if ($interestSlugs && !in_array((string) $term->slug, $interestSlugs, true)) continue;
            $html .= '<option value="' . esc_attr((string) $term->slug) . '" '
                . selected(in_array((string) $term->slug, $assigned, true), true, false) . '>'
                . esc_html((string) $term->name) . '</option>';
        }
        return $html . '</select><span class="bg-org-muted">'
            . esc_html($this->w('organizer_multi_select_help', 'Use Ctrl/Cmd to select more than one.')) . '</span></label>';
    }

    private function queryNotice(): string
    {
        $code = sanitize_key((string) ($_GET['bg_org_notice'] ?? ''));
        $messages = [
            'attendance_saved' => [$this->w('organizer_notice_attendance_saved', 'Attendance saved.'), 'success'],
            'message_sent' => [$this->w('organizer_notice_message_sent', 'The message was sent.'), 'success'],
            'message_partial' => [$this->w('organizer_notice_message_partial', 'Some messages could not be accepted by the mail server. Staff can review the BosqueGrande log.'), 'error'],
            'request_saved' => [$this->w('organizer_notice_request_saved', 'Your change request was submitted for staff approval.'), 'success'],
            'event_saved' => [$this->w('organizer_notice_event_saved', 'The new event was saved privately and submitted for staff approval.'), 'success'],
            'request_pending' => [$this->w('organizer_notice_request_pending', 'That event already has a request awaiting staff review.'), 'error'],
            'action_failed' => [$this->w('organizer_notice_action_failed', 'The requested action could not be completed.'), 'error'],
            'rate_limited' => [$this->w('organizer_notice_rate_limited', 'Please wait before sending another event message.'), 'error'],
        ];
        return isset($messages[$code]) ? $this->notice($messages[$code][0], $messages[$code][1]) : '';
    }

    private function notice(string $message, string $kind = 'notice'): string
    {
        return '<div class="bg-org-notice bg-org-' . esc_attr($kind) . '" role="status">' . esc_html($message) . '</div>';
    }

    private function attendanceLabel(string $status): string
    {
        return match ($status) {
            'checked_in' => $this->w('organizer_attendance_checked_in', 'Checked in'),
            'attended' => $this->w('organizer_attendance_attended', 'Attended'),
            'no_show' => $this->w('organizer_attendance_no_show', 'No-show'),
            default => $this->w('organizer_attendance_registered', 'Registered'),
        };
    }

    private function w(string $key, string $fallback): string
    {
        return PortalWording::text($key, $fallback);
    }

    /** @param array<string,scalar|null> $tokens */
    private function wf(string $key, string $fallback, array $tokens, array $required = []): string
    {
        return PortalWording::format($key, $fallback, $tokens, $required);
    }

    private function appUrl(): string
    {
        return (string) apply_filters('bg_organizer_home_url', home_url('/bg-organizer-app/'));
    }

    private function currentUrl(): string
    {
        $request = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        return wp_validate_redirect(home_url($request), home_url('/'));
    }
}
