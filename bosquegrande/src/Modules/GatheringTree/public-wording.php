<?php
/** Visitor-facing calendar wording and safe token interpolation. */

defined('ABSPATH') || exit;

/** @return array<string,array{label:string,text:string,group?:string,required_tokens?:string[]}> */
function bgev_gathering_wording_defaults(): array {
    static $defaults = null;
    if (is_array($defaults)) return $defaults;
    $defaults = [
        'calendar_suggest_event' => ['label' => 'Calendar: suggest-event button', 'text' => '+ Suggest Event'],
        'calendar_view_navigation_aria' => ['label' => 'Calendar: view and date-range navigation accessible label', 'text' => 'Calendar view and date range'],
        'calendar_open_registration_label' => ['label' => 'Calendar: registration-window control label', 'text' => 'Open registration in'],
        'calendar_open_registration_aria' => ['label' => 'Calendar: registration-window control accessible label', 'text' => 'Choose where event registration opens'],
        'calendar_open_same' => ['label' => 'Calendar: open registration in same window', 'text' => 'Same window'],
        'calendar_open_tab' => ['label' => 'Calendar: open registration in new tab', 'text' => 'New tab'],
        'calendar_open_window' => ['label' => 'Calendar: open registration in new window', 'text' => 'New window'],
        'calendar_showing_date' => ['label' => 'Calendar: selected-date notice ({date})', 'text' => 'Showing events on {date}', 'required_tokens' => ['date']],
        'calendar_date_clear' => ['label' => 'Calendar: clear selected date', 'text' => '× Clear'],
        'calendar_more_events' => ['label' => 'Calendar: load more events', 'text' => 'More Events'],
        'calendar_card_details' => ['label' => 'Calendar: event-card image link description ({event})', 'text' => 'View details for {event}', 'required_tokens' => ['event']],
        'calendar_previous_month' => ['label' => 'Calendar: previous-month button description', 'text' => 'Previous month'],
        'calendar_next_month' => ['label' => 'Calendar: next-month button description', 'text' => 'Next month'],
        'calendar_more_count' => ['label' => 'Calendar: additional-events link ({count})', 'text' => '+{count} more', 'required_tokens' => ['count']],
        'calendar_suggestion_section_aria' => ['label' => 'Calendar: suggestion section accessible label', 'text' => 'Suggest an event'],
        'calendar_skip' => ['label' => 'Calendar: skip-link wording', 'text' => 'Skip to the events calendar'],
        'filter_location_label' => ['label' => 'Calendar filter: location label', 'text' => 'Location:'],
        'filter_location_aria' => ['label' => 'Calendar filter: location accessible label', 'text' => 'Filter events by location'],
        'filter_all_locations' => ['label' => 'Calendar filter: all locations choice', 'text' => 'All Locations'],
        'filter_interest_label' => ['label' => 'Calendar filter: interest label', 'text' => 'Interest:'],
        'filter_interest_aria' => ['label' => 'Calendar filter: interest accessible label', 'text' => 'Filter events by interest'],
        'filter_all_interests' => ['label' => 'Calendar filter: all interests choice', 'text' => 'All Interests'],
        'filter_clear_all' => ['label' => 'Calendar filter: clear all filters', 'text' => '× Clear all'],

        'event_navigation_aria' => ['label' => 'Single event: navigation accessible label', 'text' => 'Event navigation'],
        'event_return_calendar' => ['label' => 'Single event: return-to-calendar link', 'text' => 'Return to Calendar'],
        'event_eyebrow' => ['label' => 'Single event: small heading', 'text' => 'Community event'],
        'event_fallback_title' => ['label' => 'Single event: title when no event title is available', 'text' => 'Event details'],
        'event_details_aria' => ['label' => 'Single event: date/time/location accessible label', 'text' => 'Event date, time, and location'],
        'event_date_label' => ['label' => 'Single event: date label', 'text' => 'Date'],
        'event_time_label' => ['label' => 'Single event: time label', 'text' => 'Time'],
        'event_location_label' => ['label' => 'Single event: location label', 'text' => 'Location'],
        'event_ended' => ['label' => 'Single event: ended notice', 'text' => 'This event has ended.'],
        'event_categories_aria' => ['label' => 'Single event: categories accessible label', 'text' => 'Event categories'],
        'event_categories_label' => ['label' => 'Single event: visually hidden categories label', 'text' => 'Event categories:'],
        'event_skip' => ['label' => 'Single event: skip-link wording', 'text' => 'Skip to event details'],
        'event_footer_return' => ['label' => 'Single event: footer return link', 'text' => 'Return to the events calendar'],

        'suggest_disabled_form' => ['label' => 'Suggestion form: disabled notice', 'text' => 'Event submissions are not currently enabled.'],
        'suggest_heading' => ['label' => 'Suggestion form: heading', 'text' => 'Suggest a Community Event'],
        'suggest_intro' => ['label' => 'Suggestion form: introduction', 'text' => "Have a community event you'd like added to the calendar? Fill out the form below & we'll send a verification link to your email, and once you confirm, your suggestion goes into review."],
        'suggest_image_heading' => ['label' => 'Suggestion form: image-note heading', 'text' => '📷 Have an image to suggest?'],
        'suggest_image_before_email' => ['label' => 'Suggestion form: image note before contact email', 'text' => 'Email it directly to'],
        'suggest_image_after_email' => ['label' => 'Suggestion form: image note after contact email', 'text' => "; image uploads aren't accepted through this form for security reasons."],
        'suggest_name_label' => ['label' => 'Suggestion form: name label', 'text' => 'Your name'],
        'suggest_email_label' => ['label' => 'Suggestion form: email label', 'text' => 'Your email'],
        'suggest_email_help' => ['label' => 'Suggestion form: email help', 'text' => "We'll send a verification link here. Used only for follow-up about this submission."],
        'suggest_title_label' => ['label' => 'Suggestion form: event title label', 'text' => 'Event title'],
        'suggest_start_date_label' => ['label' => 'Suggestion form: start date label', 'text' => 'Start date'],
        'suggest_start_time_label' => ['label' => 'Suggestion form: start time label', 'text' => 'Start time'],
        'suggest_end_date_label' => ['label' => 'Suggestion form: end date label', 'text' => 'End date'],
        'suggest_end_time_label' => ['label' => 'Suggestion form: end time label', 'text' => 'End time'],
        'suggest_location_label' => ['label' => 'Suggestion form: location label', 'text' => 'Location'],
        'suggest_location_placeholder' => ['label' => 'Suggestion form: location placeholder', 'text' => 'Venue name, city'],
        'suggest_registration_url_label' => ['label' => 'Suggestion form: registration URL label', 'text' => 'Registration URL'],
        'suggest_registration_url_placeholder' => ['label' => 'Suggestion form: registration URL placeholder', 'text' => 'https://...'],
        'suggest_registration_url_help' => ['label' => 'Suggestion form: registration URL help', 'text' => 'Optional. Where attendees can RSVP or buy tickets.'],
        'suggest_description_label' => ['label' => 'Suggestion form: description label', 'text' => 'Description'],
        'suggest_description_help' => ['label' => 'Suggestion form: description help', 'text' => "What's the event about? Plain text or basic formatting (paragraphs, bold, italic, links, lists). Images not allowed; email them to the contact above."],
        'suggest_location_tag_label' => ['label' => 'Suggestion form: suggested location label', 'text' => 'Suggested location'],
        'suggest_interest_tag_label' => ['label' => 'Suggestion form: suggested interest label', 'text' => 'Suggested interest'],
        'suggest_optional' => ['label' => 'Suggestion form: optional choice', 'text' => '(Optional)'],
        'suggest_honeypot_label' => ['label' => 'Suggestion form: hidden anti-spam label', 'text' => 'Website (leave blank)'],
        'suggest_required_marker' => ['label' => 'Suggestion form: required-field marker', 'text' => '*'],
        'suggest_submit_button' => ['label' => 'Suggestion form: submit button', 'text' => 'Submit Suggestion'],
        'suggest_js_submitting' => ['label' => 'Suggestion form: submitting status', 'text' => 'Submitting…'],
        'suggest_js_check_email' => ['label' => 'Suggestion form: success status introduction', 'text' => 'Check your email.'],
        'suggest_js_verify_fallback' => ['label' => 'Suggestion form: verification fallback status ({hours})', 'text' => "We've sent a verification link. Click it within {hours} hours to confirm your suggestion.", 'required_tokens' => ['hours']],
        'suggest_js_failed' => ['label' => 'Suggestion form: generic failure', 'text' => 'Submission failed. Please try again.'],
        'suggest_js_network' => ['label' => 'Suggestion form: network failure', 'text' => 'Network error. Please try again.'],

        'suggest_api_disabled' => ['label' => 'Suggestion submission: disabled response', 'text' => 'Submissions are not enabled.'],
        'suggest_api_honeypot_success' => ['label' => 'Suggestion submission: anti-spam neutral response', 'text' => 'Thanks. Check your email.'],
        'suggest_api_invalid_request' => ['label' => 'Suggestion submission: invalid field-shape response', 'text' => 'The submission contains an invalid field value. Please reload the page and try again.'],
        'suggest_api_too_long' => ['label' => 'Suggestion submission: field-length response', 'text' => 'One or more fields is too long. Please shorten the entry and try again.'],
        'suggest_api_slow_down' => ['label' => 'Suggestion submission: rate response', 'text' => 'Please slow down.'],
        'suggest_api_missing_field' => ['label' => 'Suggestion submission: missing-field response ({field})', 'text' => 'Missing required field: {field}', 'required_tokens' => ['field']],
        'suggest_api_invalid_email' => ['label' => 'Suggestion submission: invalid email response', 'text' => 'Please provide a valid email address.'],
        'suggest_api_invalid_datetime' => ['label' => 'Suggestion submission: invalid date/time response', 'text' => 'Invalid date or time format.'],
        'suggest_api_parse_start' => ['label' => 'Suggestion submission: start date/time response', 'text' => 'Could not parse start date/time.'],
        'suggest_api_past_start' => ['label' => 'Suggestion submission: past start response', 'text' => 'Event start must not be in the past.'],
        'suggest_api_parse_end' => ['label' => 'Suggestion submission: end date/time response', 'text' => 'Could not parse end date/time.'],
        'suggest_api_end_before_start' => ['label' => 'Suggestion submission: invalid end response', 'text' => 'Event end must be after its start.'],
        'suggest_api_secure_storage' => ['label' => 'Suggestion submission: secure-storage failure', 'text' => 'Secure suggestion storage is temporarily unavailable. Please contact the organizer.'],
        'suggest_api_too_many' => ['label' => 'Suggestion submission: rate-limit response', 'text' => 'Too many event-suggestion requests. Please wait and try again later.'],
        'suggest_api_save_failed' => ['label' => 'Suggestion submission: save failure', 'text' => 'The suggestion could not be saved for verification. Please try again.'],
        'suggest_api_email_failed' => ['label' => 'Suggestion submission: verification-email failure', 'text' => 'Could not send verification email. Please contact the site administrator.'],

        'suggest_email_kicker' => ['label' => 'Suggestion email: header label', 'text' => 'Event Calendar'],
        'suggest_email_footer' => ['label' => 'Suggestion email: footer ({site})', 'text' => "You're receiving this email because of an event suggestion submitted at {site}. If this wasn't you, no action is needed; the request will expire automatically.", 'required_tokens' => ['site']],
        'suggest_email_verify_heading' => ['label' => 'Suggestion verification email: heading', 'text' => 'Confirm your event suggestion'],
        'suggest_email_greeting' => ['label' => 'Suggestion email: greeting ({name})', 'text' => 'Hi {name},', 'required_tokens' => ['name']],
        'suggest_email_verify_body' => ['label' => 'Suggestion verification email: explanation ({event})', 'text' => 'Thanks for suggesting {event}. To complete your submission, please confirm by clicking the button below.', 'required_tokens' => ['event']],
        'suggest_email_verify_button' => ['label' => 'Suggestion verification email: button', 'text' => 'Confirm submission'],
        'suggest_email_verify_paste' => ['label' => 'Suggestion verification email: paste-link instruction', 'text' => 'Or paste this link into your browser:'],
        'suggest_email_verify_expiry' => ['label' => 'Suggestion verification email: expiry notice ({hours})', 'text' => "This link expires in {hours} hours. If you didn't submit this event, you can safely ignore this email.", 'required_tokens' => ['hours']],
        'suggest_email_verify_subject' => ['label' => 'Suggestion verification email: subject ({event})', 'text' => 'Confirm your event suggestion: {event}', 'required_tokens' => ['event']],
        'suggest_email_verify_layout_title' => ['label' => 'Suggestion verification email: document title', 'text' => 'Confirm submission'],
        'suggest_email_approved_heading' => ['label' => 'Suggestion result email: approved heading', 'text' => 'Your event suggestion was approved'],
        'suggest_email_reviewed_heading' => ['label' => 'Suggestion result email: reviewed heading', 'text' => 'Your event suggestion was reviewed'],
        'suggest_email_approved_message' => ['label' => 'Suggestion result email: approved message ({event})', 'text' => 'Thank you! Your suggestion for {event} has been approved and published.', 'required_tokens' => ['event']],
        'suggest_email_reviewed_message' => ['label' => 'Suggestion result email: not-published message ({event})', 'text' => 'Thank you for suggesting {event}. It was reviewed but was not published.', 'required_tokens' => ['event']],
        'suggest_email_fallback_name' => ['label' => 'Suggestion result email: fallback recipient name', 'text' => 'there'],
        'suggest_email_result_subject' => ['label' => 'Suggestion result email: subject ({heading}, {event})', 'text' => '{heading}: {event}', 'required_tokens' => ['heading', 'event']],

        'verification_invalid' => ['label' => 'Event suggestion verification: invalid-link result', 'text' => 'Invalid verification link.'],
        'verification_already_confirmed' => ['label' => 'Event suggestion verification: already-confirmed result', 'text' => 'This event suggestion is already confirmed and safely stored for staff review.'],
        'verification_expired' => ['label' => 'Event suggestion verification: expired-link result', 'text' => 'This verification link has expired or already been used. Please submit your suggestion again.'],
        'verification_confirm_instruction' => ['label' => 'Event suggestion verification: confirmation instruction', 'text' => 'Select Confirm suggestion to place this event in the staff review queue.'],
        'verification_already_processing' => ['label' => 'Event suggestion verification: already-processing result', 'text' => 'This verification link is already being processed or has already been used.'],
        'verification_other_request' => ['label' => 'Event suggestion verification: concurrent-request result', 'text' => 'This verification is being completed by another request. Please check the link again shortly.'],
        'verification_retry_processing' => ['label' => 'Event suggestion verification: retry-processing result', 'text' => 'This verification link is already being processed. Please try again shortly.'],
        'verification_create_failed' => ['label' => 'Event suggestion verification: event-creation failure', 'text' => 'Could not create event record. Please contact the site administrator.'],
        'verification_finalize_failed' => ['label' => 'Event suggestion verification: finalization failure', 'text' => 'The event is stored for review, but its verification record could not be finalized. Please contact the site administrator.'],
        'verification_secure_record_failed' => ['label' => 'Event suggestion verification: secure-record failure', 'text' => 'The event was verified, but its secure review record could not be completed. Please contact the site administrator.'],
        'verification_staff_email_failed' => ['label' => 'Event suggestion verification: staff-email failure', 'text' => 'Your suggestion is confirmed and safely stored for review. The staff notification email could not be sent, but the event remains visible in the WordPress pending-events queue.'],
    ];
    // Keep the single Wording & Styling screen usable even as the public
    // vocabulary grows. Grouping changes presentation only; keys and stored
    // overrides remain exactly the same.
    foreach ($defaults as $key => &$definition) {
        if (str_starts_with($key, 'event_')) {
            $definition['group'] = 'Event details';
        } elseif (str_starts_with($key, 'suggest_email_')) {
            $definition['group'] = 'Event suggestions: email';
        } elseif (str_starts_with($key, 'suggest_api_') || str_starts_with($key, 'verification_')) {
            $definition['group'] = 'Event suggestions: verification and responses';
        } elseif (str_starts_with($key, 'suggest_')) {
            $definition['group'] = 'Event suggestions: form';
        } else {
            $definition['group'] = 'Events calendar';
        }
    }
    unset($definition);
    return $defaults;
}

add_filter('bg_wording_defaults', static function (array $defaults): array {
    foreach (bgev_gathering_wording_defaults() as $key => $definition) {
        if (!isset($defaults[$key])) $defaults[$key] = $definition;
    }
    return $defaults;
});

/** Resolve a template and restore any functional placeholders an override omitted. */
function bgev_wording_template(string $key, string $fallback): string {
    $text = bg_str($key, $fallback);
    $definition = bgev_gathering_wording_defaults()[$key] ?? [];
    if ($definition === [] && function_exists('bg_wording_defaults')) {
        $definition = bg_wording_defaults()[$key] ?? [];
    }
    foreach ((array) ($definition['required_tokens'] ?? []) as $name) {
        $marker = '{' . sanitize_key((string) $name) . '}';
        if ($marker !== '{}' && !str_contains($text, $marker)) $text = rtrim($text) . ' ' . $marker;
    }
    return $text;
}

/** Replace named plain-text tokens after the admin wording override resolves. */
function bgev_wording(string $key, string $fallback, array $tokens = []): string {
    $text = bgev_wording_template($key, $fallback);
    $replace = [];
    foreach ($tokens as $name => $value) $replace['{' . $name . '}'] = (string) $value;
    return strtr($text, $replace);
}

/** Escape wording while allowing only caller-owned, pre-escaped token HTML. */
function bgev_wording_html(string $key, string $fallback, array $safeTokens = []): string {
    $html = esc_html(bgev_wording_template($key, $fallback));
    foreach ($safeTokens as $name => $safeHtml) {
        $html = str_replace(esc_html('{' . $name . '}'), (string) $safeHtml, $html);
    }
    return $html;
}

/** Display label for a tag slug, taken from the configured vocabulary. */
function bgev_public_tag_label(string $axis, string $slug, string $fallback): string {
    $map = $axis === 'location'
        ? \BosqueGrande\Modules\GatheringTree\Vocabulary::regions()
        : \BosqueGrande\Modules\GatheringTree\Vocabulary::interests();
    $label = (string) ($map[sanitize_key($slug)] ?? '');
    return $label !== '' ? $label : $fallback;
}
