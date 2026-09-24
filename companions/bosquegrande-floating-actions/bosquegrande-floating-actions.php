<?php
/**
 * Plugin Name:       BosqueGrande Floating Actions
 * Description:       Subscribe, Donate, and custom buttons that stay in the corner of your public pages, with a QR code made on your own site and a newsletter signup page.
 * Version:           5.0.0
 * Author:            BosqueGrande contributors
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  bosquegrande
 * Text Domain:       bosquegrande-floating-actions
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

const BGCF_VERSION = '5.0.0';
define('BGCF_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/Qr.php';
require_once __DIR__ . '/includes/Plugin.php';

register_activation_hook(__FILE__, static function (): void {
    update_option('bgcf_flush', 1, true);
});
// Drop the stored permalinks so they are rebuilt without the signup address.
register_deactivation_hook(__FILE__, static function (): void {
    delete_option('rewrite_rules');
});

\BGCompanion\Actions\Plugin::boot();
