<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Payment Setup (architecture 5.5): written for non-technical staff.
 * Secret fields are write-only; after save only a Connected badge shows.
 * Client staff paste their own keys into their own site; the contractor
 * never handles them.
 */
final class SetupPage
{
    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('bosquegrande', 'Payment Setup', 'Payment Setup',
                'manage_options', 'bosquegrande-payments', [$this, 'render']);
        }, 20);
        add_action('admin_post_bg_save_stripe', [$this, 'save']);
        add_action('admin_post_bg_test_stripe', [$this, 'test']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        /** @var Settings $settings */
        $settings = $this->container->get('settings');
        $pk = (string) $settings->get('stripe_publishable_key', '');
        $skSet = $settings->hasSecret('stripe_secret_key');
        $whSet = $settings->hasSecret('stripe_webhook_secret');
        // Online payment needs the secret and publishable
        // keys. The webhook secret hardens confirmation but no longer
        // blocks collection, so its absence is a recommendation.
        $canCollect = (string) $settings->get('stripe_publishable_key', '') !== ''
            && (string) $settings->get('stripe_secret_key', '') !== '';
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        ?>
        <div class="wrap"><h1>Payment Setup</h1>
        <?php if ($notice) : ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if (!$canCollect): ?><div class="notice notice-warning inline"><p><strong>Online payments are not ready.</strong>
        The secret key and publishable key must both be present and readable before the site can take a card payment.</p></div>
        <?php elseif (!$whSet): ?><div class="notice notice-warning inline"><p><strong>Online payments can be collected, but recurring accounting is not complete.</strong>
        A browser can confirm an initial card payment, but future monthly charges cannot post reliably to the accounting ledger until the signed Stripe webhook below is configured for <code>invoice.paid</code>.</p></div><?php endif; ?>
        <p style="max-width:640px">Connect the organization's own Stripe account in three steps.
        You are pasting keys directly into your own website; nobody else sees them, and they are
        stored encrypted. Once saved, keys are never shown again.</p>
        <ol style="max-width:640px">
          <li>Log in at <a href="https://dashboard.stripe.com" target="_blank" rel="noopener">dashboard.stripe.com</a>, open <strong>Developers &gt; API keys</strong>.</li>
          <li>Copy the <strong>Publishable key</strong> (starts with pk_) into box 1. Then click <strong>Create restricted key</strong>. Allow <em>PaymentIntents: Write</em>, <em>Refunds: Write</em>, <em>Customers: Read</em>, <em>Checkout Sessions: Write</em>, <em>Subscriptions: Write</em>, <em>Invoices: Read</em>, <em>Products: Write</em>, and <em>Prices: Write</em>. Copy that key (starts with rk_) into box 2. These permissions support event payments, the monthly-donation OneForm, recurring-payment reconciliation, and verified Donation Management without exposing the Stripe dashboard.</li>
          <li>Open <strong>Developers &gt; Webhooks &gt; Add endpoint</strong>, paste this URL:
            <code><?php echo esc_html(rest_url('bosquegrande/v1/stripe-webhook')); ?></code>,
            select the events <em>payment_intent.succeeded</em>, <em>payment_intent.payment_failed</em>, <em>checkout.session.completed</em>, and <em>invoice.paid</em>,
            then copy the <strong>Signing secret</strong> (starts with whsec_) into box 3.</li>
        </ol>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:640px">
          <?php wp_nonce_field('bg_save_stripe'); ?>
          <input type="hidden" name="action" value="bg_save_stripe">
          <table class="form-table">
            <tr><th>1. Publishable key</th>
              <td><input type="text" name="pk" class="regular-text" value="<?php echo esc_attr($pk); ?>" placeholder="pk_live_..."></td></tr>
            <tr><th>2. Restricted secret key</th>
              <td><input type="password" name="sk" class="regular-text" placeholder="<?php echo $skSet ? 'Saved (enter to replace)' : 'rk_live_...'; ?>" autocomplete="new-password">
              <?php if ($skSet) : ?> <span style="color:#2A6B40;font-weight:700">&#10003; Connected</span><?php endif; ?></td></tr>
            <tr><th>3. Webhook signing secret</th>
              <td><input type="password" name="wh" class="regular-text" placeholder="<?php echo $whSet ? 'Saved (enter to replace)' : 'whsec_...'; ?>" autocomplete="new-password">
              <?php if ($whSet) : ?> <span style="color:#2A6B40;font-weight:700">&#10003; Connected</span><?php endif; ?></td></tr>
          </table>
          <p><button class="button button-primary">Save</button></p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('bg_test_stripe'); ?>
          <input type="hidden" name="action" value="bg_test_stripe">
          <button class="button">Test connection</button>
        </form>
        </div>
        <?php
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_save_stripe');
        /** @var Settings $settings */
        $settings = $this->container->get('settings');
        $pk = sanitize_text_field((string) ($_POST['pk'] ?? ''));
        $sk = trim((string) ($_POST['sk'] ?? ''));
        $wh = trim((string) ($_POST['wh'] ?? ''));
        if ($pk !== '') $settings->set('stripe_publishable_key', $pk);
        $secretsSaved = true;
        if ($sk !== '') $secretsSaved = $settings->set('stripe_secret_key', $sk) && $secretsSaved;
        if ($wh !== '') $secretsSaved = $settings->set('stripe_webhook_secret', $wh) && $secretsSaved;
        $this->back($secretsSaved
            ? 'Saved. Keys are stored encrypted.'
            : 'The public key was saved, but secret Stripe credentials were refused because encrypted storage is unavailable.');
    }

    public function test(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_test_stripe');
        /** @var StripeClient $stripe */
        $stripe = $this->container->get('stripe');
        if (!$stripe->testConnection()) {
            $this->back('Connection failed. Check the restricted secret key and try again.');
        }
        if (!$stripe->testCheckoutAccess()) {
            $this->back('The API key works, but it cannot read or create Stripe Checkout Sessions. Edit the restricted key, set Checkout Sessions to Write, and test again.');
        }
        if (!$stripe->testInvoiceAccess()) {
            $this->back('The API key works for Checkout, but it cannot read paid Stripe invoices. Edit the restricted key, set Invoices to Read, and test again.');
        }
        $this->back($stripe->isConfigured()
            ? ($stripe->canVerifyWebhook()
                ? 'Success! Stripe credentials and invoices are readable and the webhook secret is set. Send test payment_intent.succeeded, checkout.session.completed, and invoice.paid events from Stripe to verify they reach this site.'
                : 'Success! Card collection is available, but add the webhook signing secret and subscribe it to invoice.paid before relying on the accounting ledger for future monthly charges.')
            : 'The Stripe API key works, but online payments stay off until the publishable key is also saved.');
    }

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-payments', 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }
}
