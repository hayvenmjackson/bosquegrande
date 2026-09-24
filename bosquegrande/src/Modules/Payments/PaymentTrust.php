<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Payments;

if (!defined('ABSPATH')) exit;

/**
 * Plain-language payment-security disclosure used anywhere BosqueGrande asks a
 * visitor to enter or continue to payment details. The wording deliberately
 * distinguishes payment credentials (handled by Stripe) from contact/profile
 * information (handled by the WordPress site under its privacy policy).
 */
final class PaymentTrust
{
    private const STRIPE_SECURITY_URL = 'https://docs.stripe.com/security';

    /**
     * Mutable labels around the immutable payment-security facts and URLs.
     *
     * @param array<string, array{label:string,text:string}> $defaults
     * @return array<string, array{label:string,text:string}>
     */
    public static function wordingDefaults(array $defaults): array
    {
        $items = [
            'payment_trust_default_intro' => ['Payment trust: default embedded introduction', 'The card-entry fields below are secure Stripe fields.'],
            'payment_trust_embedded_title' => ['Payment trust: embedded-payment heading', 'Secure payment powered by Stripe'],
            'payment_trust_embedded_explanation' => ['Payment trust: embedded-payment explanation (keep {processor_fact} and {card_storage_fact})', '{processor_fact} {card_storage_fact}'],
            'payment_trust_hosted_title' => ['Payment trust: hosted-checkout heading', 'Secure checkout hosted by Stripe'],
            'payment_trust_hosted_explanation' => ['Payment trust: hosted-checkout explanation (keep all fact tokens)', '{hosted_fact} {address_fact} {domain_fact} {processor_fact} {card_storage_fact}'],
            'payment_trust_compact_label' => ['Payment trust: compact Stripe label', 'Secure Stripe payment:'],
            'payment_trust_compact_explanation' => ['Payment trust: compact explanation (keep {processor_fact} and {card_storage_fact})', '{processor_fact} {card_storage_fact}'],
            'payment_trust_recipient_label' => ['Payment trust: compact recipient label', 'Payment recipient:'],
            'payment_trust_recipient_explanation' => ['Payment trust: recipient explanation (keep {recipient_fact} and {processor_fact})', '{recipient_fact} {processor_fact}'],
            'payment_trust_contact_explanation' => ['Payment trust: site-data explanation (keep all fact/link tokens)', '{site_data_fact} {card_record_fact} {links}'],
            'payment_trust_security_link' => ['Payment trust: Stripe-security link label', 'How Stripe protects payments'],
            'payment_trust_privacy_link' => ['Payment trust: privacy-policy link label', 'Privacy policy'],
            'payment_trust_aria_label' => ['Payment trust: accessibility label', 'Payment recipient and secure payment information'],
        ];
        $requiredTokens = [
            'payment_trust_embedded_explanation' => ['processor_fact', 'card_storage_fact'],
            'payment_trust_hosted_explanation' => ['hosted_fact', 'address_fact', 'domain_fact', 'processor_fact', 'card_storage_fact'],
            'payment_trust_compact_explanation' => ['processor_fact', 'card_storage_fact'],
            'payment_trust_recipient_explanation' => ['recipient_fact', 'processor_fact'],
            'payment_trust_contact_explanation' => ['site_data_fact', 'card_record_fact', 'links'],
        ];
        foreach ($items as $key => [$label, $text]) {
            if (!isset($defaults[$key])) {
                $defaults[$key] = [
                    'label' => $label,
                    'text' => $text,
                    'group' => 'Payment security',
                ];
                if (isset($requiredTokens[$key])) {
                    $defaults[$key]['required_tokens'] = $requiredTokens[$key];
                }
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

    /**
     * Escape editor-controlled prose first, then inject renderer-owned facts
     * and links only at required placeholders.
     *
     * @param array<string, string> $safeHtmlTokens
     */
    private static function htmlTemplateWording(
        string $key,
        string $fallback,
        array $safeHtmlTokens
    ): string {
        $template = self::wording($key, $fallback);
        foreach (array_keys($safeHtmlTokens) as $token) {
            if (!str_contains($template, $token)) {
                $template = $fallback;
                break;
            }
        }
        return strtr(esc_html($template), $safeHtmlTokens);
    }

    public static function embedded(string $intro = ''): string
    {
        $intro = trim($intro);
        if ($intro === '') {
            $intro = self::wording(
                'payment_trust_default_intro',
                'The card-entry fields below are secure Stripe fields.'
            );
        }

        $body = self::recipientDisclosure()
            . '<p style="margin:4px 0 0;line-height:1.5">' . esc_html($intro) . ' '
            . self::htmlTemplateWording(
                'payment_trust_embedded_explanation',
                '{processor_fact} {card_storage_fact}',
                [
                    '{processor_fact}' => 'Payment details are encrypted and sent directly to Stripe over HTTPS.',
                    '{card_storage_fact}' => '<strong>This site does not receive or store your full card number or card security code.</strong>',
                ]
            ) . '</p>'
            . self::contactDisclosure();

        return self::box(self::wording(
            'payment_trust_embedded_title',
            'Secure payment powered by Stripe'
        ), $body);
    }

    public static function hostedCheckout(): string
    {
        $body = self::recipientDisclosure()
            . '<p style="margin:4px 0 0;line-height:1.5">'
            . self::htmlTemplateWording(
                'payment_trust_hosted_explanation',
                '{hosted_fact} {address_fact} {domain_fact} {processor_fact} {card_storage_fact}',
                [
                    '{hosted_fact}' => 'You will continue to Stripe’s hosted checkout.',
                    '{address_fact}' => 'Before entering payment details, confirm that the next page uses HTTPS. Standard Stripe Checkout addresses begin with <code style="font-size:.92em">https://checkout.stripe.com/</code>;',
                    '{domain_fact}' => 'an organization may also use a Stripe-configured checkout domain.',
                    '{processor_fact}' => 'Stripe encrypts and handles the payment details.',
                    '{card_storage_fact}' => '<strong>This site does not receive or store your full card number or card security code.</strong>',
                ]
            ) . '</p>'
            . self::contactDisclosure();

        return self::box(self::wording(
            'payment_trust_hosted_title',
            'Secure checkout hosted by Stripe'
        ), $body);
    }

    public static function compact(): string
    {
        return '<p class="bg-payment-trust-compact" style="margin:10px 0;color:#33463a;font-size:.88rem;line-height:1.45">'
            . self::compactRecipientDisclosure()
            . '<strong style="color:#1F5130">' . esc_html(self::wording(
                'payment_trust_compact_label',
                'Secure Stripe payment:'
            )) . '</strong> ' . self::htmlTemplateWording(
                'payment_trust_compact_explanation',
                '{processor_fact} {card_storage_fact}',
                [
                    '{processor_fact}' => 'card details go directly to Stripe over encrypted HTTPS;',
                    '{card_storage_fact}' => 'this site never receives or stores the full card number or card security code.',
                ]
            ) . '</p>';
    }

    private static function recipientName(): string
    {
        return \BosqueGrande\Core\Organization::payee();
    }

    private static function recipientDisclosure(): string
    {
        $recipient = self::recipientName();
        if ($recipient === '') return '';

        $recipientSentence = rtrim($recipient, " \t\n\r\0\x0B.") . '.';

        return '<p class="bg-payment-recipient" style="margin:4px 0 8px;line-height:1.5">'
            . self::htmlTemplateWording(
                'payment_trust_recipient_explanation',
                '{recipient_fact} {processor_fact}',
                [
                    '{recipient_fact}' => '<strong>Payments made through this page go to ' . esc_html($recipientSentence) . '</strong>',
                    '{processor_fact}' => 'Stripe securely processes the card transaction.',
                ]
            ) . '</p>';
    }

    private static function compactRecipientDisclosure(): string
    {
        $recipient = self::recipientName();
        if ($recipient === '') return '';

        $recipientSentence = rtrim($recipient, " \t\n\r\0\x0B.") . '.';

        return '<strong style="color:#1F5130">' . esc_html(self::wording(
            'payment_trust_recipient_label',
            'Payment recipient:'
        )) . ' ' . esc_html($recipientSentence) . '</strong> ';
    }

    private static function contactDisclosure(): string
    {
        $privacyUrl = function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
        $links = '<a href="' . esc_url(self::STRIPE_SECURITY_URL) . '" target="_blank" rel="noopener noreferrer" '
            . 'style="color:#5A2A7A;font-weight:700">' . esc_html(self::wording(
                'payment_trust_security_link',
                'How Stripe protects payments'
            )) . '</a>';
        if ($privacyUrl !== '') {
            $links .= ' <span aria-hidden="true">·</span> <a href="' . esc_url($privacyUrl) . '" '
                . 'style="color:#5A2A7A;font-weight:700">' . esc_html(self::wording(
                    'payment_trust_privacy_link',
                    'Privacy policy'
                )) . '</a>';
        }

        return '<p style="margin:7px 0 0;color:#4D5F53;font-size:.88rem;line-height:1.45">'
            . self::htmlTemplateWording(
                'payment_trust_contact_explanation',
                '{site_data_fact} {card_record_fact} {links}',
                [
                    '{site_data_fact}' => 'Name, email, event, amount, and other information entered outside Stripe’s payment fields are handled by this site for registration, receipts, donor records, and accounting.',
                    '{card_record_fact}' => 'Card credentials are not part of that site record.',
                    '{links}' => $links,
                ]
            ) . '</p>';
    }

    private static function box(string $title, string $body): string
    {
        $icon = '<span aria-hidden="true" style="display:flex;align-items:center;justify-content:center;flex:0 0 42px;width:42px;height:42px;border-radius:50%;background:#1F5130;color:#FFE600">'
            . '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>'
            . '<path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
            . '</svg></span>';

        return '<aside class="bg-payment-trust" role="note" aria-label="' . esc_attr(self::wording(
            'payment_trust_aria_label',
            'Payment recipient and secure payment information'
        )) . '" '
            . 'style="display:flex;gap:13px;align-items:flex-start;margin:12px 0 16px;padding:14px 15px;border:2px solid #1F5130;border-radius:12px;background:#F7FBF7;color:#1F2A24;box-shadow:0 2px 8px rgba(31,81,48,.10)">'
            . $icon . '<div style="min-width:0"><strong style="display:block;color:#1F5130;font-size:1rem">'
            . esc_html($title) . '</strong>' . $body . '</div></aside>';
    }
}
