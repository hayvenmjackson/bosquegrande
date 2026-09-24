<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Logger;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Registration\ConfirmationEmail;
use BosqueGrande\Modules\Registration\RegistrationRepository;

if (!defined('ABSPATH')) exit;

/**
 * Payments (ADR-1 consequence). Paid registrations: the Registration
 * Engine creates the row as pending and hands back a PaymentIntent
 * client secret; the webhook is the source of truth that flips the row
 * to paid, adds the LGL invitation, records the gift, and emails the
 * confirmation. Refund: one admin button per row doing all three
 * (Stripe refund, cancel invitation, delete gift).
 */
final class PaymentsModule implements ModuleInterface
{
    public function name(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Payments (Stripe)';
    }

    public function activate(Container $container): void
    {
        (new MonthlyDonationEnrollment($container))->activate();
    }

    public function boot(Container $container): void
    {
        $container->set('stripe', fn(Container $c) => new StripeClient(
            $c->get('settings'),
            $c->get('logger'),
        ));
        $container->set('monthly_donation.enrollment', fn(Container $c) => new MonthlyDonationEnrollment($c));
        /** @var MonthlyDonationEnrollment $enrollment */
        $enrollment = $container->get('monthly_donation.enrollment');
        $enrollment->boot();

        add_action('rest_api_init', function () use ($container) {
            register_rest_route('bosquegrande/v1', '/stripe-webhook', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Signature-verified inside.
                'callback' => fn(\WP_REST_Request $req) => $this->handleWebhook($container, $req),
            ]);

            // Client-driven confirmation for sites without a
            // webhook secret. The browser reports Stripe success and
            // passes the intent id; the server INDEPENDENTLY retrieves
            // that intent from Stripe and completes the registration
            // only when Stripe itself reports succeeded and the intent
            // metadata names this registration. A forged call cannot
            // confirm anything: amount, status, and the registration
            // link all come from Stripe, not the request body. When a
            // webhook secret IS set the webhook stays the source of
            // truth and this endpoint is an idempotent no-op.
            register_rest_route('bosquegrande/v1', '/confirm-payment', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => fn(\WP_REST_Request $req) => $this->handleConfirm($container, $req),
            ]);
        });

        if (is_admin()) {
            (new SetupPage($container))->register();
            (new StatsPage($container))->register();
        }
    }

    public function deactivate(Container $container): void
    {
    }

    private function handleWebhook(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        /** @var Logger $logger */
        $logger = $container->get('logger');

        $payload = $req->get_body();
        $sig = (string) $req->get_header('stripe-signature');
        if (!$stripe->verifyWebhook($payload, $sig)) {
            $logger->warn('payments', 'Webhook signature verification failed.');
            return new \WP_REST_Response(['ok' => false], 400);
        }

        $event = json_decode($payload, true);
        $type = (string) ($event['type'] ?? '');
        $pi = $event['data']['object'] ?? [];
        if ($type === 'checkout.session.completed' && is_array($pi)
            && MonthlyDonationEnrollment::recognizes($pi)
        ) {
            /** @var MonthlyDonationEnrollment $enrollment */
            $enrollment = $container->get('monthly_donation.enrollment');
            $settled = $enrollment->settleCompleted($pi);
            if (empty($settled['ok'])) {
                $logger->error(
                    'payments',
                    'A completed monthly-donation Checkout Session could not be reconciled.',
                    ['reason' => (string) ($settled['message'] ?? 'unknown')]
                );
                return new \WP_REST_Response(['ok' => false], 500);
            }
        }
        if ($type === 'invoice.paid' && is_array($pi)
            && MonthlyDonationEnrollment::recognizesPaidInvoice($pi)
        ) {
            /** @var MonthlyDonationEnrollment $enrollment */
            $enrollment = $container->get('monthly_donation.enrollment');
            $settled = $enrollment->settlePaidInvoice($pi);
            if (empty($settled['ok'])) {
                // A 500 keeps the signature-verified Stripe event retryable.
                // Unrelated invoices never enter this branch and remain a safe
                // no-op for installations sharing the Stripe account.
                $logger->error(
                    'payments',
                    'A paid monthly-donation invoice could not be reconciled.',
                    [
                        'stripe_invoice' => (string) ($pi['id'] ?? ''),
                        'reason' => (string) ($settled['message'] ?? 'unknown'),
                    ]
                );
                return new \WP_REST_Response(['ok' => false], 500);
            }
        }
        // Signature-verified events broadcast to listeners
        // (the Revenue module settles donation intents from here).
        do_action('bg_stripe_event', $type, is_array($pi) ? $pi : []);
        if ($type === 'payment_intent.succeeded'
            && (string) ($pi['metadata']['bg_kind'] ?? '') === 'donation'
        ) {
            $intentId = (string) ($pi['id'] ?? '');
            $recorded = $intentId !== ''
                && $container->has('revenue.ledger')
                && $container->get('revenue.ledger')->idByExternalKey('stripe:' . $intentId) > 0;
            if (!$recorded) {
                $logger->error('payments', 'A paid donation was not retained in the local ledger; Stripe should retry the event.', [
                    'intent' => $intentId,
                ]);
                return new \WP_REST_Response(['ok' => false], 500);
            }
        }
        $regId = (int) ($pi['metadata']['registration_id'] ?? 0);

        if ($type === 'payment_intent.succeeded' && $regId) {
            if (!$this->completePaidRegistration($container, $regId, $pi)) {
                return new \WP_REST_Response(['ok' => false], 500);
            }
        } elseif ($type === 'payment_intent.payment_failed' && $regId) {
            /** @var RegistrationRepository $repo */
            $repo = $container->get('registration.repo');
            $row = $repo->find($regId);
            $intentId = (string) ($pi['id'] ?? '');
            $boundIntent = $row ? (string) ($row->stripe_payment_intent ?? '') : '';
            if ($row
                && (string) ($row->status ?? '') === 'pending'
                && $intentId !== ''
                && $boundIntent !== ''
                && hash_equals($boundIntent, $intentId)
            ) {
                // payment_intent.payment_failed describes an attempt, not a
                // terminal registration. The same PaymentIntent may be retried
                // successfully, so retain pending and let the verified success
                // path perform the only paid-state transition.
                $logger->warn('payments', 'Payment attempt failed; registration remains pending for retry.', [
                    'registration' => $regId,
                    'intent' => $intentId,
                ]);
            } else {
                $logger->info('payments', 'Out-of-order or unbound payment-failure event did not change registration state.', [
                    'registration' => $regId,
                    'intent' => $intentId,
                    'status' => $row ? (string) ($row->status ?? '') : 'missing',
                ]);
            }
        }

        return new \WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Confirm a payment from the browser when no webhook is set. The
     * only value trusted from the request is the intent id string;
     * everything decisive is read back from Stripe. Returns ok=true
     * once the registration is (or already was) paid, so the JS can
     * show the same finished state a webhook would have produced.
     */
    private function handleConfirm(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        /** @var Logger $logger */
        $logger = $container->get('logger');

        $intentId = sanitize_text_field((string) $req->get_param('payment_intent'));
        if (strlen($intentId) > 255 || !preg_match('/^pi_[A-Za-z0-9]+$/', $intentId)) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Payment could not be verified.'], 400);
        }
        $failureKey = 'bg_pi_confirm_fail_' . hash('sha256', $intentId);
        if (get_transient($failureKey)) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Payment could not be verified.'], 400);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        if (!self::claimConfirmBudget('payment:' . hash('sha256', $ip . '|' . $intentId), 6, MINUTE_IN_SECONDS)
            || !self::claimConfirmBudget('payment:global', 80, MINUTE_IN_SECONDS)
        ) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Too many verification attempts. Please wait briefly and try again.'], 429);
        }
        $pi = $stripe->retrievePaymentIntent($intentId);
        if (!is_array($pi)) {
            set_transient($failureKey, 1, 15);
            return new \WP_REST_Response(['ok' => false, 'message' => 'Payment could not be verified.'], 400);
        }
        $status = (string) ($pi['status'] ?? '');
        $regId = (int) ($pi['metadata']['registration_id'] ?? 0);
        if ($status !== 'succeeded' || $regId < 1) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Payment not yet complete.'], 202);
        }
        if (!$this->completePaidRegistration($container, $regId, $pi)) {
            $row = $container->get('registration.repo')->find($regId);
            if ($row && $row->status !== 'paid' && !empty($row->settlement_token)) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'message' => 'Payment was verified and is already being finalized.',
                ], 202);
            }
            return new \WP_REST_Response([
                'ok' => false,
                'message' => 'Payment was received but did not match this registration. The organizer has been notified.',
            ], 409);
        }
        $logger->info('payments', 'Payment confirmed via client callback (no webhook configured).', [
            'registration' => $regId,
        ]);
        return new \WP_REST_Response(['ok' => true], 200);
    }

    private static function claimConfirmBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_pay_confirm_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_pay_confirm_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    /** @param array<string, mixed> $pi */
    private function completePaidRegistration(Container $container, int $regId, array $pi): bool
    {
        /** @var RegistrationRepository $repo */
        $repo = $container->get('registration.repo');
        /** @var Logger $logger */
        $logger = $container->get('logger');

        $row = $repo->find($regId);
        if (!$row) {
            $logger->error('payments', 'Paid intent named a registration that does not exist.', [
                'registration' => $regId,
            ]);
            return false;
        }
        if ($row->status === 'paid') {
            // A prior request may have committed the registration before a
            // transient ledger failure. Webhook retries also repair that row.
            return $this->reconcilePaidLedger($container, $row, $pi);
        }

        // Bind completion to the exact server-created registration.
        // The old callback trusted registration_id metadata alone and
        // never persisted the intent id on the pending row, so it had
        // no independent amount/event/tier check before marking paid.
        $intentId = (string) ($pi['id'] ?? '');
        $metaReg = (int) ($pi['metadata']['registration_id'] ?? 0);
        $metaEvent = (int) ($pi['metadata']['event_post_id'] ?? 0);
        $metaTier = (string) ($pi['metadata']['tier'] ?? '');
        $amount = (int) ($pi['amount_received'] ?? $pi['amount'] ?? -1);
        $currency = strtolower((string) ($pi['currency'] ?? ''));
        $boundIntent = (string) ($row->stripe_payment_intent ?? '');
        $matches = $intentId !== ''
            && $metaReg === $regId
            && $metaEvent === (int) $row->event_post_id
            && hash_equals((string) $row->tier, $metaTier)
            && $amount === (int) $row->amount_cents
            && \BosqueGrande\Core\Money::matches($currency)
            && ($boundIntent === '' || hash_equals($boundIntent, $intentId));
        if (!$matches) {
            $logger->error('payments', 'Paid intent did not match its registration row; completion refused.', [
                'registration' => $regId,
                'intent_bound' => $boundIntent !== '',
                'amount_matches' => $amount === (int) $row->amount_cents,
                'event_matches' => $metaEvent === (int) $row->event_post_id,
                'tier_matches' => $metaTier !== '' && hash_equals((string) $row->tier, $metaTier),
            ]);
            return false;
        }
        if ($boundIntent === '' && !$repo->setPaymentIntent($regId, $intentId)) {
            $logger->error('payments', 'Verified paid intent could not be bound to its legacy pending registration.', [
                'registration' => $regId,
            ]);
            return false;
        }

        $claim = $repo->claimSettlement($regId, $intentId);
        if ($claim === 'paid') return true;
        if ($claim === 'busy') {
            $logger->info('payments', 'Payment settlement is already in progress.', ['registration' => $regId]);
            return false;
        }
        if ($claim === '') {
            $logger->error('payments', 'Payment settlement could not acquire its registration row.', [
                'registration' => $regId,
            ]);
            return false;
        }
        $row = $repo->find($regId) ?: $row;

        $localConstituentId = (int) ($pi['metadata']['bg_constituent_id'] ?? 0)
            ?: (int) ($row->bg_constituent_id ?? 0);
        $invitationId = (int) ($row->lgl_invitation_id ?? 0) ?: null;
        $giftId = (int) ($row->lgl_gift_id ?? 0) ?: null;

        if (!$repo->finishSettlement($regId, $claim, $intentId, $invitationId, $giftId)) {
            $logger->error('payments', 'Remote work finished but the paid registration state could not be committed.', [
                'registration' => $regId,
            ]);
            return false;
        }
        $logger->info('payments', 'Paid registration completed.', [
            'registration' => $regId,
            'invitation' => $invitationId,
            'gift' => $giftId,
            'lgl_sync_required' => (int) ($row->lgl_constituent_id ?? 0) > 0
                || (int) ($row->lgl_event_id ?? 0) > 0,
        ]);

        $email = (string) ($pi['metadata']['email'] ?? '');
        $first = (string) ($pi['metadata']['first_name'] ?? '');
        $eventTitle = get_the_title((int) $row->event_post_id);
        if ($email) {
            $body = ConfirmationEmail::body(
                $container,
                (int) $row->event_post_id,
                $first,
                (string) $row->tier,
                $email,
                $localConstituentId,
                true
            );
            $sent = wp_mail(
                $email,
                ConfirmationEmail::subject((int) $row->event_post_id),
                $body
            );
            // The mailer's answer is recorded instead of assumed.
            $logger->info('payments',
                $sent ? 'Paid confirmation dispatched.' : 'Paid confirmation refused by the mailer.',
                ['registration' => $regId]);
            // Payment lands on the local record's timeline.
            do_action('bg_registration_completed', $email, $first, $eventTitle, (string) $row->tier, 0);
        }
        do_action(
            'bg_payment_completed',
            $email,
            $first,
            $eventTitle,
            (int) $pi['amount'],
            (string) ($pi['id'] ?? ''),
            $regId,
            $localConstituentId
        );
        return $this->reconcilePaidLedger($container, $row, $pi);
    }

    /** @param array<string, mixed> $pi */
    private function reconcilePaidLedger(Container $container, object $row, array $pi): bool
    {
        if (!$container->has('revenue.ledger')) return true;
        $intent = (string) ($pi['id'] ?? $row->stripe_payment_intent ?? '');
        if ($intent === '') return false;
        $ledger = $container->get('revenue.ledger');
        if ($ledger->idByIntent($intent) > 0) return true;
        $registrationId = (int) ($row->id ?? 0);
        $localId = (int) ($pi['metadata']['bg_constituent_id'] ?? 0)
            ?: (int) ($row->bg_constituent_id ?? 0);
        $paymentId = $ledger->record([
            'kind' => 'event_fee',
            'amount_cents' => (int) ($row->amount_cents ?? $pi['amount'] ?? 0),
            'constituent_id' => $localId,
            'registration_id' => $registrationId,
            'stripe_payment_intent' => $intent,
            'source' => 'event-reconcile',
            'note' => (string) get_the_title((int) ($row->event_post_id ?? 0)),
        ]);
        if ($paymentId > 0 || $ledger->idByIntent($intent) > 0) return true;
        $container->get('logger')->error('payments', 'Paid registration could not be reconciled to the revenue ledger.', [
            'registration' => $registrationId,
            'payment_intent' => $intent,
        ]);
        return false;
    }

    /**
     * The one-button refund (architecture 9.3): refund Stripe and mark
     * the local row. Historical LGL artifacts are retained as references
     * for explicit reconciliation; this action never calls the CRM.
     */
    public static function refundRegistration(Container $container, int $regId): array
    {
        /** @var RegistrationRepository $repo */
        $repo = $container->get('registration.repo');
        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        /** @var Logger $logger */
        $logger = $container->get('logger');

        $row = $repo->find($regId);
        if (!$row) return ['ok' => false, 'message' => 'Registration not found.'];

        if ((string) $row->status === 'refunded') {
            return ['ok' => true, 'message' => 'This registration was already refunded.'];
        }
        $priorStatus = (string) $row->status;
        if (!$repo->setStatus($regId, 'refund_pending')) {
            return ['ok' => false, 'message' => 'The refund could not be reserved locally; Stripe was not changed.'];
        }

        $steps = [];
        if ($row->stripe_payment_intent && (int) $row->amount_cents > 0) {
            $refund = $stripe->refund((string) $row->stripe_payment_intent);
            $steps[] = $refund ? 'Stripe refunded' : 'Stripe refund FAILED';
            if (!$refund) {
                $repo->setStatus($regId, $priorStatus);
                return ['ok' => false, 'message' => 'Stripe refund failed; the registration was restored to its prior state.'];
            }
        }
        if ($row->lgl_invitation_id || $row->lgl_gift_id) {
            $steps[] = 'Historical LGL artifacts left unchanged for explicit sync/reconciliation';
        }
        if (!$repo->setStatus($regId, 'refunded')) {
            $logger->error('payments', 'Stripe refunded, but the registration could not be marked refunded.', [
                'registration' => $regId,
            ]);
            return ['ok' => false, 'message' => 'Stripe refunded the payment, but we could not finish local reconciliation. Do not issue another manual refund; consult the Activity Log.'];
        }
        // The ledger records the refund as its own row.
        $intent = (string) ($row->stripe_payment_intent ?? '');
        do_action('bg_registration_refunded', $regId, $intent);
        if ($intent !== '' && (int) $row->amount_cents > 0 && $container->has('revenue.ledger')) {
            $ledger = $container->get('revenue.ledger');
            $paymentId = $ledger->idByIntent($intent);
            if ($paymentId < 1) {
                $paymentId = $ledger->record([
                    'kind' => 'event_fee',
                    'amount_cents' => (int) $row->amount_cents,
                    'constituent_id' => (int) ($row->bg_constituent_id ?? 0),
                    'registration_id' => $regId,
                    'stripe_payment_intent' => $intent,
                    'source' => 'event-reconcile',
                    'note' => (string) get_the_title((int) $row->event_post_id),
                ]);
                if ($paymentId < 1) $paymentId = $ledger->idByIntent($intent);
            }
            if ($paymentId < 1 || !$ledger->ensureRefund($paymentId, 'event-refund')) {
                $logger->error('payments', 'Stripe and the registration were refunded, but the local revenue ledger could not be reconciled.', [
                    'registration' => $regId,
                    'payment_intent' => $intent,
                ]);
                return ['ok' => false, 'message' => 'Stripe refunded the payment and BosqueGrande marked the registration refunded, but the revenue ledger still requires reconciliation. Do not issue another refund; consult the Activity Log.'];
            }
        }
        $logger->info('payments', 'Registration refunded.', ['registration' => $regId, 'steps' => $steps]);
        return ['ok' => true, 'message' => implode('. ', $steps) ?: 'Registration cancelled.'];
    }
}
