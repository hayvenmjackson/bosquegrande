<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * The optional registration questions other than demographics: age range,
 * how the person heard about the event, whether they will help promote it,
 * and relationship status. Which ones are asked, whether they are required,
 * and their options are set under Settings.
 *
 * Answers are written to the local encrypted constituent record. Data
 * Transfer and LGL Sync map them to custom fields only when an
 * administrator chooses to exchange data.
 */
final class SurveyFields
{
    public const HEARD_OTHER = 'Other';

    /** @return string[] */
    public static function ageRanges(): array
    {
        return RegistrationConfig::question('age')['options'];
    }

    /** @return string[] */
    public static function heardOptions(): array
    {
        return RegistrationConfig::question('heard')['options'];
    }

    /** @return string[] */
    public static function promoteOptions(): array
    {
        return RegistrationConfig::question('promote')['options'];
    }

    /** @return string[] */
    public static function relationshipOptions(): array
    {
        return RegistrationConfig::question('relationship')['options'];
    }

    public static function sanitizeAge(string $value): string
    {
        return in_array($value, self::ageRanges(), true) ? $value : '';
    }

    /** @param array<int, string> $selected @return array<int, string> */
    public static function sanitizeHeard(array $selected): array
    {
        $canonical = [];
        foreach (array_map('strval', $selected) as $value) {
            $canonical[trim($value)] = true;
        }
        return array_values(array_filter(
            self::heardOptions(),
            static fn(string $option): bool => isset($canonical[$option])
        ));
    }

    /** Parse stored or imported answers against the current options. */
    public static function parseHeard(string $raw): array
    {
        return self::sanitizeHeard(PrefillResolver::parseOptions($raw, self::heardOptions()));
    }

    public static function sanitizePromote(string $value): string
    {
        return in_array($value, self::promoteOptions(), true) ? $value : '';
    }

    /** @param array<int, string> $selected @return array<int, string> */
    public static function sanitizeRelationship(array $selected): array
    {
        return array_values(array_intersect(array_map('strval', $selected), self::relationshipOptions()));
    }

    /** Human-readable invitation note fragment for the event-level answers. */
    public static function invitationNote(array $heard, string $heardOther, string $promote): string
    {
        $parts = [];
        if ($heard) {
            $label = implode(', ', $heard);
            if (in_array(self::HEARD_OTHER, $heard, true) && $heardOther !== '') {
                $label .= ' (' . $heardOther . ')';
            }
            $parts[] = 'Heard about event: ' . $label;
        }
        if ($promote !== '') {
            $parts[] = 'Will promote: ' . $promote;
        }
        return $parts ? ' | ' . implode(' | ', $parts) : '';
    }
}
