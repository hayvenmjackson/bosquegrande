<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

use BosqueGrande\Modules\Payments\MonthlyDonationEnrollment;
use BosqueGrande\Modules\Payments\PaymentTrust;
use BosqueGrande\Modules\Correspondence\Composer;
use BosqueGrande\Modules\Correspondence\Groups;

if (!defined('ABSPATH')) exit;

/**
 * Renders the registration form for one event.
 * Prefill rules:
 *   Verified identity : the local encrypted constituent envelope is
 *                       decrypted after the stable id is verified.
 *   Tier 2 session : contact fields prefilled, demographic values
 *                    rendered inside the collapsed on-file panel.
 *   Tier 1 link    : contact fields prefilled; demographic panel shows
 *                    on-file state WITHOUT values (a forwarded newsletter
 *                    must never expose the profile). Edits overwrite.
 *   Anonymous      : blank form, always usable.
 * The monthly-donor option is gated on the server; the client-side lock
 * is cosmetic.
 */
final class RegistrationForm
{
    public function __construct(
        private PrefillResolver $resolver,
        private \BosqueGrande\Core\Settings $settings,
        private ?MonthlyDonor $monthlyDonor = null,
        private ?Groups $groups = null,
    ) {
    }

    /**
     * @param array{source: string, constituent_id: ?int, via_session: bool} $identity
     */
    public function render(int $eventPostId, array $identity, ?Groups $liveGroups = null): string
    {
        $event = get_post($eventPostId);
        if (!$event || $event->post_type !== 'bg_event' || $event->post_status !== 'publish') {
            return '<p>' . esc_html(RegistrationWording::text(
                'registration_event_not_found',
                'This event could not be found.'
            )) . '</p>';
        }

        $resolved = $this->resolver->resolve($identity);
        $prefill = $resolved['prefill'];
        // A newsletter link can be forwarded, so without a signed-in device
        // session only the name is filled in; contact details stay blank.
        if (empty($identity['via_session'])) {
            foreach (['email', 'phone', 'street', 'city', 'state', 'zip'] as $field) {
                if (array_key_exists($field, $prefill)) $prefill[$field] = '';
            }
        }
        // The monthly-donor option is locked only when verification is on;
        // a lock backed by an out-of-date list would turn people away.
        $gateEnforced = $this->monthlyDonor === null || $this->monthlyDonor->enforced();
        $isMember = $resolved['is_member'];
        if (!$isMember && $this->monthlyDonor !== null) {
            $knownEmail = (string) ($prefill['email'] ?? '');
            if ($knownEmail !== '') {
                $isMember = $this->monthlyDonor->isDonor($knownEmail, (int) ($resolved['lgl_id'] ?? 0));
            }
        }
        $hasProfile = $resolved['has_profile'];
        $onlinePayment = $this->settings->hasSecret('stripe_secret_key')
            && (string) $this->settings->get('stripe_publishable_key', '') !== '';
        $maskOnFile = (int) $this->settings->get('demographic_mask_on_file', 0) === 1;
        $showDemoValues = $hasProfile && $identity['via_session'] && !$maskOnFile; // Verified session only.
        $demoOnFile = $hasProfile && !empty($prefill['demographics']);
        // Collapse the panel only when values are on file but cannot be
        // shown (token link or mask). In a verified session the boxes are
        // simply shown checked.
        $demoCollapsed = $demoOnFile && !$showDemoValues;

        // The active Constituent Group catalog is also the newsletter
        // catalog. There is deliberately no second list to configure or
        // drift. Subscription status is revealed only inside a verified
        // device session; anonymous and forwarded-link forms stay blank.
        // Resolve the catalog at render time. The form service is lazy and
        // normally receives Groups in its constructor, but a request or
        // extension can resolve that service before Correspondence finishes
        // booting. A fresh render-time dependency prevents that stale null
        // from hiding the public section for the rest of the request.
        $groupService = $liveGroups ?? $this->groups;
        $subscriptionGroups = $groupService !== null ? $groupService->all() : [];
        $canManageSubscriptions = $hasProfile
            && !empty($identity['via_session'])
            && (int) ($resolved['local_id'] ?? 0) > 0;
        // Bind unsubscribe authority to the exact verified profile that
        // rendered this form. A session can change in another tab before
        // submit; without this signed context, visually blank public/Tier 1
        // boxes could later be misread as verified opt-outs.
        $subscriptionManagementContext = $canManageSubscriptions
            ? Composer::sign([
                'a' => 'registration-subscriptions',
                'e' => $eventPostId,
                'c' => (int) $resolved['local_id'],
            ], DAY_IN_SECONDS)
            : '';
        if ($subscriptionManagementContext === '') {
            $canManageSubscriptions = false;
        }
        $activeSubscriptionIds = $canManageSubscriptions && $groupService !== null
            ? array_fill_keys(
                $groupService->activeIdsForConstituent((int) $resolved['local_id']),
                true
            )
            : [];

        $title = get_the_title($event);
        $date = function_exists('bgev_date')
            ? (string) bgev_date($eventPostId)
            : (string) get_post_meta($eventPostId, '_bg_start_date', true);
        $time = function_exists('bgev_time_range')
            ? bgev_time_range($eventPostId)
            : (string) get_post_meta($eventPostId, '_bg_start_time', true);
        $nonce = wp_create_nonce('bg_register_' . $eventPostId);
        $returnUrl = $this->calendarReturnUrl();

        ob_start();
        ?>
        <div class="bg-reg" id="bg-reg">
          <p class="bg-return-row"><a class="bg-return-calendar" href="<?php echo esc_url($returnUrl); ?>">&larr; <?php echo esc_html(RegistrationWording::text('registration_return_calendar', 'Return to Calendar')); ?></a></p>
          <h1 class="bg-reg-title"><?php echo esc_html(RegistrationWording::format('registration_heading', 'Register: {event}', ['event' => $title])); ?></h1>
          <?php if ($date) : ?>
            <p class="bg-reg-when"><?php echo esc_html($time !== '' ? $date . ', ' . $time : $date); ?></p>
          <?php endif; ?>

          <?php if (!$hasProfile) : ?>
            <div class="bg-nudge">
              <span class="bg-nudge-hl"><?php echo esc_html(bg_str('identity_nudge_lead', 'Registered with us before?')); ?></span> <button type="button" class="bg-link bg-nudge-cta" id="bg-nudge-btn"><?php echo esc_html(bg_str('identity_nudge_button', "Confirm your email and we'll fill everything in for you")); ?></button>
              <div id="bg-nudge-form" hidden>
                <input type="text" id="bg-nudge-first" placeholder="<?php echo esc_attr(RegistrationWording::text('registration_nudge_first_placeholder', 'First name')); ?>" autocomplete="given-name">
                <input type="text" id="bg-nudge-last" placeholder="<?php echo esc_attr(RegistrationWording::text('registration_nudge_last_placeholder', 'Last name')); ?>" autocomplete="family-name">
                <input type="email" id="bg-nudge-email" placeholder="<?php echo esc_attr(RegistrationWording::text('registration_nudge_email_placeholder', 'Your email')); ?>" autocomplete="email">
                <button type="button" class="bg-btn bg-btn-secondary" id="bg-nudge-send"><?php echo esc_html(RegistrationWording::text('registration_nudge_send', 'Email me a sign-in link')); ?></button>
                <p id="bg-nudge-msg" class="bg-muted" role="status"></p>
              </div>
            </div>
          <?php endif; ?>

          <form id="bg-reg-form" novalidate data-demographics-on-file="<?php echo $demoOnFile ? '1' : '0'; ?>" data-demographics-required="<?php echo RegistrationConfig::required('demographics') ? '1' : '0'; ?>">
            <input type="hidden" name="event" value="<?php echo (int) $eventPostId; ?>">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="ts" value="<?php echo (int) time(); ?>">
            <?php if ($subscriptionManagementContext !== '') : ?>
              <input type="hidden" name="subscription_context" value="<?php echo esc_attr($subscriptionManagementContext); ?>">
            <?php endif; ?>
            <input type="text" name="<?php echo esc_attr(RegistrationModule::HONEYPOT); ?>" class="bg-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <fieldset class="bg-fieldset">
              <legend><?php echo esc_html(RegistrationWording::text('registration_contact_legend', 'Your information')); ?></legend>
              <div class="bg-grid">
                <label><?php echo esc_html(RegistrationWording::text('registration_first_name_label', 'First name')); ?><input type="text" name="first_name" required autocomplete="given-name" value="<?php echo esc_attr($prefill['first_name']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_last_name_label', 'Last name')); ?><input type="text" name="last_name" required autocomplete="family-name" value="<?php echo esc_attr($prefill['last_name']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_email_label', 'Email')); ?><input type="email" name="email" required autocomplete="email" value="<?php echo esc_attr($prefill['email']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_phone_label', 'Phone')); ?><input type="tel" name="phone" autocomplete="tel" value="<?php echo esc_attr($prefill['phone']); ?>"></label>
                <label class="bg-wide"><?php echo esc_html(RegistrationWording::text('registration_street_label', 'Street address')); ?><input type="text" name="street" autocomplete="street-address" value="<?php echo esc_attr($prefill['street']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_city_label', 'City')); ?><input type="text" name="city" autocomplete="address-level2" value="<?php echo esc_attr($prefill['city']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_state_label', 'State')); ?><input type="text" name="state" autocomplete="address-level1" value="<?php echo esc_attr($prefill['state']); ?>"></label>
                <label><?php echo esc_html(RegistrationWording::text('registration_zip_label', 'ZIP')); ?><input type="text" name="zip" autocomplete="postal-code" value="<?php echo esc_attr($prefill['zip']); ?>"></label>
              </div>
            </fieldset>

            <?php if (RegistrationConfig::enabled('demographics') && DemographicFields::options()) : $demoRequired = RegistrationConfig::required('demographics'); ?>
            <fieldset class="bg-fieldset" aria-describedby="bg-demo-requirement">
              <legend><?php echo esc_html(RegistrationWording::text('registration_demographics_legend', 'Demographic information')); ?> <span class="<?php echo $demoRequired ? 'bg-required' : 'bg-muted'; ?>"><?php echo esc_html($demoRequired ? RegistrationWording::text('registration_required_label', '(required)') : RegistrationWording::text('registration_optional_label', '(optional)')); ?></span></legend>
              <p class="bg-muted bg-demo-purpose"><?php echo esc_html(bg_str('demographic_purpose_statement')); ?></p>
              <?php if ($demoCollapsed) : ?>
                <div class="bg-onfile">
                  <span aria-hidden="true">&#10003;</span> <?php echo esc_html(bg_str('demographic_on_file_notice')); ?>
                  <button type="button" class="bg-link" id="bg-demo-expand"><?php echo esc_html(RegistrationWording::text('registration_review_update', 'Review or update')); ?></button>
                </div>
              <?php endif; ?>
              <div id="bg-demo-fields" <?php echo $demoCollapsed ? 'hidden' : ''; ?>>
                <p class="bg-muted" id="bg-demo-requirement"><?php echo esc_html(bg_str('demographic_identify_prompt', 'Do you identify as any of the following? Select all that apply.')); ?></p>
                <div class="bg-demo-grid">
                  <?php foreach (DemographicFields::options() as $opt) :
                      $checked = $showDemoValues && in_array($opt, $prefill['demographics'], true); ?>
                    <label class="bg-check">
                      <input type="checkbox" name="demographics[]" value="<?php echo esc_attr($opt); ?>" <?php checked($checked); ?>>
                      <?php echo esc_html(RegistrationWording::demographicLabel($opt)); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php $showOther = $showDemoValues && in_array(DemographicFields::OTHER_KEY, $prefill['demographics'], true); ?>
                <label id="bg-demo-other" <?php echo $showOther ? '' : 'hidden'; ?>><?php echo esc_html(RegistrationWording::text('registration_please_specify', 'Please specify')); ?>
                  <input type="text" name="demographics_other" maxlength="190"
                    value="<?php echo esc_attr($showOther ? (string) ($prefill['demographics_other'] ?? '') : ''); ?>">
                </label>
              </div>
            </fieldset>
            <?php endif; ?>

            <fieldset class="bg-fieldset" id="newsletter-subscriptions">
              <legend><?php echo esc_html(RegistrationWording::text('registration_subscriptions_legend', 'Newsletter subscriptions')); ?> <span class="bg-muted"><?php echo esc_html(RegistrationWording::text('registration_optional_label', '(optional)')); ?></span></legend>
              <?php if ($subscriptionGroups) : ?>
                <input type="hidden" name="subscriptions_present" value="1">
                <p class="bg-muted"><?php echo $canManageSubscriptions
                    ? esc_html(RegistrationWording::text('registration_subscriptions_verified_help', 'These boxes show your current subscriptions. Check a newsletter to subscribe or clear it to unsubscribe.'))
                    : esc_html(RegistrationWording::text('registration_subscriptions_public_help', 'Check any newsletter you want to receive. We will email confirmation links for new choices. Existing choices stay private, and unchecked boxes on this form will not cancel an existing subscription.')); ?></p>
                <div class="bg-subscription-grid">
                  <?php foreach ($subscriptionGroups as $group) : $groupId = (int) $group->id; ?>
                    <label class="bg-check bg-subscription-option">
                      <input type="checkbox" name="subscriptions[]" value="<?php echo $groupId; ?>" <?php checked(isset($activeSubscriptionIds[$groupId])); ?>>
                      <span><strong><?php echo esc_html((string) $group->name); ?></strong><?php if (trim((string) ($group->description ?? '')) !== '') : ?><br><small><?php echo esc_html((string) $group->description); ?></small><?php endif; ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php else : ?>
                <p class="bg-muted"><?php echo esc_html(RegistrationWording::text('registration_subscriptions_unavailable', 'Newsletter choices are temporarily unavailable. You can still register for this event, and no existing subscription will be changed.')); ?></p>
              <?php endif; ?>
            </fieldset>

            <?php
            $askAge = RegistrationConfig::enabled('age') && SurveyFields::ageRanges();
            $askHeard = RegistrationConfig::enabled('heard') && SurveyFields::heardOptions();
            $askPromote = RegistrationConfig::enabled('promote') && SurveyFields::promoteOptions();
            $askRelationship = RegistrationConfig::enabled('relationship') && SurveyFields::relationshipOptions();
            $requiredMark = static function (string $key): string {
                return RegistrationConfig::required($key)
                    ? ''
                    : ' <span class="bg-muted">' . esc_html(RegistrationWording::text('registration_optional_label', '(optional)')) . '</span>';
            };
            ?>
            <?php if ($askAge || $askHeard || $askPromote || $askRelationship) : ?>
            <fieldset class="bg-fieldset">
              <legend><?php echo esc_html(RegistrationWording::text('registration_survey_legend', 'A few more questions')); ?></legend>
              <p class="bg-muted"><?php echo esc_html(bg_str('survey_purpose_note', 'These help {org} understand who its events reach.')); ?></p>

              <?php if ($askAge) : ?>
              <p class="bg-q"><strong><?php echo esc_html(RegistrationWording::text('registration_age_question', 'What is your age range?')); ?></strong><?php echo $requiredMark('age'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
              <div class="bg-demo-grid">
                <?php foreach (SurveyFields::ageRanges() as $opt) : ?>
                  <label class="bg-check">
                    <input type="radio" name="age_range" value="<?php echo esc_attr($opt); ?>" <?php echo RegistrationConfig::required('age') ? 'required' : ''; ?>
                      <?php checked($showDemoValues && $prefill['age_range'] === $opt); ?>>
                    <?php echo esc_html(RegistrationWording::ageLabel($opt)); ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <?php if ($askHeard) : ?>
              <p class="bg-q"><strong><?php echo esc_html(RegistrationWording::text('registration_heard_question', 'How did you hear about this event?')); ?></strong> <span class="bg-muted"><?php echo esc_html(RegistrationWording::text('registration_select_all_label', '(select all that apply)')); ?></span><?php echo $requiredMark('heard'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
              <div class="bg-demo-grid">
                <?php foreach (SurveyFields::heardOptions() as $opt) : ?>
                  <label class="bg-check">
                    <input type="checkbox" name="heard_about[]" value="<?php echo esc_attr($opt); ?>">
                    <?php echo esc_html(RegistrationWording::heardLabel($opt)); ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <label id="bg-heard-other" hidden><?php echo esc_html(RegistrationWording::text('registration_please_specify', 'Please specify')); ?>
                <input type="text" name="heard_other" maxlength="190">
              </label>
              <?php endif; ?>

              <?php if ($askPromote) : ?>
              <p class="bg-q"><strong><?php echo esc_html(RegistrationWording::text('registration_promote_question', 'Would you help spread the word about this event?')); ?></strong><?php echo $requiredMark('promote'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
              <select name="promote" <?php echo RegistrationConfig::required('promote') ? 'required' : ''; ?>>
                <option value=""><?php echo esc_html(RegistrationWording::text('registration_choose_one', 'Choose one...')); ?></option>
                <?php foreach (SurveyFields::promoteOptions() as $opt) : ?>
                  <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html(RegistrationWording::promoteLabel($opt)); ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>

              <?php if ($askRelationship) : ?>
              <p class="bg-q"><strong><?php echo esc_html(RegistrationWording::text('registration_relationship_question', 'What is your relationship status?')); ?></strong><?php echo $requiredMark('relationship'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
              <div class="bg-demo-grid">
                <?php foreach (SurveyFields::relationshipOptions() as $opt) : ?>
                  <label class="bg-check">
                    <input type="checkbox" name="relationship[]" value="<?php echo esc_attr($opt); ?>"
                      <?php checked($showDemoValues && in_array($opt, $prefill['relationship'], true)); ?>>
                    <?php echo esc_html(RegistrationWording::relationshipLabel($opt)); ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </fieldset>
            <?php endif; ?>

            <fieldset class="bg-fieldset">
              <legend><?php echo esc_html(RegistrationWording::text('registration_type_legend', 'Registration type')); ?></legend>
              <?php
              // Both established registration-page donation actions remain in
              // place, but they now converge on the one combined BosqueGrande.
              // The filter preserves an administrator-selected, published
              // [bg_donate] page while MonthlyDonationEnrollment validates it
              // and falls back to the canonical local route.
              $donorUrl = (string) apply_filters(
                  'bg_combined_donation_url',
                  MonthlyDonationEnrollment::url()
              );
              ?>
              <?php $isNonprofit = \BosqueGrande\Core\Organization::type() === 'nonprofit'; ?>
              <?php if ($donorUrl !== '' && $onlinePayment && $isNonprofit) : ?>
              <div class="bg-donor-cta">
                <div class="bg-donor-buttons">
                  <a class="bg-donor-btn" href="<?php echo esc_url($donorUrl); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html(bg_str('become_donor_button', 'Become a Monthly Donor')); ?>
                  </a>
                  <a class="bg-donor-btn bg-donor-btn-additional" href="<?php echo esc_url($donorUrl); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html(bg_str('additional_donation_button', 'Donate An Amount You Choose')); ?>
                  </a>
                </div>
                <p class="bg-donor-note"><?php echo esc_html(bg_str('additional_donation_note')); ?></p>
              </div>
              <?php endif; ?>
              <?php $eventTiers = Tiers::forEvent($eventPostId); $hasPaidTier = false; ?>
              <h4 class="bg-fees-heading"><?php echo esc_html(bg_str('fees_heading', 'Registration options')); ?></h4>
              <div class="bg-tiers">
                <?php foreach ($eventTiers as $key => $tier) :
                    $locked = $gateEnforced && $tier['gated'] && !$isMember;
                    $paidPending = !Tiers::isFree($key);
                    if ($paidPending) $hasPaidTier = true; ?>
                  <label class="bg-tier <?php echo $locked ? 'bg-tier-locked' : ''; ?>">
                    <input type="radio" name="tier" value="<?php echo esc_attr($key); ?>" data-volunteer="<?php echo !empty($tier['volunteer_chain']) ? '1' : '0'; ?>" <?php disabled($locked); ?> <?php checked(count($eventTiers) === 1 && !$locked); ?> required>
                    <span class="bg-tier-label"><?php echo esc_html($tier['label']); ?></span>
                    <?php if ($locked) : ?>
                      <span class="bg-muted"><?php echo esc_html(RegistrationWording::text('registration_monthly_donor_locked', 'Monthly Donors: verify your email above (Email me a sign-in link) to unlock this selection.')); ?></span>
                    <?php elseif ($paidPending && !$onlinePayment) : ?>
                      <span class="bg-muted" data-bg-paid-note><?php echo esc_html(RegistrationWording::text('registration_offline_payment_note', 'Online payment is not set up yet; the organizer will follow up about payment.')); ?></span>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php if ($hasPaidTier && $isNonprofit) : ?>
              <p class="bg-muted bg-deduct-note"><?php echo esc_html(bg_str('fees_deductibility_note')); ?></p>
              <?php endif; ?>
              <?php if ($onlinePayment) : ?>
                <?php echo PaymentTrust::compact(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <?php endif; ?>
              <label class="bg-check" id="bg-volunteer" hidden>
                <input type="checkbox" name="volunteer_interest" value="1">
                <?php echo esc_html(RegistrationWording::text('registration_volunteer_interest', "I'm interested in volunteering at this event (we'll contact you about it).")); ?>
              </label>
            </fieldset>

            <button type="submit" class="bg-btn" id="bg-submit"><?php echo esc_html(RegistrationWording::text('registration_submit_button', 'Complete registration')); ?></button>
            <p id="bg-reg-msg" role="status"></p>
          </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function calendarReturnUrl(): string
    {
        $calendar = function_exists('bgev_public_calendar_page_url') ? bgev_public_calendar_page_url() : '';
        $fallback = (string) apply_filters('bg_calendar_return_url', $calendar !== '' ? $calendar : home_url('/'));
        $candidate = isset($_GET['bg_return'])
            ? esc_url_raw(wp_unslash((string) $_GET['bg_return']))
            : '';
        return remove_query_arg('bg_return', wp_validate_redirect($candidate, $fallback));
    }

}
