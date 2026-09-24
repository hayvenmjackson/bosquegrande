<?php
// Public registration: nonce, first registration, confirmation email, repeat
// registrations that must not overwrite saved answers, duplicates, bad nonces,
// and signed email links.
require __DIR__ . '/bootstrap.php';

$mails = [];
add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) {
    $mails[] = $atts;
    return true;
}, 10, 2);

$c = \BosqueGrande\Core\Plugin::instance()->container();
$store = $c->get('constituents.store');
check($store->available(), 'encrypted store available');

// Configuration: demographics asked, optional.
\BosqueGrande\Modules\Registration\RegistrationConfig::saveQuestions([
    'demographics' => ['enabled' => 1, 'required' => 0, 'options' => "Student\nVeteran\nOther\nPrefer not to say"],
    'age' => ['enabled' => 1, 'required' => 0, 'options' => "Under 18\n18-34\n35-54\n55+"],
    'heard' => ['enabled' => 0],
]);

$eventId = wp_insert_post([
    'post_type' => 'bg_event', 'post_status' => 'publish',
    'post_title' => 'Smoke Test Picnic ' . wp_generate_password(4, false),
    'post_content' => 'A test event.',
]);
update_post_meta($eventId, '_bg_start_date', gmdate('Y-m-d', time() + 14 * DAY_IN_SECONDS));
update_post_meta($eventId, '_bg_start_time', '18:00');
check($eventId > 0, 'event created');

function rest(string $method, string $route, array $params = []): WP_REST_Response {
    // One CLI process runs every request, so release the per-request
    // submission locks that shutdown handlers would free on a real server.
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bg\\_reg\\_email\\_%' OR option_name LIKE 'bg\\_reg\\_submit\\_%'");
    wp_cache_flush();
    $req = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) $req->set_param($k, $v);
    $res = rest_do_request($req);
    return $res instanceof WP_REST_Response ? $res : rest_ensure_response($res);
}

$nonceRes = rest('GET', '/bosquegrande/v1/reg-nonce', ['event' => $eventId]);
$nonce = (string) ($nonceRes->get_data()['nonce'] ?? '');
check($nonce !== '', 'registration nonce issued');

$email = strtolower('smoke+' . wp_generate_password(6, false, false) . '@example.test');
$tiers = \BosqueGrande\Modules\Registration\Tiers::all();
$tierKey = (string) array_key_first($tiers);
$base = [
    'event' => $eventId, 'nonce' => $nonce, 'ts' => time() - 30, 'tier' => $tierKey,
    'first_name' => 'Rowan', 'last_name' => 'Tester', 'email' => $email,
];
$res = rest('POST', '/bosquegrande/v1/register', $base + ['demographics' => ['Student'], 'age_range' => '18-34']);
$data = $res->get_data();
check($res->get_status() === 200 && !empty($data['ok']), 'anonymous free registration succeeds (' . $res->get_status() . ': ' . substr((string) ($data['message'] ?? ''), 0, 80) . ')');

$ids = $store->idsByEmail($email);
check(count($ids) === 1, 'one local record created');
$localId = (int) ($ids[0] ?? 0);
$answers = $store->personalAnswersFor($localId);
check(in_array('Student', (array) ($answers['demographics'] ?? []), true), 'answers saved on first registration');

echo '  mails captured: ' . count($mails) . "\n";
$confirmation = '';
foreach ($mails as $m) if (($m['to'] ?? '') === $email || in_array($email, (array) ($m['to'] ?? []), true)) $confirmation = (string) $m['message'];
check($confirmation !== '', 'confirmation email sent to registrant');
check(str_contains($confirmation, 'bg_verify='), 'confirmation includes the save-my-info sign-in link');
check(!preg_match('/\x{2014}/u', $confirmation), 'confirmation has no em dash');

// A second, anonymous registration for the same person on another event must not replace answers.
$event2 = wp_insert_post([
    'post_type' => 'bg_event', 'post_status' => 'publish', 'post_title' => 'Smoke Test Hike', 'post_content' => 'x',
]);
update_post_meta($event2, '_bg_start_date', gmdate('Y-m-d', time() + 21 * DAY_IN_SECONDS));
$n2 = (string) (rest('GET', '/bosquegrande/v1/reg-nonce', ['event' => $event2])->get_data()['nonce'] ?? '');
$res2 = rest('POST', '/bosquegrande/v1/register', array_merge($base, ['event' => $event2, 'nonce' => $n2, 'demographics' => ['Veteran'], 'age_range' => '55+']));
check(!empty($res2->get_data()['ok']), 'second anonymous registration succeeds (' . substr((string) ($res2->get_data()['message'] ?? ''), 0, 90) . ')');
$answers2 = $store->personalAnswersFor($localId);
check(in_array('Student', (array) ($answers2['demographics'] ?? []), true)
    && !in_array('Veteran', (array) ($answers2['demographics'] ?? []), true), 'unverified form did not overwrite demographics');
check((string) ($answers2['age_range'] ?? '') === '18-34', 'unverified form did not overwrite age range');

// Duplicate registration for the same event.
$n1 = (string) (rest('GET', '/bosquegrande/v1/reg-nonce', ['event' => $eventId])->get_data()['nonce'] ?? '');
$dup = rest('POST', '/bosquegrande/v1/register', array_merge($base, ['nonce' => $n1]));
check(!empty($dup->get_data()['ok']) && stripos((string) $dup->get_data()['message'], 'already registered') !== false, 'duplicate registration is recognized');

// Honeypot and bad nonce.
$bad = rest('POST', '/bosquegrande/v1/register', array_merge($base, ['nonce' => 'bad', 'email' => 'x' . $email]));
check(empty($bad->get_data()['ok']), 'bad nonce refused');

// Currency formatting follows the setting.
check(\BosqueGrande\Core\Money::format(1250) === '$12.50', 'money format default USD');

// Signed email link round trip and cross-purpose rejection.
$token = \BosqueGrande\Modules\Correspondence\Composer::sign(['a' => 'unsub', 'c' => $localId, 'g' => 1]);
check(\BosqueGrande\Modules\Correspondence\Composer::verify($token)['c'] === $localId, 'email link token verifies');
check(\BosqueGrande\Modules\Correspondence\Composer::verify($token . 'x') === null, 'tampered email link token refused');

// Clean up the test events.
wp_delete_post($eventId, true);
wp_delete_post($event2, true);

finish();
