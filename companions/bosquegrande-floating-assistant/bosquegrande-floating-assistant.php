<?php
/**
 * Plugin Name:       BosqueGrande Floating Assistant
 * Description:       A question button on your public pages, answered by BosqueGrande's assistant with your own service provider key, plus Assistant Training for your organization's own reference material.
 * Version:           5.0.0
 * Author:            BosqueGrande contributors
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  bosquegrande
 * Text Domain:       bosquegrande-floating-assistant
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

const BGCA_VERSION = '5.0.0';
define('BGCA_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/PdfText.php';
require_once __DIR__ . '/includes/Extractor.php';
require_once __DIR__ . '/includes/Training.php';
require_once __DIR__ . '/includes/TrainingPage.php';
require_once __DIR__ . '/includes/Plugin.php';

register_activation_hook(__FILE__, static function (): void {
    \BGCompanion\Assistant\Training::maybeInstall();
});

\BGCompanion\Assistant\Plugin::boot();
