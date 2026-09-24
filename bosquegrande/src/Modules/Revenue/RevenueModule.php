<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Revenue;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Core\SetupWizard;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Payments\StripeClient;
use BosqueGrande\Modules\Engagement\PaymentDetails;

if (!defined('ABSPATH')) exit;

/**
 * Revenue: the branded money layer. The wizard's org type picks the
 * noun (Donations for nonprofits, Sales for businesses); the machinery
 * beneath is identical. The [bg_donate] legacy shortcode renders the shared
 * one-time/monthly donation OneForm, every
 * dollar in the typed ledger, one-button refunds, and the QuickBooks
 * paid-Sales-Receipt export with staff-created mapping precedence,
 * written multi-Class allocations, and a fail-closed review queue.
 */
final class RevenueModule implements ModuleInterface
{
    private const PAGE = 'bosquegrande-revenue';
    private const MIN_CENTS = 100;

    public function name(): string
    {
        return 'revenue';
    }

    public function label(): string
    {
        return 'Revenue (ledger, giving forms, QuickBooks export)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public static function noun(): string
    {
        return SetupWizard::revenueNoun();
    }

    /**
     * Receipt templates retain server-controlled noun, amount, name, and site
     * facts through required tokens.
     *
     * @param array<string, array{label:string,text:string}> $defaults
     * @return array<string, array{label:string,text:string}>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'donation_receipt_subject' => [
                'Donation receipt email: subject (keep {site_name})',
                'Thank you from {site_name}',
            ],
            'donation_receipt_body' => [
                'Donation receipt email: body (keep {first_name_suffix}, {noun}, {amount}, and {site_name})',
                "Hi{first_name_suffix},\n\nYour {noun} of {amount} went through. Thank you for supporting {site_name}!\n",
            ],
        ];
        $requiredTokens = [
            'donation_receipt_subject' => ['site_name'],
            'donation_receipt_body' => ['first_name_suffix', 'noun', 'amount', 'site_name'],
        ];
        foreach ($items as $key => [$label, $text]) {
            if (isset($defaults[$key])) continue;
            $defaults[$key] = [
                'label' => $label,
                'text' => $text,
                'group' => 'Donation receipts',
                'required_tokens' => $requiredTokens[$key],
            ];
        }
        return $defaults;
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);

        $container->set('revenue.ledger', fn(Container $c) => new Ledger(
            $c->get('logger'), $c->get('constituents.activity')
        ));
        $container->set('revenue.qbo', fn(Container $c) => new QboExporter(
            $c->get('revenue.ledger'), $c->get('constituents.store'),
            $c->get('encryption'),
            $c->get('logger')
        ));

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', self::noun(), self::noun(),
                PaymentDetails::CAP, self::PAGE, [$this, 'renderPage']);
        }, 13);

        foreach (['donation_intent' => false, 'rev_refund' => true, 'qbo_map' => true,
                  'qbo_map_update' => true, 'qbo_map_delete' => true, 'qbo_export' => true,
                  'qbo_preview' => true, 'qbo_verify' => true, 'qbo_redownload' => true,
                  'qbo_resolve' => true, 'qbo_region_classes' => true] as $op => $adminPost) {
            if ($adminPost) {
                add_action('admin_post_bg_' . $op, function () use ($container, $op): void {
                    $method = 'handle' . str_replace('_', '', ucwords($op, '_'));
                    $this->{$method}($container);
                });
            }
        }

        add_shortcode('bg_donate', [$this, 'donateShortcode']);

        add_action('rest_api_init', function () use ($container): void {
            register_rest_route('bosquegrande/v1', '/donation-intent', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Public form; validated inside.
                'callback' => fn(\WP_REST_Request $req) => $this->restDonationIntent($container, $req),
            ]);
            register_rest_route('bosquegrande/v1', '/confirm-donation', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => fn(\WP_REST_Request $req) => $this->restConfirmDonation($container, $req),
            ]);
        });

        // Donations settle through the same verified Stripe webhook.
        add_action('bg_stripe_event', function (string $type, array $pi) use ($container): void {
            if ($type === 'payment_intent.succeeded' && (string) ($pi['metadata']['bg_kind'] ?? '') === 'donation') {
                $this->settleDonation($container, $pi);
            }
        }, 10, 2);

        // Event fees land in the ledger from the registration payment action.
        add_action('bg_payment_completed', function (
            $email,
            $first,
            $eventTitle,
            $amountCents,
            $intent = '',
            $registrationId = 0,
            $localConstituentId = 0
        ) use ($container): void {
            /** @var ConstituentStore $store */
            $store = $container->get('constituents.store');
            $constituentId = (int) $localConstituentId;
            if ($constituentId < 1) {
                $ids = $store->available() ? $store->idsByEmail((string) $email) : [];
                $constituentId = count($ids) === 1 ? (int) $ids[0] : 0;
            }
            $ledgerId = $container->get('revenue.ledger')->record([
                'kind' => 'event_fee',
                'amount_cents' => (int) $amountCents,
                'constituent_id' => $constituentId,
                'registration_id' => (int) $registrationId,
                'stripe_payment_intent' => (string) $intent,
                'source' => 'event',
                'note' => (string) $eventTitle,
            ]);
            if ($ledgerId < 1) {
                $container->get('logger')->error('revenue', 'A completed event payment could not be written to the revenue ledger.', [
                    'registration' => (int) $registrationId,
                    'payment_intent' => (string) $intent,
                ]);
            }
        }, 10, 7);

        // Registration refunds mirror into the ledger.
        add_action('bg_registration_refunded', function ($regId, $intent) use ($container): void {
            /** @var Ledger $ledger */
            $ledger = $container->get('revenue.ledger');
            $paymentId = $ledger->idByIntent((string) $intent);
            if ($paymentId && !$ledger->ensureRefund($paymentId, 'event-refund')) {
                $container->get('logger')->error('revenue', 'Registration refund could not be mirrored into the ledger.', [
                    'registration' => (int) $regId,
                    'payment_intent' => (string) $intent,
                ]);
            }
        }, 10, 2);
    }

    /* ── The donation form ────────────────────────────────────────── */

    /** @param array<string, string>|string $atts */
    public function donateShortcode($atts): string
    {
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        if (!$container->has('monthly_donation.enrollment')) return '';
        return $container->get('monthly_donation.enrollment')->combinedShortcode($atts);
    }

    private function restDonationIntent(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        if ((string) $req->get_param('website') !== '') {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_generic_error', 'Something went wrong. Please try again.')], 400);
        }
        $cadence = sanitize_key((string) $req->get_param('cadence'));
        if ($cadence !== '' && $cadence !== 'one_time') {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_invalid_frequency', 'Choose a valid donation frequency.')], 400);
        }
        $requestId = self::validatedDonationRequestId((string) $req->get_param('request_id'));
        if ($requestId === '') {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_form_expired', 'This form expired. Please reload it and try again.')], 400);
        }
        $amount = (int) $req->get_param('amount_cents');
        $max = (int) apply_filters('bg_donation_max_cents', 500000);
        if ($amount < self::MIN_CENTS || $amount > $max) {
            return new \WP_REST_Response(['ok' => false,
                'message' => self::templatedWording(
                    'donation_one_time_amount_range',
                    'Amounts between {min_amount} and {max_amount}, please.',
                    [
                        '{min_amount}' => self::formatAmountCents(self::MIN_CENTS),
                        '{max_amount}' => self::formatAmountCents($max),
                    ]
                )], 400);
        }
        $email = ConstituentStore::normEmail((string) $req->get_param('email'));
        if (!is_email($email)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_one_time_email_invalid', 'Please enter a valid email address.')], 400);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        if (!self::claimDonationConfirmBudget('intent-ip:' . hash('sha256', $ip), 8, 15 * MINUTE_IN_SECONDS)
            || !self::claimDonationConfirmBudget('intent-global', 120, 15 * MINUTE_IN_SECONDS)
        ) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_one_time_rate_limit', 'Too many attempts; try again in a few minutes.')], 429);
        }
        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        $noun = self::noun() === 'Sales' ? 'Payment' : 'Donation';
        $donorName = sanitize_text_field((string) $req->get_param('name'));
        if ($donorName === '') {
            $donorName = trim(
                sanitize_text_field((string) $req->get_param('first_name')) . ' '
                . sanitize_text_field((string) $req->get_param('last_name'))
            );
        }
        $pi = $stripe->createPaymentIntent(
            $amount,
            $noun . ': ' . get_bloginfo('name'),
            [
                'bg_kind' => 'donation',
                'email' => $email,
                'donor_name' => $donorName,
            ],
            self::donationCreationIdempotencyKey('one_time', $requestId, $email, $amount)
        );
        if (!$pi || empty($pi['client_secret'])) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_payment_service_unavailable', 'The payment service is unavailable right now.')], 502);
        }
        return new \WP_REST_Response(['ok' => true, 'client_secret' => (string) $pi['client_secret']], 200);
    }

    private function restConfirmDonation(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        $intent = sanitize_text_field((string) $req->get_param('payment_intent'));
        if (strlen($intent) > 255 || !preg_match('/^pi_[A-Za-z0-9]+$/', $intent)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_payment_not_verified', 'Payment could not yet be verified.')], 400);
        }
        $failureKey = 'bg_donation_confirm_fail_' . hash('sha256', $intent);
        if (get_transient($failureKey)) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_payment_not_verified', 'Payment could not yet be verified.')], 409);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        if (!self::claimDonationConfirmBudget('object:' . hash('sha256', $ip . '|' . $intent), 6, MINUTE_IN_SECONDS)
            || !self::claimDonationConfirmBudget('global', 80, MINUTE_IN_SECONDS)
        ) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_verification_rate_limit', 'Too many verification attempts. Please wait briefly and try again.')], 429);
        }
        $pi = $container->get('stripe')->retrievePaymentIntent($intent);
        if (!is_array($pi)) {
            set_transient($failureKey, 1, 15);
            return new \WP_REST_Response(['ok' => false, 'message' => self::wording('donation_payment_not_verified', 'Payment could not yet be verified.')], 409);
        }
        if ((string) ($pi['status'] ?? '') !== 'succeeded'
            || (string) ($pi['metadata']['bg_kind'] ?? '') !== 'donation'
            || !hash_equals((string) ($pi['id'] ?? ''), $intent)
        ) {
            return new \WP_REST_Response([
                'ok' => false,
                'message' => self::wording('donation_payment_not_verified', 'Payment could not yet be verified.'),
            ], 409);
        }
        $settled = $this->settleDonation($container, $pi);
        return new \WP_REST_Response([
            'ok' => $settled,
            'message' => $settled
                ? self::wording('donation_payment_received', 'Payment received.')
                : self::wording('donation_payment_reconciliation_failed', 'Stripe received the payment, but we could not finish local reconciliation. Our team has been notified.'),
        ], $settled ? 200 : 500);
    }

    private static function claimDonationConfirmBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_don_confirm_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_don_confirm_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    private static function validatedDonationRequestId(string $requestId): string
    {
        $requestId = strtolower(trim($requestId));
        return preg_match('/^[a-f0-9]{32}$/D', $requestId) ? $requestId : '';
    }

    /** Bind a browser retry token to one exact Stripe creation operation. */
    private static function donationCreationIdempotencyKey(
        string $operation,
        string $requestId,
        string $email,
        int $amountCents
    ): string {
        $salt = (string) wp_salt('nonce') . '|' . home_url('/');
        $operation = sanitize_key($operation);
        $payload = $operation . '|' . $requestId . '|' . strtolower($email) . '|' . $amountCents;
        return 'bg-' . $operation . '-v1-' . hash_hmac('sha256', $payload, $salt);
    }

    private static function wording(string $key, string $fallback): string
    {
        $value = function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
        $value = trim(wp_strip_all_tags((string) $value));
        return $value !== '' ? $value : $fallback;
    }

    /** @param array<string, string> $tokens */
    private static function templatedWording(string $key, string $fallback, array $tokens): string
    {
        $template = function_exists('bg_str') ? bg_str($key, $fallback) : $fallback;
        $template = wp_strip_all_tags((string) $template);
        if (trim($template) === '') $template = $fallback;
        foreach (array_keys($tokens) as $token) {
            if (!str_contains($template, $token)) {
                $template = $fallback;
                break;
            }
        }
        return strtr($template, $tokens);
    }

    private static function formatAmountCents(int $amountCents): string
    {
        $decimals = $amountCents % 100 === 0 ? 0 : 2;
        return \BosqueGrande\Core\Money::format($amountCents, $decimals);
    }

    /** @param array<string, mixed> $pi Verified webhook payload. */
    public function settleDonation(Container $container, array $pi): bool
    {
        /** @var Ledger $ledger */
        $ledger = $container->get('revenue.ledger');
        $intent = (string) ($pi['id'] ?? '');
        if ($intent === '') return false;
        if ($ledger->idByExternalKey('stripe:' . $intent)) return true;
        $email = ConstituentStore::normEmail((string) ($pi['metadata']['email'] ?? ''));
        $name = (string) ($pi['metadata']['donor_name'] ?? '');
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $constituentId = ($store->available() && is_email($email))
            ? $store->upsertByEmail($email, ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? ''])
            : 0;
        $noun = self::noun() === 'Sales' ? 'payment' : 'donation';
        $paymentId = $ledger->record([
            'kind' => self::noun() === 'Sales' ? 'sale' : 'donation',
            'amount_cents' => (int) ($pi['amount'] ?? 0),
            'constituent_id' => $constituentId,
            'stripe_payment_intent' => $intent,
            'external_key' => 'stripe:' . $intent,
            'source' => 'form',
            'note' => ucfirst($noun) . ' form',
        ]);
        if (!$paymentId) {
            return $ledger->idByExternalKey('stripe:' . $intent) > 0;
        }
        if (is_email($email)) {
            $siteName = wp_strip_all_tags((string) get_bloginfo('name'));
            $subject = self::templatedWording(
                'donation_receipt_subject',
                'Thank you from {site_name}',
                ['{site_name}' => $siteName]
            );
            $subject = sanitize_text_field($subject);
            $body = self::templatedWording(
                'donation_receipt_body',
                "Hi{first_name_suffix},\n\nYour {noun} of {amount} went through. Thank you for supporting {site_name}!\n",
                [
                    '{first_name_suffix}' => ($parts[0] ?? '') !== '' ? ' ' . $parts[0] : '',
                    '{noun}' => $noun,
                    '{amount}' => \BosqueGrande\Core\Money::format((int) ($pi['amount'] ?? 0)),
                    '{site_name}' => $siteName,
                ]
            );
            $sent = wp_mail($email, $subject, $body);
            if (!$sent) {
                $container->get('logger')->warn('revenue', 'Thank-you email refused by the mailer; the payment itself settled fine.', []);
            }
        }
        $container->get('logger')->info('revenue', 'Form ' . $noun . ' settled.', ['amount' => (int) ($pi['amount'] ?? 0)]);
        return true;
    }

    /* ── Admin screen ─────────────────────────────────────────────── */

    public function renderPage(): void
    {
        if (!current_user_can(PaymentDetails::CAP)) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        /** @var Ledger $ledger */
        $ledger = $container->get('revenue.ledger');
        /** @var QboExporter $qbo */
        $qbo = $container->get('revenue.qbo');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        /** @var PaymentDetails|null $paymentDetails */
        $paymentDetails = $container->has('engagement.payment_details')
            ? $container->get('engagement.payment_details')
            : null;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $totals = $ledger->totals();
        $noun = self::noun();
        $isAdmin = current_user_can('manage_options');
        $eligiblePayments = $isAdmin ? $this->eligibleMappingPayments($ledger) : [];
        $eligibleById = [];
        foreach ($eligiblePayments as $eligiblePayment) {
            $eligibleById[(int) $eligiblePayment->id] = $eligiblePayment;
        }
        $selectedPaymentId = max(0, (int) ($_GET['bg_map_payment'] ?? 0));
        $selectedPayment = $selectedPaymentId > 0
            ? $this->eligibleMappingPayment($ledger, $selectedPaymentId)
            : null;
        $invalidSelectedPayment = $selectedPaymentId > 0 && !$selectedPayment;
        ?>
        <div class="wrap"><h1><?php echo esc_html($noun); ?></h1>
        <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:760px">Every dollar in one ledger: event fees, <?php echo $noun === 'Sales' ? 'sales' : 'donations'; ?>
        from the <code>[bg_donate]</code> form, and refunds (a refund is its own entry, never an edit). Card
        details never touch this site; Stripe holds them and we keep only its reference numbers.</p>
        <p><strong>All time:</strong>
            gross <?php echo esc_html(\BosqueGrande\Core\Money::format((int) $totals['gross'])); ?>,
            refunded <?php echo esc_html(\BosqueGrande\Core\Money::format((int) $totals['refunded'])); ?>,
            net <?php echo esc_html(\BosqueGrande\Core\Money::format((int) $totals['net'])); ?></p>

        <h2>Recent activity</h2>
        <table class="widefat striped">
            <thead><tr><th>#</th><th>When (UTC)</th><th>Kind</th><th>Person</th><th>Amount</th><th>What</th><th>Source</th><th></th></tr></thead>
            <tbody>
            <?php $rows = $ledger->recent(50);
            if (!$rows) echo '<tr><td colspan="8">Nothing yet.</td></tr>';
            foreach ($rows as $row):
                $person = '';
                if ((int) ($row->constituent_id ?? 0)) {
                    $record = $store->get((int) $row->constituent_id);
                    $person = $record ? trim($record['first_name'] . ' ' . $record['last_name']) : '';
                }
                $refundable = $row->kind !== 'refund'
                    && (string) ($row->stripe_payment_intent ?? '') !== ''
                    && !$ledger->refundOf((int) $row->id)
                    && current_user_can('manage_options');
                $detailButton = $paymentDetails
                    ? $paymentDetails->buttonForPayment($row, 'bg-revenue-detail')
                    : '';
            ?>
                <tr>
                    <td>BG-<?php echo (int) $row->id; ?></td>
                    <td style="white-space:nowrap"><?php echo esc_html(substr((string) $row->created_at, 0, 16)); ?></td>
                    <td><?php echo esc_html((string) $row->kind); ?></td>
                    <td><?php echo esc_html($person); ?></td>
                    <td><?php echo esc_html(($row->kind === 'refund' ? '-' : '') . \BosqueGrande\Core\Money::format((int) $row->amount_cents)); ?></td>
                    <td><?php echo esc_html((string) $row->note); ?></td>
                    <td><?php echo esc_html((string) $row->source); ?></td>
                    <td><div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap"><?php echo $detailButton; ?><?php if ($refundable): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Refund this payment in full through Stripe?');" style="display:inline">
                            <?php wp_nonce_field('bg_rev_refund_' . (int) $row->id); ?>
                            <input type="hidden" name="action" value="bg_rev_refund"><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
                            <button class="button button-small" type="submit">Refund</button>
                        </form>
                    <?php endif; ?><?php if (isset($eligibleById[(int) $row->id])): ?>
                        <a class="button button-small" href="<?php echo esc_url($this->mapPaymentUrl((int) $row->id)); ?>">Map</a>
                    <?php endif; ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!current_user_can('manage_options')): ?>
            <p class="description">This is a read-only accountant view. Refunds, mappings, exports, and reconciliation changes remain administrator-only.</p>
        </div>
        <?php return; endif; ?>

        <h2>QuickBooks Online paid Sales Receipts</h2>
        <p style="max-width:860px">BosqueGrande exports money already received as a paid <strong>Sales Receipt</strong>
        worksheet. In QBO Advanced, use <strong>+ Create &gt; Batch transactions &gt; Sales Receipts &gt; Import CSV</strong>.
        The columns are: BosqueGrande payment reference, customer, date, Product/Service,
        Description, Qty, Rate/Amount, Class, Deposit account, and payment method/reference. A payment exports once; unmapped or unsafe
        rows wait in Review instead of being guessed. Each file caps at 100 receipts and 999 receipt data lines
        (plus the header); run export again for the next batch.</p>
        <div class="notice notice-warning inline" style="max-width:900px"><p><strong>Gross receipts only:</strong>
        BosqueGrande does not have Stripe's exact fee for each receipt, and QuickBooks CSV import may reject
        negative lines, so processing fees are not exported. The bookkeeper must reconcile the exact Stripe processing fees
        separately. Refunds likewise wait for QBO's supported refund workflow.</p></div>

        <?php $qboVerified = get_option('bg_qbo_sales_receipt_verified', '') === '1'; ?>
        <?php if ($qboVerified): ?>
            <div class="notice notice-success inline" style="max-width:900px"><p><strong>Format verified.</strong>
            An administrator recorded that the bookkeeper test-imported this worksheet into your QuickBooks company,
            including the Class on each receipt line.</p></div>
        <?php else: ?>
            <div class="notice notice-error inline" style="max-width:900px"><p><strong>Normal export is locked.</strong>
            First download the test worksheet, import it into your QuickBooks company, and check the Sales
            Receipt fields and the Class on each line (sub-classes use a colon, for example <code>Programs:Youth</code>).</p></div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin:12px 0">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_qbo_preview'); ?>
                <input type="hidden" name="action" value="bg_qbo_preview">
                <button class="button" type="submit">Download test worksheet</button>
                <p class="description">TEST file only: it does not save an export, mark payments exported, or close reviews.</p>
            </form>
            <?php if (!$qboVerified): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:560px">
                    <?php wp_nonce_field('bg_qbo_verify'); ?>
                    <input type="hidden" name="action" value="bg_qbo_verify">
                    <label><input type="checkbox" name="confirmed" value="1" required>
                    I confirm that the bookkeeper test-imported this worksheet into our QuickBooks company
                    and the Sales Receipt fields and the Class on each line came through correctly.</label>
                    <p><button class="button" type="submit">Record QBO verification</button></p>
                </form>
            <?php endif; ?>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_qbo_export'); ?>
            <input type="hidden" name="action" value="bg_qbo_export">
            <p><button class="button button-primary" type="submit" <?php disabled(!$qboVerified); ?>>Download Sales Receipt CSV</button></p>
        </form>

        <h3>Class by region</h3>
        <p style="max-width:760px">Payments not covered by a mapping below take their Class from the payer's region
        (defined under Tags &amp; Regions). Leave a region blank to send its payments to Review, or set a default Class.</p>
        <?php $regionClasses = QboTransactionRegion::classes(); $regionList = \BosqueGrande\Modules\Constituents\RegionalGroups::regions(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_qbo_region_classes'); ?>
            <input type="hidden" name="action" value="bg_qbo_region_classes">
            <table class="form-table" style="max-width:760px"><tbody>
            <?php foreach ($regionList as $slug => $label): ?>
                <tr><th scope="row"><?php echo esc_html($label); ?></th>
                <td><input type="text" class="regular-text" maxlength="190" name="region_class[<?php echo esc_attr($slug); ?>]" value="<?php echo esc_attr((string) ($regionClasses['regions'][$slug] ?? '')); ?>"></td></tr>
            <?php endforeach; ?>
                <tr><th scope="row">Default Class</th>
                <td><input type="text" class="regular-text" maxlength="190" name="default_class" value="<?php echo esc_attr($regionClasses['default']); ?>">
                <p class="description">Used when the payer's region is unknown or has no Class above.</p></td></tr>
            </tbody></table>
            <p><button class="button" type="submit">Save region classes</button></p>
        </form>

        <h3>Saved export copies</h3>
        <p style="max-width:760px">BosqueGrande keeps each export encrypted so an interrupted or lost
        download can be retrieved without exporting the ledger rows a second time.</p>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>Batch</th><th>Created (UTC)</th><th>File</th><th>Receipts</th><th></th></tr></thead>
            <tbody>
            <?php $exports = $qbo->recentExports();
            if (!$exports) echo '<tr><td colspan="5">No export batches yet.</td></tr>';
            foreach ($exports as $export): ?>
                <tr><td>#<?php echo (int) $export->id; ?></td>
                    <td><?php echo esc_html((string) $export->created_at); ?></td>
                    <td><?php echo esc_html((string) $export->filename); ?></td>
                    <td><?php echo (int) ($export->receipts ?? $export->invoices ?? 0); ?></td>
                    <td><?php if ((int) $export->downloadable === 1): ?>
                        <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bg_qbo_redownload&id=' . (int) $export->id), 'bg_qbo_redownload_' . (int) $export->id)); ?>">Download again</a>
                    <?php else: ?><span class="description">Created before saved copies</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Mappings</h3>
        <p style="max-width:900px">The most specific rule wins: <strong>transaction override &gt;
        exact description &gt; payment route &gt; transaction type fallback &gt; Review</strong>. BosqueGrande never
        invents a Product/Service or Deposit account. When a mapping leaves Class blank and "Class by region" is
        set up, the Class comes from the donor region of the transaction's current mailing address. This never
        changes the contact's saved region. Missing or conflicting location data waits in Review instead of
        being guessed.</p>

        <h4>Create mapping from a transaction</h4>
        <?php if ($eligiblePayments): ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:1100px">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <label for="bg-map-payment"><strong>Eligible unexported payment</strong></label>
                <select id="bg-map-payment" name="bg_map_payment" required style="max-width:720px">
                    <option value="">Choose a real transaction...</option>
                    <?php foreach ($eligiblePayments as $payment): ?>
                        <option value="<?php echo (int) $payment->id; ?>" <?php selected($selectedPaymentId, (int) $payment->id); ?>><?php
                            echo esc_html($this->paymentOptionLabel($payment, $qbo));
                        ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">Use transaction</button>
            </form>
            <p class="description">Shows up to 2,000 positive, unexported receipts. Selecting one only prefills this page; nothing changes until Save mapping.</p>
        <?php else: ?>
            <p class="description">No positive unexported transactions are eligible for mapping.</p>
        <?php endif; ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:900px;margin-top:8px">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label for="bg-map-payment-id"><strong>Or enter a payment number</strong></label>
            <span>BG-</span><input id="bg-map-payment-id" type="number" name="bg_map_payment" min="1" step="1" required style="width:140px">
            <button class="button" type="submit">Use payment number</button>
        </form>

        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>Type</th><th>Applies to</th><th>Deposit account</th><th>Product/Service</th><th>Class handling</th><th></th></tr></thead>
            <tbody>
            <?php $maps = $qbo->mappings();
            $editMapId = max(0, (int) ($_GET['bg_edit_mapping'] ?? 0));
            $editMap = $editMapId > 0 ? $qbo->mapping($editMapId) : null;
            if (!$maps) echo '<tr><td colspan="6">None yet. Create the first written mapping below; unmapped rows wait in Review.</td></tr>';
            foreach ($maps as $m) {
                $display = $this->mappingDisplay($m, $ledger);
                $classDisplay = $this->mappingClassDisplay($qbo, $m);
                $editUrl = add_query_arg('bg_edit_mapping', (int) $m->id, admin_url('admin.php?page=' . self::PAGE));
                $deleteForm = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this mapping? Future matching rows will wait in review.\');" style="display:inline">'
                    . wp_nonce_field('bg_qbo_map_delete_' . (int) $m->id, '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="bg_qbo_map_delete"><input type="hidden" name="id" value="' . (int) $m->id . '">'
                    . '<button class="button button-small" type="submit">Delete</button></form>';
                printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Edit</a> %s</td></tr>',
                    esc_html($display['type']), esc_html($display['scope']),
                    esc_html((string) $m->qbo_account), esc_html((string) $m->qbo_item), $classDisplay,
                    esc_url($editUrl), $deleteForm);
            } ?>
            </tbody>
        </table>

        <?php
        $selectedDetails = $selectedPayment && $paymentDetails
            ? $paymentDetails->buttonForPayment($selectedPayment, 'bg-qbo-map-detail')
            : '';
        $storedAllocations = $editMap ? $qbo->allocationsForMapping($editMap) : [];
        // A legacy single Class is represented by qbo_class and belongs in
        // the 100% field, not in the 2–10-line split editor.
        $editAllocations = count($storedAllocations) >= 2 ? $storedAllocations : [];
        $editMappingError = $editMap ? $qbo->mappingValidationError($editMap) : null;
        $editDisplay = $editMap ? $this->mappingDisplay($editMap, $ledger) : null;
        ?>
        <form id="bg-qbo-mapping-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px;max-width:1000px">
            <h4><?php echo $editMap ? 'Edit mapping' : 'Add mapping'; ?></h4>
            <?php wp_nonce_field($editMap ? 'bg_qbo_map_update_' . (int) $editMap->id : 'bg_qbo_map'); ?>
            <input type="hidden" name="action" value="<?php echo $editMap ? 'bg_qbo_map_update' : 'bg_qbo_map'; ?>">
            <?php if ($editMap): ?><input type="hidden" name="mapping_id" value="<?php echo (int) $editMap->id; ?>"><?php endif; ?>
            <?php if ($editMap && $editDisplay): ?>
                <p><strong>Rule:</strong> <?php echo esc_html($editDisplay['type'] . ': ' . $editDisplay['scope']); ?>
                (the matching condition is locked while editing).</p>
                <?php if ($editMappingError): ?><div class="notice notice-error inline"><p><?php echo esc_html('This saved mapping is invalid: ' . $editMappingError . '. Enter corrected QBO fields before saving.'); ?></p></div><?php endif; ?>
            <?php elseif ($invalidSelectedPayment): ?>
                <div class="notice notice-error inline"><p>The requested transaction is no longer eligible: it may have been exported, refunded, handled in QBO, or fallen outside the bounded pending list. Choose another transaction above.</p></div>
            <?php elseif ($selectedPayment): ?>
                <input type="hidden" name="selected_payment_id" value="<?php echo (int) $selectedPayment->id; ?>">
                <div class="notice notice-info inline"><p><strong>Authoritative transaction:</strong>
                    <?php echo esc_html('BG-' . (int) $selectedPayment->id
                        . ' | ' . (string) $selectedPayment->kind
                        . ' | ' . (string) $selectedPayment->note
                        . ' | source ' . (string) $selectedPayment->source
                        . ' | route ' . $this->humanRoute($qbo->paymentRoute($selectedPayment))
                        . ' | $' . number_format(((int) $selectedPayment->amount_cents) / 100, 2)); ?></p>
                    <?php echo $selectedDetails; ?></div>
                <p><label><strong>Apply this mapping to</strong><br>
                    <select name="mapping_scope" required>
                        <?php foreach (['transaction', 'exact', 'route', 'kind'] as $scope):
                            $key = $qbo->mappingKeyForPayment($selectedPayment, $scope);
                            if (!$key) continue;
                        ?>
                            <option value="<?php echo esc_attr($scope); ?>"><?php echo esc_html((string) $key['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
            <?php else: ?>
                <p class="description">Legacy exact rule: use this only when no transaction is available to select above.</p>
                <p><label><strong>Transaction type</strong><br><select name="match_kind">
                    <?php foreach (['donation', 'event_fee', 'sale'] as $kind): ?>
                        <option value="<?php echo esc_attr($kind); ?>"><?php echo esc_html($kind); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
                <p><label><strong>Exact description/title</strong><br>
                    <input type="text" name="match_value" maxlength="190" placeholder="For example: Game Night" required class="regular-text">
                </label></p>
            <?php endif; ?>

            <p><label><strong>QBO Product/Service (required)</strong><br>
                <input type="text" name="qbo_item" maxlength="190" value="<?php echo esc_attr((string) ($editMap->qbo_item ?? '')); ?>" placeholder="For example: Recurring" required class="regular-text">
            </label></p>
            <p><label><strong>QBO Deposit account (optional)</strong><br>
                <input type="text" name="qbo_account" maxlength="190" value="<?php echo esc_attr((string) ($editMap->qbo_account ?? '')); ?>" placeholder="Use the exact QBO name" class="regular-text">
            </label></p>
            <p><label><strong>Fixed QBO Class at 100% (optional override)</strong><br>
                <input type="text" name="qbo_class" maxlength="190" value="<?php echo esc_attr((string) ($editMap->qbo_class ?? '')); ?>" placeholder="For example: Programs:Youth" class="regular-text">
            </label><br><span class="description">Leave blank to use the separate QBO transaction-region rule for each receipt. A fixed Class overrides that automatic region.</span></p>

            <h5 style="font-size:14px;margin-bottom:4px">Or allocate this receipt across 2–10 QBO Classes</h5>
            <p class="description" style="max-width:900px">Leave the 100% Class above blank when using these rows.
            Enter each exact hierarchical QBO Class name and a written percentage; complete rows must total exactly
            100.00%. A split overrides the automatic donor region. BosqueGrande never infers allocation percentages from Grant Tags.</p>
            <table class="widefat striped" style="max-width:720px">
                <thead><tr><th style="width:50px">Line</th><th>Exact QBO Class</th><th style="width:180px">Percent</th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < 10; $i++):
                    $allocation = $editAllocations[$i] ?? null;
                    $allocationClass = is_array($allocation) ? (string) ($allocation['class'] ?? '') : '';
                    $allocationPercent = is_array($allocation)
                        ? number_format(((int) ($allocation['bps'] ?? 0)) / 100, 2, '.', '')
                        : '';
                ?>
                    <tr><td><?php echo $i + 1; ?></td>
                        <td><input type="text" name="qbo_class_name[]" maxlength="190" value="<?php echo esc_attr($allocationClass); ?>" style="width:100%" placeholder="Programs:Youth"></td>
                        <td><input type="text" name="qbo_class_percent[]" inputmode="decimal" value="<?php echo esc_attr($allocationPercent); ?>" style="width:120px" placeholder="50.00"> %</td></tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <p><button class="button button-primary" type="submit" <?php disabled($invalidSelectedPayment && !$editMap); ?>><?php echo $editMap ? 'Save mapping' : 'Add mapping'; ?></button>
            <?php if ($editMap || $selectedPayment || $invalidSelectedPayment): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>">Cancel</a><?php endif; ?></p>
        </form>

        <h3>Review queue</h3>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>Payment</th><th>Why it is waiting</th><th></th></tr></thead>
            <tbody>
            <?php $reviews = $qbo->openReviews();
            if (!$reviews) echo '<tr><td colspan="3">Empty. Everything classifiable has a home.</td></tr>';
            foreach ($reviews as $review):
                $payment = $ledger->row((int) $review->payment_id);
                $reviewDetails = $payment && $paymentDetails
                    ? $paymentDetails->buttonForPayment($payment, 'bg-qbo-review-detail')
                    : '';
                $isRefundReview = $payment && (string) $payment->kind === 'refund';
                $resolveConfirm = $isRefundReview
                    ? 'This payment was already refunded through Stripe. Record the accounting treatment only; do not send funds again. Mark it handled only after the bookkeeper completes the QBO credit/refund workflow.'
                    : 'Use this only after the row has been handled manually in QuickBooks. It will not be offered again.';
                $resolveLabel = $isRefundReview ? 'Mark accounting recorded in QBO' : 'Mark handled in QBO';
            ?>
                <tr>
                    <td>BG-<?php echo (int) $review->payment_id; ?>
                        <?php if ($payment) {
                            $amount = ($isRefundReview ? '-' : '') . \BosqueGrande\Core\Money::format((int) $payment->amount_cents);
                            $parent = $isRefundReview && (int) ($payment->parent_id ?? 0) > 0
                                ? ', original BG-' . (int) $payment->parent_id
                                : '';
                            $state = $isRefundReview ? ', already refunded through Stripe' : '';
                            echo esc_html(' (' . $payment->kind . ', ' . $amount . $state . $parent . ', "' . $payment->note . '")');
                        } ?>
                        <?php echo $reviewDetails; ?></td>
                    <td><?php echo esc_html((string) $review->reason); ?></td>
                    <td><div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">
                        <?php if ($payment && $this->isEligibleMappingPayment($payment)): ?>
                            <a class="button button-small" href="<?php echo esc_url($this->mapPaymentUrl((int) $payment->id)); ?>">Create mapping</a>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode($resolveConfirm)); ?>);">
                            <?php wp_nonce_field('bg_qbo_resolve_' . (int) $review->id); ?>
                            <input type="hidden" name="action" value="bg_qbo_resolve"><input type="hidden" name="id" value="<?php echo (int) $review->id; ?>">
                            <button class="button button-small" type="submit"><?php echo esc_html($resolveLabel); ?></button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /* ── Handlers ─────────────────────────────────────────────────── */

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode($notice), admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    /** @return list<object> Positive, pending receipts that can safely seed a mapping. */
    private function eligibleMappingPayments(Ledger $ledger): array
    {
        $eligible = [];
        foreach (array_slice($ledger->unexported(2000), 0, 2000) as $payment) {
            if (!is_object($payment)
                || (int) ($payment->id ?? 0) < 1
                || (int) ($payment->amount_cents ?? 0) < 1
                || !in_array((string) ($payment->kind ?? ''), ['donation', 'event_fee', 'sale'], true)
                || !\BosqueGrande\Core\Money::matches((string) ($payment->currency ?? ''))
                || (string) ($payment->exported_at ?? '') !== ''
            ) {
                continue;
            }
            $eligible[] = $payment;
        }
        return $eligible;
    }

    private function eligibleMappingPayment(Ledger $ledger, int $paymentId): ?object
    {
        if ($paymentId < 1) return null;
        $rows = $ledger->unexportedAfter($paymentId - 1, 1);
        $payment = $rows[0] ?? null;
        return is_object($payment)
            && (int) ($payment->id ?? 0) === $paymentId
            && $this->isEligibleMappingPayment($payment)
                ? $payment
                : null;
    }

    private function isEligibleMappingPayment(object $payment): bool
    {
        return (int) ($payment->id ?? 0) > 0
            && (int) ($payment->amount_cents ?? 0) > 0
            && in_array((string) ($payment->kind ?? ''), ['donation', 'event_fee', 'sale'], true)
            && \BosqueGrande\Core\Money::matches((string) ($payment->currency ?? ''))
            && (string) ($payment->exported_at ?? '') === '';
    }

    private function mapPaymentUrl(int $paymentId): string
    {
        return add_query_arg(
            ['page' => self::PAGE, 'bg_map_payment' => max(0, $paymentId)],
            admin_url('admin.php')
        ) . '#bg-qbo-mapping-form';
    }

    private function paymentOptionLabel(object $payment, QboExporter $qbo): string
    {
        $note = trim((string) ($payment->note ?? ''));
        if ($note === '') $note = ucfirst((string) ($payment->kind ?? 'payment'));
        return 'BG-' . (int) ($payment->id ?? 0)
            . ' | ' . substr((string) ($payment->created_at ?? ''), 0, 10)
            . ' | ' . $this->humanKind((string) ($payment->kind ?? ''))
            . ' | $' . number_format(((int) ($payment->amount_cents ?? 0)) / 100, 2)
            . ' | ' . bg_substr($note, 0, 80)
            . ' | ' . $this->humanRoute($qbo->paymentRoute($payment));
    }

    /** @return array{type:string,scope:string} */
    private function mappingDisplay(object $mapping, Ledger $ledger): array
    {
        $kind = (string) ($mapping->match_kind ?? '');
        $value = (string) ($mapping->match_value ?? '');
        if ($kind === 'payment') {
            $payment = $ledger->row((int) $value);
            return [
                'type' => $payment ? $this->humanKind((string) $payment->kind) : 'Transaction',
                'scope' => 'Only BG-' . (int) $value . ' (transaction override)',
            ];
        }
        if (str_starts_with($kind, 'route_')) {
            return [
                'type' => $this->humanKind(substr($kind, 6)),
                'scope' => 'Payment route: ' . $this->humanRoute($value),
            ];
        }
        if ($kind === 'kind_default') {
            return [
                'type' => $this->humanKind($value),
                'scope' => 'All remaining transactions of this type',
            ];
        }
        return [
            'type' => $this->humanKind($kind),
            'scope' => 'Exact description: ' . $value,
        ];
    }

    private function humanKind(string $kind): string
    {
        return match ($kind) {
            'donation' => 'Donation',
            'event_fee' => 'Event fee',
            'sale' => 'Sale',
            'refund' => 'Refund',
            default => $kind !== '' ? ucwords(str_replace('_', ' ', $kind)) : 'Unknown',
        };
    }

    private function humanRoute(string $route): string
    {
        return match ($route) {
            'monthly_stripe' => 'Monthly Stripe donation',
            'website_form' => 'Website donation form',
            'event_card' => 'Event card payment',
            'event_cash' => 'Event cash payment',
            'event_check' => 'Event check payment',
            default => $route !== '' ? ucwords(str_replace('_', ' ', $route)) : 'Unknown route',
        };
    }

    /** Returns escaped display HTML for one mapping's written Class allocation. */
    private function mappingClassDisplay(QboExporter $qbo, object $mapping): string
    {
        $error = $qbo->mappingValidationError($mapping);
        if (is_string($error) && $error !== '') {
            return '<span style="color:#b32d2e"><strong>Invalid:</strong> ' . esc_html($error) . '</span>';
        }
        $allocations = $qbo->allocationsForMapping($mapping);
        if ($allocations) {
            $lines = [];
            foreach ($allocations as $allocation) {
                $lines[] = esc_html((string) ($allocation['class'] ?? ''))
                    . ': ' . esc_html(number_format(((int) ($allocation['bps'] ?? 0)) / 100, 2)) . '%';
            }
            return implode('<br>', $lines);
        }
        $class = trim((string) ($mapping->qbo_class ?? ''));
        return $class !== ''
            ? esc_html($class) . ': 100.00%'
            : '<span class="description">Automatic QBO donor region per transaction</span>';
    }

    /**
     * Parse the fixed Class rows without floats.
     *
     * @return array{ok:bool,class:string,allocations:list<array{class:string,bps:int}>,error:string}
     */
    private function postedClassConfiguration(): array
    {
        $mainClass = sanitize_text_field((string) wp_unslash((string) ($_POST['qbo_class'] ?? '')));
        if (bg_substr($mainClass, 0, 190) !== $mainClass) {
            return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'A QBO Class name is longer than 190 characters.'];
        }
        $rawNames = $_POST['qbo_class_name'] ?? [];
        $rawPercents = $_POST['qbo_class_percent'] ?? [];
        if (!is_array($rawNames) || !is_array($rawPercents)
            || count($rawNames) > 10 || count($rawPercents) > 10
        ) {
            return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Use no more than 10 Class allocation rows.'];
        }
        foreach (array_merge(array_keys($rawNames), array_keys($rawPercents)) as $index) {
            if (!is_int($index) || $index < 0 || $index > 9) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Class allocation rows are malformed.'];
            }
        }
        $rawNames = wp_unslash($rawNames);
        $rawPercents = wp_unslash($rawPercents);
        $allocations = [];
        $seen = [];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $rawName = $rawNames[$i] ?? '';
            $rawPercent = $rawPercents[$i] ?? '';
            if (!is_scalar($rawName) || !is_scalar($rawPercent)) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Each Class row must contain plain text.'];
            }
            $name = sanitize_text_field((string) $rawName);
            $percent = trim((string) $rawPercent);
            if ($name === '' && $percent === '') continue;
            if ($name === '' || $percent === '') {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Complete both the Class and Percent in every used allocation row.'];
            }
            if (bg_substr($name, 0, 190) !== $name) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'A QBO Class name is longer than 190 characters.'];
            }
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $percent)) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Percentages must be positive numbers with no more than two decimal places.'];
            }
            $parts = explode('.', $percent, 2);
            $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
            $bps = ((int) $parts[0] * 100) + (int) $fraction;
            if ($bps < 1 || $bps > 10000) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Each Class percentage must be greater than 0 and no greater than 100.00.'];
            }
            $dedupe = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (isset($seen[$dedupe])) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Each QBO Class may appear only once in an allocation.'];
            }
            $seen[$dedupe] = true;
            $allocations[] = ['class' => $name, 'bps' => $bps];
            $sum += $bps;
        }
        if ($allocations) {
            if ($mainClass !== '') {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Leave the 100% Class blank when using allocation rows.'];
            }
            if (count($allocations) < 2) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'A split allocation requires at least two complete Class rows.'];
            }
            if ($sum !== 10000) {
                return ['ok' => false, 'class' => '', 'allocations' => [], 'error' => 'Class allocation percentages must total exactly 100.00%.'];
            }
        }
        return ['ok' => true, 'class' => $mainClass, 'allocations' => $allocations, 'error' => ''];
    }

    /** @param array{header:mixed,rows:mixed} $batch */
    private function csvForBatch(array $batch): ?string
    {
        if (!isset($batch['header'], $batch['rows'])
            || !is_array($batch['header']) || !is_array($batch['rows'])
        ) {
            return null;
        }
        $stream = fopen('php://temp', 'w+');
        if (!is_resource($stream)) return null;
        foreach (array_merge([$batch['header']], $batch['rows']) as $row) {
            if (!is_array($row) || fputcsv($stream, $row, ',', '"', '') === false) {
                fclose($stream);
                return null;
            }
        }
        if (rewind($stream) === false) {
            fclose($stream);
            return null;
        }
        $csv = stream_get_contents($stream);
        $closed = fclose($stream);
        return is_string($csv) && $csv !== '' && $closed ? $csv : null;
    }

    private function streamCsv(string $filename, string $csv): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name($filename));
        header('Content-Length: ' . strlen($csv));
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV attachment.
        exit;
    }

    private function handleRevRefund(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('bg_rev_refund_' . $id);
        /** @var Ledger $ledger */
        $ledger = $container->get('revenue.ledger');
        $row = $ledger->row($id);
        if (!$row || (string) ($row->stripe_payment_intent ?? '') === '' || $ledger->refundOf($id)) {
            $this->back('Nothing to refund.');
        }
        /** @var StripeClient $stripe */
        $stripe = $container->get('stripe');
        $result = $stripe->refund((string) $row->stripe_payment_intent);
        if (!$result) {
            $this->back('Stripe refused the refund; the reason is in the log.');
        }
        if (!$ledger->ensureRefund($id, 'admin')) {
            $container->get('logger')->error('revenue', 'Stripe refunded, but the local refund ledger row could not be verified.', [
                'payment' => $id,
            ]);
            $this->back('Stripe refunded the payment, but we could not finish local reconciliation. Do not refund it again; consult the Activity Log.');
        }
        $this->back('Refunded in full.');
    }

    private function handleQboMap(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_qbo_map');
        /** @var QboExporter $qbo */
        $qbo = $container->get('revenue.qbo');
        /** @var Ledger $ledger */
        $ledger = $container->get('revenue.ledger');
        $classes = $this->postedClassConfiguration();
        if (!$classes['ok']) $this->back('Mapping not added: ' . $classes['error']);

        $account = sanitize_text_field((string) wp_unslash((string) ($_POST['qbo_account'] ?? '')));
        $item = sanitize_text_field((string) wp_unslash((string) ($_POST['qbo_item'] ?? '')));
        if ($item === '') $this->back('Mapping not added: QBO Product/Service is required.');
        if (bg_substr($account, 0, 190) !== $account || bg_substr($item, 0, 190) !== $item) {
            $this->back('Mapping not added: QBO names may not exceed 190 characters.');
        }

        $paymentId = max(0, (int) ($_POST['selected_payment_id'] ?? 0));
        if ($paymentId > 0) {
            // The ID selects a candidate only. Re-fetch the append-only row and
            // derive its condition; never trust posted kind/note/source data.
            $payment = $this->eligibleMappingPayment($ledger, $paymentId);
            if (!$payment) {
                $this->back('Mapping not added: that transaction is missing, exported, refunded, or already handled in QBO.');
            }
            $scope = sanitize_key((string) ($_POST['mapping_scope'] ?? ''));
            if (!in_array($scope, ['transaction', 'exact', 'route', 'kind'], true)) {
                $this->back('Mapping not added: choose a valid mapping scope.');
            }
            $key = $qbo->mappingKeyForPayment($payment, $scope);
            if (!$key || empty($key['kind']) || empty($key['value'])) {
                $this->back('Mapping not added: the scope could not be derived from the selected transaction.');
            }
            $kind = (string) $key['kind'];
            $value = (string) $key['value'];
        } else {
            $kind = sanitize_key((string) ($_POST['match_kind'] ?? ''));
            $value = sanitize_text_field((string) wp_unslash((string) ($_POST['match_value'] ?? '')));
            if (!in_array($kind, ['donation', 'event_fee', 'sale'], true) || $value === '') {
                $this->back('Mapping not added: choose a valid transaction type and exact description.');
            }
            if (bg_substr($value, 0, 190) !== $value) {
                $this->back('Mapping not added: the exact description may not exceed 190 characters.');
            }
        }

        $ok = $qbo->addMapping(
            $kind,
            $value,
            $account,
            $item,
            $classes['class'],
            $classes['allocations']
        );
        $this->back($ok
            ? 'Mapping added. Matching pending receipts can now be included in a test worksheet.'
            : 'Mapping not added: the rule is a duplicate, a field is invalid, or Product/Service is missing.');
    }

    private function handleQboMapUpdate(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = max(0, (int) ($_POST['mapping_id'] ?? 0));
        check_admin_referer('bg_qbo_map_update_' . $id);
        /** @var QboExporter $qbo */
        $qbo = $container->get('revenue.qbo');
        $mapping = $qbo->mapping($id);
        if (!$mapping) $this->back('Mapping not saved: that mapping no longer exists.');
        $classes = $this->postedClassConfiguration();
        if (!$classes['ok']) $this->back('Mapping not saved: ' . $classes['error']);
        $account = sanitize_text_field((string) wp_unslash((string) ($_POST['qbo_account'] ?? '')));
        $item = sanitize_text_field((string) wp_unslash((string) ($_POST['qbo_item'] ?? '')));
        if ($item === '') $this->back('Mapping not saved: QBO Product/Service is required.');
        if (bg_substr($account, 0, 190) !== $account || bg_substr($item, 0, 190) !== $item) {
            $this->back('Mapping not saved: QBO names may not exceed 190 characters.');
        }
        // The match condition comes from the stored mapping. Hidden form data
        // cannot change a transaction override into a broader rule.
        $ok = $qbo->updateMapping(
            $id,
            (string) $mapping->match_kind,
            (string) $mapping->match_value,
            $account,
            $item,
            $classes['class'],
            $classes['allocations']
        );
        $this->back($ok
            ? 'Mapping saved.'
            : 'Mapping not saved: a field is invalid, the mapping is missing, or Product/Service is missing.');
    }

    private function handleQboMapDelete(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = max(0, (int) ($_POST['id'] ?? 0));
        check_admin_referer('bg_qbo_map_delete_' . $id);
        $ok = $container->get('revenue.qbo')->deleteMapping($id);
        $this->back($ok ? 'Mapping deleted.' : 'Mapping was not deleted.');
    }

    private function handleQboResolve(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('bg_qbo_resolve_' . $id);
        $ok = $container->get('revenue.qbo')->resolveReview($id);
        $this->back($ok
            ? 'Marked handled in QuickBooks. This row will not be offered again.'
            : 'The review could not be marked handled; it remains in the queue.');
    }

    private function handleQboRegionClasses(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_qbo_region_classes');
        $posted = isset($_POST['region_class']) && is_array($_POST['region_class'])
            ? wp_unslash($_POST['region_class'])
            : [];
        QboTransactionRegion::save(
            array_map(static fn($value): string => sanitize_text_field((string) $value), $posted),
            sanitize_text_field(wp_unslash((string) ($_POST['default_class'] ?? '')))
        );
        $this->back('Region classes saved.');
    }

    private function handleQboVerify(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_qbo_verify');
        if ((string) ($_POST['confirmed'] ?? '') !== '1') {
            $this->back('QBO verification was not recorded: the explicit bookkeeper confirmation is required.');
        }
        update_option('bg_qbo_sales_receipt_verified', '1', false);
        $this->back(get_option('bg_qbo_sales_receipt_verified', '') === '1'
            ? 'QuickBooks verification recorded. Normal exports are now enabled.'
            : 'QBO verification could not be saved; normal exports remain locked.');
    }

    private function handleQboPreview(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_qbo_preview');
        /** @var QboExporter $qbo */
        $qbo = $container->get('revenue.qbo');
        $batch = $qbo->prepare(false);
        if (empty($batch['rows'])) {
            $reviewed = max(0, (int) ($batch['reviewed'] ?? 0));
            $this->back('No mapped Sales Receipts are available for a test worksheet'
                . ($reviewed ? '; ' . $reviewed . ' waiting in Review' : '') . '.');
        }
        $csv = $this->csvForBatch($batch);
        if ($csv === null) {
            $this->back('The TEST Sales Receipt worksheet could not be written. No payments were marked exported.');
        }
        // Deliberately no saved export, markExported(), or review closure.
        $this->streamCsv('bg-qbo-sales-receipts-TEST-' . gmdate('Ymd-His') . '.csv', $csv);
    }

    private function handleQboRedownload(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $id = max(0, (int) ($_GET['id'] ?? 0));
        check_admin_referer('bg_qbo_redownload_' . $id);
        $copy = $container->get('revenue.qbo')->exportCopy($id);
        if (!$copy) $this->back('That saved export is unavailable or did not pass its integrity check.');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name((string) $copy['filename']));
        header('Content-Length: ' . strlen((string) $copy['csv']));
        echo (string) $copy['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV attachment.
        exit;
    }

    private function handleQboExport(Container $container): void
    {
        global $wpdb;
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_qbo_export');
        if (get_option('bg_qbo_sales_receipt_verified', '') !== '1') {
            $this->back('Normal export is locked until an administrator records a successful QuickBooks test import.');
        }
        $exportLock = 'bg_qbo_export_lock';
        $exportOwner = OptionLease::acquire($exportLock, 10 * MINUTE_IN_SECONDS);
        if ($exportOwner === '') {
            $this->back('Another QuickBooks export is already being prepared.');
        }
        register_shutdown_function(static function () use ($exportLock, $exportOwner): void {
            OptionLease::release($exportLock, $exportOwner);
        });

        /** @var QboExporter $qbo */
        $qbo = $container->get('revenue.qbo');
        /** @var Ledger $ledger */
        $ledger = $container->get('revenue.ledger');
        $batch = $qbo->prepare();
        if (!$batch['rows']) {
            $this->back('No Sales Receipts are ready to export' . ($batch['reviewed'] ? '; ' . $batch['reviewed'] . ' waiting in Review' : '') . '.');
        }
        $csv = $this->csvForBatch($batch);
        if ($csv === null) $this->back('The Sales Receipt CSV could not be written. No ledger rows were marked exported.');
        $filename = 'bg-qbo-sales-receipts-' . gmdate('Ymd-His') . '.csv';
        if ($wpdb->query('START TRANSACTION') === false) {
            $databaseError = bg_substr(sanitize_text_field((string) $wpdb->last_error), 0, 180);
            $container->get('logger')->error('revenue', 'QuickBooks export transaction could not be started.', [
                'stage' => 'start',
                'receipts' => count($batch['ids']),
                'database_error' => $databaseError,
            ]);
            $this->back('The export transaction could not be started. No ledger rows were marked exported.');
        }
        $exportId = $qbo->recordExport($filename, count($batch['ids']), $csv);
        $failureStage = '';
        if ($exportId < 1) {
            $failureStage = 'retain_copy';
        } elseif (!$ledger->markExported($batch['ids'], $exportId)) {
            $failureStage = 'claim_ledger_rows';
        } elseif (!$qbo->closeReviewsForExport($batch['ids'])) {
            $failureStage = 'close_reviews';
        }
        if ($failureStage !== '') {
            $databaseError = bg_substr(sanitize_text_field((string) $wpdb->last_error), 0, 180);
            $wpdb->query('ROLLBACK');
            // recordExport's in-transaction diagnostic is rolled back with the
            // failed batch on transactional tables. Log only bounded metadata
            // after rollback so the Activity Log retains an actionable cause
            // without copying customer or CSV data into it.
            $container->get('logger')->error('revenue', 'QuickBooks export transaction was rolled back.', [
                'stage' => $failureStage,
                'receipts' => count($batch['ids']),
                'database_error' => $databaseError,
            ]);
            $this->back('The export could not be committed. No ledger rows were marked exported.');
        }
        if ($wpdb->query('COMMIT') === false) {
            $databaseError = bg_substr(sanitize_text_field((string) $wpdb->last_error), 0, 180);
            $wpdb->query('ROLLBACK');
            $container->get('logger')->error('revenue', 'QuickBooks export transaction could not be committed.', [
                'stage' => 'commit',
                'receipts' => count($batch['ids']),
                'database_error' => $databaseError,
            ]);
            $this->back('The export transaction could not be committed.');
        }
        OptionLease::release($exportLock, $exportOwner);
        $this->streamCsv($filename, $csv);
    }
}
