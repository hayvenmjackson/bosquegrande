<?php
// Staff side: vocabulary regions and postal rules, the payment ledger and
// currency lock, the QuickBooks worksheet with classes by region, grant
// reports, and grant tag keyword rules.
$bgTestUri = '/wp-admin/';
require __DIR__ . '/bootstrap.php';
wp_set_current_user(1);

use BosqueGrande\Core\Money;
use BosqueGrande\Modules\Constituents\RegionalGroups;
use BosqueGrande\Modules\GatheringTree\Vocabulary;
use BosqueGrande\Modules\Revenue\QboTransactionRegion;

$c = \BosqueGrande\Core\Plugin::instance()->container();
$store = $c->get('constituents.store');

// Vocabulary and region rules.
Vocabulary::save(['north-shore' => 'North Shore', 'valley' => 'The Valley'], Vocabulary::interests());
update_option('bg_region_postal_rules', ['049' => 'north-shore', '04' => 'valley'], false);
$regions = RegionalGroups::regions();
check(isset($regions['north-shore'], $regions['valley']), 'vocabulary regions are available');
$r1 = RegionalGroups::classifyRecord(['address1' => '1 Main St', 'city' => 'Anywhere', 'state' => 'ME', 'postal' => '04901']);
$r2 = RegionalGroups::classifyRecord(['address1' => '1 Main St', 'city' => 'Elsewhere', 'state' => 'ME', 'postal' => '04401']);
check(($r1['region'] ?? '') === 'north-shore', 'longest postal prefix wins (' . json_encode($r1) . ')');
check(($r2['region'] ?? '') === 'valley', 'shorter postal prefix applies (' . json_encode($r2) . ')');

// A donor in the North Shore region.
$email = 'donor' . wp_rand(1000, 9999) . '@example.test';
$cid = $store->upsertByEmail($email, [
    'first_name' => 'Dana', 'last_name' => 'Donor',
    'address1' => '12 Harbor Rd', 'city' => 'Anywhere', 'state' => 'ME', 'postal' => '04901',
]);
check($cid > 0, 'donor record created');

// Currency is USD and unlocked until a payment exists.
check(Money::currency() === 'usd', 'default currency is USD');

/** @var \BosqueGrande\Modules\Revenue\Ledger $ledger */
$ledger = $c->get('revenue.ledger');
$pid = $ledger->record([
    'kind' => 'donation', 'amount_cents' => 2500, 'constituent_id' => $cid,
    'source' => 'website_form', 'external_key' => 'smoke:' . wp_generate_password(8, false),
]);
check($pid > 0, 'donation recorded in the ledger');
check(Money::locked(), 'currency locks once a payment exists');
check(!Money::save('eur'), 'currency change refused after payments');
$row = $ledger->row($pid);
check(Money::matches((string) $row->currency), 'ledger row uses the site currency');

// Class by region.
QboTransactionRegion::save(['north-shore' => 'Programs:North Shore', 'valley' => 'Programs:Valley'], 'Programs:General');
/** @var \BosqueGrande\Modules\Revenue\QboExporter $qbo */
$qbo = $c->get('revenue.qbo');
$qbo->addMapping('kind_default', 'donation', 'Undeposited Funds', 'Donations', '');
$prepared = $qbo->prepare(false);
$lines = array_map(static fn(array $r): string => implode(',', array_map('strval', $r)), (array) ($prepared['rows'] ?? []));
$csv = implode("\n", $lines);
$mine = array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, 'Donations') && str_contains($l, '25.00')));
check(in_array($pid, array_map('intval', (array) ($prepared['ids'] ?? [])), true), 'QuickBooks worksheet includes the donation (' . count($lines) . ' lines, ' . (int) ($prepared['reviewed'] ?? 0) . ' reviewed)');
check($mine !== [] && str_contains($mine[0], 'Programs:North Shore'), 'blank mapping class uses the donor region class');

// Grant report over the last year.
/** @var \BosqueGrande\Modules\GrantReporting\ReportBuilder $builder */
$builder = $c->get('grant.builder');
try {
    $report = $builder->build([
        'title' => 'Smoke report',
        'period_start' => gmdate('Y-m-d', time() - 365 * DAY_IN_SECONDS),
        'period_end' => gmdate('Y-m-d', time() + 60 * DAY_IN_SECONDS),
        'privacy_threshold' => 3,
    ]);
    check(is_array($report) && $report !== [], 'grant report builds');
    check(isset($report['summary'], $report['financials'], $report['sources']), 'grant report has summary, financials, and sources');
} catch (\Throwable $e) {
    check(false, 'grant report builds: ' . $e->getMessage());
}

// Grant tag rules suggest a tag by keyword.
$term = wp_insert_term('Smoke Grant ' . wp_rand(100, 999), 'bg_grant_tag');
check(!is_wp_error($term), 'grant tag created');
if (!is_wp_error($term)) {
    update_term_meta($term['term_id'], '_bg_grant_rules', ['from' => '', 'to' => '', 'tags' => [], 'keywords' => ['garden']]);
    $eventId = wp_insert_post([
        'post_type' => 'bg_event', 'post_status' => 'publish',
        'post_title' => 'Smoke Garden Workday', 'post_content' => 'Weeding and planting.',
    ]);
    update_post_meta($eventId, '_bg_start_date', gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS));
    $suggested = \BosqueGrande\Modules\GrantReporting\GrantTags::suggestForEvent($eventId);
    $slug = (string) get_term((int) $term['term_id'], 'bg_grant_tag')->slug;
    check(in_array($slug, (array) $suggested, true), 'grant tag rule matches by keyword');
    wp_delete_post($eventId, true);
    wp_delete_term((int) $term['term_id'], 'bg_grant_tag');
}

// Clean up the test payment so reruns start fresh.
global $wpdb;
$wpdb->delete($wpdb->prefix . 'bg_payments', ['id' => $pid]);
$wpdb->query("DELETE FROM {$wpdb->prefix}bg_qbo_mappings");
$wpdb->query("DELETE FROM {$wpdb->prefix}bg_qbo_review");

finish();
