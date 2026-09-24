<?php
// Organizer app walk-ins: a saved record is used only when the name and a
// typed email or phone point to the same single person. A name alone, or a
// name and town, must never bind to a saved record.
$bgTestUri = '/wp-admin/';
require __DIR__ . '/bootstrap.php';
wp_set_current_user(1);

$c = \BosqueGrande\Core\Plugin::instance()->container();
$store = $c->get('constituents.store');
$service = $c->get('organizer_app.service');

$tag = wp_generate_password(6, false, false);
$first = 'Casey' . $tag;
$last = 'Known';
$email = strtolower('casey' . $tag . '@example.test');
$knownId = $store->upsertByEmail($email, [
    'first_name' => $first, 'last_name' => $last, 'phone' => '207-555-0142',
    'address1' => '9 Private Lane', 'city' => 'Anywhere', 'state' => 'ME', 'postal' => '04901',
]);
check($knownId > 0, 'saved person created');

$eventId = wp_insert_post([
    'post_type' => 'bg_event', 'post_status' => 'publish',
    'post_title' => 'Smoke Walk-in Night ' . $tag, 'post_content' => 'x',
]);
update_post_meta($eventId, '_bg_start_date', gmdate('Y-m-d'));
update_post_meta($eventId, '_bg_start_time', '18:00');

$walkIn = static function (array $fields) use ($service, $eventId) {
    return $service->createWalkIn(1, $eventId, $fields + ['idempotency_key' => bin2hex(random_bytes(12))]);
};

// Name and a different town: must not attach to the saved person.
$r1 = $walkIn(['first_name' => $first, 'last_name' => $last, 'city' => 'Elsewhere', 'state' => 'VT']);
check(is_array($r1), 'name-and-town walk-in accepted' . (is_wp_error($r1) ? ': ' . $r1->get_error_message() : ''));
if (is_array($r1)) {
    check((int) $r1['constituent_id'] !== $knownId, 'name alone does not bind to the saved record');
    check($r1['match_status'] === 'ambiguous' && !empty($r1['review_required']), 'near match is held for staff review');
}

// Name and a wrong email: still not the saved person.
$r2 = $walkIn(['first_name' => $first, 'last_name' => $last, 'email' => 'someone-else-' . $tag . '@example.test']);
check(is_array($r2) && (int) $r2['constituent_id'] !== $knownId, 'name with a different email does not bind');

// Name and the saved email: the saved person.
$r3 = $walkIn(['first_name' => $first, 'last_name' => $last, 'email' => $email]);
check(is_array($r3) && (int) $r3['constituent_id'] === $knownId && $r3['match_status'] === 'matched', 'name with the saved email matches');

// Someone new: a new record, no review.
$r4 = $walkIn(['first_name' => 'Nova' . $tag, 'last_name' => 'Newcomer', 'city' => 'Anywhere', 'state' => 'ME']);
check(is_array($r4) && $r4['match_status'] === 'new' && empty($r4['review_required']), 'a new person gets a new record');

// Clean up: registrations, intakes, attendance, and the test event.
global $wpdb;
$regIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}bg_registrations WHERE event_post_id = %d", $eventId
)));
foreach (['bg_event_attendance' => 'registration_id', 'bg_event_intakes' => 'registration_id'] as $table => $column) {
    if ($regIds && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table))) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} IN (" . implode(',', $regIds) . ')');
    }
}
$wpdb->delete($wpdb->prefix . 'bg_registrations', ['event_post_id' => $eventId]);
delete_transient('bg_org_walkin_1');
wp_delete_post($eventId, true);
finish();
