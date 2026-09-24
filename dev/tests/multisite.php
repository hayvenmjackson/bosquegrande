<?php
// Multisite isolation: signed links and account links from one site must not
// work on another, whether the sites share one keyring file or each has its
// own. Needs a network with BosqueGrande active on at least two sites.
require __DIR__ . '/bootstrap.php';

use BosqueGrande\Core\UserLink;
use BosqueGrande\Modules\Correspondence\Composer;

if (!is_multisite()) {
    echo "SKIP not a multisite network\n";
    exit(0);
}
$sites = array_map('intval', get_sites(['fields' => 'ids', 'number' => 2, 'deleted' => 0, 'archived' => 0]));
if (count($sites) < 2) {
    echo "SKIP needs at least two sites\n";
    exit(0);
}
[$a, $b] = $sites;
$enc = \BosqueGrande\Core\Plugin::instance()->container()->get('encryption');

$crossSiteChecks = static function (string $setup) use ($a, $b, $enc): void {
    switch_to_blog($a);
    $keyA = $enc->signingKey('email-link');
    $token = Composer::sign(['a' => 'unsub', 'c' => 5, 'g' => 1]);
    $ownSite = Composer::verify($token);
    restore_current_blog();
    switch_to_blog($b);
    $keyB = $enc->signingKey('email-link');
    $otherSite = Composer::verify($token);
    restore_current_blog();
    check($keyA !== '' && $keyB !== '' && $keyA !== $keyB, "$setup: signing keys differ between sites");
    check(is_array($ownSite) && (int) ($ownSite['c'] ?? 0) === 5, "$setup: a link verifies on its own site");
    check($otherSite === null, "$setup: the same link is refused on another site");
};

// 1. Every site shares one keyring file (a plain network install).
$shared = sys_get_temp_dir() . '/bg-shared-ring-' . bin2hex(random_bytes(3)) . '.php';
$sharedPath = static fn(): string => $shared;
$noSeal = static fn(): string => '';
add_filter('bg_keyring_path', $sharedPath, PHP_INT_MAX);
add_filter('bg_keyring_seal_key', $noSeal, PHP_INT_MAX);
$crossSiteChecks('shared keyring');
remove_filter('bg_keyring_path', $sharedPath, PHP_INT_MAX);
remove_filter('bg_keyring_seal_key', $noSeal, PHP_INT_MAX);
@unlink($shared);
@unlink($shared . '.lock');

// 2. The site's own configuration (per-site keyrings in the hosted edition).
$crossSiteChecks('configured keyrings');

// 3. Account links are per site.
$login = 'bgms' . bin2hex(random_bytes(3));
$userId = wpmu_create_user($login, wp_generate_password(20), $login . '@example.test');
check($userId > 0, 'test account created');
if ($userId) {
    add_user_to_blog($a, $userId, 'subscriber');
    add_user_to_blog($b, $userId, 'subscriber');
    switch_to_blog($a);
    UserLink::set($userId, 5);
    $onA = UserLink::get($userId);
    $foundOnA = in_array($userId, UserLink::usersFor(5), true);
    restore_current_blog();
    switch_to_blog($b);
    $onB = UserLink::get($userId);
    $foundOnB = in_array($userId, UserLink::usersFor(5), true);
    restore_current_blog();
    check($onA === 5 && $foundOnA, 'account link reads back on its own site');
    check($onB === 0 && !$foundOnB, 'account link is invisible on another site');
    require_once ABSPATH . 'wp-admin/includes/ms.php';
    wpmu_delete_user($userId);
}

finish();
