<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Profile;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Identity\Session;
use BosqueGrande\Modules\Payments\MonthlyDonationEnrollment;
use BosqueGrande\Modules\Registration\AnswerRetry;
use BosqueGrande\Modules\Registration\DemographicFields;
use BosqueGrande\Modules\Registration\PrefillResolver;
use BosqueGrande\Modules\Registration\RegistrationConfig;
use BosqueGrande\Modules\Registration\SurveyFields;

if (!defined('ABSPATH')) exit;

/**
 * Profile form ([bosquegrande_profile] shortcode, architecture 5.3).
 * Requires a Tier 2 verified session: a Tier 1 newsletter token is NOT
 * enough here, because the profile shows the full record including
 * demographic values (ADR-3 masking would be pointless otherwise).
 * The verified session carries its source, but ordinary profile saves
 * land only in the local encrypted constituent record. The explicit
 * monthly-donation cancellation action is the narrow exception: after
 * Stripe confirms future charges are stopped, it mirrors inactive
 * status to LGL and the local constituent record.
 */
final class ProfileModule implements ModuleInterface
{
    /** A rendered profile must not remain writable after the verified person changes. */
    private const PROFILE_BINDING_LIFETIME = 7200;
    private const PROFILE_BINDING_FUTURE_SKEW = 300;

    public function name(): string
    {
        return 'profile';
    }

    public function label(): string
    {
        return 'Profile';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_filter('bg_wording_defaults', [MonthlyDonationCancellation::class, 'wordingDefaults']);

        add_shortcode('bosquegrande_profile', function () use ($container): string {
            // A page should have one public application landmark. If an
            // accidental mixed page contains the complete [bosquegrande]
            // apparatus as well, Calendar owns the document and this second
            // full application is omitted rather than nested.
            if (function_exists('bgev_page_has_complete_app') && bgev_page_has_complete_app()) return '';
            return $this->render($container);
        });

        add_action('rest_api_init', function () use ($container) {
            register_rest_route('bosquegrande/v1', '/profile', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Session-gated inside.
                'callback' => fn(\WP_REST_Request $req) => $this->save($container, $req),
            ]);
            // The form fetches its nonce from here at submit
            // time, so creation and verification happen in the SAME
            // auth context (REST cookie requests without X-WP-Nonce run
            // as user 0; a nonce printed at page render while an admin
            // is logged in belongs to a different user and can never
            // verify). Same pattern, and same fix, as /reg-nonce.
            register_rest_route('bosquegrande/v1', '/profile-nonce', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => static fn() => new \WP_REST_Response(
                    ['nonce' => wp_create_nonce('bg_profile')],
                    200,
                    ['Cache-Control' => 'no-store']
                ),
            ]);
            register_rest_route('bosquegrande/v1', '/profile/cancel-monthly-donation', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Verified session and nonce checked inside.
                'callback' => fn(\WP_REST_Request $req) => $this->cancelMonthlyDonation($container, $req),
            ]);
            register_rest_route('bosquegrande/v1', '/profile/manage-monthly-donation', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Verified session and nonce checked inside.
                'callback' => fn(\WP_REST_Request $req) => $this->manageMonthlyDonation($container, $req),
            ]);
        });

        $container->get('jobs')->register(
            MonthlyDonationCancellation::RETRY_JOB,
            fn(array $args) => (new MonthlyDonationCancellation($container))->retryLgl($args)
        );
    }

    public function deactivate(Container $container): void
    {
    }

    /**
     * Visitor-facing profile copy. Submitted option values, money bounds,
     * identity bindings, nonces, and API routes remain fixed protocol data;
     * the editor controls only their human-readable labels and explanations.
     *
     * @param array<string, array{label:string,text:string}> $defaults
     * @return array<string, array{label:string,text:string}>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'profile_gate_title' => ['My Profile: signed-out heading', 'Manage your info'],
            'profile_gate_intro' => ['My Profile: signed-out introduction', 'Update your name, contact details, and demographic information on file.'],
            'profile_gate_first_placeholder' => ['My Profile: first-name placeholder', 'First name'],
            'profile_gate_last_placeholder' => ['My Profile: last-name placeholder', 'Last name'],
            'profile_gate_email_placeholder' => ['My Profile: email placeholder', 'Your email'],
            'profile_gate_button' => ['My Profile: sign-in-link button', 'Email me a sign-in link'],
            'profile_gate_check_inbox' => ['My Profile: sign-in-link fallback result', 'Check your inbox.'],
            'profile_shell_skip_label' => ['My Profile frame: skip-link label', 'Skip to My Profile'],
            'profile_load_failed' => ['My Profile: profile-load failure', 'We could not load your info right now. Please request a new sign-in link.'],
            'profile_prepare_failed' => ['My Profile: secure-form preparation failure', 'We could not securely prepare your info right now. Please reload the page.'],
            'profile_form_heading' => ['My Profile: signed-in heading', 'Your info on file'],
            'profile_contact_legend' => ['My Profile: contact section heading', 'Contact'],
            'profile_first_name_label' => ['My Profile: first-name label', 'First name'],
            'profile_last_name_label' => ['My Profile: last-name label', 'Last name'],
            'profile_email_label' => ['My Profile: email label', 'Email'],
            'profile_phone_label' => ['My Profile: phone label', 'Phone'],
            'profile_street_label' => ['My Profile: street-address label', 'Street address'],
            'profile_city_label' => ['My Profile: city label', 'City'],
            'profile_state_label' => ['My Profile: state label', 'State'],
            'profile_zip_label' => ['My Profile: ZIP label', 'ZIP'],
            'profile_email_change_note' => ['My Profile: primary-email privacy note', 'For privacy, changing the primary email requires staff verification. Other contact details can be updated here.'],
            'profile_contact_sharing_opt_out' => ['My Profile: contact-sharing opt-out', 'Do not share any of my personal information with event organizers or other members.'],
            'profile_contact_sharing_note' => ['My Profile: contact-sharing explanation', 'If selected, event rosters and exports show an anonymous registration only. Authorized administrators can still maintain your private BosqueGrande profile.'],
            'profile_about_legend' => ['My Profile: optional personal-information heading', 'About you'],
            'profile_optional_marker' => ['My Profile: optional marker', '(optional)'],
            'profile_age_question' => ['My Profile: age-range question', 'What is your age range?'],
            'profile_relationship_question' => ['My Profile: relationship-status question', 'What is your Relationship Status?'],
            'profile_other_specify_label' => ['My Profile: other-demographic detail label', 'Please specify'],
            'profile_demographic_legend' => ['My Profile: demographic section heading', 'Demographic information'],
            'profile_required_marker' => ['My Profile: required marker', '(required)'],
            'profile_demographic_instruction' => ['My Profile: demographic selection instruction', 'Select at least one response. “Prefer not to say” is a valid response.'],
            'profile_contact_stored_note' => ['My Profile: contact-storage note', 'Your contact details are stored encrypted on this site.'],
            'profile_subscriptions_legend' => ['My Profile: subscriptions heading', 'Subscriptions'],
            'profile_subscriptions_instruction' => ['My Profile: subscriptions instruction', 'Choose the newsletters you want to receive. Unchecking a newsletter records an unsubscribe; checking it records a new subscription.'],
            'profile_subscriptions_unavailable' => ['My Profile: no-subscriptions message', 'No newsletter subscriptions are currently available.'],
            'profile_monthly_legend' => ['My Profile: monthly-donation heading', 'Monthly donation'],
            'profile_monthly_enroll_button' => ['My Profile: become-monthly-donor button', 'Become a Monthly Donor'],
            'profile_monthly_enroll_note' => ['My Profile: monthly-enrollment explanation', 'Choose a monthly amount here, then complete the enrollment in secure Stripe checkout.'],
            'profile_monthly_manage_button' => ['My Profile: donation-management button', 'Change Donation Status'],
            'profile_monthly_manage_note' => ['My Profile: donation-management explanation', 'Increase, decrease, or cancel a monthly donation when the payment can be safely matched.'],
            'profile_monthly_management_heading' => ['My Profile: donation-management panel heading', 'Donation Management'],
            'profile_monthly_current_label' => ['My Profile: current-monthly-amount label', 'Current monthly amount:'],
            'profile_monthly_next_charge_label' => ['My Profile: next-charge label', 'Next scheduled charge:'],
            'profile_monthly_new_amount_label' => ['My Profile: new-monthly-amount label', 'New monthly amount'],
            'profile_monthly_save_amount_button' => ['My Profile: save-new-amount button', 'Save New Monthly Amount'],
            'profile_monthly_amount_timing_note' => ['My Profile: new-amount timing note', 'The new amount begins on the next scheduled charge. No mid-cycle charge or credit is created.'],
            'profile_monthly_external_amount_note' => ['My Profile: externally managed amount note', 'This subscription can be cancelled here, but its amount must be changed by office staff in LGL Forms.'],
            'profile_monthly_cancel_button' => ['My Profile: cancel-monthly-donation button', 'Cancel Monthly Donation'],
            'profile_monthly_cancel_status_button' => ['My Profile: cancel-monthly-status button', 'Cancel Monthly-Donor Status'],
            'profile_monthly_manual_cancel_note' => ['My Profile: cash/check cancellation note', 'This removes the cash/check status here and updates the linked LGL constituent. There is no Stripe charge to stop.'],
            'profile_monthly_external_cancel_lead' => ['My Profile: external cancellation warning heading', 'No automatic cancellation is offered.'],
            'profile_monthly_external_cancel_detail' => ['My Profile: external cancellation warning detail', 'A status-only change would not reliably stop an LGL Forms charge. Please contact us.'],
            'profile_save_button' => ['My Profile: save button', 'Save changes'],
            'profile_js_demographic_required' => ['My Profile: browser demographic error', 'Please answer the demographic question. “Prefer not to say” is available.'],
            'profile_js_saving' => ['My Profile: saving status', 'Saving...'],
            'profile_js_generic_error' => ['My Profile: browser save failure', 'Something went wrong. Please try again.'],
            'profile_js_cancelling' => ['My Profile: cancellation progress', 'Cancelling and verifying...'],
            'profile_js_changing_amount' => ['My Profile: amount-change progress', 'Saving and verifying the new amount...'],
            'profile_js_donation_error' => ['My Profile: browser donation-management failure', 'Something went wrong. Nothing is being reported as changed. Please try again.'],
            'profile_js_cancel_confirm' => ['My Profile: cancellation confirmation', 'Cancel this monthly donation? This stops future charges but does not refund completed donations.'],
            'profile_session_expired' => ['My Profile: expired-session error', 'Your session expired. Please request a new sign-in link.'],
            'profile_form_expired' => ['My Profile: expired-form error', 'This form expired. Please reload the page.'],
            'profile_action_expired' => ['My Profile: expired-action error', 'This action expired. Please reload the page.'],
            'profile_invalid_contact' => ['My Profile: invalid-contact error', 'Please fill in your name and a valid email.'],
            'profile_email_change_blocked' => ['My Profile: primary-email change error', 'For privacy, the primary email cannot be changed in My Profile. Please ask authorized staff to verify and update it.'],
            'profile_saved_restricted' => ['My Profile: saved-with-delivery-restriction result', 'Saved. This email has a newsletter delivery restriction that cannot be removed from My Profile; contact {org} if you believe it is an error.'],
            'profile_saved_success' => ['My Profile: save success', 'Saved! Your info is up to date.'],
            'profile_saved_partial' => ['My Profile: partial-save result', 'Some changes were saved, but we could not complete every section. Reload this page to review the current information, then retry the remaining choices.'],
            'profile_save_failed' => ['My Profile: save failure', 'We could not save right now. Please try again shortly.'],
            'profile_invalid_subscription' => ['My Profile: invalid-Stripe-subscription error', 'The selected Stripe subscription is invalid. Nothing was changed.'],
            'profile_monthly_amount_range' => ['My Profile: monthly-amount range error (keep {min_amount} and {max_amount})', 'Enter a monthly amount from {min_amount} through {max_amount}.'],
            'profile_monthly_action_required' => ['My Profile: missing donation action error', 'Choose whether to change the amount or cancel the donation.'],
            'profile_stale_page' => ['My Profile: stale verified-profile error', 'This profile page no longer matches your current verified sign-in. Please reload the page before trying again.'],
        ];
        foreach (SurveyFields::ageRanges() as $option) {
            $items[self::optionWordingKey('age', $option)] = ['My Profile: age choice “' . $option . '”', $option];
        }
        foreach (SurveyFields::relationshipOptions() as $option) {
            $items[self::optionWordingKey('relationship', $option)] = ['My Profile: relationship choice “' . $option . '”', $option];
        }
        foreach (DemographicFields::options() as $option) {
            $items[self::optionWordingKey('demographic', $option)] = ['My Profile: demographic choice “' . $option . '”', $option];
        }
        foreach ($items as $key => [$label, $text]) {
            if (isset($defaults[$key])) continue;
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'My Profile'];
            if ($key === 'profile_monthly_amount_range') {
                $defaults[$key]['required_tokens'] = ['min_amount', 'max_amount'];
            }
        }
        return $defaults;
    }

    private static function wording(string $key, string $fallback): string
    {
        $value = function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
        $value = trim(wp_strip_all_tags((string) $value));
        return $value !== '' ? $value : $fallback;
    }

    private static function optionWordingKey(string $group, string $option): string
    {
        return 'profile_' . sanitize_key($group) . '_option_' . substr(hash('sha256', $option), 0, 12);
    }

    private static function optionWording(string $group, string $option): string
    {
        return self::wording(self::optionWordingKey($group, $option), $option);
    }

    /**
     * Dynamic facts in visitor messages stay server-controlled. An editor may
     * rewrite the surrounding sentence only while retaining every named token.
     *
     * @param array<string, string> $tokens
     */
    private static function templatedWording(
        string $key,
        string $fallback,
        array $tokens
    ): string {
        $template = self::wording($key, $fallback);
        foreach (array_keys($tokens) as $token) {
            if (!str_contains($template, $token)) {
                $template = $fallback;
                break;
            }
        }
        return strtr($template, $tokens);
    }

    private function render(Container $container): string
    {
        /** @var Session $session */
        $session = $container->get('identity.session');
        $identity = $session->identity();
        $cid = $identity ? (int) $identity['constituent_id'] : 0;
        $source = $identity ? (string) $identity['source'] : '';

        if (!$cid) {
            $nonceless = str_replace(
                [
                    '__BG_PROFILE_GATE_TITLE__',
                    '__BG_PROFILE_GATE_INTRO__',
                    '__BG_PROFILE_GATE_NOTICE__',
                    '__BG_PROFILE_GATE_FIRST__',
                    '__BG_PROFILE_GATE_LAST__',
                    '__BG_PROFILE_GATE_EMAIL__',
                    '__BG_PROFILE_GATE_BUTTON__',
                    '__BG_PROFILE_GATE_CHECK_INBOX__',
                    '__BG_IDENTITY_ENDPOINT__',
                ],
                [
                    esc_html(self::wording('profile_gate_title', 'Manage your info')),
                    esc_html(self::wording('profile_gate_intro', 'Update your name, contact details, and demographic information on file.')),
                    esc_html(bg_str_format(
                        'profile_gate_notice',
                        'To protect your privacy, your details appear only after you confirm your email. Tap the button below and we\'ll send you a link; it works for {minutes} minutes.',
                        ['minutes' => \BosqueGrande\Modules\Identity\MagicLink::lifetimeMinutes()],
                        ['minutes']
                    )),
                    esc_attr(self::wording('profile_gate_first_placeholder', 'First name')),
                    esc_attr(self::wording('profile_gate_last_placeholder', 'Last name')),
                    esc_attr(self::wording('profile_gate_email_placeholder', 'Your email')),
                    esc_html(self::wording('profile_gate_button', 'Email me a sign-in link')),
                    wp_json_encode(self::wording('profile_gate_check_inbox', 'Check your inbox.')),
                    wp_json_encode(esc_url_raw(rest_url('bosquegrande/v1/identity/request-link'))),
                ],
                <<<'HTML'
<div class="bg-reg" id="bg-profile-gate">
  <h2>__BG_PROFILE_GATE_TITLE__</h2>
  <p>__BG_PROFILE_GATE_INTRO__
  __BG_PROFILE_GATE_NOTICE__</p>
  <input type="text" id="bg-prof-first" placeholder="__BG_PROFILE_GATE_FIRST__" autocomplete="given-name">
  <input type="text" id="bg-prof-last" placeholder="__BG_PROFILE_GATE_LAST__" autocomplete="family-name">
  <input type="email" id="bg-prof-email" placeholder="__BG_PROFILE_GATE_EMAIL__" autocomplete="email">
  <button type="button" class="bg-btn" id="bg-prof-send">__BG_PROFILE_GATE_BUTTON__</button>
  <p id="bg-prof-msg" role="status"></p>
</div>
<script>
(function(){
  var b=document.getElementById('bg-prof-send');if(!b)return;
  b.addEventListener('click',function(){
    var body=new FormData();
    body.append('email',document.getElementById('bg-prof-email').value);
    body.append('first_name',document.getElementById('bg-prof-first').value);
    body.append('last_name',document.getElementById('bg-prof-last').value);
    body.append('redirect',location.href);
    fetch(__BG_IDENTITY_ENDPOINT__,{method:'POST',body:body,cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){document.getElementById('bg-prof-msg').textContent=d.message||__BG_PROFILE_GATE_CHECK_INBOX__;});
  });
})();
</script>
HTML
            );
            return $this->withCss($nonceless);
        }

        // The same local-only resolver serves registration and profile,
        // so a verified stable id unlocks the encrypted envelope without
        // any remote dependency.
        /** @var PrefillResolver $resolver */
        $resolver = $container->get('registration.prefill');
        $resolved = $resolver->resolve([
            'source' => $source,
            'constituent_id' => $cid,
            'via_session' => true,
        ]);
        if (!$resolved['has_profile']) {
            return $this->withCss('<p>' . esc_html(self::wording(
                'profile_load_failed',
                'We could not load your info right now. Please request a new sign-in link.'
            )) . '</p>');
        }
        $p = $resolved['prefill'];
        $contactSharingAllowed = (bool) ($p['contact_sharing_allowed'] ?? true);
        $linkedLglId = (int) $resolved['lgl_id'];
        $askAge = RegistrationConfig::enabled('age') && SurveyFields::ageRanges();
        $askRelationship = RegistrationConfig::enabled('relationship') && SurveyFields::relationshipOptions();
        $askDemographics = RegistrationConfig::enabled('demographics') && DemographicFields::options();
        $demographicsRequired = $askDemographics && RegistrationConfig::required('demographics');
        $supportsDemographics = $askAge || $askRelationship || $askDemographics;
        $nonce = wp_create_nonce('bg_profile');
        $profileNonceEndpoint = esc_url_raw(rest_url('bosquegrande/v1/profile-nonce'));
        $profileEndpoint = esc_url_raw(rest_url('bosquegrande/v1/profile'));
        $donationEndpoint = esc_url_raw(rest_url('bosquegrande/v1/profile/manage-monthly-donation'));
        $donationManager = new MonthlyDonationCancellation($container);
        $monthlyStatus = $donationManager->status(
            (string) ($p['email'] ?? ''),
            $linkedLglId
        );
        $isMonthlyDonor = (bool) $monthlyStatus['yes'];
        $localProfileId = (int) ($resolved['local_id'] ?? 0);
        $profileBinding = $this->issueProfileBinding($localProfileId, $source, $cid);
        if ($profileBinding === '') {
            return $this->withCss('<p>' . esc_html(self::wording(
                'profile_prepare_failed',
                'We could not securely prepare your info right now. Please reload the page.'
            )) . '</p>');
        }
        $donationState = $donationManager->management(
            $localProfileId,
            $linkedLglId,
            (string) ($p['email'] ?? '')
        );
        $donationMode = (string) ($donationState['mode'] ?? 'blocked');
        $canEnrollMonthly = !$isMonthlyDonor || $donationMode === 'other_person';
        $subscription = (array) ($donationState['subscription'] ?? []);
        $subscriptionAmount = (int) ($subscription['amount_cents'] ?? 0);
        $subscriptionAmountDisplay = number_format($subscriptionAmount / 100, 2, '.', '');
        $enrollmentUrl = MonthlyDonationEnrollment::url();

        // Active newsletter groups are the live subscription catalog.
        // A newly created group appears here immediately; archiving it removes
        // it without erasing the person's consent history. The legacy
        // MailChimp Status YES import is represented by the permanent
        // `general` group, displayed as Monthly Newsletter.
        $subscriptionGroups = [];
        $activeSubscriptionIds = [];
        if ($localProfileId > 0 && $container->has('correspondence.groups')) {
            $groupService = $container->get('correspondence.groups');
            $subscriptionGroups = $groupService->all();
            $activeSubscriptionIds = array_fill_keys(
                $groupService->activeIdsForConstituent($localProfileId),
                true
            );
        }

        ob_start();
        ?>
        <div class="bg-reg" id="bg-profile">
          <h2><?php echo esc_html(self::wording('profile_form_heading', 'Your info on file')); ?></h2>
          <form id="bg-prof-form" novalidate data-demographics-required="<?php echo $demographicsRequired ? '1' : '0'; ?>">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="profile_binding" value="<?php echo esc_attr($profileBinding); ?>">
            <input type="hidden" name="contact_sharing_preference_present" value="1">
            <fieldset class="bg-fieldset"><legend><?php echo esc_html(self::wording('profile_contact_legend', 'Contact')); ?></legend>
              <div class="bg-grid">
                <label><?php echo esc_html(self::wording('profile_first_name_label', 'First name')); ?><input type="text" name="first_name" required value="<?php echo esc_attr($p['first_name']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_last_name_label', 'Last name')); ?><input type="text" name="last_name" required value="<?php echo esc_attr($p['last_name']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_email_label', 'Email')); ?><input type="email" name="email" required readonly aria-describedby="bg-profile-email-note" value="<?php echo esc_attr($p['email']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_phone_label', 'Phone')); ?><input type="tel" name="phone" value="<?php echo esc_attr($p['phone']); ?>"></label>
                <label class="bg-wide"><?php echo esc_html(self::wording('profile_street_label', 'Street address')); ?><input type="text" name="street" value="<?php echo esc_attr($p['street']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_city_label', 'City')); ?><input type="text" name="city" value="<?php echo esc_attr($p['city']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_state_label', 'State')); ?><input type="text" name="state" value="<?php echo esc_attr($p['state']); ?>"></label>
                <label><?php echo esc_html(self::wording('profile_zip_label', 'ZIP')); ?><input type="text" name="zip" value="<?php echo esc_attr($p['zip']); ?>"></label>
              </div>
              <p class="bg-muted" id="bg-profile-email-note"><?php echo esc_html(self::wording('profile_email_change_note', 'For privacy, changing the primary email requires staff verification. Other contact details can be updated here.')); ?></p>
              <div class="bg-contact-sharing">
                <label class="bg-check">
                  <input type="checkbox" name="do_not_share_contact" value="1" <?php checked(!$contactSharingAllowed); ?>>
                  <strong><?php echo esc_html(self::wording('profile_contact_sharing_opt_out', 'Do not share any of my personal information with event organizers or other members.')); ?></strong>
                </label>
                <p class="bg-muted"><?php echo esc_html(self::wording('profile_contact_sharing_note', 'If selected, event rosters and exports show an anonymous registration only. Authorized administrators can still maintain your private BosqueGrande profile.')); ?></p>
              </div>
            </fieldset>
            <?php if ($supportsDemographics) : ?>
              <?php if ($askAge || $askRelationship) : ?>
              <fieldset class="bg-fieldset"><legend><?php echo esc_html(self::wording('profile_about_legend', 'About you')); ?> <span class="bg-muted"><?php echo esc_html(self::wording('profile_optional_marker', '(optional)')); ?></span></legend>
                <?php if ($askAge) : ?>
                <p class="bg-q"><strong><?php echo esc_html(self::wording('profile_age_question', 'What is your age range?')); ?></strong></p>
                <div class="bg-demo-grid">
                  <?php foreach (SurveyFields::ageRanges() as $opt) : ?>
                    <label class="bg-check">
                      <input type="radio" name="age_range" value="<?php echo esc_attr($opt); ?>"
                        <?php checked($p['age_range'] === $opt); ?>>
                      <?php echo esc_html(self::optionWording('age', $opt)); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($askRelationship) : ?>
                <p class="bg-q"><strong><?php echo esc_html(self::wording('profile_relationship_question', 'What is your relationship status?')); ?></strong></p>
                <div class="bg-demo-grid">
                  <?php foreach (SurveyFields::relationshipOptions() as $opt) : ?>
                    <label class="bg-check">
                      <input type="checkbox" name="relationship[]" value="<?php echo esc_attr($opt); ?>"
                        <?php checked(in_array($opt, $p['relationship'], true)); ?>>
                      <?php echo esc_html(self::optionWording('relationship', $opt)); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </fieldset>
              <?php endif; ?>
              <?php if ($askDemographics) : ?>
              <fieldset class="bg-fieldset"><legend><?php echo esc_html(self::wording('profile_demographic_legend', 'Demographic information')); ?> <span class="<?php echo $demographicsRequired ? 'bg-required' : 'bg-muted'; ?>"><?php echo esc_html($demographicsRequired ? self::wording('profile_required_marker', '(required)') : self::wording('profile_optional_marker', '(optional)')); ?></span></legend>
                <p class="bg-muted"><?php echo esc_html(self::wording('profile_demographic_instruction', 'Select all that apply. “Prefer not to say” is always an option.')); ?></p>
                <div class="bg-demo-grid">
                  <?php foreach (DemographicFields::options() as $opt) : ?>
                    <label class="bg-check">
                      <input type="checkbox" name="demographics[]" value="<?php echo esc_attr($opt); ?>"
                        <?php checked(in_array($opt, $p['demographics'], true)); ?>>
                      <?php echo esc_html(self::optionWording('demographic', $opt)); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php $profileOther = in_array(DemographicFields::OTHER_KEY, $p['demographics'], true); ?>
                <label id="bg-prof-demo-other" <?php echo $profileOther ? '' : 'hidden'; ?>><?php echo esc_html(self::wording('profile_other_specify_label', 'Please specify')); ?>
                  <input type="text" name="demographics_other" maxlength="190"
                    value="<?php echo esc_attr($profileOther ? (string) ($p['demographics_other'] ?? '') : ''); ?>">
                </label>
              </fieldset>
              <?php endif; ?>
            <?php else : ?>
              <p class="bg-muted"><?php echo esc_html(self::wording('profile_contact_stored_note', 'Your contact details are stored encrypted on this site.')); ?></p>
            <?php endif; ?>
            <fieldset class="bg-fieldset" id="subscriptions">
              <legend><?php echo esc_html(self::wording('profile_subscriptions_legend', 'Subscriptions')); ?></legend>
              <input type="hidden" name="subscriptions_present" value="1">
              <p class="bg-muted"><?php echo esc_html(self::wording('profile_subscriptions_instruction', 'Choose the newsletters you want to receive. Unchecking a newsletter records an unsubscribe; checking it records a new subscription.')); ?></p>
              <?php if ($subscriptionGroups) : ?>
                <div class="bg-subscription-grid">
                  <?php foreach ($subscriptionGroups as $group) : $groupId = (int) $group->id; ?>
                    <label class="bg-check bg-subscription-option">
                      <input type="checkbox" name="subscriptions[]" value="<?php echo $groupId; ?>" <?php checked(isset($activeSubscriptionIds[$groupId])); ?>>
                      <span><strong><?php echo esc_html((string) $group->name); ?></strong><?php if (trim((string) ($group->description ?? '')) !== '') : ?><br><small><?php echo esc_html((string) $group->description); ?></small><?php endif; ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php else : ?>
                <p class="bg-muted"><?php echo esc_html(self::wording('profile_subscriptions_unavailable', 'No newsletter subscriptions are currently available.')); ?></p>
              <?php endif; ?>
            </fieldset>
            <fieldset class="bg-fieldset bg-monthly-management-panel">
              <legend><?php echo esc_html(self::wording('profile_monthly_legend', 'Monthly donation')); ?></legend>
              <?php if ($canEnrollMonthly): ?>
                <a class="bg-btn bg-btn-become-monthly" href="<?php echo esc_url($enrollmentUrl); ?>" target="_blank" rel="noopener">
                  <?php echo esc_html(self::wording('profile_monthly_enroll_button', 'Become a Monthly Donor')); ?>
                </a>
                <p class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_enroll_note', 'Choose a monthly amount here, then complete the enrollment in secure Stripe checkout.')); ?></p>
              <?php else: ?>
              <button type="button" class="bg-btn bg-btn-manage-monthly" id="bg-manage-monthly"
                aria-expanded="false" aria-controls="bg-monthly-oneform">
                <?php echo esc_html(self::wording('profile_monthly_manage_button', 'Change Donation Status')); ?>
              </button>
              <p class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_manage_note', 'Increase, decrease, or cancel a monthly donation when the payment can be safely matched.')); ?></p>
              <div id="bg-monthly-oneform" class="bg-oneform" tabindex="-1" hidden>
                <h3><?php echo esc_html(self::wording('profile_monthly_management_heading', 'Donation Management')); ?></h3>
                <p><?php echo esc_html((string) ($donationState['message'] ?? '')); ?></p>
                <?php if ($donationMode === 'stripe'): ?>
                  <p><strong><?php echo esc_html(self::wording('profile_monthly_current_label', 'Current monthly amount:')); ?></strong>
                    <?php echo esc_html(\BosqueGrande\Core\Money::format((int) $subscriptionAmount)); ?>
                    <?php if (!empty($subscription['next_charge'])): ?>
                      <br><span class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_next_charge_label', 'Next scheduled charge:')); ?> <?php echo esc_html((string) $subscription['next_charge']); ?></span>
                    <?php endif; ?>
                  </p>
                  <input type="hidden" id="bg-monthly-subscription" value="<?php echo esc_attr((string) ($subscription['id'] ?? '')); ?>">
                  <?php if (!empty($subscription['can_change_amount'])): ?>
                    <label class="bg-oneform-amount"><?php echo esc_html(self::wording('profile_monthly_new_amount_label', 'New monthly amount')); ?>
                      <span class="bg-money-input"><span><?php echo esc_html(\BosqueGrande\Core\Money::symbol()); ?></span><input type="number" id="bg-monthly-amount"
                        min="1.00" max="100000.00" step="0.01" inputmode="decimal"
                        value="<?php echo esc_attr($subscriptionAmountDisplay); ?>"></span>
                    </label>
                    <button type="button" class="bg-btn bg-btn-small" id="bg-update-monthly"><?php echo esc_html(self::wording('profile_monthly_save_amount_button', 'Save New Monthly Amount')); ?></button>
                    <p class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_amount_timing_note', 'The new amount begins on the next scheduled charge. No mid-cycle charge or credit is created.')); ?></p>
                  <?php else: ?>
                    <p class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_external_amount_note', 'This subscription can be cancelled here, but its amount must be changed by office staff in LGL Forms.')); ?></p>
                  <?php endif; ?>
                  <hr>
                  <button type="button" class="bg-btn bg-btn-danger bg-btn-small" id="bg-cancel-monthly"><?php echo esc_html(self::wording('profile_monthly_cancel_button', 'Cancel Monthly Donation')); ?></button>
                <?php elseif ($donationMode === 'manual'): ?>
                  <input type="hidden" id="bg-monthly-subscription" value="">
                  <button type="button" class="bg-btn bg-btn-danger bg-btn-small" id="bg-cancel-monthly"><?php echo esc_html(self::wording('profile_monthly_cancel_status_button', 'Cancel Monthly-Donor Status')); ?></button>
                  <p class="bg-muted"><?php echo esc_html(self::wording('profile_monthly_manual_cancel_note', 'This removes the cash/check status here and updates the linked LGL constituent. There is no Stripe charge to stop.')); ?></p>
                <?php elseif ($donationMode === 'external'): ?>
                  <p class="bg-oneform-warning"><strong><?php echo esc_html(self::wording('profile_monthly_external_cancel_lead', 'No automatic cancellation is offered.')); ?></strong> <?php echo esc_html(self::wording('profile_monthly_external_cancel_detail', 'A status-only change would not reliably stop an LGL Forms charge. Please contact us.')); ?></p>
                <?php endif; ?>
                <p id="bg-monthly-msg" role="status"></p>
              </div>
              <?php endif; ?>
            </fieldset>
            <button type="submit" class="bg-btn"><?php echo esc_html(self::wording('profile_save_button', 'Save changes')); ?></button>
            <p id="bg-prof-msg" role="status"></p>
          </form>
        </div>
        <script>
        (function(){
          var f=document.getElementById('bg-prof-form');if(!f)return;
          var nonceEndpoint=<?php echo wp_json_encode($profileNonceEndpoint); ?>;
          var profileEndpoint=<?php echo wp_json_encode($profileEndpoint); ?>;
          var donationEndpoint=<?php echo wp_json_encode($donationEndpoint); ?>;
          var copy=<?php echo wp_json_encode([
              'demographicRequired' => self::wording('profile_js_demographic_required', 'Please answer the demographic question. “Prefer not to say” is available.'),
              'saving' => self::wording('profile_js_saving', 'Saving...'),
              'genericError' => self::wording('profile_js_generic_error', 'Something went wrong. Please try again.'),
              'cancelling' => self::wording('profile_js_cancelling', 'Cancelling and verifying...'),
              'changingAmount' => self::wording('profile_js_changing_amount', 'Saving and verifying the new amount...'),
              'donationError' => self::wording('profile_js_donation_error', 'Something went wrong. Nothing is being reported as changed. Please try again.'),
              'cancelConfirm' => self::wording('profile_js_cancel_confirm', 'Cancel this monthly donation? This stops future charges but does not refund completed donations.'),
          ]); ?>;
          var manageButton=document.getElementById('bg-manage-monthly');
          var oneform=document.getElementById('bg-monthly-oneform');
          var cancelButton=document.getElementById('bg-cancel-monthly');
          var updateButton=document.getElementById('bg-update-monthly');
          var donationMessage=document.getElementById('bg-monthly-msg');
          var profileBinding=f.querySelector('input[name="profile_binding"]');
          f.addEventListener('change',function(e){
            if(e.target.name!=='demographics[]')return;
            var decline=f.querySelector('input[name="demographics[]"][value="Prefer not to say"]');
            if(e.target.checked&&e.target.value==='Prefer not to say'){
              f.querySelectorAll('input[name="demographics[]"]').forEach(function(input){
                if(input!==e.target){input.checked=false;}
              });
            }else if(e.target.checked&&decline){
              decline.checked=false;
            }
            var other=f.querySelector('input[name="demographics[]"][value="Other"]');
            var detail=document.getElementById('bg-prof-demo-other');
            if(detail){detail.hidden=!(other&&other.checked);}
          });
          f.addEventListener('submit',function(e){
            e.preventDefault();
            var msg=document.getElementById('bg-prof-msg');
            var selected=f.querySelector('input[name="demographics[]"]:checked');
            if(f.dataset.demographicsRequired==='1'&&!selected){
              msg.textContent=copy.demographicRequired;
              var first=f.querySelector('input[name="demographics[]"]');
              if(first){first.focus();}
              return;
            }
            msg.textContent=copy.saving;
            fetch(nonceEndpoint,{cache:'no-store',credentials:'same-origin'})
              .then(function(r){return r.json();})
              .then(function(d){
                var fd=new FormData(f);
                fd.set('nonce',(d&&d.nonce)||fd.get('nonce')||'');
                return fetch(profileEndpoint,{method:'POST',body:fd,cache:'no-store',credentials:'same-origin'});
              })
              .then(function(r){return r.json();})
              .then(function(d){msg.textContent=d.message||'';})
              .catch(function(){msg.textContent=copy.genericError;});
          });
          if(manageButton&&oneform){
            manageButton.addEventListener('click',function(){
              var opening=oneform.hidden;
              oneform.hidden=!opening;
              manageButton.setAttribute('aria-expanded',opening?'true':'false');
              if(opening){oneform.focus();}
            });
          }
          function donationAction(action){
            if(!donationMessage)return;
            var subscription=document.getElementById('bg-monthly-subscription');
            var amount=document.getElementById('bg-monthly-amount');
            var buttons=[cancelButton,updateButton].filter(Boolean);
            buttons.forEach(function(button){button.disabled=true;});
            donationMessage.textContent=action==='cancel'?copy.cancelling:copy.changingAmount;
            fetch(nonceEndpoint,{cache:'no-store',credentials:'same-origin'})
              .then(function(r){return r.json();})
              .then(function(d){
                var fd=new FormData();
                fd.set('nonce',(d&&d.nonce)||'');
                fd.set('profile_binding',profileBinding?profileBinding.value:'');
                fd.set('donation_action',action);
                fd.set('subscription_id',subscription?subscription.value:'');
                if(amount){fd.set('amount',amount.value);}
                return fetch(donationEndpoint,{method:'POST',body:fd,cache:'no-store',credentials:'same-origin'});
              })
              .then(function(r){return r.json();})
              .then(function(d){
                donationMessage.textContent=(d&&d.message)||'';
                if(d&&d.ok){setTimeout(function(){location.reload();},1800);return;}
                buttons.forEach(function(button){button.disabled=false;});
              })
              .catch(function(){
                donationMessage.textContent=copy.donationError;
                buttons.forEach(function(button){button.disabled=false;});
              });
          }
          if(updateButton){
            updateButton.addEventListener('click',function(){donationAction('change_amount');});
          }
          if(cancelButton&&!cancelButton.disabled){
            cancelButton.addEventListener('click',function(){
              if(confirm(copy.cancelConfirm)){
                donationAction('cancel');
              }
            });
          }
        })();
        </script>
        <?php
        return $this->withCss((string) ob_get_clean());
    }

    private function save(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        /** @var Session $session */
        $session = $container->get('identity.session');
        $identity = $session->identity();
        $cid = $identity ? (int) $identity['constituent_id'] : 0;
        $source = $identity ? (string) $identity['source'] : '';
        if (!$cid) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_session_expired',
                'Your session expired. Please request a new sign-in link.'
            )], 403);
        }
        if (!wp_verify_nonce((string) $req->get_param('nonce'), 'bg_profile')) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_form_expired',
                'This form expired. Please reload the page.'
            )], 400);
        }

        /** @var ConstituentStore $pstore */
        $pstore = $container->get('constituents.store');
        $boundProfile = $this->resolveBoundProfile(
            $pstore,
            $identity,
            (string) $req->get_param('profile_binding')
        );
        if ($boundProfile === null) {
            return $this->staleProfileResponse();
        }
        $localId = (int) $boundProfile['local_id'];
        $lglId = (int) $boundProfile['lgl_id'];
        $current = $boundProfile['record'];

        $first = sanitize_text_field((string) $req->get_param('first_name'));
        $last = sanitize_text_field((string) $req->get_param('last_name'));
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if ($first === '' || $last === '' || !is_email($email)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_invalid_contact',
                'Please fill in your name and a valid email.'
            )], 400);
        }

        $phone = sanitize_text_field((string) $req->get_param('phone'));
        $street = sanitize_text_field((string) $req->get_param('street'));
        $city = sanitize_text_field((string) $req->get_param('city'));
        $demographics = RegistrationConfig::enabled('demographics')
            ? DemographicFields::sanitize((array) $req->get_param('demographics'))
            : [];
        $demographicsOther = in_array(DemographicFields::OTHER_KEY, $demographics, true)
            ? sanitize_text_field((string) $req->get_param('demographics_other'))
            : '';
        if (!$demographics && RegistrationConfig::required('demographics')) {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::wording(
                    'profile_js_demographic_required',
                    'Please answer the demographic question. “Prefer not to say” is available.'
                ),
            ], 400);
        }
        $ageRange = RegistrationConfig::enabled('age')
            ? SurveyFields::sanitizeAge((string) $req->get_param('age_range'))
            : '';
        $relationship = RegistrationConfig::enabled('relationship')
            ? SurveyFields::sanitizeRelationship((array) $req->get_param('relationship'))
            : [];

        $contactSaved = false;
        $identityLinkRequested = $source !== 'bg' && $lglId > 0;
        $identityLinkSaved = !$identityLinkRequested;
        if (ConstituentStore::normEmail((string) ($current['email'] ?? '')) !== ConstituentStore::normEmail($email)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_email_change_blocked',
                'For privacy, the primary email cannot be changed in My Profile. Please ask authorized staff to verify and update it.'
            )], 400);
        }
        $contact = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'address1' => $street,
            'city' => $city,
            'state' => sanitize_text_field((string) $req->get_param('state')),
            'postal' => sanitize_text_field((string) $req->get_param('zip')),
        ];
        if ($source === 'bg') {
            $contactSaved = $pstore->update($localId, array_merge($current, $contact));
        } else {
            $contactSaved = $pstore->update($localId, array_merge($current, $contact));
            $localId = $contactSaved ? $localId : 0;
            if ($contactSaved && $identityLinkRequested) {
                $identityLinkSaved = $pstore->linkLgl($localId, $lglId);
            }
        }

        $hasPersonalAnswers = (bool) $demographics || $ageRange !== '' || (bool) $relationship;
        $answersSaved = true;
        if ($hasPersonalAnswers) {
            /** @var AnswerRetry $answerRetry */
            $answerRetry = $container->get('registration.answer_retry');
            $answersSaved = $answerRetry->saveRequired(
                $localId, $demographics, $demographicsOther, $ageRange, $relationship
            );
            if (!$answersSaved) {
                update_option('bg_answer_store_failure', current_time('mysql', true), false);
                $container->get('logger')->error('profile', 'Mandatory encrypted profile answers did not verify.', [
                    'constituent' => $cid,
                    'source' => $source,
                    'local_constituent' => $localId,
                ]);
            }
        }

        $consentRequested = (string) $req->get_param('contact_sharing_preference_present') === '1';
        $consentSaved = !$consentRequested || $localId > 0;
        if ($consentRequested && $localId > 0) {
            $contactSharingGranted = (string) $req->get_param('do_not_share_contact') !== '1';
            $existingConsent = $pstore->latestConsent(
                $localId,
                ConstituentStore::CONTACT_SHARING_PURPOSE
            );
            $consentSaved = $existingConsent === $contactSharingGranted
                || $pstore->recordConsent(
                    $localId,
                    ConstituentStore::CONTACT_SHARING_PURPOSE,
                    $contactSharingGranted,
                    'profile'
                );
        }

        $subscriptionsRequested = (string) $req->get_param('subscriptions_present') === '1'
            && $container->has('correspondence.groups');
        $subscriptionsSaved = !$subscriptionsRequested || $localId > 0;
        $subscriptionDeliveryRestricted = false;
        if ($subscriptionsRequested
            && $localId > 0
        ) {
            $groups = $container->get('correspondence.groups');
            $requestedSubscriptionIds = (array) $req->get_param('subscriptions');
            $validSubscriptionIds = $groups->validActiveSelectionIds($requestedSubscriptionIds);
            $suppressionReason = $groups->suppressionReason(
                $container->get('encryption')->blindIndex(ConstituentStore::normEmail($email))
            );
            $subscriptionDeliveryRestricted = $suppressionReason !== null
                && $suppressionReason !== 'unsubscribe-all';
            $subscriptionsSaved = $groups->saveActiveSelections(
                $localId,
                $validSubscriptionIds,
                'profile',
                true,
                !$subscriptionDeliveryRestricted
            );
            // A verified person who explicitly opts back in may reverse only
            // their own earlier "Stop ALL emails" action. Other suppression
            // reasons remain above every group and cannot be cleared here.
            if ($subscriptionsSaved && $validSubscriptionIds && !$subscriptionDeliveryRestricted) {
                $subscriptionsSaved = $groups->clearUserUnsubscribeAll(
                    $container->get('encryption')->blindIndex(ConstituentStore::normEmail($email)),
                    $localId,
                    'profile'
                );
            }
        }

        $sections = [
            'contact' => $contactSaved ? 'saved' : 'failed',
            'identity_link' => !$identityLinkRequested ? 'not_requested' : ($identityLinkSaved ? 'saved' : 'failed'),
            'personal_answers' => !$hasPersonalAnswers ? 'not_requested' : ($answersSaved ? 'saved' : 'failed'),
            'contact_sharing' => !$consentRequested ? 'not_requested' : ($consentSaved ? 'saved' : 'failed'),
            'subscriptions' => !$subscriptionsRequested ? 'not_requested' : ($subscriptionsSaved ? 'saved' : 'failed'),
        ];
        $saved = !in_array('failed', $sections, true);
        $anythingSaved = in_array('saved', $sections, true);
        if ($saved) {
            PrefillResolver::invalidate($localId, $lglId);

            /** @var Logger $logger */
            $logger = $container->get('logger');
            $logger->info('profile', 'Profile updated.', [
                'constituent' => $cid,
                'source' => $source,
                'lgl_sync_required' => $lglId > 0,
            ]);
            $message = $subscriptionDeliveryRestricted
                ? self::wording(
                    'profile_saved_restricted',
                    'Saved. This email has a newsletter delivery restriction that cannot be removed from My Profile; contact {org} if you believe it is an error.'
                )
                : self::wording('profile_saved_success', 'Saved! Your info is up to date.');
            return new \WP_REST_Response(['ok' => true, 'message' => $message], 200);
        }
        if ($anythingSaved) {
            if ($localId > 0) PrefillResolver::invalidate($localId, $lglId);
            $container->get('logger')->warn('profile', 'Profile update completed only in part.', [
                'constituent' => $cid,
                'source' => $source,
                'local_constituent' => $localId,
                'sections' => $sections,
            ]);
            return new \WP_REST_Response([
                'ok' => false,
                'partial' => true,
                'sections' => $sections,
                'message' => self::wording(
                    'profile_saved_partial',
                    'Some changes were saved, but we could not complete every section. Reload this page to review the current information, then retry the remaining choices.'
                ),
            ], 207);
        }
        return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
            'profile_save_failed',
            'We could not save right now. Please try again shortly.'
        )], 500);
    }

    private function cancelMonthlyDonation(
        Container $container,
        \WP_REST_Request $req
    ): \WP_REST_Response {
        /** @var Session $session */
        $session = $container->get('identity.session');
        $identity = $session->identity();
        if (!$identity || (int) ($identity['constituent_id'] ?? 0) < 1) {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::wording(
                    'profile_session_expired',
                    'Your session expired. Please request a new sign-in link.'
                ),
            ], 403);
        }
        if (!wp_verify_nonce((string) $req->get_param('nonce'), 'bg_profile')) {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::wording(
                    'profile_action_expired',
                    'This action expired. Please reload the page.'
                ),
            ], 400);
        }

        /** @var ConstituentStore $pstore */
        $pstore = $container->get('constituents.store');
        $boundProfile = $this->resolveBoundProfile(
            $pstore,
            $identity,
            (string) $req->get_param('profile_binding')
        );
        if ($boundProfile === null) return $this->staleProfileResponse();
        $localId = (int) $boundProfile['local_id'];
        $lglId = (int) $boundProfile['lgl_id'];
        $email = strtolower((string) ($boundProfile['record']['email'] ?? ''));
        if (!is_email($email)) return $this->staleProfileResponse();

        $result = (new MonthlyDonationCancellation($container))->cancel($localId, $lglId, $email);
        return new \WP_REST_Response($result, $result['ok'] ? 200 : 502, [
            'Cache-Control' => 'no-store',
        ]);
    }

    private function manageMonthlyDonation(
        Container $container,
        \WP_REST_Request $req
    ): \WP_REST_Response {
        /** @var Session $session */
        $session = $container->get('identity.session');
        $identity = $session->identity();
        if (!$identity || (int) ($identity['constituent_id'] ?? 0) < 1) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_session_expired',
                'Your session expired. Please request a new sign-in link.'
            )], 403);
        }
        if (!wp_verify_nonce((string) $req->get_param('nonce'), 'bg_profile')) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_action_expired',
                'This action expired. Please reload the page.'
            )], 400);
        }

        /** @var ConstituentStore $pstore */
        $pstore = $container->get('constituents.store');
        $boundProfile = $this->resolveBoundProfile(
            $pstore,
            $identity,
            (string) $req->get_param('profile_binding')
        );
        if ($boundProfile === null) return $this->staleProfileResponse();
        $localId = (int) $boundProfile['local_id'];
        $lglId = (int) $boundProfile['lgl_id'];
        $email = strtolower((string) ($boundProfile['record']['email'] ?? ''));
        if (!is_email($email)) return $this->staleProfileResponse();

        $subscriptionId = sanitize_text_field((string) $req->get_param('subscription_id'));
        if ($subscriptionId !== '' && !preg_match('/^sub_[A-Za-z0-9]+$/', $subscriptionId)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording(
                'profile_invalid_subscription',
                'The selected Stripe subscription is invalid. Nothing was changed.'
            )], 400);
        }
        $manager = new MonthlyDonationCancellation($container);
        $action = sanitize_key((string) $req->get_param('donation_action'));
        if ($action === 'cancel') {
            $result = $manager->cancel($localId, $lglId, $email, $subscriptionId);
        } elseif ($action === 'change_amount') {
            $amountCents = $this->amountToCents((string) $req->get_param('amount'));
            if ($amountCents < 100 || $amountCents > 10000000) {
                $result = [
                    'ok' => false,
                    'message' => self::templatedWording(
                        'profile_monthly_amount_range',
                        'Enter a monthly amount from {min_amount} through {max_amount}.',
                        ['{min_amount}' => '$1.00', '{max_amount}' => '$100,000.00']
                    ),
                    'lgl_synced' => false,
                    'sync_pending' => false,
                ];
            } else {
                $result = $manager->changeAmount($localId, $lglId, $email, $subscriptionId, $amountCents);
            }
        } else {
            $result = [
                'ok' => false,
                'message' => self::wording(
                    'profile_monthly_action_required',
                    'Choose whether to change the amount or cancel the donation.'
                ),
                'lgl_synced' => false,
                'sync_pending' => false,
            ];
        }
        return new \WP_REST_Response($result, !empty($result['ok']) ? 200 : 422, ['Cache-Control' => 'no-store']);
    }

    /**
     * Resolve only the exact local record named by the current verified
     * session, then require the short-lived binding issued with the rendered
     * form. Email is deliberately not an identity key here: household members
     * may share it, and an old browser tab may outlive a session change.
     *
     * This method performs reads only, so a stale or mismatched form is
     * rejected before contact, answers, consent, subscriptions, or donations
     * can be changed.
     *
     * @param array{source?: string, constituent_id?: int} $identity
     * @return array{local_id: int, lgl_id: int, record: array<string, mixed>}|null
     */
    private function resolveBoundProfile(
        ConstituentStore $store,
        array $identity,
        string $binding
    ): ?array {
        if (!$store->available()) return null;
        $source = (string) ($identity['source'] ?? '');
        $identityId = (int) ($identity['constituent_id'] ?? 0);
        if ($identityId < 1 || !in_array($source, ['bg', 'lgl'], true)) return null;

        $localId = 0;
        $lglId = 0;
        $record = null;
        if ($source === 'bg') {
            $localId = $identityId;
            $record = $store->get($localId);
            $lglId = (int) ($record['lgl_constituent_id'] ?? 0);
        } else {
            $lglId = $identityId;
            $linked = $store->idsByLglId($lglId);
            // A legacy LGL identity is safe only when its explicit link is
            // unambiguous; choosing the first shared match would cross people.
            if (count($linked) !== 1) return null;
            $localId = (int) $linked[0];
            $record = $store->get($localId);
        }
        if (!$record || ($record['status'] ?? '') !== 'active') return null;
        if (!$this->verifyProfileBinding($binding, $localId, $source, $identityId)) return null;

        return [
            'local_id' => $localId,
            'lgl_id' => $lglId,
            'record' => $record,
        ];
    }

    private function issueProfileBinding(int $localId, string $source, int $identityId): string
    {
        if ($localId < 1 || $identityId < 1 || !in_array($source, ['bg', 'lgl'], true)) return '';
        $key = $this->profileBindingKey();
        if ($key === '') return '';
        $payload = $source . '|' . $identityId . '|' . $localId . '|' . time();
        return $payload . '|' . hash_hmac('sha256', $payload, $key);
    }

    private function verifyProfileBinding(
        string $binding,
        int $localId,
        string $source,
        int $identityId
    ): bool {
        $parts = explode('|', $binding);
        if (count($parts) !== 5) return false;
        [$boundSource, $boundIdentity, $boundLocal, $issued, $signature] = $parts;
        if (!preg_match('/^[1-9][0-9]*$/', $boundIdentity)
            || !preg_match('/^[1-9][0-9]*$/', $boundLocal)
            || !preg_match('/^[1-9][0-9]*$/', $issued)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }
        $issuedAt = (int) $issued;
        $now = time();
        if ($issuedAt > $now + self::PROFILE_BINDING_FUTURE_SKEW
            || $issuedAt < $now - self::PROFILE_BINDING_LIFETIME
            || $boundSource !== $source
            || (int) $boundIdentity !== $identityId
            || (int) $boundLocal !== $localId
        ) {
            return false;
        }
        $key = $this->profileBindingKey();
        if ($key === '') return false;
        $payload = $boundSource . '|' . $boundIdentity . '|' . $boundLocal . '|' . $issued;
        return hash_equals(hash_hmac('sha256', $payload, $key), $signature);
    }

    private function profileBindingKey(): string
    {
        return \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')->signingKey('profile-binding');
    }

    private function staleProfileResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ok' => false,
            'message' => self::wording(
                'profile_stale_page',
                'This profile page no longer matches your current verified sign-in. Please reload the page before trying again.'
            ),
        ], 409, ['Cache-Control' => 'no-store']);
    }

    private function amountToCents(string $raw): int
    {
        $raw = str_replace([',', '$', \BosqueGrande\Core\Money::symbol()], '', trim($raw));
        if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $raw)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        return ((int) $whole * 100) + (int) substr($fraction, 0, 2);
    }

    private function withCss(string $html): string
    {
        $css = '.bg-reg{max-width:680px;margin:0 auto;font-family:Inter,-apple-system,sans-serif;color:#1F2A24;background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:26px 24px;position:relative;box-shadow:0 2px 10px rgba(31,42,36,.06)}'
            . '.bg-reg::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#1F5130 0%,#2E7D4F 70%,#C98A1B 100%)}'
            . '.bg-reg h2{color:var(--bgc-primary,#1F5130);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:1.55rem;line-height:1.2;letter-spacing:-.02em;text-transform:none}'
            . '#bg-profile-gate>input[type=text],#bg-profile-gate>input[type=email]{display:block;width:100%;max-width:420px;margin:0 0 10px;padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;background:#fff;color:#1F2A24;font:400 1rem/1.4 Inter,-apple-system,sans-serif}'
            . '.bg-fieldset{border:1px solid #D9E5D9;border-radius:12px;padding:18px 16px;margin:18px 0;background:#FBFDFB}'
            . '.bg-fieldset legend{font-weight:700;padding:0 8px;color:#1F5130}'
            . '.bg-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
            . '.bg-grid label{display:flex;flex-direction:column;font-size:.9rem;font-weight:600;gap:5px;color:#3C4A42}'
            . '.bg-grid input,#bg-prof-email{padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;font-size:1rem}'
            . '.bg-fieldset input[type=text],.bg-fieldset input[type=email],.bg-fieldset input[type=tel],.bg-fieldset select{max-width:100%;padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;background:#fff;color:#1F2A24;font:400 1rem/1.4 Inter,-apple-system,sans-serif}'
            . '.bg-grid input[readonly]{background:#F1F4F1;color:#536158;cursor:not-allowed}'
            . '.bg-grid input:focus-visible,#bg-prof-email:focus-visible,.bg-fieldset input:focus-visible,.bg-fieldset select:focus-visible,#bg-profile-gate>input:focus-visible{outline:2px solid #2E7D4F;outline-offset:1px;border-color:#2E7D4F}'
            . '.bg-wide{grid-column:1/-1}'
            . '.bg-demo-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}'
            . '.bg-subscription-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 16px;margin-top:12px}.bg-subscription-option{align-items:flex-start}.bg-subscription-option small{color:#4B5A4B}'
            . '.bg-check{display:flex;align-items:center;gap:8px;font-size:.92rem}'
            . '.bg-check input{accent-color:#1F5130}'
            . '.bg-contact-sharing{margin-top:16px;padding:13px 14px;border-left:4px solid #1F5130;background:#F2F7F2;border-radius:8px}.bg-contact-sharing p{margin:7px 0 0 26px}'
            . '.bg-btn{background:linear-gradient(135deg,#2A6B40,#1F5130);color:#fff;font-weight:700;border:0;border-radius:999px;padding:13px 28px;font-size:1rem;cursor:pointer;margin-top:8px;box-shadow:0 3px 10px rgba(31,81,48,.25)}'
            . '.bg-btn:focus-visible{outline:2px solid #C98A1B;outline-offset:2px}'
            . '.bg-btn-manage-monthly,.bg-btn-danger{background:#8B1E1E;box-shadow:0 3px 10px rgba(139,30,30,.2)}'
            . '.bg-btn-become-monthly{display:inline-block;text-decoration:none;background:linear-gradient(135deg,var(--bgc-donor-a,#1F5130),var(--bgc-donor-b,#2E7D4F));box-shadow:0 3px 10px rgba(31,81,48,.3)}'
            . '.bg-btn-small{padding:10px 18px;font-size:.92rem;margin-right:8px}'
            . '.bg-oneform{margin-top:14px;padding:16px;border:1px solid #D9E5D9;border-radius:10px;background:#fff}'
            . '.bg-oneform:focus{outline:none}.bg-oneform h3{margin-top:0;color:#1F5130}'
            . '.bg-oneform-amount{display:flex;flex-direction:column;gap:6px;font-weight:700;max-width:240px}'
            . '.bg-money-input{display:flex;align-items:center;border:1px solid #C4D6C4;border-radius:9px;background:#fff;padding-left:10px}'
            . '.bg-money-input input{width:100%;border:0;padding:10px;font-size:1rem;background:transparent}'
            . '.bg-money-input input:focus{outline:0}.bg-oneform-warning{padding:12px;border-left:4px solid #8B1E1E;background:#FFF4F3}'
            . '#bg-monthly-msg{font-weight:600;color:#1F5130}'
            . '.bg-muted{color:#5A6A61;font-size:.85rem;font-weight:400}'
            . '.bg-required{color:#8B1E1E;font-size:.85rem;font-weight:700}'
            . '#bg-prof-msg{font-weight:600;color:#1F5130}'
            . '@media(max-width:600px){.bg-grid,.bg-demo-grid,.bg-subscription-grid{grid-template-columns:minmax(0,1fr)}}';
        $styled = '<style>' . $css . '</style>' . $html;
        if (function_exists('bgev_public_document_context')
            && bgev_public_document_context() === 'profile') {
            return $styled;
        }
        if (function_exists('bgev_public_app_shell')) {
            return bgev_public_app_shell($styled, [
                'context' => 'profile',
                'main_id' => 'bg-profile-app-main',
                'title' => self::wording('shell_profile_title', 'My Profile'),
                'eyebrow' => self::wording('shell_profile_eyebrow', 'Your account'),
                'intro' => self::wording('shell_profile_intro', 'Review your information, newsletter subscriptions, privacy preference, and monthly-donation status in one secure place.'),
                'skip_label' => self::wording('profile_shell_skip_label', 'Skip to My Profile'),
            ]);
        }
        return $styled;
    }
}
