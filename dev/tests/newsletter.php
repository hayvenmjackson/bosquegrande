<?php
// Newsletter: queue and send to the default group, then check the one-click
// unsubscribe header, the signed unsubscribe link, and the unsubscribe itself.
$bgTestUri = '/wp-admin/';
require __DIR__ . '/bootstrap.php';
wp_set_current_user(1);
$mails = [];
remove_all_filters('pre_wp_mail');
add_filter('pre_wp_mail', function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);

$c = \BosqueGrande\Core\Plugin::instance()->container();
$groups = $c->get('correspondence.groups');
$store = $c->get('constituents.store');
$general = $groups->bySlug('general');
check($general !== null, 'default group exists');
$email = 'reader' . wp_rand(1000, 9999) . '@example.test';
$cid = $store->upsertByEmail($email, ['first_name' => 'Rea', 'last_name' => 'Der']);
check($groups->subscribe($cid, (int) $general->id, 'admin'), 'reader subscribed');

$nl = wp_insert_post(['post_type' => 'bg_newsletter', 'post_status' => 'draft', 'post_title' => 'Smoke Newsletter', 'post_content' => '<p>Hello from the smoke test.</p>']);
$sender = $c->get('correspondence.sender');
$queued = $sender->queueNewsletter($nl, (int) $general->id);
check(!empty($queued['queued']) || !empty($queued['ok']), 'newsletter queued (' . json_encode($queued) . ')');
update_post_meta($nl, \BosqueGrande\Modules\Correspondence\Sender::AUTH_META, '1');
$remaining = $sender->runBatch($nl);
check($remaining === 0 && count($mails) >= 1, 'batch delivered (' . count($mails) . ' message(s), ' . $remaining . ' remaining)');
$msg = null;
foreach ($mails as $m) if (($m['to'] ?? '') === $email || in_array($email, (array) ($m['to'] ?? []), true)) $msg = $m;
check($msg !== null, 'reader received the newsletter');
$body = (string) ($msg['message'] ?? '');
$headers = implode("\n", (array) ($msg['headers'] ?? []));
check(str_contains($headers, 'List-Unsubscribe-Post'), 'one-click unsubscribe header present');
check(!preg_match('/\x{2014}/u', $body), 'newsletter body has no em dash');
preg_match('/[?&]bg_unsub=([A-Za-z0-9_-]+)/', $body, $mm);
$token = $mm[1] ?? '';
check($token !== '', 'unsubscribe link found in the body');
$payload = \BosqueGrande\Modules\Correspondence\Composer::verify($token);
check(is_array($payload) && (int) ($payload['c'] ?? 0) === $cid, 'unsubscribe token is signed for this reader');
$req = new WP_REST_Request('POST', '/bosquegrande/v1/unsubscribe');
$req->set_param('t', $token);
$res = rest_do_request($req);
check($res->get_status() === 200, 'one-click unsubscribe works');
check($groups->subscriptionStatus($cid, (int) $general->id) === 'unsubscribed', 'reader is unsubscribed');
wp_delete_post($nl, true);
finish();
