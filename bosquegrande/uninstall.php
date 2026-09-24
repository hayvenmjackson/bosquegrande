<?php

/**
 * BosqueGrande uninstall. Deletion is opt-in: nothing is removed unless the
 * site owner turned on the uninstall option in Connectors > Danger zone
 * (bg_delete_on_uninstall) beforehand. Without it, tables, options, events,
 * and newsletters survive removal of the plugin files, and reinstalling
 * picks everything back up.
 *
 * The keyring file is never deleted here. A database backup restored later
 * is unreadable without it.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/** Remove one site's BosqueGrande data when that site opted in. */
function bosquegrande_uninstall_site(): void
{
    global $wpdb;
    if ((int) get_option('bg_delete_on_uninstall', 0) !== 1) return;

    $tables = [
        'bg_registrations', 'bg_survey_responses', 'bg_identity_tokens', 'bg_jobs_log',
        'bg_staging', 'bg_transfer_runs',
        'bg_constituents', 'bg_constituent_emails', 'bg_constituent_regions', 'bg_constituent_donor_facts',
        'bg_activity', 'bg_ctags', 'bg_ctag_links', 'bg_cfields', 'bg_cvalues', 'bg_consents',
        'bg_groups', 'bg_subscriptions', 'bg_send_queue', 'bg_suppression',
        'bg_payments', 'bg_qbo_mappings', 'bg_qbo_exports', 'bg_qbo_review',
        'bg_grant_reports',
        'bg_event_organizers', 'bg_event_attendance', 'bg_event_change_requests', 'bg_organizer_audit',
        'bg_event_intakes', 'bg_event_tenders', 'bg_event_closeouts', 'bg_attendee_sessions', 'bg_event_roles',
        'bg_import_jobs', 'bg_import_rows', 'bg_import_mutations', 'bg_external_records', 'bg_registration_answers',
        'bg_lgl_diffs',
        // Present only on sites upgraded from 4.x.
        'bg_volunteer_shifts', 'bg_qbo_cache',
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    $uploads = wp_get_upload_dir();
    $privateBase = defined('BG_PRIVATE_DIR') && is_string(BG_PRIVATE_DIR) && BG_PRIVATE_DIR !== ''
        ? rtrim(BG_PRIVATE_DIR, '/\\')
        : WP_CONTENT_DIR;
    $privateBase = rtrim((string) apply_filters('bg_private_dir', $privateBase), '/\\');
    $siteSuffix = is_multisite() ? '/site-' . (int) get_current_blog_id() : '';
    $directories = [
        $privateBase . '/bg-private-imports' . $siteSuffix,
        $privateBase . '/bg-private-group-imports' . $siteSuffix,
        trailingslashit((string) ($uploads['basedir'] ?? '')) . 'bg-fixtures',
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory)) continue;
        $files = array_merge(glob($directory . '/*') ?: [], glob($directory . '/.*') ?: []);
        foreach ($files as $file) {
            if (is_file($file) && dirname($file) === $directory) @unlink($file);
        }
        @rmdir($directory);
    }

    // Events and newsletters, with their revisions, meta, and tag links.
    $postIds = array_map('intval', (array) $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('bg_event', 'bg_newsletter')"
    ));
    foreach (array_chunk($postIds, 500) as $chunk) {
        $in = implode(',', $chunk);
        $children = array_map('intval', (array) $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($in) AND post_type = 'revision'"
        ));
        $all = implode(',', array_merge($chunk, $children));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($all)");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($all)");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($all)");
    }

    // Event tags and grant tags.
    $termIds = array_map('intval', (array) $wpdb->get_col(
        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('bg_tag', 'bg_grant_tag')"
    ));
    $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('bg_tag', 'bg_grant_tag')");
    foreach (array_chunk($termIds, 500) as $chunk) {
        $in = implode(',', $chunk);
        $wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($in)");
        $wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id IN ($in)
            AND term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})");
    }

    // Links from WordPress accounts to contact records.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", $wpdb->get_blog_prefix() . 'bg_constituent_id'));
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'bg_constituent_id'");

    $patterns = [
        'bg\_%', 'bgev\_%', 'bosquegrande%',
        '\_transient\_bg\_%', '\_transient\_timeout\_bg\_%',
        '\_transient\_bgev\_%', '\_transient\_timeout\_bgev\_%',
    ];
    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }

    foreach (['bg_event_organizer', 'bg_accountant'] as $role) {
        remove_role($role);
    }
    $admin = get_role('administrator');
    if ($admin) $admin->remove_cap('bg_view_payment_details');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        bosquegrande_uninstall_site();
        restore_current_blog();
    }
} else {
    bosquegrande_uninstall_site();
}
