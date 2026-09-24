<?php
/** Organizer Launcher keeps nothing but its settings; remove them. */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$bgolSites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [0];
foreach ($bgolSites as $bgolSite) {
    if ($bgolSite) switch_to_blog((int) $bgolSite);
    delete_option('bgol_settings');
    if ($bgolSite) restore_current_blog();
}
