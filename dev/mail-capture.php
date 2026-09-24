<?php
/**
 * Plugin Name: BosqueGrande test mail capture
 * Description: Test sites only. Records outgoing mail as JSON lines instead of sending it.
 *
 * Copy into wp-content/mu-plugins/ on a disposable site. The log goes to the
 * BG_TEST_MAIL_LOG constant if defined, otherwise to the system temp folder.
 */
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $log = defined('BG_TEST_MAIL_LOG') ? (string) BG_TEST_MAIL_LOG : sys_get_temp_dir() . '/bosquegrande-mail.jsonl';
    file_put_contents($log, wp_json_encode($atts) . "\n", FILE_APPEND | LOCK_EX);
    return true;
}, 10, 2);
