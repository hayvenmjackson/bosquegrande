<?php
/**
 * Deleting the Floating Assistant keeps its training info and settings
 * unless "Also delete all training info and these settings" was turned on,
 * so reinstalling picks everything back up.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

function bgca_uninstall_site(): void
{
    global $wpdb;
    $settings = get_option('bgca_settings', []);
    if (!is_array($settings) || (int) ($settings['delete_on_uninstall'] ?? 0) !== 1) return;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bgca_training_chunks");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bgca_training_docs");
    foreach (['bgca\_%', '\_transient\_bgca\_%', '\_transient\_timeout\_bgca\_%'] as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        bgca_uninstall_site();
        restore_current_blog();
    }
} else {
    bgca_uninstall_site();
}
