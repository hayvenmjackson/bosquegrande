<?php
/**
 * Plugin Name: BosqueGrande
 * Description: Constituent relationship management for WordPress: people, events and registration, newsletters, payments, and integrations, with personal data encrypted at rest.
 * Version:     5.0.0
 * Author:      BosqueGrande contributors
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: bosquegrande
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('BG_VERSION', '5.0.0');
define('BG_FILE', __FILE__);
define('BG_DIR', plugin_dir_path(__FILE__));
define('BG_URL', plugin_dir_url(__FILE__));

/** UTF-8-aware truncation without making the optional mbstring extension a hidden requirement. */
function bg_substr(string $value, int $start, int $length): string {
    if (function_exists('mb_substr')) return mb_substr($value, $start, $length, 'UTF-8');
    if (preg_match_all('/./su', $value, $chars)) return implode('', array_slice($chars[0], $start, $length));
    return substr($value, $start, $length);
}

/* Minimal PSR-4 autoloader for the BosqueGrande\ namespace. */
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'BosqueGrande\\') !== 0) return;
    $rel = str_replace('\\', '/', substr($class, strlen('BosqueGrande\\')));
    $file = BG_DIR . 'src/' . $rel . '.php';
    if (is_file($file)) require $file;
});

register_activation_hook(__FILE__, function () {
    \BosqueGrande\Core\Plugin::instance()->activate();
});

register_deactivation_hook(__FILE__, function () {
    \BosqueGrande\Core\Plugin::instance()->deactivate();
});

add_action('plugins_loaded', function () {
    \BosqueGrande\Core\Plugin::instance()->boot();
}, 5);
