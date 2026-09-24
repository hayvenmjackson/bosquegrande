<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * Public Registration wording and display-only labels.
 *
 * Submitted demographic and survey values are reporting codes. They never
 * pass through this class: only the text printed beside those fixed values is
 * editable. Registration fees follow the same rule; the amount is rendered
 * from immutable cents and only the descriptor is editable.
 */
final class RegistrationWording
{
    /** @var array<string, string[]> */
    private const REQUIRED_TOKENS = [
        'registration_document_title' => ['event'],
        'registration_ended_document_title' => ['event'],
        'registration_heading' => ['event'],
        'registration_ended_body' => ['event'],
        'registration_email_subject' => ['event'],
        'registration_email_paid_intro' => ['event', 'tier'],
        'registration_email_unpaid_intro' => ['event', 'tier'],
        'registration_email_greeting' => ['first'],
        'registration_email_contact_intro' => ['contact'],
        'registration_email_save_info_body' => ['link'],
        'registration_payment_description' => ['event'],
        'registration_confirmed_message' => ['first'],
        'registration_offline_reserved_message' => ['first'],
    ];

    /** @param array<string, array<string, mixed>> $defaults */
    public static function defaults(array $defaults): array
    {
        $items = [
            'registration_document_title' => ['Registration page: browser title', 'Register: {event}'],
            'registration_ended_document_title' => ['Ended registration page: browser title', 'Registration ended: {event}'],
            'registration_skip_label' => ['Registration page: skip link', 'Skip to event registration'],
            'registration_event_not_found' => ['Registration: event-not-found message', 'This event could not be found.'],
            'registration_return_calendar' => ['Registration form: return-to-calendar link', 'Return to Calendar'],
            'registration_heading' => ['Registration form: heading', 'Register: {event}'],
            'registration_nudge_first_placeholder' => ['Registration sign-in nudge: first-name placeholder', 'First name'],
            'registration_nudge_last_placeholder' => ['Registration sign-in nudge: last-name placeholder', 'Last name'],
            'registration_nudge_email_placeholder' => ['Registration sign-in nudge: email placeholder', 'Your email'],
            'registration_nudge_send' => ['Registration sign-in nudge: send-link button', 'Email me a sign-in link'],
            'registration_contact_legend' => ['Registration form: contact-information heading', 'Your information'],
            'registration_first_name_label' => ['Registration form: first-name label', 'First name'],
            'registration_last_name_label' => ['Registration form: last-name label', 'Last name'],
            'registration_email_label' => ['Registration form: email label', 'Email'],
            'registration_phone_label' => ['Registration form: phone label', 'Phone'],
            'registration_street_label' => ['Registration form: street-address label', 'Street address'],
            'registration_city_label' => ['Registration form: city label', 'City'],
            'registration_state_label' => ['Registration form: state label', 'State'],
            'registration_zip_label' => ['Registration form: ZIP label', 'ZIP'],
            'registration_demographics_legend' => ['Registration form: demographic-information heading', 'Demographic information'],
            'registration_required_label' => ['Registration form: required marker', '(required)'],
            'registration_review_update' => ['Registration form: review-or-update button', 'Review or update'],
            'registration_please_specify' => ['Registration form: “Please specify” label', 'Please specify'],
            'registration_subscriptions_legend' => ['Registration form: newsletter-subscriptions heading', 'Newsletter subscriptions'],
            'registration_optional_label' => ['Registration form: optional marker', '(optional)'],
            'registration_subscriptions_verified_help' => ['Registration form: verified subscription instructions', 'These boxes show your current subscriptions. Check a newsletter to subscribe or clear it to unsubscribe.'],
            'registration_subscriptions_public_help' => ['Registration form: public subscription instructions', 'Check any newsletter you want to receive. We will email confirmation links for new choices. Existing choices stay private, and unchecked boxes on this form will not cancel an existing subscription.'],
            'registration_subscriptions_unavailable' => ['Registration form: newsletter choices unavailable', 'Newsletter choices are temporarily unavailable. You can still register for this event, and no existing subscription will be changed.'],
            'registration_survey_legend' => ['Registration form: additional-questions heading', 'A few more questions'],
            'registration_age_question' => ['Registration form: age-range question', 'What is your age range?'],
            'registration_heard_question' => ['Registration form: how-heard question', 'How did you hear about this event?'],
            'registration_select_all_label' => ['Registration form: select-all-that-apply marker', '(select all that apply)'],
            'registration_promote_question' => ['Registration form: event-promotion question', 'In the week before the event, would you post a notice on social media and/or email an invite to a couple of friends/acquaintances, to help spread the word?'],
            'registration_relationship_question' => ['Registration form: relationship-status question', 'What is your Relationship Status?'],
            'registration_choose_one' => ['Registration form: empty select choice', 'Choose one...'],
            'registration_type_legend' => ['Registration form: registration-type heading', 'Registration type'],
            'registration_monthly_donor_locked' => ['Registration form: locked monthly-donor explanation', 'Monthly Donors: verify your email above (Email me a sign-in link) to unlock this selection.'],
            'registration_offline_payment_note' => ['Registration form: online-payment unavailable explanation', 'Online payment is not set up yet; the organizer will follow up about payment.'],
            'registration_volunteer_interest' => ['Registration form: volunteer-interest choice', "I'm interested in volunteering at this event (we'll contact you about it)."],
            'registration_submit_button' => ['Registration form: submit button', 'Complete registration'],
            'registration_unavailable_document_title' => ['Unavailable registration page: browser title', 'Registration unavailable'],
            'registration_unavailable_status' => ['Unavailable registration page: status label', 'Not available'],
            'registration_unavailable_heading' => ['Unavailable registration page: heading', 'Registration is unavailable'],
            'registration_unavailable_body' => ['Unavailable registration page: explanation', 'This registration page could not be found or is no longer public.'],
            'registration_view_other_events' => ['Unavailable/ended registration page: return link', 'View other events'],
            'registration_ended_status' => ['Ended registration page: status label', 'Event ended'],
            'registration_ended_heading' => ['Ended registration page: heading', 'Registration has ended'],
            'registration_ended_body' => ['Ended registration page: explanation', 'Registration for {event} is closed because this event has ended.'],
            'registration_ended_message' => ['Registration: event-ended response', 'Registration is closed because this event has ended.'],
            'registration_payment_trust_intro' => ['Registration payment: Stripe-fields introduction', 'The card-entry fields below are secure Stripe fields for this event registration.'],

            'registration_bot_success' => ['Registration response: automated-submission neutral success', 'Thanks! Your registration is confirmed.'],
            'registration_too_fast' => ['Registration error: submission too fast', 'That was quick. Please review the form and try again.'],
            'registration_stale_form' => ['Registration error: stale form', 'This form has been open a while & must be refreshed. Refreshing & resubmitting now.'],
            'registration_rate_limit' => ['Registration error: too many attempts', 'Too many attempts. Please wait a few minutes and try again.'],
            'registration_choose_type_error' => ['Registration error: missing registration type', 'Please choose a registration type.'],
            'registration_contact_error' => ['Registration error: invalid contact information', 'Please fill in your first name, last name, and a valid email.'],
            'registration_donor_gate_error' => ['Registration error: monthly-donor option is locked', 'The free option is reserved for Monthly Donors. Confirm your email first (the sign-in link button above), or pick another option.'],
            'registration_demographic_error' => ['Registration error: missing demographic answer', 'Please answer the demographic question. “Prefer not to say” is available.'],
            'registration_age_error' => ['Registration error: missing age range', 'Please select your age range.'],
            'registration_heard_error' => ['Registration error: missing how-heard answer', 'Please tell us how you heard about this event.'],
            'registration_heard_other_error' => ['Registration error: missing how-heard detail', 'You chose Other for how you heard about this event; please specify.'],
            'registration_answer_store_unavailable' => ['Registration error: answer storage unavailable', 'We cannot safely save your answers right now. Please try again shortly or contact the organizer.'],
            'registration_match_unavailable' => ['Registration error: safe matching unavailable', 'We cannot safely match your registration right now. Please try again shortly.'],
            'registration_already_processing' => ['Registration error: request already processing', 'This registration is already being processed. Please wait a moment before trying again.'],
            'registration_contact_save_error' => ['Registration error: contact save failed', 'We could not safely save your information. Please try again shortly.'],
            'registration_answer_save_error' => ['Registration error: answer save failed', 'We could not safely save your answers. Nothing has been registered yet; please try again.'],
            'registration_already_registered' => ['Registration response: already registered', 'You are already registered for this event. No duplicate registration was created.'],
            'registration_existing_payment_ready' => ['Registration response: existing registration ready for payment', 'Your existing registration is ready for payment; no duplicate was created.'],
            'registration_existing_finalizing' => ['Registration error: existing registration finalizing', 'An existing registration is still being finalized. Please wait a moment and refresh before trying again.'],
            'registration_record_error' => ['Registration error: record could not be created', 'We could not record the registration. Please try again in a moment.'],
            'registration_payment_start_error' => ['Registration error: payment could not start', 'We could not start the payment. Please try again in a moment.'],
            'registration_payment_link_error' => ['Registration error: payment could not be linked', 'We could not safely link the payment to your registration. Please try again in a moment.'],
            'registration_payment_prompt' => ['Registration response: payment required', 'Almost there: enter payment below to confirm your spot.'],
            'registration_mail_accepted_note' => ['Registration response: confirmation email accepted', 'A confirmation email has been submitted for delivery.'],
            'registration_mail_failed_note' => ['Registration response: confirmation email failed', 'Your registration is saved, but the confirmation email could not be sent; please contact the organizer if you need a written copy.'],
            'registration_confirmed_message' => ['Registration response: confirmed', 'Thanks, {first}! Your registration is confirmed.'],
            'registration_offline_reserved_message' => ['Registration response: paid place reserved offline', 'Thanks, {first}! Your spot is reserved. This event has a fee and online payment is not set up yet, so the organizer will follow up about payment.'],
            'registration_volunteer_note' => ['Registration response: volunteer follow-up', 'The organizer has your volunteer interest and will follow up directly.'],
            'registration_newsletter_save_failed' => ['Registration response: newsletter update failed', 'Your event registration is saved, but newsletter choices could not be updated; please use My Profile later.'],
            'registration_newsletter_restricted' => ['Registration response: newsletter delivery restriction', 'Your event registration is saved. This email has a newsletter delivery restriction that cannot be changed here; contact {org} if you believe it is an error.'],
            'registration_newsletter_saved' => ['Registration response: newsletter choices saved', 'Your newsletter choices were saved.'],
            'registration_newsletter_confirmation' => ['Registration response: newsletter confirmation may be needed', 'If confirmation is needed for your newsletter choices, check your email for a separate confirmation link.'],

            'registration_js_invalid_email' => ['Registration sign-in nudge: invalid email', 'Please enter a valid email address.'],
            'registration_js_sending_link' => ['Registration sign-in nudge: sending status', 'Sending…'],
            'registration_js_check_inbox' => ['Registration sign-in nudge: inbox fallback', 'Check your inbox.'],
            'registration_js_general_error' => ['Registration form: browser request failure', 'Something went wrong. Please try again.'],
            'registration_js_payment_button' => ['Registration payment: confirm button', 'Pay securely with Stripe and confirm'],
            'registration_js_payment_processing' => ['Registration payment: processing status', 'Processing securely with Stripe...'],
            'registration_js_payment_failed' => ['Registration payment: failure fallback', 'Payment failed. Please try again.'],
            'registration_js_payment_finalizing' => ['Registration payment: finalizing status', 'Payment received. Finalizing your registration...'],
            'registration_js_payment_confirmed' => ['Registration payment: confirmed status', 'Payment received and your registration is confirmed. A confirmation email is on its way.'],
            'registration_js_payment_pending' => ['Registration payment: reconciliation status', 'Payment received. Your registration is being finalized; the confirmation should follow shortly.'],
            'registration_js_sending_registration' => ['Registration form: sending status', 'Sending...'],
            'registration_js_stale_reload' => ['Registration form: stale reload fallback', 'This form has been open a while. Please reload the page & submit again.'],

            'registration_email_subject' => ['Registration confirmation email: subject', 'You are registered: {event}'],
            'registration_email_paid_intro' => ['Registration confirmation email: paid introduction', "Your payment went through and you're registered for {event} ({tier})."],
            'registration_email_unpaid_intro' => ['Registration confirmation email: unpaid introduction', "You're registered for {event} ({tier})."],
            'registration_email_greeting' => ['Registration confirmation email: greeting', 'Hi {first},'],
            'registration_email_date_label' => ['Registration confirmation email: date label', 'Date'],
            'registration_email_time_label' => ['Registration confirmation email: time label', 'Time'],
            'registration_email_description_label' => ['Registration confirmation email: event-description label', 'Event description'],
            'registration_email_contact_intro' => ['Registration confirmation email: contact introduction', 'If you have any questions, please contact {contact}:'],
            'registration_email_contact_email_label' => ['Registration confirmation email: contact email label', 'Email'],
            'registration_email_contact_phone_label' => ['Registration confirmation email: contact phone label', 'Phone'],
            'registration_email_contact_name' => ['Registration confirmation email: contact name (blank uses the organization name)', ''],
            'registration_email_contact_email' => ['Registration confirmation email: contact email address (blank uses the organization contact email)', ''],
            'registration_email_contact_phone' => ['Registration confirmation email: contact telephone number (blank uses the organization phone, if any)', ''],
            'registration_email_closing' => ['Registration confirmation email: closing', 'We look forward to seeing you!'],
            'registration_email_event_fallback' => ['Registration confirmation email: missing event-title fallback', 'your event'],
            'registration_email_save_info_body' => ['Registration confirmation email: save-information invitation', "----\nTired of retyping your info? Tap once to save it on this device,\nand every future registration form fills itself in automatically:\n{link}"],
            'registration_payment_description' => ['Registration payment: Stripe description', 'Event registration: {event}'],
        ];

        foreach ($items as $key => [$label, $text]) {
            $defaults[$key] = [
                'label' => $label,
                'text' => $text,
                'group' => 'Registration form',
            ];
        }
        foreach (self::REQUIRED_TOKENS as $key => $tokens) {
            $defaults[$key]['required_tokens'] = $tokens;
        }

        return $defaults;
    }

    public static function text(string $key, string $fallback): string
    {
        return function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
    }

    /** @param array<string, scalar|null> $tokens @param string[] $required */
    public static function format(
        string $key,
        string $fallback,
        array $tokens = [],
        array $required = []
    ): string {
        $registered = self::REQUIRED_TOKENS[$key] ?? [];
        $required = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            array_merge($registered, $required)
        ))));
        if (function_exists('bg_str_format')) {
            return bg_str_format($key, $fallback, $tokens, $required);
        }
        $template = self::text($key, $fallback);
        foreach ($required as $token) {
            $marker = '{' . $token . '}';
            if (!str_contains($template, $marker)) $template = rtrim($template) . "\n\n" . $marker;
        }
        $replace = [];
        foreach ($tokens as $token => $value) $replace['{' . $token . '}'] = (string) $value;
        return strtr($template, $replace);
    }

    public static function suffix(string $key, string $fallback): string
    {
        $text = trim(self::text($key, $fallback));
        return $text === '' ? '' : ' ' . $text;
    }

    public static function demographicLabel(string $code): string
    {
        return self::displayLabel('demographic', $code, DemographicFields::options());
    }

    public static function ageLabel(string $code): string
    {
        return self::displayLabel('age', $code, SurveyFields::ageRanges());
    }

    public static function heardLabel(string $code): string
    {
        return self::displayLabel('heard', $code, SurveyFields::heardOptions());
    }

    public static function promoteLabel(string $code): string
    {
        return self::displayLabel('promote', $code, SurveyFields::promoteOptions());
    }

    public static function relationshipLabel(string $code): string
    {
        return self::displayLabel('relationship', $code, SurveyFields::relationshipOptions());
    }

    /**
     * Option text is edited directly under Settings, so the stored answer
     * is also its display label.
     *
     * @param string[] $codes
     */
    private static function displayLabel(string $family, string $code, array $codes): string
    {
        unset($family, $codes);
        return $code;
    }
}
