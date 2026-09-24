<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Constituents;

if (!defined('ABSPATH')) exit;

/**
 * Custom-field keys BosqueGrande itself relies on, plus the one value
 * normalizer they need. Both fields are ordinary encrypted custom fields.
 */
final class CoreFields
{
    /** Strict Yes/No: whether this person is a monthly donor. */
    public const MONTHLY_DONOR = 'monthly_donor';
    /** Semicolon-separated titles of past events, from imports or a connector. */
    public const PAST_EVENTS = 'attended_events_list';

    /** Convert connector and spreadsheet spellings into the only two stored values. */
    public static function normalizeMonthlyDonor(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['yes', 'y', 'true', '1', 'active', 'monthly', 'recurring'], true)) {
            return 'Yes';
        }
        if (in_array($value, ['no', 'n', 'false', '0', 'inactive', 'cancelled', 'canceled', 'one-time', 'one time'], true)) {
            return 'No';
        }
        return '';
    }
}
