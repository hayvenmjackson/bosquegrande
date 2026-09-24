<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * The installing organization's identity. Every name, logo, and contact
 * address shown to visitors or written into email comes from here, so the
 * plugin carries no built-in organization of its own.
 */
final class Organization
{
    private const OPTION = 'bosquegrande_settings';

    /** Public name, for example in email signatures and page headers. */
    public static function name(): string
    {
        $name = trim((string) self::get('org_name'));
        if ($name === '') $name = trim(wp_strip_all_tags((string) get_option('blogname', '')));
        return $name !== '' ? $name : 'Our organization';
    }

    /**
     * Legal name shown before a visitor pays. Empty means "not configured",
     * and payment surfaces fall back to the public name.
     */
    public static function legalName(): string
    {
        return trim((string) self::get('org_legal_name'));
    }

    /** The name a payer should expect to see on their statement. */
    public static function payee(): string
    {
        $legal = self::legalName();
        return $legal !== '' ? $legal : self::name();
    }

    /** Address visitors are told to write to when something needs a person. */
    public static function contactEmail(): string
    {
        $email = sanitize_email((string) self::get('org_contact_email'));
        if ($email === '') $email = sanitize_email((string) get_option('admin_email', ''));
        return is_email($email) ? $email : '';
    }

    /** Optional public phone number; empty when not set. */
    public static function contactPhone(): string
    {
        return trim(sanitize_text_field((string) self::get('org_contact_phone')));
    }

    public static function logoUrl(): string
    {
        $id = (int) self::get('org_logo_id');
        if ($id > 0) {
            $url = wp_get_attachment_image_url($id, 'medium');
            if (is_string($url) && $url !== '') return $url;
        }
        $url = esc_url_raw((string) self::get('org_logo_url'));
        if ($url !== '') return $url;
        $icon = get_site_icon_url(192);
        return is_string($icon) ? $icon : '';
    }

    public static function type(): string
    {
        return get_option('bg_org_type', 'nonprofit') === 'business' ? 'business' : 'nonprofit';
    }

    /** The principal color from Wording & Styling, for email buttons and headings. */
    public static function brandColor(): string
    {
        $calendar = get_option('bg_calendar_settings', []);
        $color = is_array($calendar) ? sanitize_hex_color((string) ($calendar['color_primary'] ?? '')) : '';
        return $color ?: '#1F5130';
    }

    /** Whether the small "Powered by BosqueGrande" footer line is shown. */
    public static function showAttribution(): bool
    {
        return (int) self::get('show_attribution', 1) === 1;
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void
    {
        $settings = get_option(self::OPTION, []);
        if (!is_array($settings)) $settings = [];
        if (array_key_exists('org_name', $values)) {
            $settings['org_name'] = sanitize_text_field((string) $values['org_name']);
        }
        if (array_key_exists('org_legal_name', $values)) {
            $settings['org_legal_name'] = sanitize_text_field((string) $values['org_legal_name']);
        }
        if (array_key_exists('org_contact_email', $values)) {
            $settings['org_contact_email'] = sanitize_email((string) $values['org_contact_email']);
        }
        if (array_key_exists('org_contact_phone', $values)) {
            $settings['org_contact_phone'] = bg_substr(sanitize_text_field((string) $values['org_contact_phone']), 0, 40);
        }
        if (array_key_exists('org_logo_id', $values)) {
            $id = absint($values['org_logo_id']);
            $settings['org_logo_id'] = ($id > 0 && wp_attachment_is_image($id)) ? $id : 0;
        }
        if (array_key_exists('org_logo_url', $values)) {
            $settings['org_logo_url'] = esc_url_raw((string) $values['org_logo_url'], ['https', 'http']);
        }
        if (array_key_exists('show_attribution', $values)) {
            $settings['show_attribution'] = empty($values['show_attribution']) ? 0 : 1;
        }
        update_option(self::OPTION, $settings, false);
        Plugin::instance()->container()->get('settings')->refresh();
    }

    private static function get(string $key, mixed $default = ''): mixed
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
    }
}
