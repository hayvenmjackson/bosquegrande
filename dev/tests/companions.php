<?php
// Floating Actions and Organizer Launcher companions: actions follow the
// settings, the QR code encodes the signup address, the built-in signup page
// honors its settings, and the launcher's audience and page rules hold.
// (The Floating Assistant has its own test, assistant.php.)
require __DIR__ . '/bootstrap.php';

use BGCompanion\Actions\Plugin as Actions;
use BGCompanion\Actions\Qr;
use BGCompanion\Launcher\Plugin as Launcher;

if (!class_exists(Actions::class) || !class_exists(Launcher::class)) {
    echo "SKIP Floating Actions and Organizer Launcher must both be active\n";
    exit(0);
}

$savedActions = get_option(Actions::OPTION, null);
$savedLauncher = get_option(Launcher::OPTION, null);

// Floating Actions.
update_option(Actions::OPTION, ['subscribe' => 1, 'donate' => 0, 'custom' => 0], false);
$keys = array_column(Actions::actions(), 'key');
check($keys === ['subscribe'], 'subscribe only by default (' . implode(',', $keys) . ')');
check(Actions::signupUrl() === home_url('/bg-newsletter-signup/'), 'built-in signup address');
update_option(Actions::OPTION, ['subscribe' => 1, 'donate' => 1, 'custom' => 1, 'custom_url' => 'https://example.org/volunteer/', 'signup_path' => 'join/us'], false);
$actions = Actions::actions();
check(array_column($actions, 'key') === ['subscribe', 'donate', 'custom'], 'all three actions in order');
check(Actions::signupUrl() === home_url('/join/us/'), 'custom signup address');
update_option(Actions::OPTION, ['subscribe' => 1, 'custom' => 1, 'custom_url' => 'javascript:alert(1)'], false);
check(!in_array('custom', array_column(Actions::actions(), 'key'), true), 'unsafe custom address is dropped');

$qr = new Qr(home_url('/bg-newsletter-signup/'), 1);
check($qr->version() <= 5 && $qr->size() === $qr->version() * 4 + 17, 'signup address fits a small QR code (version ' . $qr->version() . ')');
$svg = $qr->svg(2, '#000', '#fff', 'Scan me');
check(str_starts_with($svg, '<svg') && str_contains($svg, 'aria-label="Scan me"') && substr_count($svg, 'h1v1h-1z') > 100, 'QR renders as accessible SVG');
check(Qr::numDataCodewords(1, 1) === 16 && Qr::numDataCodewords(10, 1) === 216 && Qr::numDataCodewords(40, 0) === 2956, 'QR capacity tables');
$threw = false;
try {
    new Qr(str_repeat('x', 3000), 3);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check($threw, 'oversized QR content is refused');
check(Actions::textOn('#ffffff') === '#111111' && Actions::textOn('#1F5130') === '#ffffff' && Actions::textOn('#ffd400') === '#111111', 'button text picks the readable color');

// Organizer Launcher (front-page checks run outside a page request, so is_front_page() is false here).
update_option(Launcher::OPTION, ['audience' => 'organizers', 'pages' => 'all'], false);
check(Launcher::show(false, true) === true && Launcher::show(false, false) === false, 'organizers only: organizer yes, visitor no');
update_option(Launcher::OPTION, ['audience' => 'everyone', 'pages' => 'all'], false);
check(Launcher::show(false, false) === true, 'everyone: visitors see it');
update_option(Launcher::OPTION, ['audience' => 'everyone', 'pages' => 'front'], false);
check(Launcher::show(true, true) === false, 'front page only: hidden elsewhere');
update_option(Launcher::OPTION, ['audience' => 'nobody', 'pages' => 'all'], false);
check(Launcher::show(true, true) === false, 'nobody: hidden even from organizers');
update_option(Launcher::OPTION, ['audience' => 'bogus', 'position' => 'sideways'], false);
$s = Launcher::settings();
check($s['audience'] === 'organizers' && $s['position'] === 'bottom-right', 'invalid settings fall back to defaults');

if ($savedActions === null) delete_option(Actions::OPTION); else update_option(Actions::OPTION, $savedActions, false);
if ($savedLauncher === null) delete_option(Launcher::OPTION); else update_option(Launcher::OPTION, $savedLauncher, false);
finish();
