<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

use BosqueGrande\Core\Logger;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Direct Stripe REST client (no vendor SDK; keeps the single-artifact
 * rule). Payment Element + PaymentIntents. Card data never touches the
 * server (SAQ-A); amounts are always derived server-side from the tier.
 */
final class StripeClient
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(
        private Settings $settings,
        private Logger $logger,
    ) {
    }

    /**
     * Whether online payment can be COLLECTED: a secret key to create
     * the PaymentIntent and a publishable key to mount the Elements
     * form. The webhook secret is deliberately NOT required here (see
     * canVerifyWebhook): it is only needed to confirm a payment after
     * the fact, and gating collection on it silently downgraded paid
     * tiers to pay-at-the-door whenever the webhook was unset.
     */
    public function isConfigured(): bool
    {
        return (string) $this->settings->get('stripe_secret_key', '') !== ''
            && (string) $this->settings->get('stripe_publishable_key', '') !== '';
    }

    /** Whether authenticated server-side Stripe API operations are available. */
    public function canUseApi(): bool
    {
        return (string) $this->settings->get('stripe_secret_key', '') !== '';
    }

    /**
     * Whether an incoming webhook can be signature-verified. When this
     * is false the site still collects payment, but reconciliation
     * runs on the client-side confirmation result rather than a
     * verified server callback; the Payments setup screen surfaces the
     * missing webhook secret as a recommended, not blocking, step.
     */
    public function canVerifyWebhook(): bool
    {
        return (string) $this->settings->get('stripe_webhook_secret', '') !== '';
    }

    public function publishableKey(): string
    {
        return (string) $this->settings->get('stripe_publishable_key', '');
    }

    /** @param array<string, string> $metadata */
    public function createPaymentIntent(
        int $amountCents,
        string $description,
        array $metadata,
        string $idempotencyKey = ''
    ): ?array
    {
        return $this->post('/payment_intents', [
            'amount' => (string) $amountCents,
            'currency' => \BosqueGrande\Core\Money::currency(),
            'description' => $description,
            'automatic_payment_methods[enabled]' => 'true',
        ] + $this->flattenMeta($metadata), $idempotencyKey);
    }

    /**
     * Create the Stripe-hosted checkout used by BG's monthly-donation
     * OneForm. The browser supplies a tier key, never a trusted price: the
     * caller resolves and validates the amount before this request is made.
     *
     * @param array<string, string> $metadata
     * @return array<string, mixed>|null
     */
    public function createMonthlyCheckoutSession(
        int $amountCents,
        string $tierLabel,
        string $email,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey = ''
    ): ?array {
        $email = strtolower(trim($email));
        if (!$this->canUseApi() || $amountCents < 100 || $amountCents > 10000000
            || !is_email($email) || $successUrl === '' || $cancelUrl === ''
        ) return null;
        $productId = $this->monthlyDonationProductId();
        if ($productId === '') return null;

        $body = [
            'mode' => 'subscription',
            'submit_type' => 'donate',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $email,
            'payment_method_types[0]' => 'card',
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => \BosqueGrande\Core\Money::currency(),
            'line_items[0][price_data][unit_amount]' => (string) $amountCents,
            'line_items[0][price_data][recurring][interval]' => 'month',
            'line_items[0][price_data][recurring][interval_count]' => '1',
            'line_items[0][price_data][product]' => $productId,
            'subscription_data[description]' => $tierLabel,
        ];
        foreach ($metadata as $key => $value) {
            $safeKey = sanitize_key($key);
            if ($safeKey === '') continue;
            $body['metadata[' . $safeKey . ']'] = bg_substr((string) $value, 0, 500);
            if (in_array($safeKey, [
                'bg_enrollment', 'bg_constituent_id', 'bg_tier_key',
                'bg_amount_cents', 'bg_first_name', 'bg_last_name', 'bg_email',
            ], true)) {
                $body['subscription_data[metadata][' . $safeKey . ']'] = bg_substr((string) $value, 0, 500);
            }
        }
        return $this->post('/checkout/sessions', $body, $idempotencyKey);
    }

    /** Reuse one Stripe product while allowing exact or custom monthly prices. */
    private function monthlyDonationProductId(): string
    {
        $saved = (string) $this->settings->get('stripe_monthly_donation_product_id', '');
        if (preg_match('/^prod_[A-Za-z0-9]+$/', $saved)) {
            $product = $this->get('/products/' . rawurlencode($saved));
            if (is_array($product) && (string) ($product['id'] ?? '') === $saved
                && !empty($product['active'])
            ) return $saved;
        }
        $site = strtolower(untrailingslashit(home_url('/')));
        $salt = (string) wp_salt('auth');
        $creationPurpose = $saved === '' ? 'initial' : 'replacement|' . $saved;
        $productPayload = $site . '|' . $creationPurpose;
        $productIdempotencyKey = 'bg-monthly-product-v1-'
            . hash_hmac('sha256', $productPayload, $salt);
        $created = $this->post('/products', [
            'name' => 'Monthly Sustaining Donation',
            'description' => 'Monthly donation enrollment created through BosqueGrande.',
            'metadata[bg_kind]' => 'monthly_donation',
        ], $productIdempotencyKey);
        $id = is_array($created) ? (string) ($created['id'] ?? '') : '';
        if (!preg_match('/^prod_[A-Za-z0-9]+$/', $id)) return '';
        $this->settings->set('stripe_monthly_donation_product_id', $id);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function retrieveCheckoutSession(string $sessionId): ?array
    {
        if (!preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $sessionId)) return null;
        return $this->get('/checkout/sessions/' . rawurlencode($sessionId));
    }

    /**
     * Read a bounded window of paid Stripe invoices for explicit accounting
     * reconciliation. Callers still have to prove that each invoice belongs to
     * a BosqueGrande subscription; this method deliberately performs no broad
     * ingestion or classification itself.
     *
     * @return list<array<string, mixed>>|null Null means Stripe could not be read.
     */
    public function recentPaidInvoices(int $createdAfter, int $limit = 300): ?array
    {
        if (!$this->canUseApi()) return null;
        $createdAfter = max(0, $createdAfter);
        $limit = max(1, min(300, $limit));
        $found = [];
        $startingAfter = '';

        while (count($found) < $limit) {
            $query = [
                'status' => 'paid',
                'created' => ['gte' => $createdAfter],
                'limit' => min(100, $limit - count($found)),
            ];
            if ($startingAfter !== '') $query['starting_after'] = $startingAfter;
            $page = $this->get('/invoices?' . http_build_query($query));
            if ($page === null) return null;

            $rows = array_values(array_filter((array) ($page['data'] ?? []), 'is_array'));
            foreach ($rows as $invoice) {
                $id = (string) ($invoice['id'] ?? '');
                if ($id === '' || isset($found[$id])) continue;
                $found[$id] = $invoice;
                if (count($found) >= $limit) break;
            }
            $last = $rows ? end($rows) : null;
            $startingAfter = is_array($last) ? (string) ($last['id'] ?? '') : '';
            if (empty($page['has_more']) || $startingAfter === '' || !$rows) break;
        }
        return array_values($found);
    }

    /**
     * Bounded Checkout-session listing used to repair locally processed
     * enrollments without issuing one Stripe request per saved session id.
     *
     * @return list<array<string, mixed>>|null
     */
    public function recentCompletedCheckoutSessions(int $createdAfter, int $limit = 300): ?array
    {
        if (!$this->canUseApi()) return null;
        $createdAfter = max(0, $createdAfter);
        $limit = max(1, min(300, $limit));
        $found = [];
        $startingAfter = '';

        while (count($found) < $limit) {
            $query = [
                'status' => 'complete',
                'created' => ['gte' => $createdAfter],
                'limit' => min(100, $limit - count($found)),
            ];
            if ($startingAfter !== '') $query['starting_after'] = $startingAfter;
            $page = $this->get('/checkout/sessions?' . http_build_query($query));
            if ($page === null) return null;

            $rows = array_values(array_filter((array) ($page['data'] ?? []), 'is_array'));
            foreach ($rows as $session) {
                $id = (string) ($session['id'] ?? '');
                if ($id === '' || isset($found[$id])) continue;
                $found[$id] = $session;
                if (count($found) >= $limit) break;
            }
            $last = $rows ? end($rows) : null;
            $startingAfter = is_array($last) ? (string) ($last['id'] ?? '') : '';
            if (empty($page['has_more']) || $startingAfter === '' || !$rows) break;
        }
        return array_values($found);
    }

    /** Bind a newly completed subscription to BG's permanent person id. */
    public function bindSubscriptionToConstituent(string $subscriptionId, int $constituentId): bool
    {
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $subscriptionId) || $constituentId < 1) return false;
        $updated = $this->post('/subscriptions/' . rawurlencode($subscriptionId), [
            'metadata[bg_constituent_id]' => (string) $constituentId,
            'metadata[bg_enrollment]' => 'monthly_oneform_v1',
        ]);
        return is_array($updated)
            && (string) ($updated['id'] ?? '') === $subscriptionId
            && (int) ($updated['metadata']['bg_constituent_id'] ?? 0) === $constituentId;
    }

    public function refund(string $paymentIntentId): ?array
    {
        if (strpos($paymentIntentId, 'pi_') !== 0) return null;
        $refund = $this->post(
            '/refunds',
            ['payment_intent' => $paymentIntentId],
            'bg-refund-' . hash('sha256', $paymentIntentId)
        );
        if (is_array($refund)) return $refund;

        // A timeout can happen after Stripe commits the refund. Confirm the
        // authoritative state before telling the caller that it failed.
        $existing = $this->get('/refunds?payment_intent=' . rawurlencode($paymentIntentId) . '&limit=10');
        foreach ((array) ($existing['data'] ?? []) as $row) {
            if (is_array($row)
                && (string) ($row['payment_intent'] ?? '') === $paymentIntentId
                && in_array((string) ($row['status'] ?? ''), ['pending', 'succeeded'], true)
            ) return $row;
        }
        return null;
    }

    /** Cheap auth check for the Test Connection button. */
    public function testConnection(): bool
    {
        $res = wp_remote_get(self::API . '/balance', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()],
        ]);
        return !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200;
    }

    /** Read-only permission check for the monthly enrollment path. */
    public function testCheckoutAccess(): bool
    {
        if (!$this->canUseApi()) return false;
        $res = wp_remote_get(self::API . '/checkout/sessions?limit=1', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()],
        ]);
        return !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200;
    }

    /** Read permission used by monthly-payment reconciliation and recovery. */
    public function testInvoiceAccess(): bool
    {
        if (!$this->canUseApi()) return false;
        $res = wp_remote_get(self::API . '/invoices?limit=1', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()],
        ]);
        return !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200;
    }

    /**
     * Verify a Stripe webhook signature (Stripe-Signature header:
     * t=timestamp,v1=hmac). Constant-time compare, 5 minute tolerance.
     */
    public function verifyWebhook(string $payload, string $sigHeader): bool
    {
        $secret = (string) $this->settings->get('stripe_webhook_secret', '');
        if ($secret === '' || $sigHeader === '') return false;

        $t = '';
        $v1s = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $t = $v;
            if ($k === 'v1') $v1s[] = $v;
        }
        if ($t === '' || !$v1s || abs(time() - (int) $t) > 300) return false;

        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($v1s as $v1) {
            if (hash_equals($expected, $v1)) return true;
        }
        return false;
    }

    /**
     * Retrieve a PaymentIntent by id. Used to confirm a
     * payment server-side when no webhook secret is configured: the
     * browser reports success, and the server independently asks
     * Stripe whether the intent truly succeeded before marking the
     * registration paid, so a forged client call cannot confirm a
     * payment that did not happen.
     * @return array<string, mixed>|null
     */
    public function retrievePaymentIntent(string $intentId): ?array
    {
        if ($intentId === '' || strpos($intentId, 'pi_') !== 0) return null;
        return $this->get('/payment_intents/' . rawurlencode($intentId));
    }

    /**
     * Does this email have a live recurring subscription?
     *
     * This is what makes "monthly donor" answerable without asking
     * anyone: the processor that charges the card knows exactly who is
     * subscribed, and knows the moment one lapses. Statuses 'active'
     * and 'trialing' count; 'past_due' deliberately does not, so a
     * failed card ends the benefit rather than quietly extending it.
     *
     * An email can map to several Stripe customers (people check out
     * twice with different casing, or a duplicate is created), so every
     * match is examined before answering no. Cached briefly: this runs
     * on form render as well as on submit.
     */
    public function hasActiveSubscription(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !$this->canUseApi()) return false;

        $key = 'bg_stripe_sub_' . md5($email);
        $cached = get_transient($key);
        if ($cached === '1' || $cached === '0') return $cached === '1';

        $subscriptions = $this->activeMonthlySubscriptions($email);
        if ($subscriptions === null) return false; // Never cache a transport failure as "not a donor".
        $found = $subscriptions !== [];
        set_transient($key, $found ? '1' : '0', 15 * MINUTE_IN_SECONDS);
        return $found;
    }

    /**
     * Find every active or trialing monthly subscription owned by a
     * Stripe customer whose email exactly matches the verified profile.
     *
     * Null means Stripe could not provide an authoritative answer.
     * An empty array means the query succeeded and found no subscription.
     *
     * @return list<array<string, mixed>>|null
     */
    public function activeMonthlySubscriptions(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !$this->canUseApi()) return [];

        $found = [];
        $customerStartingAfter = '';
        do {
            $customerQuery = ['email' => $email, 'limit' => 100];
            if ($customerStartingAfter !== '') {
                $customerQuery['starting_after'] = $customerStartingAfter;
            }
            $customers = $this->get('/customers?' . http_build_query($customerQuery));
            if ($customers === null) return null;
            $customerRows = array_values(array_filter((array) ($customers['data'] ?? []), 'is_array'));

            foreach ($customerRows as $customer) {
                $customerId = (string) ($customer['id'] ?? '');
                if ($customerId === '') continue;

                foreach (['active', 'trialing'] as $status) {
                    $startingAfter = '';
                    do {
                        $query = [
                            'customer' => $customerId,
                            'status' => $status,
                            'limit' => 100,
                        ];
                        if ($startingAfter !== '') $query['starting_after'] = $startingAfter;
                        $page = $this->get('/subscriptions?' . http_build_query($query));
                        if ($page === null) return null;

                        $rows = array_values(array_filter((array) ($page['data'] ?? []), 'is_array'));
                        foreach ($rows as $subscription) {
                            $id = (string) ($subscription['id'] ?? '');
                            if ($id !== '' && $this->subscriptionIsMonthly($subscription)) {
                                $found[$id] = $subscription;
                            }
                        }
                        $last = $rows ? end($rows) : null;
                        $startingAfter = is_array($last) ? (string) ($last['id'] ?? '') : '';
                        $hasMore = !empty($page['has_more']) && $startingAfter !== '';
                    } while ($hasMore);
                }
            }
            $lastCustomer = $customerRows ? end($customerRows) : null;
            $customerStartingAfter = is_array($lastCustomer)
                ? (string) ($lastCustomer['id'] ?? '')
                : '';
            $hasMoreCustomers = !empty($customers['has_more']) && $customerStartingAfter !== '';
        } while ($hasMoreCustomers);
        return array_values($found);
    }

    /**
     * Immediately cancel all matching active monthly subscriptions.
     * Re-querying after every attempt makes a transport timeout safe:
     * if Stripe committed the cancellation before the timeout, the
     * authoritative follow-up sees no remaining subscription.
     *
     * @return array{ok: bool, found: int, cancelled: int, remaining: int}
     */
    public function cancelMonthlySubscriptionsForEmail(string $email): array
    {
        $subscriptions = $this->activeMonthlySubscriptions($email);
        if ($subscriptions === null) {
            return ['ok' => false, 'found' => 0, 'cancelled' => 0, 'remaining' => 0];
        }

        $cancelled = 0;
        foreach ($subscriptions as $subscription) {
            $id = (string) ($subscription['id'] ?? '');
            if ($id === '') continue;
            $result = $this->delete('/subscriptions/' . rawurlencode($id), [
                'cancellation_details[comment]' => 'Cancelled by the verified donor through BosqueGrande My Profile.',
            ]);
            if (is_array($result) && (string) ($result['status'] ?? '') === 'canceled') {
                $cancelled++;
            }
        }

        $this->clearSubscriptionCache($email);
        $remaining = $this->activeMonthlySubscriptions($email);
        if ($remaining === null) {
            return [
                'ok' => false,
                'found' => count($subscriptions),
                'cancelled' => $cancelled,
                'remaining' => 0,
            ];
        }
        return [
            'ok' => $remaining === [],
            'found' => count($subscriptions),
            'cancelled' => $cancelled,
            'remaining' => count($remaining),
        ];
    }

    /**
     * Return the one monthly Stripe subscription that a verified profile may
     * safely manage. A shared email with several live subscriptions is
     * deliberately ambiguous: BosqueGrande never guesses which person's charge belongs
     * to the signed-in constituent.
     *
     * @return array{
     *   ok: bool, reason: string,
     *   subscription?: array{
     *     id: string, amount_cents: int, currency: string,
     *     next_charge: string, can_change_amount: bool, identity_bound: bool
     *   }
     * }
     */
    public function manageableMonthlySubscription(string $email, int $constituentId): array
    {
        if (!$this->canUseApi()) return ['ok' => false, 'reason' => 'stripe_unavailable'];
        $subscriptions = $this->activeMonthlySubscriptions($email);
        if ($subscriptions === null) return ['ok' => false, 'reason' => 'stripe_unavailable'];
        if (!$subscriptions) return ['ok' => false, 'reason' => 'not_found'];
        $selection = $this->subscriptionForConstituent($subscriptions, $constituentId);
        if (empty($selection['subscription'])) {
            return ['ok' => false, 'reason' => (string) ($selection['reason'] ?? 'ambiguous')];
        }
        $subscription = (array) $selection['subscription'];
        $storedConstituentId = (int) ($subscription['metadata']['bg_constituent_id'] ?? 0);
        if ($storedConstituentId > 0 && $constituentId > 0
            && $storedConstituentId !== $constituentId
        ) {
            return ['ok' => false, 'reason' => 'identity_mismatch'];
        }

        $id = (string) ($subscription['id'] ?? '');
        if ($id === '') return ['ok' => false, 'reason' => 'not_found'];
        $item = $this->fixedMonthlyItem($subscription);
        $next = (int) ($subscription['current_period_end'] ?? 0);
        return [
            'ok' => true,
            'reason' => '',
            'subscription' => [
                'id' => $id,
                'amount_cents' => $this->subscriptionAmountCents($subscription),
                'currency' => strtolower((string) ($item['currency']
                    ?? $subscription['currency']
                    ?? \BosqueGrande\Core\Money::currency())),
                'next_charge' => $next > 0 ? gmdate('Y-m-d', $next) : '',
                'can_change_amount' => $item !== null,
                'identity_bound' => $storedConstituentId > 0
                    && $storedConstituentId === $constituentId,
            ],
        ];
    }

    /**
     * Change one verified fixed-price monthly subscription at its next renewal.
     * The existing billing date is retained and no mid-cycle proration is made.
     *
     * @return array{ok: bool, reason: string, old_amount: int, new_amount: int}
     */
    public function updateMonthlySubscriptionAmount(
        string $email,
        int $constituentId,
        string $subscriptionId,
        int $amountCents
    ): array {
        $failure = ['ok' => false, 'reason' => 'not_found', 'old_amount' => 0, 'new_amount' => 0];
        if ($amountCents < 100 || $amountCents > 10000000) {
            $failure['reason'] = 'invalid_amount';
            return $failure;
        }
        $manageable = $this->manageableMonthlySubscription($email, $constituentId);
        if (!$manageable['ok']) {
            $failure['reason'] = (string) $manageable['reason'];
            return $failure;
        }
        $summary = (array) ($manageable['subscription'] ?? []);
        if ($subscriptionId === '' || !hash_equals((string) ($summary['id'] ?? ''), $subscriptionId)) {
            $failure['reason'] = 'identity_mismatch';
            return $failure;
        }
        if (empty($summary['can_change_amount'])) {
            $failure['reason'] = 'unsupported_price';
            return $failure;
        }

        $subscriptions = $this->activeMonthlySubscriptions($email);
        if (!is_array($subscriptions)) {
            $failure['reason'] = 'stripe_unavailable';
            return $failure;
        }
        $subscription = null;
        foreach ($subscriptions as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $subscriptionId) {
                $subscription = $candidate;
                break;
            }
        }
        if (!is_array($subscription)) {
            $failure['reason'] = 'not_found';
            return $failure;
        }
        $storedConstituentId = (int) ($subscription['metadata']['bg_constituent_id'] ?? 0);
        if ($storedConstituentId > 0 && $storedConstituentId !== $constituentId) {
            $failure['reason'] = 'identity_mismatch';
            return $failure;
        }
        $item = $this->fixedMonthlyItem($subscription);
        if ($item === null || !hash_equals((string) ($subscription['id'] ?? ''), $subscriptionId)) {
            $failure['reason'] = 'unsupported_price';
            return $failure;
        }
        $oldAmount = $this->subscriptionAmountCents($subscription);
        if ($oldAmount === $amountCents) {
            return ['ok' => true, 'reason' => '', 'old_amount' => $oldAmount, 'new_amount' => $amountCents];
        }

        $updated = $this->post('/subscriptions/' . rawurlencode($subscriptionId), [
            'items[0][id]' => (string) $item['id'],
            'items[0][price_data][currency]' => (string) $item['currency'],
            'items[0][price_data][product]' => (string) $item['product'],
            'items[0][price_data][recurring][interval]' => 'month',
            'items[0][price_data][recurring][interval_count]' => '1',
            'items[0][price_data][unit_amount]' => (string) $amountCents,
            'items[0][quantity]' => '1',
            'proration_behavior' => 'none',
            'payment_behavior' => 'error_if_incomplete',
            'metadata[bg_constituent_id]' => (string) $constituentId,
            'metadata[bg_last_management_action]' => 'amount_change',
        ]);
        if (!is_array($updated)) {
            $failure['reason'] = 'stripe_unavailable';
            return $failure;
        }
        $verified = $this->fixedMonthlyItem($updated);
        $verifiedAmount = $verified === null ? 0 : $this->subscriptionAmountCents($updated);
        if ($verifiedAmount !== $amountCents) {
            $failure['reason'] = 'verification_failed';
            $failure['old_amount'] = $oldAmount;
            return $failure;
        }
        $this->clearSubscriptionCache($email);
        return ['ok' => true, 'reason' => '', 'old_amount' => $oldAmount, 'new_amount' => $amountCents];
    }

    /**
     * Cancel one unambiguous subscription owned by the verified email. The id
     * supplied by the browser is never trusted until Stripe's live result has
     * tied it back to that exact address and constituent metadata.
     *
     * @return array{ok: bool, reason: string, cancelled: int, remaining: int}
     */
    public function cancelManageableMonthlySubscription(
        string $email,
        int $constituentId,
        string $subscriptionId
    ): array {
        $manageable = $this->manageableMonthlySubscription($email, $constituentId);
        if (!$manageable['ok']) {
            return [
                'ok' => false,
                'reason' => (string) $manageable['reason'],
                'cancelled' => 0,
                'remaining' => 0,
            ];
        }
        $expected = (string) (($manageable['subscription']['id'] ?? ''));
        if ($subscriptionId === '') $subscriptionId = $expected;
        if ($expected === '' || !hash_equals($expected, $subscriptionId)) {
            return ['ok' => false, 'reason' => 'identity_mismatch', 'cancelled' => 0, 'remaining' => 1];
        }

        $result = $this->delete('/subscriptions/' . rawurlencode($subscriptionId), [
            'cancellation_details[comment]' => 'Cancelled by the verified donor through BosqueGrande Donation Management.',
        ]);
        $this->clearSubscriptionCache($email);
        $remaining = $this->activeMonthlySubscriptions($email);
        if ($remaining === null) {
            return ['ok' => false, 'reason' => 'verification_failed', 'cancelled' => 0, 'remaining' => 0];
        }
        $cancelled = is_array($result) && (string) ($result['status'] ?? '') === 'canceled';
        $ownedRemaining = array_values(array_filter($remaining, static function (array $subscription) use ($constituentId): bool {
            return (int) ($subscription['metadata']['bg_constituent_id'] ?? 0) === $constituentId;
        }));
        $deletedStillVisible = array_filter($remaining, static fn(array $subscription): bool =>
            (string) ($subscription['id'] ?? '') === $subscriptionId
        );
        return [
            'ok' => $cancelled && !$deletedStillVisible && !$ownedRemaining,
            'reason' => $cancelled && !$deletedStillVisible && !$ownedRemaining ? '' : 'verification_failed',
            'cancelled' => $cancelled ? 1 : 0,
            'remaining' => count($ownedRemaining),
        ];
    }

    /**
     * Prefer a subscription explicitly bound to this permanent record ID. An
     * older unbound subscription is manageable only when it is the sole
     * active monthly charge for the email.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @return array{subscription?: array<string, mixed>, reason?: string}
     */
    private function subscriptionForConstituent(array $subscriptions, int $constituentId): array
    {
        $owned = array_values(array_filter($subscriptions, static function (array $subscription) use ($constituentId): bool {
            return $constituentId > 0
                && (int) ($subscription['metadata']['bg_constituent_id'] ?? 0) === $constituentId;
        }));
        if (count($owned) === 1) return ['subscription' => $owned[0]];
        if (count($owned) > 1) return ['reason' => 'ambiguous'];
        if (count($subscriptions) !== 1) {
            $allBelongElsewhere = $subscriptions !== [];
            foreach ($subscriptions as $subscription) {
                if ((int) ($subscription['metadata']['bg_constituent_id'] ?? 0) < 1) {
                    $allBelongElsewhere = false;
                    break;
                }
            }
            return ['reason' => $allBelongElsewhere ? 'identity_mismatch' : 'ambiguous'];
        }
        $only = $subscriptions[0];
        $stored = (int) ($only['metadata']['bg_constituent_id'] ?? 0);
        if ($stored > 0 && $stored !== $constituentId) return ['reason' => 'identity_mismatch'];
        return ['subscription' => $only];
    }

    public function clearSubscriptionCache(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email !== '') delete_transient('bg_stripe_sub_' . md5($email));
    }

    /** @param array<string, mixed> $subscription */
    private function subscriptionIsMonthly(array $subscription): bool
    {
        foreach ((array) ($subscription['items']['data'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $interval = (string) ($item['price']['recurring']['interval']
                ?? $item['plan']['interval']
                ?? '');
            if ($interval === 'month') return true;
        }
        return (string) ($subscription['plan']['interval'] ?? '') === 'month';
    }

    /**
     * A safely editable donation has exactly one fixed monthly item, quantity
     * one, a reusable product, and a currency/amount Stripe can reproduce.
     * Metered, tiered, multi-item, or non-monthly subscriptions remain
     * cancellable but their amount is left for staff review.
     *
     * @return array{id: string, product: string, currency: string}|null
     */
    private function fixedMonthlyItem(array $subscription): ?array
    {
        $items = array_values(array_filter((array) ($subscription['items']['data'] ?? []), 'is_array'));
        if (count($items) !== 1) return null;
        $item = $items[0];
        $price = is_array($item['price'] ?? null)
            ? $item['price']
            : (is_array($item['plan'] ?? null) ? $item['plan'] : []);
        $interval = (string) ($price['recurring']['interval'] ?? $price['interval'] ?? '');
        $intervalCount = (int) ($price['recurring']['interval_count'] ?? $price['interval_count'] ?? 1);
        $usage = (string) ($price['recurring']['usage_type'] ?? $price['usage_type'] ?? 'licensed');
        $product = $price['product'] ?? '';
        if (is_array($product)) $product = (string) ($product['id'] ?? '');
        $id = (string) ($item['id'] ?? '');
        $currency = strtolower((string) ($price['currency'] ?? $subscription['currency'] ?? ''));
        $unitAmount = $price['unit_amount'] ?? null;
        $quantity = (int) ($item['quantity'] ?? 1);
        if ($id === '' || $product === '' || $currency === '' || !is_numeric($unitAmount)
            || $interval !== 'month' || $intervalCount !== 1 || $usage === 'metered'
            || $quantity !== 1
        ) return null;
        return ['id' => $id, 'product' => (string) $product, 'currency' => $currency];
    }

    private function subscriptionAmountCents(array $subscription): int
    {
        $total = 0;
        foreach ((array) ($subscription['items']['data'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $price = is_array($item['price'] ?? null)
                ? $item['price']
                : (is_array($item['plan'] ?? null) ? $item['plan'] : []);
            $interval = (string) ($price['recurring']['interval'] ?? $price['interval'] ?? '');
            if ($interval !== 'month') continue;
            $amount = is_numeric($price['unit_amount'] ?? null) ? (int) $price['unit_amount'] : 0;
            $total += $amount * max(1, (int) ($item['quantity'] ?? 1));
        }
        return max(0, $total);
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body, string $idempotencyKey = ''): ?array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey(),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        if ($idempotencyKey !== '') $headers['Idempotency-Key'] = bg_substr($idempotencyKey, 0, 255);
        $res = wp_remote_post(self::API . $path, [
            'timeout' => 20,
            'headers' => $headers,
            'body' => $body,
        ]);
        if (is_wp_error($res)) {
            $this->logger->error('payments', 'Stripe transport error.', ['path' => $path, 'error' => $res->get_error_message()]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($res);
        $decoded = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) {
            $this->logger->error('payments', "Stripe request failed ({$code}).", [
                'path' => $path,
                'stripe_error' => $decoded['error']['message'] ?? 'unknown',
            ]);
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, string> $body @return array<string, mixed>|null */
    private function delete(string $path, array $body = []): ?array
    {
        $res = wp_remote_request(self::API . $path, [
            'method' => 'DELETE',
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secretKey(),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);
        if (is_wp_error($res)) {
            $this->logger->error('payments', 'Stripe transport error.', [
                'path' => $path,
                'error' => $res->get_error_message(),
            ]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($res);
        $decoded = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) {
            $this->logger->error('payments', "Stripe request failed ({$code}).", [
                'path' => $path,
                'stripe_error' => $decoded['error']['message'] ?? 'unknown',
            ]);
            return null;
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed>|null */
    private function get(string $path): ?array
    {
        $res = wp_remote_get(self::API . $path, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()],
        ]);
        if (is_wp_error($res)) {
            $this->logger->error('payments', 'Stripe transport error.', ['path' => $path, 'error' => $res->get_error_message()]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($res);
        $decoded = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) {
            $this->logger->error('payments', "Stripe request failed ({$code}).", [
                'path' => $path,
                'stripe_error' => $decoded['error']['message'] ?? 'unknown',
            ]);
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, string> $meta @return array<string, string> */
    private function flattenMeta(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            $out["metadata[{$k}]"] = (string) $v;
        }
        return $out;
    }

    private function secretKey(): string
    {
        return (string) $this->settings->get('stripe_secret_key', '');
    }
}
