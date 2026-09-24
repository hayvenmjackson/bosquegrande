<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * Where uploaded spreadsheets wait while an import runs. The base is
 * wp-content unless BG_PRIVATE_DIR or the bg_private_dir filter points
 * outside the web root; on multisite each site gets its own folder.
 */
final class PrivateStorage
{
    public static function directory(string $name): string
    {
        $base = defined('BG_PRIVATE_DIR') && is_string(BG_PRIVATE_DIR) && BG_PRIVATE_DIR !== ''
            ? BG_PRIVATE_DIR
            : WP_CONTENT_DIR;
        $base = (string) apply_filters('bg_private_dir', $base);
        $dir = rtrim($base, '/\\') . '/' . sanitize_key($name);
        if (is_multisite()) $dir .= '/site-' . (int) get_current_blog_id();
        return $dir;
    }
}
