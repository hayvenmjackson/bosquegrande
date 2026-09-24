<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Constituents\CoreFields;
use BosqueGrande\Modules\Profile\MonthlyDonationCancellation;

if (!defined('ABSPATH')) exit;

/**
 * One reusable donation OneForm. A donor explicitly chooses one-time or
 * monthly giving; the two choices retain their independently verified Stripe
 * settlement paths and converge only in the public presentation.
 */
final class MonthlyDonationEnrollment
{
    private const QUERY_VAR = 'bg_donation';
    private const LEGACY_QUERY_VAR = 'bg_monthly_donation';
    private const PRETTY_PATH = '/bg-donation/';
    private const LEGACY_PRETTY_PATH = '/bg-monthly-donation/';
    private const REWRITE_OPTION = 'bg_monthly_rewrite_contract';
    private const REWRITE_CONTRACT = '3';
    private const NONCE_ACTION = 'bg_monthly_donation';
    private const ENROLLMENT_MARKER = 'monthly_oneform_v1';
    private const PROCESSED_OPTION = 'bg_monthly_checkout_settled';

    private const LEVELS_OPTION = 'bg_monthly_levels';

    /**
     * Suggested monthly amounts, edited under Settings. Keys are stable so
     * a completed Stripe session can be matched back to its level.
     *
     * @return array<string, array{amount: int, label: string}>
     */
    public static function levels(): array
    {
        $saved = get_option(self::LEVELS_OPTION, null);
        if (!is_array($saved) || !$saved) {
            return [
                'monthly_1000' => ['amount' => 1000, 'label' => ''],
                'monthly_2500' => ['amount' => 2500, 'label' => ''],
                'monthly_5000' => ['amount' => 5000, 'label' => ''],
                'monthly_10000' => ['amount' => 10000, 'label' => ''],
            ];
        }
        $out = [];
        foreach ($saved as $key => $level) {
            if (!is_array($level)) continue;
            $amount = (int) ($level['amount'] ?? 0);
            $key = sanitize_key((string) $key);
            if ($key === '' || $amount < 100 || $amount > 10000000) continue;
            $out[$key] = ['amount' => $amount, 'label' => sanitize_text_field((string) ($level['label'] ?? ''))];
        }
        return $out;
    }

    /** Parse "25 = Supporter" lines (amount in dollars, optional label). */
    public static function saveLevels(string $text): void
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$amount, $label] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            $amount = str_replace([',', '$', ' ', \BosqueGrande\Core\Money::symbol()], '', $amount);
            if (!is_numeric($amount)) continue;
            $cents = (int) round(((float) $amount) * 100);
            if ($cents < 100 || $cents > 10000000) continue;
            $out['monthly_' . $cents] = ['amount' => $cents, 'label' => bg_substr(sanitize_text_field($label), 0, 80)];
        }
        update_option(self::LEVELS_OPTION, array_slice($out, 0, 12, true), false);
    }

    /** The saved levels as editable text. */
    public static function levelsText(): string
    {
        $lines = [];
        foreach (self::levels() as $level) {
            $amount = rtrim(rtrim(number_format($level['amount'] / 100, 2, '.', ''), '0'), '.');
            $lines[] = $amount . ($level['label'] !== '' ? ' = ' . $level['label'] : '');
        }
        return implode("\n", $lines);
    }

    private static function defaultLevelKey(): string
    {
        $keys = array_keys(self::levels());
        return (string) ($keys[min(1, max(0, count($keys) - 1))] ?? '');
    }

    public function __construct(private Container $container)
    {
    }

    public static function url(): string
    {
        // Direct path recognition happens before WordPress's canonical/404
        // handling, so this readable URL does not depend on a fresh rewrite
        // table. Query-var and former monthly-only routes remain aliases.
        return home_url(self::PRETTY_PATH);
    }

    /**
     * An editable CTA destination is valid only when it is a published local
     * page that actually embeds the combined [bg_donate] component.
     */
    public static function combinedDonationUrl(string $candidate): string
    {
        $canonical = self::url();
        $candidate = wp_validate_redirect($candidate, '');
        if ($candidate === '' || untrailingslashit($candidate) === untrailingslashit($canonical)) {
            return $canonical;
        }
        $pageId = url_to_postid($candidate);
        $page = $pageId > 0 ? get_post($pageId) : null;
        if (!$page instanceof \WP_Post
            || $page->post_type !== 'page'
            || $page->post_status !== 'publish'
            || !has_shortcode((string) $page->post_content, 'bg_donate')
        ) return $canonical;
        return (string) get_permalink($page);
    }

    /** @param array<string, string>|string $atts */
    public function combinedShortcode($atts): string
    {
        return $this->renderForm(is_array($atts) ? $atts : []);
    }

    /** @return list<array{key:string,amount:int,label:string}> */
    public static function organizerOptions(): array
    {
        $options = [];
        foreach (self::levels() as $key => $tier) {
            $options[] = [
                'key' => (string) $key,
                'amount' => (int) $tier['amount'],
                'label' => self::publicTierLabel((string) $key, $tier),
            ];
        }
        return $options;
    }

    /** @param array<string, mixed> $session */
    public static function recognizes(array $session): bool
    {
        return (string) ($session['metadata']['bg_enrollment'] ?? '') === self::ENROLLMENT_MARKER;
    }

    /** @param array<string, mixed> $invoice */
    public static function recognizesPaidInvoice(array $invoice): bool
    {
        $context = self::invoiceSubscriptionContext($invoice);
        return (string) ($invoice['object'] ?? '') === 'invoice'
            && preg_match('/^sub_[A-Za-z0-9]+$/', $context['subscription_id']) === 1
            && (string) ($context['metadata']['bg_enrollment'] ?? '') === self::ENROLLMENT_MARKER;
    }

    /**
     * Settle one signature-verified Stripe invoice event into the accounting ledger.
     * Only an invoice carrying the exact BosqueGrande subscription marker is in
     * scope. The Stripe invoice id is the durable idempotency boundary shared
     * with Checkout-session settlement and later reconciliation runs.
     *
     * @param array<string, mixed> $invoice
     * @return array{recognized:bool,ok:bool,message:string,payment_id?:int,new?:bool}
     */
    public function settlePaidInvoice(array $invoice): array
    {
        if (!self::recognizesPaidInvoice($invoice)) {
            return ['recognized' => false, 'ok' => true, 'message' => 'Unrelated Stripe invoice ignored.'];
        }

        $invoiceId = self::stripeId($invoice['id'] ?? '', 'in_');
        $amountCents = (int) ($invoice['amount_paid'] ?? 0);
        // Stripe removed Invoice.paid in the 2025-03-31 Basil API. Current
        // payloads prove payment with status=paid plus amount_paid; when an
        // older payload still supplies the legacy boolean, explicit false
        // remains a refusal.
        $legacyPaidRefused = array_key_exists('paid', $invoice) && $invoice['paid'] !== true;
        if ($invoiceId === '' || $legacyPaidRefused
            || (string) ($invoice['status'] ?? '') !== 'paid'
            || $amountCents <= 0
            || !\BosqueGrande\Core\Money::matches((string) ($invoice['currency'] ?? ''))
        ) {
            return [
                'recognized' => true,
                'ok' => false,
                'message' => 'The monthly invoice was not a positive paid invoice in the site currency.',
            ];
        }

        $context = self::invoiceSubscriptionContext($invoice);
        $constituentId = $this->resolveInvoiceConstituent($invoice, $context['metadata']);
        $paymentIntent = self::invoicePaymentIntent($invoice);
        $createdAtUtc = self::stripePaidAtUtc($invoice);
        if (!$this->container->has('revenue.ledger')) {
            return [
                'recognized' => true,
                'ok' => false,
                'message' => 'The BosqueGrande accounting ledger is unavailable.',
            ];
        }

        $ledger = $this->container->get('revenue.ledger');
        $wasPresent = $ledger->idByExternalKey('stripe-invoice:' . $invoiceId) > 0;
        $paymentId = $ledger->ensureMonthlyDonation(
            $invoiceId,
            $amountCents,
            $constituentId,
            $paymentIntent,
            $createdAtUtc
        );
        if ($paymentId < 1) {
            return [
                'recognized' => true,
                'ok' => false,
                'message' => 'The paid monthly invoice could not be written to the accounting ledger.',
            ];
        }

        $this->container->get('logger')->info('payments', 'Monthly Stripe invoice reconciled to the accounting ledger.', [
            'stripe_invoice' => $invoiceId,
            'payment_id' => $paymentId,
            'constituent' => $constituentId,
            'amount_cents' => $amountCents,
        ]);
        return [
            'recognized' => true,
            'ok' => true,
            'message' => 'Monthly donation recorded.',
            'payment_id' => $paymentId,
            'new' => !$wasPresent,
        ];
    }

    /**
     * Administrator recovery path for missed webhook delivery. It first
     * revisits recent Checkout Sessions already marked processed locally, then
     * scans a bounded Stripe paid-invoice window. Both routes converge on the
     * same invoice id and are safe to repeat.
     *
     * @return array{ok:bool,checkout_scanned:int,invoice_scanned:int,recorded:int,already_present:int,ignored:int,errors:int,message:string}
     */
    public function reconcileRecentPayments(int $days = 120, int $limit = 300): array
    {
        $result = [
            'ok' => false,
            'checkout_scanned' => 0,
            'invoice_scanned' => 0,
            'recorded' => 0,
            'already_present' => 0,
            'ignored' => 0,
            'errors' => 0,
            'message' => '',
        ];
        if (!current_user_can('manage_options')) {
            $result['errors'] = 1;
            $result['message'] = 'Administrator permission is required.';
            return $result;
        }

        $days = max(1, min(3650, $days));
        $limit = max(1, min(300, $limit));
        $createdAfter = time() - ($days * DAY_IN_SECONDS);
        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        if (!$stripe->canUseApi()) {
            $result['errors'] = 1;
            $result['message'] = 'Stripe API access is unavailable.';
            return $result;
        }

        $processed = get_option(self::PROCESSED_OPTION, []);
        $sessions = [];
        if (is_array($processed)) {
            foreach ($processed as $sessionId => $state) {
                $settledAt = is_array($state) ? (int) ($state['settled_at'] ?? 0) : 0;
                if ($settledAt < $createdAfter
                    || !preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', (string) $sessionId)
                ) continue;
                $sessions[(string) $sessionId] = $settledAt;
            }
            arsort($sessions, SORT_NUMERIC);
        }

        // Preserve at least one invoice slot even for a tiny caller-supplied
        // limit. Fetch one bounded recent-session window and intersect it with
        // BG's stored processed ids; this avoids one Stripe call per id while
        // ensuring arbitrary Checkout Sessions are never imported.
        $sessionBudget = $limit > 1 ? min(100, $limit - 1) : 0;
        if ($sessionBudget > 0 && $sessions) {
            $recentSessions = $stripe->recentCompletedCheckoutSessions($createdAfter, $limit);
            if ($recentSessions === null) {
                $result['errors']++;
            } else {
                $sessionsById = [];
                foreach ($recentSessions as $session) {
                    $sessionId = (string) ($session['id'] ?? '');
                    if ($sessionId !== '' && isset($sessions[$sessionId])) {
                        $sessionsById[$sessionId] = $session;
                    }
                }
                foreach (array_slice(array_keys($sessions), 0, $sessionBudget) as $sessionId) {
                    $session = $sessionsById[$sessionId] ?? null;
                    if (!is_array($session)) continue;
                    $result['checkout_scanned']++;
                    $settled = $this->settleCompleted($session);
                    if (empty($settled['ok'])) {
                        $result['errors']++;
                        continue;
                    }
                    if (!empty($settled['new'])) $result['recorded']++;
                    else $result['already_present']++;
                }
            }
        }

        $invoiceBudget = max(1, $limit - $result['checkout_scanned']);
        $invoices = $stripe->recentPaidInvoices($createdAfter, $invoiceBudget);
        if ($invoices === null) {
            $result['errors']++;
        } else {
            foreach ($invoices as $invoice) {
                $result['invoice_scanned']++;
                if (!self::recognizesPaidInvoice($invoice)) {
                    $result['ignored']++;
                    continue;
                }
                $settled = $this->settlePaidInvoice($invoice);
                if (empty($settled['ok'])) {
                    $result['errors']++;
                    continue;
                }
                if (!empty($settled['new'])) $result['recorded']++;
                else $result['already_present']++;
            }
        }

        $result['ok'] = $result['errors'] === 0;
        $result['message'] = sprintf(
            'Reconciliation checked %d completed Checkout session(s) and %d paid invoice(s); %d new monthly payment(s) were recorded, %d were already present, %d unrelated invoice(s) were ignored, and %d error(s) occurred.',
            $result['checkout_scanned'],
            $result['invoice_scanned'],
            $result['recorded'],
            $result['already_present'],
            $result['ignored'],
            $result['errors']
        );
        return $result;
    }

    public function activate(): void
    {
        $this->addRewrite();
    }

    public function boot(): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        add_filter('bg_wording_defaults', [PaymentTrust::class, 'wordingDefaults']);
        add_filter('bg_combined_donation_url', [self::class, 'combinedDonationUrl'], 50);
        add_action('init', fn() => $this->addRewrite());
        add_action('init', function (): void {
            if ((string) get_option(self::REWRITE_OPTION, '') === self::REWRITE_CONTRACT) return;
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, self::REWRITE_CONTRACT, false);
        }, 99);

        add_filter('query_vars', function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            $vars[] = self::LEGACY_QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', function (): void {
            if (!$this->isEnrollmentRequest()) return;
            $this->renderPage();
            exit;
        }, 0);

        add_action('rest_api_init', function (): void {
            register_rest_route('bosquegrande/v1', '/monthly-donation/nonce', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => static function (): \WP_REST_Response {
                    nocache_headers();
                    return new \WP_REST_Response([
                        'ok' => true,
                        'nonce' => wp_create_nonce(self::NONCE_ACTION),
                    ], 200, ['Cache-Control' => 'no-store']);
                },
            ]);
            register_rest_route('bosquegrande/v1', '/monthly-donation/checkout', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => fn(\WP_REST_Request $request) => $this->createCheckout($request),
            ]);
            register_rest_route('bosquegrande/v1', '/monthly-donation/confirm', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => fn(\WP_REST_Request $request) => $this->confirmCheckout($request),
            ]);
        });

    }

    /**
     * Register every string introduced by the combined donation OneForm with
     * the shared Wording editor. Existing defaults supplied by the sitewide
     * wording catalog retain precedence.
     *
     * @param array<string, array{label:string,text:string}> $defaults
     * @return array<string, array{label:string,text:string}>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'donation_oneform_heading' => ['Donation form: heading', 'Make a Donation'],
            'donation_document_title' => ['Donation page: browser title', 'Donate'],
            'donation_skip_label' => ['Donation page: skip-link label', 'Skip to the donation form'],
            'donation_pitch_intro' => ['Donation form: introduction', 'Your gift keeps {org} going. Giving monthly is one of the most helpful ways to support our work.'],
            'donation_pitch_lead' => ['Donation form: benefits heading (shown only when at least one benefit below is filled in)', 'As a monthly donor you will:'],
            'donation_pitch_free_events' => ['Donation form: first benefit (blank hides it)', ''],
            'donation_pitch_discounts' => ['Donation form: second benefit (blank hides it)', ''],
            'donation_pitch_incentive' => ['Donation form: third benefit (blank hides it)', ''],
            'donation_frequency_legend' => ['Donation form: frequency heading', 'Donation frequency'],
            'donation_frequency_monthly' => ['Donation form: monthly choice', 'Monthly donation'],
            'donation_frequency_one_time' => ['Donation form: one-time choice', 'One-time donation'],
            'donation_information_legend' => ['Donation form: donor-information heading', 'Your information'],
            'donation_first_name_label' => ['Donation form: first-name label', 'First name'],
            'donation_last_name_label' => ['Donation form: last-name label', 'Last name'],
            'donation_email_label' => ['Donation form: email label', 'Email'],
            'donation_monthly_amount_legend' => ['Donation form: monthly-amount heading', 'Monthly amount'],
            'donation_one_time_amount_legend' => ['Donation form: one-time-amount heading', 'One-time amount'],
            'donation_other_monthly_amount' => ['Donation form: custom monthly amount choice', 'Other monthly amount'],
            'donation_other_one_time_amount' => ['Donation form: custom one-time amount choice', 'Other one-time amount'],
            'donation_custom_monthly_aria' => ['Donation form: custom monthly amount accessible label', 'Custom monthly amount'],
            'donation_custom_one_time_aria' => ['Donation form: custom one-time amount accessible label', 'Custom one-time amount'],
            'donation_monthly_submit' => ['Donation form: monthly continue button', 'Continue to Secure Stripe Checkout'],
            'donation_one_time_submit' => ['Donation form: one-time continue button', 'Continue with one-time donation'],
            'donation_complete_one_time_button' => ['Donation form: complete one-time payment button', 'Complete donation securely'],
            'donation_monthly_confirmation_note' => ['Donation form: monthly confirmation note', 'No monthly donation begins unless Stripe confirms the checkout. You can return without completing it.'],
            'donation_one_time_confirmation_note' => ['Donation form: one-time confirmation note', 'No one-time donation is recorded unless Stripe confirms the payment.'],
            'donation_change_details_note' => ['Donation form: change-details note', 'To change the amount or contact information after secure card entry opens, reload this form and begin again.'],
            'donation_one_time_trust_intro' => ['Donation form: one-time Stripe security introduction', 'After you choose an amount and enter receipt contact information, Stripe’s secure card-entry fields will appear here.'],
            'donation_monthly_unavailable' => ['Donation form: monthly unavailable message', 'Monthly enrollment is temporarily unavailable. Please contact {org}.'],
            'donation_one_time_unavailable' => ['Donation form: one-time unavailable message', 'One-time online donations are temporarily unavailable. Please contact {org}.'],
            'donation_cancelled_notice' => ['Donation form: cancelled-checkout notice', 'No donation was started. You can choose an amount and try again whenever you are ready.'],
            'donation_monthly_success_heading' => ['Donation form: monthly success heading', 'Thank you for becoming a Monthly Donor'],
            'donation_monthly_verifying' => ['Donation form: monthly verification message', 'Stripe received your enrollment. We are verifying the monthly donation now.'],
            'donation_return_button' => ['Donation form: return button', 'Return to the donation form'],
            'donation_invalid_frequency' => ['Donation form: invalid-frequency error', 'Choose a valid donation frequency.'],
            'donation_form_expired' => ['Donation form: expired-form error', 'This form expired. Please reload it and try again.'],
            'donation_form_review' => ['Donation form: automated-review error', 'Please review the form and try again.'],
            'donation_checkout_rate_limit' => ['Donation form: checkout rate-limit error', 'Too many checkout attempts were made. Please wait a few minutes and try again.'],
            'donation_contact_invalid' => ['Donation form: invalid contact-information error', 'Enter your first name, last name, and a valid email address.'],
            'donation_monthly_amount_invalid' => ['Donation form: invalid monthly-amount error (keep {min_amount} and {max_amount})', 'Choose one of the monthly amounts, or enter a custom amount from {min_amount} through {max_amount}.'],
            'donation_checkout_start_failed' => ['Donation form: Stripe checkout start error', 'Stripe could not start the secure checkout. Please try again shortly.'],
            'donation_enrollment_not_verified' => ['Donation form: enrollment verification error', 'Stripe could not verify this enrollment yet. Please refresh the page in a moment.'],
            'donation_verification_rate_limit' => ['Donation form: verification rate-limit error', 'Too many verification attempts. Please wait briefly and try again.'],
            'donation_monthly_verifying_fallback' => ['Donation form: monthly verification fallback', 'Your monthly donation is being verified.'],
            'donation_monthly_reconcile_fallback' => ['Donation form: monthly reconciliation fallback', 'Stripe received the enrollment. We will finish updating your status automatically.'],
            'donation_choose_frequency' => ['Donation form: choose-frequency error', 'Choose monthly or one-time donation.'],
            'donation_choose_monthly_amount' => ['Donation form: choose-monthly-amount error', 'Choose a monthly amount.'],
            'donation_enter_custom_monthly' => ['Donation form: invalid custom monthly amount (keep {min_amount} and {max_amount})', 'Enter a custom monthly amount from {min_amount} through {max_amount}.'],
            'donation_choose_one_time_amount' => ['Donation form: choose-one-time-amount error', 'Choose a one-time amount.'],
            'donation_enter_custom_one_time' => ['Donation form: invalid custom one-time amount (keep {min_amount} and {max_amount})', 'Enter a custom one-time amount from {min_amount} through {max_amount}.'],
            'donation_opening_checkout' => ['Donation form: opening monthly checkout status', 'Opening secure Stripe checkout...'],
            'donation_checkout_failed' => ['Donation form: monthly checkout failure', 'Stripe checkout could not be started. Please try again.'],
            'donation_opening_payment_fields' => ['Donation form: opening one-time card fields status', 'Opening secure Stripe payment fields...'],
            'donation_generic_error' => ['Donation form: general failure', 'Something went wrong. Please try again.'],
            'donation_processing_payment' => ['Donation form: processing one-time payment status', 'Processing securely with Stripe...'],
            'donation_payment_failed' => ['Donation form: one-time payment failure', 'Payment failed.'],
            'donation_payment_processing' => ['Donation form: one-time payment processing status', 'Payment is processing; please keep this page open.'],
            'donation_received_reconcile' => ['Donation form: received pending reconciliation status', 'Payment was received; the organizer will reconcile it.'],
            'donation_one_time_thanks' => ['Donation form: one-time success message', 'Thank you! A receipt is on its way to your email.'],
            'donation_one_time_amount_range' => ['Donation form: one-time amount range error (keep {min_amount} and {max_amount})', 'Amounts between {min_amount} and {max_amount}, please.'],
            'donation_one_time_email_invalid' => ['Donation form: invalid one-time receipt email', 'Please enter a valid email address.'],
            'donation_one_time_rate_limit' => ['Donation form: one-time attempt rate limit', 'Too many attempts; try again in a few minutes.'],
            'donation_payment_service_unavailable' => ['Donation form: payment service unavailable', 'The payment service is unavailable right now.'],
            'donation_payment_not_verified' => ['Donation form: one-time payment verification message', 'Payment could not yet be verified.'],
            'donation_payment_received' => ['Donation form: one-time payment received message', 'Payment received.'],
            'donation_payment_reconciliation_failed' => ['Donation form: one-time reconciliation failure', 'Stripe received the payment, but we could not finish local reconciliation. Our team has been notified.'],
            'donation_monthly_not_completed' => ['Donation verification: incomplete Stripe enrollment', 'Stripe has not confirmed a completed monthly enrollment.'],
            'donation_monthly_amount_mismatch' => ['Donation verification: Stripe amount mismatch', 'The completed Stripe amount did not match one of the monthly-donation options.'],
            'donation_monthly_email_mismatch' => ['Donation verification: Stripe email mismatch', 'The completed Stripe enrollment did not match the submitted email address.'],
            'donation_monthly_active' => ['Donation verification: active monthly-donation result', 'Your monthly donation is active. Thank you!'],
            'donation_monthly_reconciling' => ['Donation verification: reconciliation-in-progress result', 'This completed enrollment is already being reconciled. Please refresh in a moment.'],
            'donation_monthly_constituent_store_failed' => ['Donation verification: constituent-store failure', 'We could not safely update the constituent record. Our team has been notified.'],
            'donation_monthly_constituent_match_failed' => ['Donation verification: constituent-match failure', 'We could not identify a constituent record for this completed enrollment. Our team has been notified.'],
            'donation_monthly_status_failed' => ['Donation verification: donor-status save failure', 'The donation is active in Stripe, but we could not save the monthly-donor status. Our team has been notified.'],
            'donation_monthly_binding_failed' => ['Donation verification: permanent-ID binding failure', 'The donation is active in Stripe, but its permanent record ID could not be attached yet. Our team has been notified.'],
            'donation_monthly_lgl_schedule_failed' => ['Donation verification: LGL reconciliation scheduling failure', 'The donation is active in Stripe, but we could not schedule the required LGL reconciliation. Our team has been notified.'],
            'donation_monthly_marker_failed' => ['Donation verification: reconciliation-marker failure', 'The donation is active in Stripe, but we could not save its reconciliation marker. Our team has been notified.'],
            'donation_monthly_invoice_failed' => ['Donation verification: paid-invoice identification failure', 'Stripe confirmed the monthly enrollment, but its paid invoice could not be identified for the accounting ledger.'],
            'donation_monthly_ledger_unavailable' => ['Donation verification: accounting-ledger unavailable', 'The BosqueGrande accounting ledger is unavailable.'],
            'donation_monthly_ledger_write_failed' => ['Donation verification: accounting-row failure', 'Stripe received the monthly donation, but we could not write its accounting row. Our team has been notified.'],
            'donation_monthly_recorded' => ['Donation verification: accounting success', 'Monthly donation recorded.'],
            'donation_custom_monthly_descriptor' => ['Donation form: custom monthly Stripe descriptor', 'custom monthly donation'],
        ];
        $requiredTokens = [
            'donation_monthly_amount_invalid' => ['min_amount', 'max_amount'],
            'donation_enter_custom_monthly' => ['min_amount', 'max_amount'],
            'donation_enter_custom_one_time' => ['min_amount', 'max_amount'],
            'donation_one_time_amount_range' => ['min_amount', 'max_amount'],
        ];
        foreach ($items as $key => [$label, $text]) {
            if (isset($defaults[$key])) continue;
            $defaults[$key] = [
                'label' => $label,
                'text' => $text,
                'group' => 'Donation form',
            ];
            if (isset($requiredTokens[$key])) {
                $defaults[$key]['required_tokens'] = $requiredTokens[$key];
            }
        }
        return $defaults;
    }

    private function addRewrite(): void
    {
        if (!isset($GLOBALS['wp_rewrite']) || !($GLOBALS['wp_rewrite'] instanceof \WP_Rewrite)) return;
        add_rewrite_rule('^bg-donation/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^bg-monthly-donation/?$', 'index.php?' . self::LEGACY_QUERY_VAR . '=1', 'top');
    }

    /**
     * Accept both the rewrite-independent URL emitted by current buttons and
     * the original pretty path. Direct path detection runs before WordPress's
     * canonical redirect and theme 404 template, so stale rewrite tables no
     * longer make the OneForm disappear.
     */
    private function isEnrollmentRequest(): bool
    {
        if ((int) get_query_var(self::QUERY_VAR) === 1
            || (int) ($_GET[self::QUERY_VAR] ?? 0) === 1
            || (int) get_query_var(self::LEGACY_QUERY_VAR) === 1
            || (int) ($_GET[self::LEGACY_QUERY_VAR] ?? 0) === 1
        ) return true;

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? wp_unslash((string) $_SERVER['REQUEST_URI'])
            : '';
        $requestPath = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        $prettyPaths = [
            (string) wp_parse_url(home_url(self::PRETTY_PATH), PHP_URL_PATH),
            (string) wp_parse_url(home_url(self::LEGACY_PRETTY_PATH), PHP_URL_PATH),
        ];
        if ($requestPath === '') return false;
        foreach ($prettyPaths as $prettyPath) {
            if (untrailingslashit($requestPath) === untrailingslashit($prettyPath)) return true;
        }
        return false;
    }

    private function renderPage(): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('X-Robots-Tag: noindex, nofollow', true);
        add_filter('pre_get_document_title', static fn(): string => self::wording(
            'donation_document_title',
            'Donate'
        ), PHP_INT_MAX);

        $content = '<div class="bg-monthly-wrap">' . $this->renderForm([]) . '</div>';
        if (function_exists('bgev_output_owned_document')) {
            bgev_output_owned_document($content, [
                'context' => 'donation',
                'body_class' => 'bg-donation-app-page',
                'main_id' => 'bg-donation-app-main',
                'skip_label' => self::wording('donation_skip_label', 'Skip to the donation form'),
                'no_cache' => true,
            ]);
            return;
        }
        if (function_exists('bgev_public_app_shell')) {
            $content = bgev_public_app_shell($content, [
                'context' => 'donation',
                'main_id' => 'bg-donation-app-main',
                'skip_label' => self::wording('donation_skip_label', 'Skip to the donation form'),
            ]);
        }
        $this->renderFallbackDocument($content);
    }

    /** Backward-compatible fallback for installations missing the shared shell. */
    private function renderFallbackDocument(string $content): void
    {
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
          <meta charset="<?php bloginfo('charset'); ?>">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <?php if (!current_theme_supports('title-tag')): ?>
            <title><?php echo esc_html(wp_get_document_title()); ?></title>
          <?php endif; ?>
          <meta name="bosquegrande-shell-template" content="route-donation-fallback">
          <?php wp_head(); ?>
        </head>
        <body <?php body_class('bg-event-app-page bg-public-app-page bg-donation-app-page'); ?>>
        <?php wp_body_open(); ?>
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the OneForm/shared shell. ?>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    /** @param array<string, string> $atts */
    private function renderForm(array $atts): string
    {
        $checkout = sanitize_key((string) ($_GET['checkout'] ?? ''));
        $sessionId = sanitize_text_field((string) ($_GET['session_id'] ?? ''));
        if ($checkout === 'success' && preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $sessionId)) {
            $successId = 'bg-monthly-success-' . wp_rand(1000, 999999);
            return '<style>' . $this->css() . '</style><section class="bg-monthly-card" id="'
                . esc_attr($successId) . '" data-bg-monthly-success data-session="'
                . esc_attr($sessionId) . '"><h1>'
                . esc_html(self::wording('donation_monthly_success_heading', 'Thank you for becoming a Monthly Donor'))
                . '</h1><p data-bg-monthly-result role="status">'
                . esc_html(self::wording('donation_monthly_verifying', 'Stripe received your enrollment. We are verifying the monthly donation now.'))
                . '</p><p><a class="bg-monthly-secondary" href="' . esc_url(self::url()) . '">'
                . esc_html(self::wording('donation_return_button', 'Return to the donation form'))
                . '</a></p></section><script>' . $this->js('', $successId) . '</script>';
        }

        $atts = shortcode_atts([
            'amounts' => '10,25,50,100',
            'label' => self::wording('donation_one_time_submit', 'Continue with one-time donation'),
        ], $atts, 'bg_donate');
        $oneTimeAmounts = $this->oneTimeAmounts((string) $atts['amounts']);
        $prefill = $this->verifiedPrefill();
        $cancelled = $checkout === 'cancelled'
            ? '<p class="bg-monthly-notice">' . esc_html(self::wording(
                'donation_cancelled_notice',
                'No donation was started. You can choose an amount and try again whenever you are ready.'
            )) . '</p>'
            : '';
        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        $monthlyAvailable = $stripe->canUseApi();
        $oneTimeAvailable = $stripe->isConfigured();
        $defaultCadence = $monthlyAvailable ? 'monthly' : ($oneTimeAvailable ? 'one_time' : '');
        $formId = 'bg-donation-oneform-' . wp_rand(1000, 999999);
        $requestId = self::newRequestId();
        $monthlyPanelId = $formId . '-monthly';
        $oneTimePanelId = $formId . '-one-time';
        $submitDisabled = !$monthlyAvailable && !$oneTimeAvailable;

        ob_start();
        ?>
        <style><?php echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
        <section class="bg-monthly-card bg-donation-card">
          <h1><?php echo esc_html(self::wording('donation_oneform_heading', 'Make a Donation')); ?></h1>
          <div class="bg-donation-pitch">
            <p><?php echo esc_html(self::wording(
                'donation_pitch_intro',
                'Your gift keeps {org} going. Giving monthly is one of the most helpful ways to support our work.'
            )); ?></p>
            <?php $benefits = array_values(array_filter(array_map('trim', [
                self::wording('donation_pitch_free_events', ''),
                self::wording('donation_pitch_discounts', ''),
                self::wording('donation_pitch_incentive', ''),
            ]))); ?>
            <?php if ($benefits): ?>
            <p><strong><?php echo esc_html(self::wording('donation_pitch_lead', 'As a monthly donor you will:')); ?></strong></p>
            <ul>
              <?php foreach ($benefits as $benefit): ?><li><?php echo esc_html($benefit); ?></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
          <div data-bg-trust="monthly" <?php echo $defaultCadence === 'monthly' ? '' : 'hidden'; ?>>
            <?php echo PaymentTrust::hostedCheckout(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
          <div data-bg-trust="one_time" <?php echo $defaultCadence === 'one_time' ? '' : 'hidden'; ?>>
            <?php echo PaymentTrust::embedded(self::wording('donation_one_time_trust_intro', 'After you choose an amount and enter receipt contact information, Stripe’s secure card-entry fields will appear here.')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
          <?php echo $cancelled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <?php if (!$monthlyAvailable): ?>
            <p class="bg-monthly-error"><?php echo esc_html(self::wording('donation_monthly_unavailable', 'Monthly enrollment is temporarily unavailable. Please contact {org}.')); ?></p>
          <?php endif; ?>
          <?php if (!$oneTimeAvailable): ?>
            <p class="bg-monthly-error"><?php echo esc_html(self::wording('donation_one_time_unavailable', 'One-time online donations are temporarily unavailable. Please contact {org}.')); ?></p>
          <?php endif; ?>
          <form id="<?php echo esc_attr($formId); ?>" class="bg-combined-donation" novalidate
            data-publishable-key="<?php echo esc_attr($stripe->publishableKey()); ?>"
            data-monthly-label="<?php echo esc_attr(self::wording('donation_monthly_submit', 'Continue to Secure Stripe Checkout')); ?>"
            data-one-time-label="<?php echo esc_attr((string) $atts['label']); ?>">
            <input type="hidden" name="request_id" value="<?php echo esc_attr($requestId); ?>">
            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
            <input type="hidden" name="ts" value="<?php echo (int) time(); ?>">
            <input class="bg-monthly-hp" type="text" name="bg_company_7c4" tabindex="-1" autocomplete="off" aria-hidden="true">
            <input class="bg-monthly-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <fieldset>
              <legend><?php echo esc_html(self::wording('donation_frequency_legend', 'Donation frequency')); ?></legend>
              <div class="bg-donation-cadence">
                <label class="bg-monthly-tier">
                  <input type="radio" name="cadence" value="monthly" aria-controls="<?php echo esc_attr($monthlyPanelId); ?>"
                    <?php checked($defaultCadence, 'monthly'); ?> <?php disabled(!$monthlyAvailable); ?>>
                  <span><?php echo esc_html(self::wording('donation_frequency_monthly', 'Monthly donation')); ?></span>
                </label>
                <label class="bg-monthly-tier">
                  <input type="radio" name="cadence" value="one_time" aria-controls="<?php echo esc_attr($oneTimePanelId); ?>"
                    <?php checked($defaultCadence, 'one_time'); ?> <?php disabled(!$oneTimeAvailable); ?>>
                  <span><?php echo esc_html(self::wording('donation_frequency_one_time', 'One-time donation')); ?></span>
                </label>
              </div>
            </fieldset>
            <fieldset>
              <legend><?php echo esc_html(self::wording('donation_information_legend', 'Your information')); ?></legend>
              <div class="bg-monthly-grid">
                <label><?php echo esc_html(self::wording('donation_first_name_label', 'First name')); ?><input type="text" name="first_name" autocomplete="given-name" required value="<?php echo esc_attr($prefill['first_name']); ?>"></label>
                <label><?php echo esc_html(self::wording('donation_last_name_label', 'Last name')); ?><input type="text" name="last_name" autocomplete="family-name" required value="<?php echo esc_attr($prefill['last_name']); ?>"></label>
                <label class="bg-monthly-wide"><?php echo esc_html(self::wording('donation_email_label', 'Email')); ?><input type="email" name="email" autocomplete="email" required value="<?php echo esc_attr($prefill['email']); ?>"></label>
              </div>
            </fieldset>
            <fieldset id="<?php echo esc_attr($monthlyPanelId); ?>" data-bg-frequency-panel="monthly" <?php echo $defaultCadence === 'monthly' ? '' : 'hidden'; ?>>
              <legend><?php echo esc_html(self::wording('donation_monthly_amount_legend', 'Monthly amount')); ?></legend>
              <div class="bg-monthly-tiers">
                <?php foreach (self::levels() as $key => $tier): ?>
                  <label class="bg-monthly-tier">
                    <input type="radio" name="tier" value="<?php echo esc_attr($key); ?>" required
                      <?php checked($key, self::defaultLevelKey()); ?> <?php disabled($defaultCadence !== 'monthly' || !$monthlyAvailable); ?>>
                    <span><?php echo esc_html(self::publicTierLabel((string) $key, $tier)); ?></span>
                  </label>
                <?php endforeach; ?>
                <label class="bg-monthly-tier bg-monthly-custom">
                  <input type="radio" name="tier" value="custom" required <?php disabled($defaultCadence !== 'monthly' || !$monthlyAvailable); ?>>
                  <span><?php echo esc_html(self::wording('donation_other_monthly_amount', 'Other monthly amount')); ?></span>
                  <span class="bg-monthly-money"><span><?php echo esc_html(\BosqueGrande\Core\Money::symbol()); ?></span><input type="number" name="custom_amount" min="1.00" max="100000.00" step="0.01" inputmode="decimal" aria-label="<?php echo esc_attr(self::wording('donation_custom_monthly_aria', 'Custom monthly amount')); ?>" <?php disabled($defaultCadence !== 'monthly' || !$monthlyAvailable); ?>></span>
                </label>
              </div>
            </fieldset>
            <fieldset id="<?php echo esc_attr($oneTimePanelId); ?>" data-bg-frequency-panel="one_time" <?php echo $defaultCadence === 'one_time' ? '' : 'hidden'; ?>>
              <legend><?php echo esc_html(self::wording('donation_one_time_amount_legend', 'One-time amount')); ?></legend>
              <div class="bg-monthly-tiers bg-one-time-tiers">
                <?php foreach ($oneTimeAmounts as $index => $amount): ?>
                  <label class="bg-monthly-tier">
                    <input type="radio" name="one_time_preset" value="<?php echo (int) ($amount * 100); ?>" required
                      <?php checked($index, min(1, count($oneTimeAmounts) - 1)); ?> <?php disabled($defaultCadence !== 'one_time' || !$oneTimeAvailable); ?>>
                    <span><?php echo esc_html(\BosqueGrande\Core\Money::format((int) round($amount * 100))); ?></span>
                  </label>
                <?php endforeach; ?>
                <label class="bg-monthly-tier bg-monthly-custom">
                  <input type="radio" name="one_time_preset" value="custom" required <?php disabled($defaultCadence !== 'one_time' || !$oneTimeAvailable); ?>>
                  <span><?php echo esc_html(self::wording('donation_other_one_time_amount', 'Other one-time amount')); ?></span>
                  <span class="bg-monthly-money"><span><?php echo esc_html(\BosqueGrande\Core\Money::symbol()); ?></span><input type="number" name="one_time_custom" min="1.00" max="5000.00" step="0.01" inputmode="decimal" aria-label="<?php echo esc_attr(self::wording('donation_custom_one_time_aria', 'Custom one-time amount')); ?>" <?php disabled($defaultCadence !== 'one_time' || !$oneTimeAvailable); ?>></span>
                </label>
              </div>
            </fieldset>
            <button class="bg-monthly-button" type="submit" <?php disabled($submitDisabled); ?>><?php echo esc_html($defaultCadence === 'monthly'
                ? self::wording('donation_monthly_submit', 'Continue to Secure Stripe Checkout')
                : (string) $atts['label']); ?></button>
            <p class="bg-monthly-small" data-bg-frequency-note="monthly" <?php echo $defaultCadence === 'monthly' ? '' : 'hidden'; ?>><?php echo esc_html(self::wording('donation_monthly_confirmation_note', 'No monthly donation begins unless Stripe confirms the checkout. You can return without completing it.')); ?></p>
            <p class="bg-monthly-small" data-bg-frequency-note="one_time" <?php echo $defaultCadence === 'one_time' ? '' : 'hidden'; ?>><?php echo esc_html(self::wording('donation_one_time_confirmation_note', 'No one-time donation is recorded unless Stripe confirms the payment.')); ?></p>
            <div class="bg-one-time-payment" data-bg-payment hidden>
              <div data-bg-payment-el></div>
              <button type="button" class="bg-monthly-button" data-bg-pay><?php echo esc_html(self::wording('donation_complete_one_time_button', 'Complete donation securely')); ?></button>
              <p class="bg-monthly-small"><?php echo esc_html(self::wording('donation_change_details_note', 'To change the amount or contact information after secure card entry opens, reload this form and begin again.')); ?></p>
            </div>
            <p data-bg-message role="status" aria-live="polite" aria-atomic="true"></p>
          </form>
        </section>
        <script src="https://js.stripe.com/v3/"></script>
        <script><?php echo $this->js($formId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
        <?php
        return (string) ob_get_clean();
    }

    private function createCheckout(\WP_REST_Request $request): \WP_REST_Response
    {
        $cadence = sanitize_key((string) $request->get_param('cadence'));
        if ($cadence !== '' && $cadence !== 'monthly') {
            return $this->response(false, self::wording('donation_invalid_frequency', 'Choose a valid donation frequency.'), 400);
        }
        if (!wp_verify_nonce((string) $request->get_param('nonce'), self::NONCE_ACTION)) {
            return $this->response(false, self::wording('donation_form_expired', 'This form expired. Please reload it and try again.'), 400);
        }
        $requestId = self::validatedRequestId((string) $request->get_param('request_id'));
        if ($requestId === '') {
            return $this->response(false, self::wording('donation_form_expired', 'This form expired. Please reload it and try again.'), 400);
        }
        if ((string) $request->get_param('bg_company_7c4') !== ''
            || time() - (int) $request->get_param('ts') < 2
        ) return $this->response(false, self::wording('donation_form_review', 'Please review the form and try again.'), 400);
        if ($this->rateLimited()) {
            return $this->response(false, self::wording('donation_checkout_rate_limit', 'Too many checkout attempts were made. Please wait a few minutes and try again.'), 429);
        }

        $first = sanitize_text_field((string) $request->get_param('first_name'));
        $last = sanitize_text_field((string) $request->get_param('last_name'));
        $email = strtolower(sanitize_email((string) $request->get_param('email')));
        if ($first === '' || $last === '' || !is_email($email)) {
            return $this->response(false, self::wording('donation_contact_invalid', 'Enter your first name, last name, and a valid email address.'), 400);
        }
        $tierKey = sanitize_key((string) $request->get_param('tier'));
        $tier = $this->resolveTier($tierKey, (string) $request->get_param('custom_amount'));
        if ($tier === null) {
            return $this->response(false, self::templatedWording(
                'donation_monthly_amount_invalid',
                'Choose one of the monthly amounts, or enter a custom amount from {min_amount} through {max_amount}.',
                ['{min_amount}' => '$1.00', '{max_amount}' => '$100,000.00']
            ), 400);
        }
        $idempotencyKey = self::creationIdempotencyKey('monthly', $requestId, $email, (int) $tier['amount']);

        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        if (!$stripe->canUseApi()) {
            return $this->response(false, self::wording('donation_monthly_unavailable', 'Monthly enrollment is temporarily unavailable. Please contact {org}.'), 503);
        }
        $localId = $this->verifiedLocalId($email);
        $successUrl = add_query_arg('checkout', 'success', self::url())
            . '&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = add_query_arg('checkout', 'cancelled', self::url());
        $session = $stripe->createMonthlyCheckoutSession(
            $tier['amount'],
            $tier['label'],
            $email,
            $successUrl,
            $cancelUrl,
            [
                'bg_enrollment' => self::ENROLLMENT_MARKER,
                'bg_constituent_id' => (string) $localId,
                'bg_tier_key' => $tierKey,
                'bg_amount_cents' => (string) $tier['amount'],
                'bg_first_name' => bg_substr($first, 0, 190),
                'bg_last_name' => bg_substr($last, 0, 190),
                'bg_email' => $email,
            ],
            $idempotencyKey
        );
        $url = is_array($session) ? (string) ($session['url'] ?? '') : '';
        if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return $this->response(false, self::wording('donation_checkout_start_failed', 'Stripe could not start the secure checkout. Please try again shortly.'), 502);
        }
        return new \WP_REST_Response(['ok' => true, 'checkout_url' => esc_url_raw($url)], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    private function confirmCheckout(\WP_REST_Request $request): \WP_REST_Response
    {
        $sessionId = sanitize_text_field((string) $request->get_param('session_id'));
        if (strlen($sessionId) > 255 || !preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $sessionId)) {
            return $this->response(false, self::wording('donation_enrollment_not_verified', 'Stripe could not verify this enrollment yet. Please refresh the page in a moment.'), 422);
        }
        $failureKey = 'bg_monthly_confirm_fail_' . hash('sha256', $sessionId);
        if (get_transient($failureKey)) {
            return $this->response(false, self::wording('donation_enrollment_not_verified', 'Stripe could not verify this enrollment yet. Please refresh the page in a moment.'), 422);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        if (!$this->claimConfirmBudget('object:' . hash('sha256', $ip . '|' . $sessionId), 6, MINUTE_IN_SECONDS)
            || !$this->claimConfirmBudget('global', 80, MINUTE_IN_SECONDS)
        ) {
            return $this->response(false, self::wording('donation_verification_rate_limit', 'Too many verification attempts. Please wait briefly and try again.'), 429);
        }
        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        $session = $stripe->retrieveCheckoutSession($sessionId);
        if (!is_array($session)) {
            set_transient($failureKey, 1, 15);
            return $this->response(false, self::wording('donation_enrollment_not_verified', 'Stripe could not verify this enrollment yet. Please refresh the page in a moment.'), 422);
        }
        $result = $this->settleCompleted($session);
        return new \WP_REST_Response($result, !empty($result['ok']) ? 200 : 422, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string, mixed> $session @return array{ok:bool,message:string,payment_id?:int,new?:bool} */
    public function settleCompleted(array $session): array
    {
        $sessionId = (string) ($session['id'] ?? '');
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        if (!preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $sessionId)
            || (string) ($session['object'] ?? '') !== 'checkout.session'
            || (string) ($metadata['bg_enrollment'] ?? '') !== self::ENROLLMENT_MARKER
            || (string) ($session['mode'] ?? '') !== 'subscription'
            || (string) ($session['status'] ?? '') !== 'complete'
            || (string) ($session['payment_status'] ?? '') !== 'paid'
        ) return ['ok' => false, 'message' => self::wording(
            'donation_monthly_not_completed',
            'Stripe has not confirmed a completed monthly enrollment.'
        )];

        $tierKey = sanitize_key((string) ($metadata['bg_tier_key'] ?? ''));
        $amountCents = (int) ($metadata['bg_amount_cents'] ?? 0);
        $expected = $tierKey === 'custom'
            ? ($amountCents >= 100 && $amountCents <= 10000000 ? $amountCents : 0)
            : (int) (self::levels()[$tierKey]['amount'] ?? 0);
        if ($expected < 100 || (int) ($session['amount_total'] ?? 0) !== $expected
            || !\BosqueGrande\Core\Money::matches((string) ($session['currency'] ?? ''))
        ) return ['ok' => false, 'message' => self::wording(
            'donation_monthly_amount_mismatch',
            'The completed Stripe amount did not match one of the monthly-donation options.'
        )];

        $email = strtolower(sanitize_email((string) (
            $session['customer_details']['email']
            ?? $session['customer_email']
            ?? $metadata['bg_email']
            ?? ''
        )));
        $metadataEmail = strtolower(sanitize_email((string) ($metadata['bg_email'] ?? '')));
        if (!is_email($email) || !is_email($metadataEmail) || !hash_equals($metadataEmail, $email)) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_email_mismatch',
                'The completed Stripe enrollment did not match the submitted email address.'
            )];
        }

        // A replayed session may already have its constituent and status side
        // effects but no accounting row. Reconcile the invoice before the
        // processed-session fast path so a replay repairs that gap.
        $processedId = $this->processedConstituent($sessionId);
        if ($processedId > 0) {
            $ledger = $this->settleCheckoutLedger($session, $expected, $processedId);
            if (empty($ledger['ok'])) {
                return ['ok' => false, 'message' => (string) $ledger['message']];
            }
            return [
                'ok' => true,
                'message' => self::wording(
                    'donation_monthly_active',
                    'Your monthly donation is active. Thank you!'
                ),
                'payment_id' => (int) $ledger['payment_id'],
                'new' => !empty($ledger['new']),
            ];
        }

        // Browser confirmation and Stripe's webhook may arrive together.
        // An owner-bound lease ensures only one request performs local, LGL,
        // and activity side effects for this Checkout Session.
        $claimKey = 'bg_monthly_checkout_claim_' . hash('sha256', $sessionId);
        $claimOwner = OptionLease::acquire($claimKey, 15 * MINUTE_IN_SECONDS);
        if ($claimOwner === '') {
            if ($this->processedConstituent($sessionId) > 0) {
                return ['ok' => true, 'message' => self::wording(
                    'donation_monthly_active',
                    'Your monthly donation is active. Thank you!'
                )];
            }
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_reconciling',
                'This completed enrollment is already being reconciled. Please refresh in a moment.'
            )];
        }
        register_shutdown_function(static function () use ($claimKey, $claimOwner): void {
            OptionLease::release($claimKey, $claimOwner);
        });

        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        if (!$store->available()) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_constituent_store_failed',
                'We could not safely update the constituent record. Our team has been notified.'
            )];
        }
        $localId = (int) ($metadata['bg_constituent_id'] ?? 0);
        $record = $localId > 0 ? $store->get($localId) : null;
        if (!$record || ($record['status'] ?? '') !== 'active'
            || !hash_equals(ConstituentStore::normEmail((string) ($record['email'] ?? '')), $email)
        ) {
            $localId = $store->upsertByEmail($email, [
                'first_name' => sanitize_text_field((string) ($metadata['bg_first_name'] ?? '')),
                'last_name' => sanitize_text_field((string) ($metadata['bg_last_name'] ?? '')),
                'email' => $email,
            ]);
            $record = $localId > 0 ? $store->get($localId) : null;
        }
        if ($localId < 1 || !$record) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_constituent_match_failed',
                'We could not identify a constituent record for this completed enrollment. Our team has been notified.'
            )];
        }

        // Accounting is independent of the donor-status and LGL mirrors below:
        // Stripe has already collected this invoice, so record that financial
        // truth first and let webhook retries repair any later side effect.
        $ledger = $this->settleCheckoutLedger($session, $expected, $localId);
        if (empty($ledger['ok'])) {
            return ['ok' => false, 'message' => (string) $ledger['message']];
        }

        $fieldId = $store->ensureField(CoreFields::MONTHLY_DONOR, 'Monthly Donor', false);
        if ($fieldId < 1 || !$store->setValue($localId, $fieldId, 'Yes')) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_status_failed',
                'The donation is active in Stripe, but we could not save the monthly-donor status. Our team has been notified.'
            )];
        }

        $subscriptionId = (string) ($session['subscription'] ?? '');
        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        $bound = $stripe->bindSubscriptionToConstituent($subscriptionId, $localId);
        if (!$bound) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_binding_failed',
                'The donation is active in Stripe, but its permanent record ID could not be attached yet. Our team has been notified.'
            )];
        }
        $lglId = (int) ($record['lgl_constituent_id'] ?? 0);
        $lglSynced = $lglId < 1;
        $syncPending = false;
        if ($lglId > 0 && $this->container->has('lgl')) {
            $lgl = $this->container->get('lgl');
            $lglSynced = $lgl->hasKey() && $lgl->setMonthlyDonorStatus($lglId, true);
            if (!$lglSynced) {
                $syncPending = $this->container->get('jobs')->enqueue(
                    MonthlyDonationCancellation::RETRY_JOB,
                    ['local_id' => $localId, 'lgl_id' => $lglId, 'active' => true, 'attempt' => 1],
                    60
                );
                if (!$syncPending) {
                    return ['ok' => false, 'message' => self::wording(
                        'donation_monthly_lgl_schedule_failed',
                        'The donation is active in Stripe, but we could not schedule the required LGL reconciliation. Our team has been notified.'
                    )];
                }
            }
        }
        if (!$this->rememberProcessed($sessionId, $localId)) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_marker_failed',
                'The donation is active in Stripe, but we could not save its reconciliation marker. Our team has been notified.'
            )];
        }
        if ($this->container->has('constituents.activity')) {
            $activitySaved = $this->container->get('constituents.activity')->append(
                $localId,
                'subscription',
                'Monthly donation started through BosqueGrande.',
                [
                    'amount_cents' => $expected,
                    'stripe_subscription_bound' => $bound,
                    'lgl_status_updated' => $lglSynced,
                    'lgl_retry_queued' => $syncPending,
                ]
            );
            if (!$activitySaved) {
                $this->container->get('logger')->warn('payments', 'Monthly-donation enrollment settled, but its timeline entry could not be stored.', [
                    'constituent' => $localId,
                    'checkout_session' => $sessionId,
                ]);
            }
        }
        $this->container->get('registration.monthly_donor')->clearCache($email, $lglId);
        $this->container->get('logger')->info('payments', 'Monthly-donation enrollment reconciled.', [
            'constituent' => $localId,
            'amount_cents' => $expected,
            'stripe_subscription_bound' => $bound,
            'lgl_status_updated' => $lglSynced,
        ]);
        return [
            'ok' => true,
            'message' => self::wording(
                'donation_monthly_active',
                'Your monthly donation is active. Thank you!'
            ),
            'payment_id' => (int) $ledger['payment_id'],
            'new' => !empty($ledger['new']),
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{ok:bool,message:string,payment_id?:int,new?:bool}
     */
    private function settleCheckoutLedger(array $session, int $amountCents, int $constituentId): array
    {
        $invoiceValue = $session['invoice'] ?? '';
        $invoice = is_array($invoiceValue) ? $invoiceValue : [];
        $invoiceId = self::stripeId($invoiceValue, 'in_');
        if ($invoiceId === '' || $amountCents <= 0 || $constituentId < 1) {
            return [
                'ok' => false,
                'message' => self::wording(
                    'donation_monthly_invoice_failed',
                    'Stripe confirmed the monthly enrollment, but its paid invoice could not be identified for the accounting ledger.'
                ),
            ];
        }
        if (!$this->container->has('revenue.ledger')) {
            return ['ok' => false, 'message' => self::wording(
                'donation_monthly_ledger_unavailable',
                'The BosqueGrande accounting ledger is unavailable.'
            )];
        }

        $paymentIntent = $invoice ? self::invoicePaymentIntent($invoice) : '';
        if ($paymentIntent === '') {
            $paymentIntent = self::stripeId($session['payment_intent'] ?? '', 'pi_');
        }
        $createdAtUtc = $invoice ? self::stripePaidAtUtc($invoice) : '';
        if ($createdAtUtc === '') $createdAtUtc = self::stripeCreatedAtUtc($session);

        $ledger = $this->container->get('revenue.ledger');
        $wasPresent = $ledger->idByExternalKey('stripe-invoice:' . $invoiceId) > 0;
        $paymentId = $ledger->ensureMonthlyDonation(
            $invoiceId,
            $amountCents,
            $constituentId,
            $paymentIntent,
            $createdAtUtc
        );
        if ($paymentId < 1) {
            return [
                'ok' => false,
                'message' => self::wording(
                    'donation_monthly_ledger_write_failed',
                    'Stripe received the monthly donation, but we could not write its accounting row. Our team has been notified.'
                ),
            ];
        }
        return [
            'ok' => true,
            'message' => self::wording('donation_monthly_recorded', 'Monthly donation recorded.'),
            'payment_id' => $paymentId,
            'new' => !$wasPresent,
        ];
    }

    /** @param array<string, mixed> $invoice @param array<string, mixed> $metadata */
    private function resolveInvoiceConstituent(array $invoice, array $metadata): int
    {
        /** @var ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        if (!$store->available()) return 0;

        $metadataEmail = ConstituentStore::normEmail((string) ($metadata['bg_email'] ?? ''));
        $invoiceEmail = ConstituentStore::normEmail((string) ($invoice['customer_email'] ?? ''));
        $metadataValid = is_email($metadataEmail);
        $invoiceValid = is_email($invoiceEmail);
        $emailsConflict = $metadataValid && $invoiceValid && !hash_equals($metadataEmail, $invoiceEmail);
        $email = !$emailsConflict
            ? ($metadataValid ? $metadataEmail : ($invoiceValid ? $invoiceEmail : ''))
            : '';

        $constituentId = (int) ($metadata['bg_constituent_id'] ?? 0);
        if ($constituentId > 0 && !$emailsConflict) {
            $record = $store->get($constituentId);
            $recordEmail = is_array($record)
                ? ConstituentStore::normEmail((string) ($record['email'] ?? ''))
                : '';
            if ($record && ($record['status'] ?? '') === 'active'
                && ($email === '' || hash_equals($recordEmail, $email))
            ) return $constituentId;
        }
        if (!is_email($email)) return 0;

        return $store->upsertByEmail($email, [
            'first_name' => sanitize_text_field((string) ($metadata['bg_first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($metadata['bg_last_name'] ?? '')),
            'email' => $email,
        ]);
    }

    /**
     * Normalize the legacy and current Stripe invoice subscription shapes.
     * Invoice-level metadata alone is intentionally not accepted: only the
     * subscription snapshot created by BosqueGrande proves scope.
     *
     * @param array<string, mixed> $invoice
     * @return array{subscription_id:string,metadata:array<string,mixed>}
     */
    private static function invoiceSubscriptionContext(array $invoice): array
    {
        $legacyDetails = is_array($invoice['subscription_details'] ?? null)
            ? $invoice['subscription_details']
            : [];
        $parent = is_array($invoice['parent'] ?? null) ? $invoice['parent'] : [];
        $parentDetails = (string) ($parent['type'] ?? '') === 'subscription_details'
            && is_array($parent['subscription_details'] ?? null)
            ? $parent['subscription_details']
            : [];
        $subscriptionObject = is_array($invoice['subscription'] ?? null)
            ? $invoice['subscription']
            : [];
        $globalSubscriptionId = self::stripeId($invoice['subscription'] ?? '', 'sub_');

        foreach ([$parentDetails, $legacyDetails, $subscriptionObject] as $details) {
            $metadata = is_array($details['metadata'] ?? null) ? $details['metadata'] : [];
            if ((string) ($metadata['bg_enrollment'] ?? '') !== self::ENROLLMENT_MARKER) continue;
            $subscriptionId = self::stripeId($details['subscription'] ?? '', 'sub_');
            if ($subscriptionId === '') $subscriptionId = self::stripeId($details, 'sub_');
            if ($subscriptionId === '') $subscriptionId = $globalSubscriptionId;
            return ['subscription_id' => $subscriptionId, 'metadata' => $metadata];
        }
        return ['subscription_id' => '', 'metadata' => []];
    }

    /** @param array<string, mixed> $invoice */
    private static function invoicePaymentIntent(array $invoice): string
    {
        $intent = self::stripeId($invoice['payment_intent'] ?? '', 'pi_');
        if ($intent !== '') return $intent;
        foreach ((array) ($invoice['payments']['data'] ?? []) as $payment) {
            if (!is_array($payment)) continue;
            $paymentObject = is_array($payment['payment'] ?? null) ? $payment['payment'] : [];
            $intent = self::stripeId(
                $paymentObject['payment_intent'] ?? $payment['payment_intent'] ?? '',
                'pi_'
            );
            if ($intent !== '') return $intent;
        }
        return '';
    }

    /** @param mixed $value */
    private static function stripeId($value, string $prefix): string
    {
        if (is_array($value)) $value = (string) ($value['id'] ?? '');
        $value = (string) $value;
        return preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]+$/', $value) ? $value : '';
    }

    /** @param array<string, mixed> $invoice */
    private static function stripePaidAtUtc(array $invoice): string
    {
        $transitions = is_array($invoice['status_transitions'] ?? null)
            ? $invoice['status_transitions']
            : [];
        $timestamp = (int) ($transitions['paid_at'] ?? 0);
        if ($timestamp < 1) $timestamp = (int) ($invoice['created'] ?? 0);
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    /** @param array<string, mixed> $object */
    private static function stripeCreatedAtUtc(array $object): string
    {
        $timestamp = (int) ($object['created'] ?? 0);
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    /** @return array{amount: int, label: string}|null */
    private function resolveTier(string $tierKey, string $custom): ?array
    {
        $levels = self::levels();
        if (isset($levels[$tierKey])) {
            $tier = $levels[$tierKey];
            $tier['label'] = self::publicTierLabel($tierKey, $tier);
            return $tier;
        }
        if ($tierKey !== 'custom') return null;
        $cents = $this->amountToCents($custom);
        if ($cents < 100 || $cents > 10000000) return null;
        return [
            'amount' => $cents,
            'label' => self::fixedAmountLabel($cents, true) . ' ' . self::wording(
                'donation_custom_monthly_descriptor',
                'custom monthly donation'
            ),
        ];
    }

    private function amountToCents(string $raw): int
    {
        $raw = str_replace([',', '$', \BosqueGrande\Core\Money::symbol()], '', trim($raw));
        if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $raw)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @return array{first_name: string, last_name: string, email: string} */
    private function verifiedPrefill(): array
    {
        $blank = ['first_name' => '', 'last_name' => '', 'email' => ''];
        $identity = $this->currentIdentity();
        if ($identity['constituent_id'] < 1 || !$identity['via_session']) return $blank;
        $resolved = $this->container->get('registration.prefill')->resolve($identity);
        if (empty($resolved['has_profile'])) return $blank;
        return [
            'first_name' => (string) ($resolved['prefill']['first_name'] ?? ''),
            'last_name' => (string) ($resolved['prefill']['last_name'] ?? ''),
            'email' => (string) ($resolved['prefill']['email'] ?? ''),
        ];
    }

    private function verifiedLocalId(string $submittedEmail): int
    {
        $identity = $this->currentIdentity();
        if ($identity['constituent_id'] < 1 || !$identity['via_session']) return 0;
        $resolved = $this->container->get('registration.prefill')->resolve($identity);
        $resolvedEmail = strtolower((string) ($resolved['prefill']['email'] ?? ''));
        return !empty($resolved['has_profile']) && is_email($resolvedEmail)
            && hash_equals($resolvedEmail, strtolower($submittedEmail))
            ? (int) ($resolved['local_id'] ?? 0)
            : 0;
    }

    /** @return array{source: string, constituent_id: int, via_session: bool} */
    private function currentIdentity(): array
    {
        $identity = apply_filters('bg_current_identity', [
            'source' => '', 'constituent_id' => 0, 'via_session' => false,
        ]);
        return is_array($identity) ? [
            'source' => (string) ($identity['source'] ?? ''),
            'constituent_id' => (int) ($identity['constituent_id'] ?? 0),
            'via_session' => !empty($identity['via_session']),
        ] : ['source' => '', 'constituent_id' => 0, 'via_session' => false];
    }

    private function rememberProcessed(string $sessionId, int $localId): bool
    {
        // Keep one durable, non-autoloaded marker per Stripe Checkout Session.
        // The rolling index below is retained for backwards compatibility and
        // admin visibility, but no longer defines the idempotency boundary.
        $settledKey = $this->settledKey($sessionId);
        $durableSaved = update_option($settledKey, $localId, false);
        if (!$durableSaved && (int) get_option($settledKey, 0) !== $localId) return false;

        $processed = get_option(self::PROCESSED_OPTION, []);
        if (!is_array($processed)) $processed = [];
        if (count($processed) >= 500) $processed = array_slice($processed, -499, null, true);
        $processed[$sessionId] = ['constituent_id' => $localId, 'settled_at' => time()];
        update_option(self::PROCESSED_OPTION, $processed, false);
        return true;
    }

    private function processedConstituent(string $sessionId): int
    {
        $settledKey = $this->settledKey($sessionId);
        $localId = (int) get_option($settledKey, 0);
        if ($localId > 0) return $localId;
        $processed = get_option(self::PROCESSED_OPTION, []);
        $localId = is_array($processed)
            ? (int) ($processed[$sessionId]['constituent_id'] ?? 0)
            : 0;
        if ($localId > 0) update_option($settledKey, $localId, false);
        return $localId;
    }

    private function settledKey(string $sessionId): string
    {
        return 'bg_monthly_checkout_settled_' . hash('sha256', $sessionId);
    }

    private function rateLimited(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return !$this->claimConfirmBudget(
            'checkout-ip:' . hash('sha256', $ip),
            10,
            15 * MINUTE_IN_SECONDS
        ) || !$this->claimConfirmBudget('checkout-global', 120, 15 * MINUTE_IN_SECONDS);
    }

    private function claimConfirmBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_monthly_confirm_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_monthly_confirm_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    private function response(bool $ok, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(['ok' => $ok, 'message' => $message], $status, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** A per-render opaque token used only to deduplicate Stripe creation. */
    private static function newRequestId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $uuid = function_exists('wp_generate_uuid4')
                ? strtolower(str_replace('-', '', (string) wp_generate_uuid4()))
                : '';
            if (preg_match('/^[a-f0-9]{32}$/D', $uuid)) return $uuid;
            return substr(hash('sha256', (string) wp_rand() . '|' . microtime(true)), 0, 32);
        }
    }

    private static function validatedRequestId(string $requestId): string
    {
        $requestId = strtolower(trim($requestId));
        return preg_match('/^[a-f0-9]{32}$/D', $requestId) ? $requestId : '';
    }

    /** Bind a browser retry token to one exact creation operation. */
    private static function creationIdempotencyKey(
        string $operation,
        string $requestId,
        string $email,
        int $amountCents
    ): string {
        $salt = (string) wp_salt('nonce') . '|' . home_url('/');
        $payload = sanitize_key($operation) . '|' . $requestId . '|' . strtolower($email) . '|' . $amountCents;
        return 'bg-' . sanitize_key($operation) . '-v1-' . hash_hmac('sha256', $payload, $salt);
    }

    private function css(): string
    {
        return <<<'CSS'
.bg-monthly-wrap{max-width:780px;margin:36px auto;padding:0 16px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1F2A24}
.bg-monthly-card{width:min(100%,760px);box-sizing:border-box;margin:0 auto;background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:28px 26px;position:relative;box-shadow:0 2px 10px rgba(31,42,36,.08);color:#1F2A24;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.bg-monthly-card:before{content:"";position:absolute;top:0;left:0;right:0;height:5px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#1F5130,#2E7D4F,#C98A1B)}
.bg-monthly-card h1{margin:4px 0 8px;font-size:1.65rem}.bg-monthly-card fieldset{min-width:0;border:1px solid #D9E5D9;border-radius:12px;padding:18px 16px;margin:18px 0;background:#FBFDFB}
.bg-monthly-card legend{font-weight:700;padding:0 8px;color:#1F5130}.bg-monthly-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.bg-monthly-grid label{display:flex;flex-direction:column;gap:5px;font-weight:650;font-size:.92rem}.bg-monthly-grid input{box-sizing:border-box;min-height:44px;padding:10px 11px;border:1px solid #C4D6C4;border-radius:9px;font-size:1rem;background:#fff}.bg-monthly-grid input:focus-visible,.bg-monthly-money input:focus-visible{outline:3px solid #1F5130;outline-offset:2px}.bg-monthly-wide{grid-column:1/-1}
.bg-donation-pitch{margin:16px 0;padding:17px 18px;border-radius:13px;background:linear-gradient(135deg,#F2F7F2,#FFF9DB);border:1px solid #E8D5F2;line-height:1.58}.bg-donation-pitch p{margin:0 0 9px}.bg-donation-pitch ul{margin:5px 0 0;padding-left:1.3rem}.bg-donation-pitch li+li{margin-top:6px}
.bg-donation-cadence{display:grid;grid-template-columns:1fr 1fr;gap:10px}.bg-monthly-tiers{display:flex;flex-direction:column;gap:9px}.bg-monthly-tier{box-sizing:border-box;min-height:44px;display:flex;align-items:center;gap:10px;border:1.5px solid #C4D6C4;border-radius:11px;padding:11px 13px;background:#fff;cursor:pointer}.bg-monthly-tier:hover{border-color:#2E7D4F}.bg-monthly-tier:has(input[type=radio]:checked){border-color:#2E7D4F;background:#F2F7F2;box-shadow:inset 0 0 0 1px #2E7D4F}.bg-monthly-tier:has(input:disabled){opacity:.58;cursor:not-allowed}.bg-monthly-tier input[type=radio]{accent-color:#2E7D4F;min-width:18px;min-height:18px}.bg-monthly-tier input[type=radio]:focus-visible{outline:3px solid #1F5130;outline-offset:3px}.bg-monthly-custom{flex-wrap:wrap}.bg-monthly-money{box-sizing:border-box;max-width:100%;display:inline-flex;align-items:center;border:1px solid #C4D6C4;border-radius:8px;padding-left:8px;background:#fff}.bg-monthly-money input{box-sizing:border-box;width:130px;max-width:calc(100vw - 100px);min-height:44px;padding:8px;border:0;background:transparent}
.bg-monthly-button,.bg-monthly-secondary{box-sizing:border-box;min-height:44px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:13px 26px;color:#fff!important;background:linear-gradient(135deg,#1F5130,#2E7D4F);box-shadow:0 3px 10px rgba(31,81,48,.3);font-weight:750;font-size:1rem;text-decoration:none;cursor:pointer}.bg-monthly-button:disabled{opacity:.55;cursor:not-allowed}.bg-monthly-button:focus-visible,.bg-monthly-secondary:focus-visible{outline:3px solid #1F5130;outline-offset:3px}.bg-monthly-small{font-size:.85rem;color:#5A6A61}.bg-monthly-notice{padding:11px 13px;border-left:4px solid #2E7D4F;background:#F2F7F2}.bg-monthly-error{padding:11px 13px;border-left:4px solid #8B1E1E;background:#FFF4F3}.bg-monthly-hp{display:none!important}
.bg-one-time-payment{margin-top:18px;padding-top:18px;border-top:1px solid #D9E5D9}.bg-one-time-payment [data-bg-pay]{margin-top:14px}.bg-combined-donation [data-bg-message],[data-bg-monthly-result]{min-height:1.4em;font-weight:650;color:#1F5130}.bg-combined-donation [hidden],.bg-donation-card [hidden]{display:none!important}
@media(max-width:600px){.bg-monthly-grid,.bg-donation-cadence{grid-template-columns:1fr}.bg-monthly-wide{grid-column:auto}.bg-monthly-card{padding:22px 16px}.bg-monthly-custom{align-items:flex-start}}
CSS;
    }

    private function js(string $formId, string $successId = ''): string
    {
        $monthlyBase = trailingslashit(esc_url_raw(rest_url('bosquegrande/v1/monthly-donation')));
        $strings = [
            'verifying' => self::wording('donation_monthly_verifying_fallback', 'Your monthly donation is being verified.'),
            'reconcile' => self::wording('donation_monthly_reconcile_fallback', 'Stripe received the enrollment. We will finish updating your status automatically.'),
            'frequency' => self::wording('donation_choose_frequency', 'Choose monthly or one-time donation.'),
            'monthly_amount' => self::wording('donation_choose_monthly_amount', 'Choose a monthly amount.'),
            'monthly_custom' => self::templatedWording(
                'donation_enter_custom_monthly',
                'Enter a custom monthly amount from {min_amount} through {max_amount}.',
                ['{min_amount}' => '$1.00', '{max_amount}' => '$100,000.00']
            ),
            'one_time_amount' => self::wording('donation_choose_one_time_amount', 'Choose a one-time amount.'),
            'one_time_custom' => self::templatedWording(
                'donation_enter_custom_one_time',
                'Enter a custom one-time amount from {min_amount} through {max_amount}.',
                ['{min_amount}' => '$1.00', '{max_amount}' => '$5,000.00']
            ),
            'opening_checkout' => self::wording('donation_opening_checkout', 'Opening secure Stripe checkout...'),
            'checkout_failed' => self::wording('donation_checkout_failed', 'Stripe checkout could not be started. Please try again.'),
            'opening_fields' => self::wording('donation_opening_payment_fields', 'Opening secure Stripe payment fields...'),
            'generic_error' => self::wording('donation_generic_error', 'Something went wrong. Please try again.'),
            'processing' => self::wording('donation_processing_payment', 'Processing securely with Stripe...'),
            'payment_failed' => self::wording('donation_payment_failed', 'Payment failed.'),
            'payment_processing' => self::wording('donation_payment_processing', 'Payment is processing; please keep this page open.'),
            'received_reconcile' => self::wording('donation_received_reconcile', 'Payment was received; the organizer will reconcile it.'),
            'thanks' => self::wording('donation_one_time_thanks', 'Thank you! A receipt is on its way to your email.'),
        ];
        return strtr(<<<'JS'
(function(){
  var monthlyBase=__BG_MONTHLY_REST__,strings=__BG_STRINGS__;
  var successId=__BG_SUCCESS_ID__,success=successId?document.getElementById(successId):null;
  if(success){
    var result=success.querySelector('[data-bg-monthly-result]');
    var body=new FormData();body.append('session_id',success.dataset.session||'');
    fetch(monthlyBase+'confirm',{method:'POST',body:body,cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){result.textContent=(d&&d.message)||strings.verifying;})
      .catch(function(){result.textContent=strings.reconcile;});
    return;
  }
  var form=document.getElementById(__BG_FORM_ID__);if(!form)return;
  var submit=form.querySelector('button[type=submit]'),message=form.querySelector('[data-bg-message]');
  var monthlyCustom=form.querySelector('input[name=custom_amount]');
  var oneTimeCustom=form.querySelector('input[name=one_time_custom]');
  var intentEndpoint=__BG_INTENT_REST__,confirmEndpoint=__BG_CONFIRM_REST__;
  function selectedCadence(){var selected=form.querySelector('input[name=cadence]:checked');return selected?selected.value:'';}
  function showMessage(text){if(message)message.textContent=text||'';}
  function unlock(text){delete form.dataset.bgLocked;if(submit)submit.disabled=false;showMessage(text);}
  function cents(raw){
    raw=String(raw||'').replace(/[$,]/g,'').trim();
    if(!/^\d{1,6}(?:\.\d{1,2})?$/.test(raw))return 0;
    var parts=raw.split('.'),fraction=(parts[1]||'').slice(0,2);
    while(fraction.length<2)fraction+='0';
    return (parseInt(parts[0],10)*100)+parseInt(fraction||'0',10);
  }
  function updateCadence(){
    var cadence=selectedCadence();
    form.querySelectorAll('[data-bg-frequency-panel]').forEach(function(panel){
      var active=panel.getAttribute('data-bg-frequency-panel')===cadence;
      panel.hidden=!active;
      panel.querySelectorAll('input,select,textarea').forEach(function(control){control.disabled=!active;});
    });
    form.closest('.bg-donation-card').querySelectorAll('[data-bg-trust]').forEach(function(box){box.hidden=box.getAttribute('data-bg-trust')!==cadence;});
    form.querySelectorAll('[data-bg-frequency-note]').forEach(function(note){note.hidden=note.getAttribute('data-bg-frequency-note')!==cadence;});
    if(submit){submit.textContent=cadence==='monthly'?form.dataset.monthlyLabel:form.dataset.oneTimeLabel;submit.disabled=!cadence;}
    showMessage('');
  }
  function resumeRedirectedPayment(){
    if(typeof window.URL!=='function')return;
    var returnUrl;
    try{returnUrl=new window.URL(window.location.href);}catch(ignore){return;}
    var intent=returnUrl.searchParams.get('payment_intent')||'';
    // Stripe may put its client secret in the return URL. It is not needed
    // for BG's server-side verification, so remove it from the visible URL
    // and browser history as soon as the return is recognized.
    if(returnUrl.searchParams.has('payment_intent_client_secret')&&window.history&&window.history.replaceState){
      returnUrl.searchParams.delete('payment_intent_client_secret');
      window.history.replaceState(window.history.state,'',returnUrl.toString());
    }
    if(!/^pi_[A-Za-z0-9]+$/.test(intent))return;
    form.dataset.bgLocked='1';if(submit)submit.disabled=true;showMessage(strings.processing);
    var redirected=new FormData();redirected.append('payment_intent',intent);
    fetch(confirmEndpoint,{method:'POST',body:redirected,cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(confirmed){
        if(!confirmed||!confirmed.ok){showMessage((confirmed&&confirmed.message)||strings.received_reconcile);return;}
        showMessage(strings.thanks);
      })
      .catch(function(){showMessage(strings.received_reconcile);});
  }
  form.querySelectorAll('input[name=cadence]').forEach(function(radio){radio.addEventListener('change',updateCadence);});
  if(monthlyCustom){monthlyCustom.addEventListener('focus',function(){var radio=form.querySelector('input[name=tier][value=custom]');if(radio)radio.checked=true;});}
  if(oneTimeCustom){oneTimeCustom.addEventListener('focus',function(){var radio=form.querySelector('input[name=one_time_preset][value=custom]');if(radio)radio.checked=true;});}
  updateCadence();
  resumeRedirectedPayment();
  form.addEventListener('submit',function(e){
    e.preventDefault();
    if(form.dataset.bgLocked)return;
    var cadence=selectedCadence();
    if(!cadence){showMessage(strings.frequency);return;}
    if(!form.reportValidity())return;
    if(cadence==='monthly'){
      var monthlyTier=form.querySelector('input[name=tier]:checked');
      if(!monthlyTier){showMessage(strings.monthly_amount);return;}
      if(monthlyTier.value==='custom'&&(!monthlyCustom||!monthlyCustom.checkValidity()||!monthlyCustom.value)){
        showMessage(strings.monthly_custom);if(monthlyCustom)monthlyCustom.focus();return;
      }
      form.dataset.bgLocked='1';submit.disabled=true;showMessage(strings.opening_checkout);
      fetch(monthlyBase+'nonce',{cache:'no-store',credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(n){
          var monthlyBody=new FormData(form);monthlyBody.set('nonce',(n&&n.nonce)||'');monthlyBody.set('cadence','monthly');
          return fetch(monthlyBase+'checkout',{method:'POST',body:monthlyBody,cache:'no-store',credentials:'same-origin'});
        })
        .then(function(r){return r.json();})
        .then(function(d){
          if(d&&d.ok&&d.checkout_url){window.location.assign(d.checkout_url);return;}
          unlock((d&&d.message)||strings.checkout_failed);
        })
        .catch(function(){unlock(strings.checkout_failed);});
      return;
    }

    var oneTimeTier=form.querySelector('input[name=one_time_preset]:checked');
    if(!oneTimeTier){showMessage(strings.one_time_amount);return;}
    var amount=oneTimeTier.value==='custom'?cents(oneTimeCustom?oneTimeCustom.value:''):parseInt(oneTimeTier.value||'0',10);
    if(oneTimeTier.value==='custom'&&(!oneTimeCustom||!oneTimeCustom.checkValidity()||amount<100||amount>500000)){
      showMessage(strings.one_time_custom);if(oneTimeCustom)oneTimeCustom.focus();return;
    }
    if(amount<100){showMessage(strings.one_time_amount);return;}
    form.dataset.bgLocked='1';submit.disabled=true;showMessage(strings.opening_fields);
    var onceBody=new FormData(form);
    onceBody.set('cadence','one_time');onceBody.set('amount_cents',String(amount));
    onceBody.set('name',((onceBody.get('first_name')||'')+' '+(onceBody.get('last_name')||'')).trim());
    fetch(intentEndpoint,{method:'POST',body:onceBody,cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.ok||!d.client_secret){unlock((d&&d.message)||strings.generic_error);return;}
        if(typeof window.Stripe!=='function'||!form.dataset.publishableKey){unlock(strings.generic_error);return;}
        var wrap=form.querySelector('[data-bg-payment]'),mount=form.querySelector('[data-bg-payment-el]');
        var stripe=window.Stripe(form.dataset.publishableKey),elements=stripe.elements({clientSecret:d.client_secret});
        elements.create('payment').mount(mount);wrap.hidden=false;showMessage('');
        form.querySelectorAll('input:not([type=hidden])').forEach(function(input){input.disabled=true;});
        var pay=form.querySelector('[data-bg-pay]');
        pay.addEventListener('click',function(){
          pay.disabled=true;showMessage(strings.processing);
          stripe.confirmPayment({elements:elements,redirect:'if_required',confirmParams:{return_url:window.location.href}})
            .then(function(res){
              if(res.error){showMessage(res.error.message||strings.payment_failed);pay.disabled=false;return;}
              if(!res.paymentIntent){showMessage(strings.payment_processing);return;}
              var done=new FormData();done.append('payment_intent',res.paymentIntent.id);
              fetch(confirmEndpoint,{method:'POST',body:done,cache:'no-store',credentials:'same-origin'})
                .then(function(r){return r.json();})
                .then(function(confirmed){
                  if(!confirmed||!confirmed.ok){showMessage((confirmed&&confirmed.message)||strings.received_reconcile);return;}
                  wrap.hidden=true;showMessage(strings.thanks);
                })
                .catch(function(){showMessage(strings.received_reconcile);});
            })
            .catch(function(){showMessage(strings.generic_error);pay.disabled=false;});
        });
      })
      .catch(function(){unlock(strings.generic_error);});
  });
})();
JS
        , [
            '__BG_MONTHLY_REST__' => wp_json_encode($monthlyBase),
            '__BG_INTENT_REST__' => wp_json_encode(esc_url_raw(rest_url('bosquegrande/v1/donation-intent'))),
            '__BG_CONFIRM_REST__' => wp_json_encode(esc_url_raw(rest_url('bosquegrande/v1/confirm-donation'))),
            '__BG_FORM_ID__' => wp_json_encode($formId),
            '__BG_SUCCESS_ID__' => wp_json_encode($successId),
            '__BG_STRINGS__' => wp_json_encode($strings),
        ]);
    }

    /** @return list<int> Whole-dollar legacy [bg_donate] preset amounts. */
    private function oneTimeAmounts(string $raw): array
    {
        $amounts = [];
        foreach (explode(',', $raw) as $value) {
            $amount = (int) trim($value);
            if ($amount < 1 || $amount > 5000 || isset($amounts[$amount])) continue;
            $amounts[$amount] = $amount;
            if (count($amounts) >= 12) break;
        }
        if (!$amounts) $amounts = [10 => 10, 25 => 25, 50 => 50, 100 => 100];
        return array_values($amounts);
    }

    /** @param array{amount:int,label:string} $tier */
    private static function publicTierLabel(string $key, array $tier): string
    {
        unset($key);
        $amount = self::fixedAmountLabel((int) $tier['amount']);
        $descriptor = trim((string) ($tier['label'] ?? ''));
        return $descriptor === '' ? $amount : $amount . ' - ' . $descriptor;
    }

    private static function fixedAmountLabel(int $amountCents, bool $alwaysDecimals = false): string
    {
        $decimals = $alwaysDecimals || $amountCents % 100 !== 0 ? 2 : 0;
        return \BosqueGrande\Core\Money::format($amountCents, $decimals);
    }

    /** @param array<string, string> $tokens */
    private static function templatedWording(string $key, string $fallback, array $tokens): string
    {
        $template = self::wording($key, $fallback);
        foreach (array_keys($tokens) as $token) {
            if (!str_contains($template, $token)) {
                $template = $fallback;
                break;
            }
        }
        return strtr($template, $tokens);
    }

    private static function wording(string $key, string $fallback): string
    {
        $value = function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
        $value = trim(wp_strip_all_tags((string) $value));
        return $value !== '' ? $value : $fallback;
    }
}
