<?php
/**
 * Wording layer. bg_str() resolves a key to the admin override when one
 * is saved, the shipped default otherwise. {org} in any string becomes
 * the organization name from Settings. Other modules register strings through the
 * bg_wording_defaults filter so they appear in Wording & Styling
 * automatically. The default voice is modern and plain; formal packs
 * can be layered on through the same filter.
 */

defined('ABSPATH') || exit;

function bg_str(string $key, string $fallback = ''): string
{
    static $cachedOverrides = null;
    $cacheReady = did_action('init') > 0 && !doing_action('init');
    if (!$cacheReady || !is_array($cachedOverrides)) {
        $loaded = get_option('bg_wording', []);
        $cachedOverrides = is_array($loaded) ? $loaded : [];
    }
    $overrides = $cachedOverrides;
    $resolve = static function (string $text): string {
        return str_contains($text, '{org}')
            ? str_replace('{org}', \BosqueGrande\Core\Organization::name(), $text)
            : $text;
    };
    if (($overrides[$key] ?? '') !== '') {
        return $resolve((string) $overrides[$key]);
    }
    $defaults = bg_wording_defaults();
    if (isset($defaults[$key])) {
        return $resolve($defaults[$key]['text']);
    }
    return $resolve($fallback);
}

/**
 * Resolve named placeholders after wording lookup. Required placeholders are
 * appended when an older or malformed override omits them, so an editor can
 * never remove a functional link from an authentication message.
 *
 * @param array<string, scalar|null> $tokens
 * @param string[] $required
 */
function bg_str_format(
    string $key,
    string $fallback,
    array $tokens = [],
    array $required = []
): string {
    // The definition is the canonical token contract. Callers may add
    // stricter requirements, but an omitted caller argument must never let a
    // saved legacy override discard a confirmation link or another required
    // dynamic value.
    $definitions = bg_wording_defaults();
    $registered = isset($definitions[$key]) && is_array($definitions[$key])
        ? (array) ($definitions[$key]['required_tokens'] ?? [])
        : [];
    $required = array_values(array_unique(array_filter(array_map(
        'sanitize_key',
        array_merge($registered, $required)
    ))));
    $template = bg_str($key, $fallback);
    foreach ($required as $token) {
        $marker = '{' . $token . '}';
        if (!str_contains($template, $marker)) {
            $template = rtrim($template) . "\n\n" . $marker;
        }
    }
    $replace = [];
    foreach ($tokens as $token => $value) {
        $replace['{' . $token . '}'] = (string) $value;
    }
    return strtr($template, $replace);
}

/** @return array<string, array{label: string, text: string, group?: string, required_tokens?: string[]}> */
function bg_wording_defaults(): array
{
    static $cache = null;
    $cacheReady = did_action('init') > 0 && !doing_action('init');
    if ($cacheReady && is_array($cache)) return $cache;

    $defaults = [

        /* ── Public site shell & navigation ── */
        'nav_accessible_label' => [
            'label' => 'Site navigation: accessible label',
            'text'  => 'Primary site navigation',
        ],
        'nav_menu_button' => [
            'label' => 'Site navigation: mobile menu button',
            'text'  => 'Menu',
        ],
        'nav_submenu_open' => [
            'label' => 'Site navigation: submenu button instruction',
            'text'  => 'Open {item} menu',
            'required_tokens' => ['item'],
        ],
        'nav_logo_home' => [
            'label' => 'Site navigation: logo link description',
            'text'  => '{org} home',
        ],
        'nav_home' => [
            'label' => 'Site navigation: Home link (used when no header menu is assigned)',
            'text'  => 'Home',
        ],
        'nav_events' => [
            'label' => 'Site navigation: Events link (used when no header menu is assigned)',
            'text'  => 'Events',
        ],
        'nav_profile_cta' => [
            'label' => 'Site navigation: My Profile button',
            'text'  => 'My Profile',
        ],
        'nav_donate_cta' => [
            'label' => 'Site navigation: giving button',
            'text'  => 'Donate',
        ],
        'shell_skip_content' => [
            'label' => 'Public site: skip-link wording',
            'text'  => 'Skip to content',
        ],
        'shell_calendar_title' => [
            'label' => 'Calendar frame: heading',
            'text'  => 'Events',
        ],
        'shell_calendar_eyebrow' => [
            'label' => 'Calendar frame: small heading',
            'text'  => '{org}',
        ],
        'shell_calendar_intro' => [
            'label' => 'Calendar frame: introduction',
            'text'  => 'Search by place or interest, switch between Pinboard, Calendar, and List views, register, or suggest an event.',
        ],
        'shell_profile_title' => [
            'label' => 'My Profile frame: heading',
            'text'  => 'My Profile',
        ],
        'shell_profile_eyebrow' => [
            'label' => 'My Profile frame: small heading',
            'text'  => 'Your account',
        ],
        'shell_profile_intro' => [
            'label' => 'My Profile frame: introduction',
            'text'  => 'Your contact details, newsletter subscriptions, privacy choices, and monthly giving, in one place.',
        ],
        'shell_site_title_prefix' => [
            'label' => 'Ordinary page frame: small heading above page titles (blank hides it)',
            'text'  => '',
        ],
        'footer_platform_label' => [
            'label' => 'Public footer: platform credit (hide it in Settings)',
            'text'  => 'Powered by BosqueGrande',
        ],
        'verification_eyebrow' => [
            'label' => 'Event suggestion verification: small heading',
            'text'  => 'Event suggestion',
        ],
        'verification_confirm_title' => [
            'label' => 'Event suggestion verification: confirmation heading',
            'text'  => 'Confirm Event Suggestion',
        ],
        'verification_success_title' => [
            'label' => 'Event suggestion verification: success heading',
            'text'  => 'Submission Verified',
        ],
        'verification_failure_title' => [
            'label' => 'Event suggestion verification: failure heading',
            'text'  => 'Verification Failed',
        ],
        'verification_confirm_button' => [
            'label' => 'Event suggestion verification: confirmation button',
            'text'  => 'Confirm suggestion',
        ],
        'verification_return_home' => [
            'label' => 'Event suggestion verification: return link',
            'text'  => 'Return home',
        ],
        'verification_skip' => [
            'label' => 'Event suggestion verification: skip link',
            'text'  => 'Skip to verification result',
        ],

        /* ── Registration & fees ── */
        'fees_heading' => [
            'label' => 'Registration options heading',
            'text'  => 'Registration options',
        ],
        'fees_deductibility_note' => [
            'label' => 'Deductibility note (shown beneath the fee selections & on receipts)',
            'text'  => 'Donations made with your registration may be tax-deductible; check with {org} for details.',
        ],
        'become_donor_button' => [
            'label' => 'Become a Monthly Donor button',
            'text'  => 'Become a Monthly Donor',
        ],
        'additional_donation_button' => [
            'label' => 'Additional donation button',
            'text'  => 'Donate An Amount You Choose',
        ],
        'additional_donation_note' => [
            'label' => 'Additional donation explanation',
            'text'  => 'An additional gift helps {org} keep events affordable for everyone.',
        ],

        /* ── Demographic section ── */
        'demographic_purpose_statement' => [
            'label' => 'Demographic section: purpose statement',
            'text'  => 'Why we ask: {org} uses these answers only in aggregate, to understand who we serve and to report to funders. Your identifying details are never shared. “Prefer not to say” is always an option.',
        ],
        'demographic_identify_prompt' => [
            'label' => 'Demographic section: selection instruction',
            'text'  => 'Do you identify as any of the following? Select all that apply.',
        ],
        'demographic_on_file_notice' => [
            'label' => 'Demographic section: answers-on-file notice',
            'text'  => 'Answered these before? You\'re all set: your previous answers carry over automatically. Fill this in only if it\'s your first time or you want to update something.',
        ],
        'survey_purpose_note' => [
            'label' => 'Registration questions: explanation',
            'text'  => 'These help {org} understand who its events reach.',
        ],

        /* ── Address confirmation (magic link) ── */
        'confirm_email_subject' => [
            'label' => 'Email confirmation message: subject line',
            'text'  => 'Confirm your email',
        ],
        'confirm_email_body' => [
            'label' => 'Address confirmation message: body',
            'text'  => "Tap the link below to confirm your email. It works once and expires in {minutes} minutes.\n\n{link}\n\nDidn't request this? You can safely ignore this message; nothing has been shared.",
            'required_tokens' => ['link', 'minutes'],
        ],
        'confirm_screen_notice' => [
            'label' => 'On-screen notice after a confirmation request',
            'text'  => 'If we have this address on file, a confirmation email is on its way. Check your inbox (and spam folder) and tap the link to continue.',
        ],
        'magic_confirm_title' => [
            'label' => 'Successful sign-in screen: heading',
            'text'  => 'Congratulations, you’re signed in',
        ],
        'magic_confirm_body' => [
            'label' => 'Successful sign-in screen: explanation',
            'text'  => 'Your email is confirmed on this device. Your saved contact and demographic information will prefill where it is requested, and you can review it before submitting.',
        ],
        'magic_confirm_registration_button' => [
            'label' => 'Successful sign-in screen: registration button',
            'text'  => 'Continue to registration',
        ],
        'magic_confirm_continue_button' => [
            'label' => 'Successful sign-in screen: general button',
            'text'  => 'Continue',
        ],
        'magic_expired_title' => [
            'label' => 'Expired sign-in screen: heading',
            'text'  => 'This sign-in link has expired',
        ],
        'magic_expired_body' => [
            'label' => 'Expired sign-in screen: explanation',
            'text'  => 'For your protection, each sign-in link works once and expires after {minutes} minutes. Return to the form and request a new link.',
            'required_tokens' => ['minutes'],
        ],
        'magic_expired_button' => [
            'label' => 'Expired sign-in screen: button',
            'text'  => 'Return to the site',
        ],
        /* ── Calendar surfaces ── */
        'scope_upcoming' => [
            'label' => 'Calendar: Upcoming selection',
            'text'  => 'Upcoming',
        ],
        'scope_past' => [
            'label' => 'Calendar: Past selection',
            'text'  => 'Past',
        ],
        'view_pinboard' => [
            'label' => 'Calendar: pinboard view selection',
            'text'  => 'Pinboard',
        ],
        'view_calendar' => [
            'label' => 'Calendar: monthly view selection',
            'text'  => 'Calendar',
        ],
        'view_list' => [
            'label' => 'Calendar: list view selection',
            'text'  => 'List',
        ],
        'suggest_verify_notice' => [
            'label' => 'Suggestion form: notice after submission',
            'text'  => 'We\'ve emailed you a confirmation link. Tap it within {hours} hours to confirm your suggestion.',
            'required_tokens' => ['hours'],
        ],
        'suggest_thanks_notice' => [
            'label' => 'Suggestion form: notice after confirmation',
            'text'  => 'Thanks! Your suggestion is confirmed and headed to our team for review.',
        ],
        'register_button' => [
            'label' => 'Register button',
            'text'  => 'REGISTER',
        ],
        'none_upcoming' => [
            'label' => 'Calendar: no upcoming events',
            'text'  => 'No upcoming events found.',
        ],
        'profile_gate_notice' => [
            'label' => 'My Profile: notice shown before address confirmation',
            'text'  => 'To protect your privacy, your details appear only after you confirm your email. Tap the button below and we\'ll send you a link; it works for {minutes} minutes.',
            'required_tokens' => ['minutes'],
        ],
        'identity_nudge_lead' => [
            'label' => 'Registration form: lead-in beside the confirmation button',
            'text'  => 'Registered with us before?',
        ],
        'identity_nudge_button' => [
            'label' => 'Registration form: the confirmation nudge button',
            'text'  => 'Confirm your email and we\'ll fill everything in for you',
        ],
        'search_label' => [
            'label' => 'Search: field label',
            'text'  => 'Search:',
        ],
        'search_placeholder' => [
            'label' => 'Search: input placeholder',
            'text'  => 'Search events ("game night" -online)',
        ],
        'search_button' => [
            'label' => 'Search: button',
            'text'  => 'Search',
        ],
        'search_clear' => [
            'label' => 'Search: clear link',
            'text'  => 'Clear',
        ],
        'search_no_strict_note' => [
            'label' => 'Search: notice when no entry matches every term',
            'text'  => 'Nothing matches every word, so here are the closest matches.',
        ],
        'none_past' => [
            'label' => 'Calendar: no past events',
            'text'  => 'No past events found.',
        ],
    ];

    $groups = [
        'Site shell and navigation' => [
            'nav_accessible_label', 'nav_menu_button',
            'nav_submenu_open', 'nav_logo_home', 'nav_home', 'nav_events', 'nav_profile_cta',
            'nav_donate_cta', 'shell_skip_content', 'shell_calendar_title',
            'shell_calendar_eyebrow', 'shell_calendar_intro',
            'shell_profile_title', 'shell_profile_eyebrow',
            'shell_profile_intro', 'shell_site_title_prefix',
            'footer_platform_label',
        ],
        'Registration form' => [
            'fees_heading', 'fees_deductibility_note', 'become_donor_button',
            'additional_donation_button', 'additional_donation_note',
            'demographic_purpose_statement', 'demographic_identify_prompt',
            'demographic_on_file_notice', 'survey_purpose_note',
            'identity_nudge_lead', 'identity_nudge_button',
        ],
        'Identity and sign-in' => [
            'confirm_email_subject', 'confirm_email_body',
            'confirm_screen_notice', 'magic_confirm_title',
            'magic_confirm_body', 'magic_confirm_registration_button',
            'magic_confirm_continue_button', 'magic_expired_title',
            'magic_expired_body', 'magic_expired_button',
        ],
        'Events calendar' => [
            'scope_upcoming', 'scope_past', 'view_pinboard', 'view_calendar',
            'view_list', 'register_button', 'none_upcoming', 'search_label',
            'search_placeholder', 'search_button', 'search_clear',
            'search_no_strict_note', 'none_past',
        ],
        'Event suggestions: verification and responses' => [
            'verification_eyebrow', 'verification_confirm_title',
            'verification_success_title', 'verification_failure_title',
            'verification_confirm_button', 'verification_return_home',
            'verification_skip', 'suggest_verify_notice',
            'suggest_thanks_notice',
        ],
        'My Profile' => ['profile_gate_notice'],
    ];
    foreach ($groups as $group => $keys) {
        foreach ($keys as $key) {
            if (isset($defaults[$key])) $defaults[$key]['group'] = $group;
        }
    }

    $resolved = apply_filters('bg_wording_defaults', $defaults);
    if ($cacheReady) $cache = $resolved;
    return $resolved;
}
