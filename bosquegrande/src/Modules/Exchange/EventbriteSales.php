<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Exchange;

if (!defined('ABSPATH')) exit;

/**
 * Interprets Eventbrite's event and ticket-availability JSON.
 *
 * Eventbrite can leave an event itself "live" after every ticket class
 * has stopped selling. The event status therefore cannot be the only
 * import gate. This reader accepts list responses, expanded event
 * responses, and the wrapped payload used by captured-record fixtures.
 */
final class EventbriteSales
{
    public const SELLING = 'selling';
    public const CLOSED = 'no_longer_selling';
    public const NOT_YET = 'not_yet_selling';
    public const UNKNOWN = 'unknown';

    /** @param array<string, mixed> $payload */
    public static function state(array $payload, ?int $now = null): string
    {
        $event = self::event($payload);
        $now ??= time();

        if (in_array(self::eventStatus($event), ['ended', 'completed', 'canceled', 'cancelled'], true)) {
            return self::CLOSED;
        }

        foreach ([
            $event['sales_status'] ?? null,
            $event['ticket_sales_status'] ?? null,
            is_array($event['ticket_availability'] ?? null)
                ? ($event['ticket_availability']['status'] ?? $event['ticket_availability']['sales_status'] ?? null)
                : null,
        ] as $rawStatus) {
            $status = self::key($rawStatus);
            if (in_array($status, ['no_longer_selling', 'no_longer_selling_tickets', 'sales_ended', 'sale_ended', 'closed', 'off_sale'], true)) {
                return self::CLOSED;
            }
            if (in_array($status, ['selling', 'on_sale', 'available'], true)) {
                return self::SELLING;
            }
            if (in_array($status, ['not_yet_selling', 'not_yet_on_sale', 'sales_not_started'], true)) {
                return self::NOT_YET;
            }
        }

        $availability = $event['ticket_availability'] ?? null;
        if (is_array($availability)) {
            $hasTickets = self::boolean($availability['has_available_tickets'] ?? null);
            if ($hasTickets === true) return self::SELLING;
            if ($hasTickets === false) {
                $salesStart = self::timestamp($availability['start_sales_date'] ?? null);
                return $salesStart !== null && $salesStart > $now ? self::NOT_YET : self::CLOSED;
            }
        }

        $classes = $event['ticket_classes'] ?? [];
        if (is_array($classes) && isset($classes['ticket_classes']) && is_array($classes['ticket_classes'])) {
            $classes = $classes['ticket_classes'];
        }
        if (!is_array($classes) || $classes === []) return self::UNKNOWN;

        $hasClass = false;
        $selling = false;
        $future = false;
        $closed = false;

        foreach ($classes as $class) {
            if (!is_array($class)) continue;
            $recordStatus = self::key($class['status'] ?? '');
            if (in_array($recordStatus, ['deleted', 'archived'], true)) continue;

            $hasClass = true;
            $saleStatus = self::key($class['on_sale_status'] ?? $class['sales_status'] ?? '');
            $salesStart = self::timestamp($class['sales_start'] ?? null);
            $salesEnd = self::timestamp($class['sales_end'] ?? null);

            if ($salesStart !== null && $salesStart > $now) {
                $future = true;
                continue;
            }
            if ($salesEnd !== null && $salesEnd <= $now) {
                $closed = true;
                continue;
            }
            if ($saleStatus === 'available') {
                $selling = true;
                continue;
            }
            if (in_array($saleStatus, ['sold_out', 'sales_ended', 'sale_ended', 'closed', 'off_sale'], true)) {
                $closed = true;
            }
        }

        if ($selling) return self::SELLING;
        if ($future) return self::NOT_YET;
        if ($hasClass && $closed) return self::CLOSED;
        return self::UNKNOWN;
    }

    /** @param array<string, mixed> $payload */
    public static function isClosed(array $payload): bool
    {
        return self::state($payload) === self::CLOSED;
    }

    /** @param array<string, mixed> $payload */
    public static function eventStatus(array $payload): string
    {
        $event = self::event($payload);
        return self::key($event['status'] ?? '');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function event(array $payload): array
    {
        return isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : $payload;
    }

    private static function key(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) return '';
        return trim((string) preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $value))) ?? ''), '_');
    }

    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1') return true;
        if ($value === 0 || $value === '0') return false;
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['true', 'yes'], true)) return true;
            if (in_array($value, ['false', 'no'], true)) return false;
        }
        return null;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach (['utc', 'local', 'date'] as $key) {
                if (array_key_exists($key, $value)) {
                    $parsed = self::timestamp($value[$key]);
                    if ($parsed !== null) return $parsed;
                }
            }
            return null;
        }
        if (is_int($value) && $value > 0) return $value;
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return (new \DateTimeImmutable(trim($value)))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
