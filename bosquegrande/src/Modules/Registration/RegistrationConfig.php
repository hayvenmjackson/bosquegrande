<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Registration;

if (!defined('ABSPATH')) exit;

/**
 * Site-wide registration choices: the fee options (tiers) and the optional
 * questions asked on the registration form, My Profile, and the organizer
 * app's walk-in form. Everything here is edited under Settings.
 */
final class RegistrationConfig
{
    private const TIERS_OPTION = 'bg_registration_tiers';
    private const QUESTIONS_OPTION = 'bg_registration_questions';

    public const QUESTIONS = ['demographics', 'age', 'heard', 'promote', 'relationship'];

    /** @return list<array{key: string, label: string, amount_cents: int, gated: bool, volunteer_chain: bool}> */
    public static function tiers(): array
    {
        $saved = get_option(self::TIERS_OPTION, null);
        $rows = is_array($saved) && $saved ? $saved : [[
            'key' => 'free',
            'label' => 'Free',
            'amount_cents' => 0,
            'gated' => false,
            'volunteer_chain' => false,
        ]];
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = sanitize_key((string) ($row['key'] ?? ''));
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'label' => $label,
                'amount_cents' => max(0, min(10000000, (int) ($row['amount_cents'] ?? 0))),
                'gated' => !empty($row['gated']),
                'volunteer_chain' => !empty($row['volunteer_chain']),
            ];
        }
        return $out;
    }

    /**
     * Save posted tier rows. Keys are kept for existing rows so past
     * registrations keep pointing at the right option; new rows get a key
     * derived from their label.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function saveTiers(array $rows): void
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if ($label === '' || !empty($row['remove'])) continue;
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($key === '') $key = sanitize_key(str_replace('-', '_', sanitize_title($label)));
            if ($key === '' || in_array($key, ['historical', 'eventbrite', 'walk_in'], true)) $key = 'tier_' . substr(md5($label), 0, 6);
            $base = $key;
            for ($i = 2; isset($seen[$key]); $i++) $key = $base . '_' . $i;
            $seen[$key] = true;
            $amount = str_replace([',', '$', ' ', \BosqueGrande\Core\Money::symbol()], '', (string) ($row['amount'] ?? '0'));
            $cents = is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
            $out[] = [
                'key' => bg_substr($key, 0, 40),
                'label' => bg_substr($label, 0, 120),
                'amount_cents' => max(0, min(10000000, $cents)),
                'gated' => !empty($row['gated']),
                'volunteer_chain' => !empty($row['volunteer_chain']),
            ];
        }
        if (!$out) {
            $out[] = ['key' => 'free', 'label' => 'Free', 'amount_cents' => 0, 'gated' => false, 'volunteer_chain' => false];
        }
        update_option(self::TIERS_OPTION, $out, false);
    }

    /** @return array{enabled: bool, required: bool, options: list<string>} */
    public static function question(string $key): array
    {
        $defaults = self::defaultQuestions();
        $default = $defaults[$key] ?? ['enabled' => false, 'required' => false, 'options' => []];
        $saved = get_option(self::QUESTIONS_OPTION, []);
        $row = is_array($saved) && isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
        $options = isset($row['options']) && is_array($row['options']) && $row['options']
            ? array_values(array_filter(array_map(
                static fn($value): string => sanitize_text_field((string) $value),
                $row['options']
            ), static fn(string $value): bool => $value !== ''))
            : $default['options'];
        return [
            'enabled' => array_key_exists('enabled', $row) ? !empty($row['enabled']) : $default['enabled'],
            'required' => array_key_exists('required', $row) ? !empty($row['required']) : $default['required'],
            'options' => $options,
        ];
    }

    public static function enabled(string $key): bool
    {
        return self::question($key)['enabled'];
    }

    public static function required(string $key): bool
    {
        $question = self::question($key);
        return $question['enabled'] && $question['required'];
    }

    /** @param array<string, array<string, mixed>> $posted */
    public static function saveQuestions(array $posted): void
    {
        $out = [];
        foreach (self::QUESTIONS as $key) {
            $row = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : [];
            $lines = preg_split('/\r\n|\r|\n/', sanitize_textarea_field((string) ($row['options'] ?? ''))) ?: [];
            $options = array_values(array_unique(array_filter(array_map(
                static fn(string $line): string => bg_substr(trim($line), 0, 120),
                $lines
            ), static fn(string $line): bool => $line !== '')));
            $out[$key] = [
                'enabled' => !empty($row['enabled']),
                'required' => !empty($row['required']),
                'options' => array_slice($options, 0, 60),
            ];
        }
        update_option(self::QUESTIONS_OPTION, $out, false);
    }

    /** @return array<string, array{enabled: bool, required: bool, options: list<string>}> */
    private static function defaultQuestions(): array
    {
        $pnts = DemographicFields::PNTS_KEY;
        return [
            'demographics' => [
                'enabled' => false,
                'required' => false,
                'options' => [
                    'Student', 'Veteran', 'Person with a disability', 'Senior (65+)',
                    'Low income', 'Immigrant or refugee', DemographicFields::OTHER_KEY, $pnts,
                ],
            ],
            'age' => [
                'enabled' => false,
                'required' => false,
                'options' => ['Under 18', '18-24', '25-34', '35-44', '45-54', '55-64', '65+', $pnts],
            ],
            'heard' => [
                'enabled' => true,
                'required' => false,
                'options' => [
                    'Email newsletter', 'Our website', 'Friend or family', 'Social media',
                    'Search engine', 'Flyer or poster', 'News or press', 'Another organization',
                    'Eventbrite', SurveyFields::HEARD_OTHER,
                ],
            ],
            'promote' => [
                'enabled' => false,
                'required' => false,
                'options' => [
                    'Yes, I will invite a friend or two by email.',
                    'Yes, I will share it on social media.',
                    'Not this time.',
                ],
            ],
            'relationship' => [
                'enabled' => false,
                'required' => false,
                'options' => ['Single', 'In a relationship', 'Married', 'Widowed', $pnts],
            ],
        ];
    }
}
