<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * The site's currency and money formatting. Amounts are stored as integer
 * minor units (cents), so only two-decimal currencies are offered. The
 * currency can change only while the payment ledger is empty.
 */
final class Money
{
    private const OPTION = 'bg_currency';
    private const DEFAULT = 'usd';

    /** Stripe-supported two-decimal currencies: code => display prefix. */
    public const CURRENCIES = [
        'usd' => '$',
        'cad' => 'CA$',
        'aud' => 'A$',
        'nzd' => 'NZ$',
        'eur' => '€',
        'gbp' => '£',
        'chf' => 'CHF ',
        'sek' => 'SEK ',
        'nok' => 'NOK ',
        'dkk' => 'DKK ',
        'mxn' => 'MX$',
        'brl' => 'R$',
        'zar' => 'R ',
        'sgd' => 'S$',
        'hkd' => 'HK$',
        'inr' => '₹',
    ];

    /** Lowercase ISO 4217 code, as Stripe expects it. */
    public static function currency(): string
    {
        $code = strtolower((string) get_option(self::OPTION, self::DEFAULT));
        return isset(self::CURRENCIES[$code]) ? $code : self::DEFAULT;
    }

    public static function code(): string
    {
        return strtoupper(self::currency());
    }

    public static function symbol(): string
    {
        return self::CURRENCIES[self::currency()];
    }

    /** "$12.50" style text for an amount in minor units. */
    public static function format(int $cents, int $decimals = 2): string
    {
        $sign = $cents < 0 ? '-' : '';
        return $sign . self::symbol() . number_format(abs($cents) / 100, $decimals, '.', ',');
    }

    /** Parse "12.50", "$12.50", or "1,200" into minor units; null when invalid. */
    public static function parse(string $raw): ?int
    {
        $raw = trim(str_replace([',', ' ', self::symbol(), '$'], '', $raw));
        if ($raw === '' || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $raw)) return null;
        return (int) round(((float) $raw) * 100);
    }

    /** True when the stored ledger row's currency is the site currency. */
    public static function matches(string $currency): bool
    {
        return strtolower($currency) === self::currency();
    }

    /** Change the currency. Refused once any payment is recorded. */
    public static function save(string $code): bool
    {
        $code = strtolower($code);
        if (!isset(self::CURRENCIES[$code])) return false;
        if ($code === self::currency()) return true;
        if (self::locked()) return false;
        return update_option(self::OPTION, $code, false);
    }

    public static function locked(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bg_payments';
        $suppressed = $wpdb->suppress_errors(true);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $wpdb->suppress_errors($suppressed);
        return $count > 0;
    }
}
