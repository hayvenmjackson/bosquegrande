<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Engagement;

use BosqueGrande\Core\Container;
use BosqueGrande\Modules\Revenue\Ledger;

if (!defined('ABSPATH')) exit;

/** Read-only-first accounting view over paid monthly Stripe invoice rows. */
final class MonthlyDonationsPage
{
    public const PAGE = 'bosquegrande-monthly-donations';

    public function __construct(private Container $container)
    {
    }

    public function render(): void
    {
        if (!current_user_can(PaymentDetails::CAP)) return;
        /** @var Ledger $ledger */
        $ledger = $this->container->get('revenue.ledger');
        $month = sanitize_text_field((string) ($_GET['bg_month'] ?? ''));
        if ($month !== '' && !preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) $month = '';
        $order = sanitize_key((string) ($_GET['bg_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $months = $ledger->monthlyMonths($order);
        if ($month !== '' && !in_array($month, $months, true)) $month = '';
        $rows = $ledger->monthlyRows($month, $order, 1000);
        $totals = $ledger->monthlyTotals($month);
        $notice = sanitize_text_field((string) ($_GET['bg_notice'] ?? ''));
        $noticeType = sanitize_key((string) ($_GET['bg_notice_type'] ?? 'success')) === 'error'
            ? 'notice-error'
            : 'notice-success';
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $timezoneLabel = $timezone->getName();
        $isAdmin = current_user_can('manage_options');
        $revenueUrl = add_query_arg('page', 'bosquegrande-revenue', admin_url('admin.php'));
        ?>
        <div class="wrap">
          <h1>Monthly Donations</h1>
          <?php if ($notice !== '') : ?>
            <div class="notice <?php echo esc_attr($noticeType); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
          <?php endif; ?>
          <p style="max-width:900px">Each row is a successfully paid monthly Stripe invoice recorded in the same append-only BosqueGrande ledger and QuickBooks review/export workflow as other donations. Dates and month filters use the site’s business timezone: <strong><?php echo esc_html($timezoneLabel); ?></strong>.</p>
          <?php if ($isAdmin) : ?>
            <div class="notice notice-info inline" style="max-width:900px"><p><strong>Administrator controls:</strong> Reconciliation safely rechecks recent Stripe subscription payments and cannot duplicate an invoice already in the ledger. Refund an eligible collected charge from <a href="<?php echo esc_url($revenueUrl); ?>">Revenue and ledger</a>. <strong>Refunding a charge does not cancel future monthly charges;</strong> subscription cancellation is a separate action through verified Donation Management or Stripe.</p></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0 18px">
              <?php wp_nonce_field('bg_reconcile_monthly_donations'); ?>
              <input type="hidden" name="action" value="bg_reconcile_monthly_donations">
              <button class="button button-primary" type="submit">Reconcile Recent Stripe Payments</button>
            </form>
          <?php else : ?>
            <div class="notice notice-info inline" style="max-width:900px"><p>This is a read-only accountant view. Reconciliation, refunds, QuickBooks exports, mappings, and subscription changes remain Administrator-only.</p></div>
          <?php endif; ?>

          <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin:18px 0">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label><strong>Month</strong><br>
              <select name="bg_month">
                <option value="">All months</option>
                <?php foreach ($months as $availableMonth) : ?>
                  <option value="<?php echo esc_attr($availableMonth); ?>" <?php selected($month, $availableMonth); ?>><?php echo esc_html($this->monthLabel($availableMonth, $timezone)); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><strong>Order</strong><br>
              <select name="bg_order">
                <option value="desc" <?php selected($order, 'desc'); ?>>Newest first</option>
                <option value="asc" <?php selected($order, 'asc'); ?>>Oldest first</option>
              </select>
            </label>
            <button class="button" type="submit">Apply</button>
          </form>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:900px;margin:0 0 20px">
            <?php
            $summary = [
                ['Paid invoices', number_format((int) $totals['count'])],
                ['Gross', $this->money((int) $totals['gross'])],
                ['Refunded', $this->money((int) $totals['refunded'])],
                ['Net', $this->money((int) $totals['net'])],
            ];
            foreach ($summary as [$label, $value]) : ?>
              <div class="card" style="max-width:none;margin:0;padding:14px 16px"><div class="description"><?php echo esc_html($label); ?></div><div style="font-size:1.35rem;font-weight:700;margin-top:4px"><?php echo esc_html($value); ?></div></div>
            <?php endforeach; ?>
          </div>

          <h2><?php echo esc_html($month !== '' ? $this->monthLabel($month, $timezone) : 'All monthly donations'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th>Collected</th><th>Payment</th><th>Donor</th><th>Amount</th><th>Stripe reference</th><th>Status</th><th>QuickBooks</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
              <tr><td colspan="8">No paid monthly donations were recorded for this period.</td></tr>
            <?php else :
                $store = $this->container->get('constituents.store');
                /** @var PaymentDetails $paymentDetails */
                $paymentDetails = $this->container->get('engagement.payment_details');
                foreach ($rows as $row) :
                    $constituentId = (int) ($row->constituent_id ?? 0);
                    $record = $constituentId > 0 ? $store->get($constituentId) : null;
                    $name = is_array($record)
                        ? trim((string) ($record['first_name'] ?? '') . ' ' . (string) ($record['last_name'] ?? ''))
                        : '';
                    if ($name === '') $name = $constituentId > 0 ? 'Contact #' . $constituentId : 'Identity awaiting match';
                    $invoiceId = str_starts_with((string) ($row->external_key ?? ''), 'stripe-invoice:')
                        ? substr((string) $row->external_key, strlen('stripe-invoice:'))
                        : '';
                    $refunded = (int) ($row->refunded_cents ?? 0);
                    $details = $paymentDetails->buttonForPayment($row, 'bg-monthly-detail');
            ?>
              <tr>
                <td style="white-space:nowrap"><?php echo esc_html(get_date_from_gmt((string) $row->created_at, 'M j, Y g:i a T')); ?></td>
                <td>BG-<?php echo (int) $row->id; ?></td>
                <td><?php echo esc_html($name); ?></td>
                <td><?php echo esc_html($this->money((int) $row->amount_cents)); ?></td>
                <td><?php echo $invoiceId !== '' ? '<code>' . esc_html($invoiceId) . '</code>' : '<span class="description">Unavailable</span>'; ?><?php if ((string) ($row->stripe_payment_intent ?? '') !== '') : ?><br><small><?php echo esc_html((string) $row->stripe_payment_intent); ?></small><?php endif; ?></td>
                <td><?php if ($refunded > 0) : ?><strong>Refunded <?php echo esc_html($this->money($refunded)); ?></strong><?php if ((string) ($row->refunded_at ?? '') !== '') : ?><br><small><?php echo esc_html(get_date_from_gmt((string) $row->refunded_at, 'M j, Y')); ?></small><?php endif; ?><?php else : ?>Paid<?php endif; ?></td>
                <td><?php echo $this->qboStatus($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                <td><?php echo $details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
          <?php if (count($rows) >= 1000) : ?><p class="description">Showing the first 1,000 receipts in the selected order. Choose a month to narrow the list.</p><?php endif; ?>
          <p class="description" style="max-width:900px">QuickBooks export remains fail-closed. Monthly donations use Kind <code>donation</code> and Matches <code>Monthly donation</code>; an Administrator must create the exact bookkeeper-approved account/item mapping before export.</p>
        </div>
        <?php
    }

    private function monthLabel(string $month, \DateTimeZone $timezone): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $timezone);
        return $date ? wp_date('F Y', $date->getTimestamp(), $timezone) : $month;
    }

    private function money(int $cents): string
    {
        return \BosqueGrande\Core\Money::format($cents);
    }

    private function qboStatus(object $row): string
    {
        $status = '';
        if ((string) ($row->exported_at ?? '') !== '') {
            $batch = (int) ($row->export_id ?? 0);
            $status = '<strong>Payment exported</strong>'
                . ($batch > 0 ? '<br><small>Batch #' . $batch . '</small>' : '');
        } else {
            $resolved = (int) ($row->qbo_review_resolved ?? 0);
            $disposition = (string) ($row->qbo_review_disposition ?? '');
            if ($resolved === 1 && $disposition === 'handled_in_qbo') {
                $status = '<strong>Payment handled in QBO</strong>';
            } elseif ($resolved === 1 && $disposition === 'exported') {
                $status = '<strong>Payment exported</strong>';
            } elseif ($resolved === 0 && (string) ($row->qbo_review_reason ?? '') !== '') {
                $status = '<strong>Payment review needed</strong><br><small>'
                    . esc_html((string) $row->qbo_review_reason) . '</small>';
            } else {
                $status = '<span>Payment not yet exported</span>';
            }
        }
        if ((int) ($row->refunded_cents ?? 0) < 1) return $status;

        $refundResolved = (int) ($row->refund_qbo_review_resolved ?? 0);
        $refundDisposition = (string) ($row->refund_qbo_review_disposition ?? '');
        if ($refundResolved === 1 && $refundDisposition === 'handled_in_qbo') {
            return $status . '<hr style="margin:5px 0"><strong>Refund accounting recorded</strong>';
        }
        if ($refundResolved === 0 && (string) ($row->refund_qbo_review_reason ?? '') !== '') {
            return $status . '<hr style="margin:5px 0"><strong>Refund review needed</strong><br><small>'
                . esc_html((string) $row->refund_qbo_review_reason) . '</small>';
        }
        return $status . '<hr style="margin:5px 0"><span>Refund accounting not yet reviewed</span>';
    }
}
