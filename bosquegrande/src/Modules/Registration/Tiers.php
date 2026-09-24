<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * Registration fee options. The catalog is edited under Settings; each
 * event may offer all of them or a chosen subset. A gated option is free
 * for monthly donors only, checked on the server when verification is on.
 * A volunteer option chains the volunteer follow-up message.
 */
final class Tiers
{
    public const EVENT_META = '_bg_reg_tiers';

    /** @return array<string, array{label: string, amount_cents: int, gated: bool, volunteer_chain: bool}> */
    public static function all(): array
    {
        $out = [];
        foreach (RegistrationConfig::tiers() as $tier) {
            $out[$tier['key']] = [
                'label' => self::label($tier['label'], $tier['amount_cents']),
                'amount_cents' => $tier['amount_cents'],
                'gated' => $tier['gated'],
                'volunteer_chain' => $tier['volunteer_chain'],
            ];
        }
        return $out;
    }

    /**
     * The options offered for one event: the event's own selection when it
     * has one, otherwise every option.
     *
     * @return array<string, array{label: string, amount_cents: int, gated: bool, volunteer_chain: bool}>
     */
    public static function forEvent(int $eventId): array
    {
        $all = self::all();
        $chosen = $eventId > 0 ? get_post_meta($eventId, self::EVENT_META, true) : [];
        if (!is_array($chosen) || !$chosen) return $all;
        $out = [];
        foreach ($chosen as $key) {
            $key = sanitize_key((string) $key);
            if (isset($all[$key])) $out[$key] = $all[$key];
        }
        return $out ?: $all;
    }

    public static function exists(string $tier): bool
    {
        return isset(self::all()[$tier]);
    }

    public static function offeredFor(string $tier, int $eventId): bool
    {
        return isset(self::forEvent($eventId)[$tier]);
    }

    public static function amount(string $tier): int
    {
        return (int) (self::all()[$tier]['amount_cents'] ?? 0);
    }

    public static function isFree(string $tier): bool
    {
        return self::amount($tier) === 0;
    }

    public static function isGated(string $tier): bool
    {
        return (bool) (self::all()[$tier]['gated'] ?? false);
    }

    public static function volunteerChain(string $tier): bool
    {
        return (bool) (self::all()[$tier]['volunteer_chain'] ?? false);
    }

    /** The first option in catalog order, used when a form omits one. */
    public static function defaultKey(int $eventId = 0): string
    {
        $keys = array_keys(self::forEvent($eventId));
        return (string) ($keys[0] ?? '');
    }

    /** "$10 General admission"; free options show their label alone. */
    private static function label(string $label, int $amountCents): string
    {
        if ($amountCents <= 0) return $label;
        $prefix = \BosqueGrande\Core\Money::format($amountCents);
        $prefix = preg_replace('/\.00$/', '', $prefix) ?: $prefix;
        return $prefix . ' ' . $label;
    }
}
