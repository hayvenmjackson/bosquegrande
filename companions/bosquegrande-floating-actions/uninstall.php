<?php
/** Floating Actions keeps nothing but its settings; remove them. */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$bgcfSites = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [0];
foreach ($bgcfSites as $bgcfSite) {
    if ($bgcfSite) switch_to_blog((int) $bgcfSite);
    delete_option('bgcf_settings');
    delete_option('bgcf_flush');
    delete_option('rewrite_rules');
    if ($bgcfSite) restore_current_blog();
}
