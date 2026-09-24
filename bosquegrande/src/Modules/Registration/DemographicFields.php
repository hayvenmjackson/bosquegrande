<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * The optional demographic question. Its options, and whether it is asked
 * or required, are set under Settings. "Prefer not to say" is exclusive.
 * Answers are never logged; they rest in the constituent's encrypted
 * record, are shown only in verified sessions, and are excluded from
 * export and from assisted features.
 */
final class DemographicFields
{
    public const OTHER_KEY = 'Other';
    public const PNTS_KEY = 'Prefer not to say';

    /** @return string[] Options in form order. */
    public static function options(): array
    {
        return RegistrationConfig::question('demographics')['options'];
    }

    /**
     * Sanitize a submitted selection against the canonical list.
     * "Prefer not to say" is exclusive: selecting it clears the rest.
     *
     * @param array<int, string> $selected
     * @return array<int, string>
     */
    public static function sanitize(array $selected): array
    {
        $valid = array_intersect(array_map('strval', $selected), self::options());
        if (in_array(self::PNTS_KEY, $valid, true)) {
            return [self::PNTS_KEY];
        }
        return array_values($valid);
    }
}
