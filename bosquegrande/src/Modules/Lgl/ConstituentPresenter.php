<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Lgl;

if (!defined('ABSPATH')) exit;

/**
 * Shared flattener: LGL constituent record to form-shaped prefill.
 * Used by the Registration Engine and the Profile form so both read
 * the record identically. Demographic keywords come from the mapped
 * LGL category (settings: demographic_lgl_category, ADR-8).
 */
final class ConstituentPresenter
{
    /**
     * @param array<string, mixed>|null $c
     * @return array{first_name: string, last_name: string, email: string,
     *   phone: string, street: string, address2: string, city: string,
     *   state: string, zip: string, notes: string, demographics: array<int, string>,
     *   age_range: string, relationship: array<int, string>}
     */
    public static function prefill(?array $c): array
    {
        $blank = [
            'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
            'street' => '', 'address2' => '', 'city' => '', 'state' => '', 'zip' => '',
            'notes' => '',
            'demographics' => [], 'age_range' => '', 'relationship' => [],
        ];
        if (!$c) return $blank;

        $email = (string) (self::emails($c)[0] ?? '');
        $phone = '';
        foreach (($c['phone_numbers'] ?? []) as $p) {
            if (!is_array($p) || !empty($p['not_current'])) continue;
            $candidate = trim((string) ($p['number'] ?? ''));
            if ($candidate === '') continue;
            if ($phone === '') $phone = $candidate;
            if (!empty($p['is_preferred'])) {
                $phone = $candidate;
                break;
            }
        }
        $addr = ['street' => '', 'address2' => '', 'city' => '', 'state' => '', 'zip' => ''];
        foreach (($c['street_addresses'] ?? []) as $a) {
            if (!is_array($a) || !empty($a['not_current'])) continue;
            $street = (string) ($a['street'] ?? '');
            $address2 = (string) ($a['street2'] ?? $a['street_2'] ?? '');
            if ($address2 === '' && preg_match('/\R/', $street)) {
                $parts = preg_split('/\R/', $street, 2) ?: [$street];
                $street = (string) ($parts[0] ?? '');
                $address2 = (string) ($parts[1] ?? '');
            }
            $candidate = [
                'street' => $street,
                'address2' => $address2,
                'city' => (string) ($a['city'] ?? ''),
                'state' => (string) ($a['state'] ?? ''),
                'zip' => (string) ($a['postal_code'] ?? ''),
            ];
            if ($addr === ['street' => '', 'address2' => '', 'city' => '', 'state' => '', 'zip' => '']) {
                $addr = $candidate;
            }
            if (!empty($a['is_preferred'])) {
                $addr = $candidate;
                break;
            }
        }

        $demographics = self::categoryKeywords($c, (string) apply_filters('bg_demographic_lgl_category', 'Demographics'));
        $ageValues = self::categoryKeywords($c, (string) apply_filters('bg_age_range_lgl_category', 'Age Range'));
        $relationship = self::categoryKeywords($c, (string) apply_filters('bg_relationship_lgl_category', 'Relationship Status'));

        return array_merge($blank, [
            'first_name' => (string) ($c['first_name'] ?? ''),
            'last_name' => (string) ($c['last_name'] ?? ''),
            'email' => $email,
            'phone' => $phone,
            'notes' => self::backgroundInfo($c),
        ], $addr, [
            'demographics' => array_filter($demographics),
            'age_range' => (string) ($ageValues[0] ?? ''),
            'relationship' => array_filter($relationship),
        ]);
    }

    /**
     * Every current valid LGL address, preferred first. Expired addresses
     * are intentionally excluded; addresses already learned by the local
     * store remain available there as historic aliases.
     *
     * @param array<string, mixed>|null $c
     * @return list<string>
     */
    public static function emails(?array $c): array
    {
        if (!$c) return [];
        $preferred = [];
        $others = [];
        foreach (($c['email_addresses'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['not_current'])) continue;
            $email = strtolower(trim(sanitize_email((string) ($item['address'] ?? ''))));
            if (!is_email($email)) continue;
            if (!empty($item['is_preferred'])) $preferred[$email] = $email;
            else $others[$email] = $email;
        }
        return array_values($preferred + $others);
    }

    /**
     * Keywords of the LGL category matching a configured name. The match
     * is tolerant (exact, key, containment, Levenshtein, optional assistant
     * classification) through CategoryMatcher, the same matcher the write
     * path uses.
     * @return array<int, string>
     */
    private static function categoryKeywords(array $c, string $name): array
    {
        $categories = [];
        foreach (($c['categories'] ?? []) as $cat) {
            if (is_array($cat)) $categories[] = $cat;
        }
        return CategoryMatcher::keywords($categories, $name);
    }

    /** LGL exposes Background Info as the background_info custom attribute. */
    private static function backgroundInfo(array $c): string
    {
        $legacy = trim((string) ($c['background_info'] ?? ''));
        if ($legacy !== '') return $legacy;
        foreach (($c['custom_attrs'] ?? []) as $attr) {
            if (!is_array($attr)) continue;
            $key = strtolower(trim((string) ($attr['key'] ?? '')));
            $name = strtolower((string) preg_replace(
                '/[^a-z0-9]+/',
                '',
                (string) ($attr['name'] ?? '')
            ));
            if ($key !== 'background_info' && $name !== 'backgroundinfo') continue;
            return trim((string) ($attr['value'] ?? $attr['description'] ?? ''));
        }
        return '';
    }
}
