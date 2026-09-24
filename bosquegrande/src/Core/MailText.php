<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Converts WordPress/HTML display strings into clean plain-text mail.
 *
 * WordPress legitimately represents an ampersand as &#038; in several
 * display contexts. A text/plain message does not render that entity,
 * so it must be decoded before being handed to PHPMailer.
 */
final class MailText
{
    public static function line(string $value): string
    {
        $value = self::decode(wp_strip_all_tags($value));
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public static function block(string $value): string
    {
        if (function_exists('strip_shortcodes')) {
            $value = strip_shortcodes($value);
        }
        $value = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $value);
        $value = (string) preg_replace(
            '/<\/(?:p|div|li|ul|ol|h[1-6]|blockquote)\s*>/i',
            "\n",
            $value
        );
        $value = self::decode(wp_strip_all_tags($value));
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));
        $value = implode("\n", $lines);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
        return trim($value);
    }

    private static function decode(string $value): string
    {
        // Also repair the malformed legacy spelling seen in a few saved
        // WordPress titles: &038 or &038; (missing the numeric # marker).
        $value = (string) preg_replace('/&0?38;?/i', '&', $value);
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) break;
            $value = $decoded;
        }
        return $value;
    }
}
