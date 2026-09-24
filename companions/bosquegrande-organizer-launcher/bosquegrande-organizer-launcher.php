<?php
/**
 * Plugin Name:       BosqueGrande Organizer Launcher
 * Description:       Choose who sees the Organizer App button, on which pages, and where; add a prominent open-or-install button to the Organizer Portal.
 * Version:           5.0.0
 * Author:            BosqueGrande contributors
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  bosquegrande
 * Text Domain:       bosquegrande-organizer-launcher
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

const BGOL_VERSION = '5.0.0';
define('BGOL_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/Plugin.php';

\BGCompanion\Launcher\Plugin::boot();
