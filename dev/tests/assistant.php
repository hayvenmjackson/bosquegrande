<?php
// Floating Assistant companion: training info reaches the right audience,
// visitors never get staff-only material or the staff reference, the chat
// route answers and enforces its limits, and escalation replies stand in
// for guesses. A recording provider stands in for the real service, so no
// key is needed and nothing leaves the site.
require __DIR__ . '/bootstrap.php';

use BGCompanion\Assistant\Training;
use BGCompanion\Assistant\Plugin as Companion;

if (!class_exists(Training::class)) {
    echo "SKIP the Floating Assistant companion is not active\n";
    exit(0);
}

final class RecordingProvider implements \BosqueGrande\Modules\Intelligence\ProviderInterface
{
    /** @var list<array{system: string, prompt: string}> */
    public static array $calls = [];
    public static string $reply = 'Recorded answer.';
    public function name(): string { return 'anthropic'; }
    public function isConfigured(): bool { return true; }
    public function complete(string $system, string $prompt, array $opts = []): string
    {
        self::$calls[] = ['system' => $system, 'prompt' => $prompt];
        return self::$reply;
    }
}

$c = \BosqueGrande\Core\Plugin::instance()->container();
$c->set('ai', fn() => new RecordingProvider());
$settings = $c->get('settings');
$before = ['ai_enabled' => $settings->get('ai_enabled', 0), 'features' => $settings->get('ai_features', [])];
$companionBefore = get_option(Companion::OPTION, null);
$settings->set('ai_enabled', 1);
remove_all_filters('pre_wp_mail');
add_filter('pre_wp_mail', fn() => true);

$tag = strtoupper(wp_generate_password(6, false, false));
$ids = [];
$ids[] = Training::save(0, ['title' => "Visitor FAQ $tag", 'audience' => 'visitor', 'pinned' => false, 'enabled' => true],
    "# Refunds\nRegistrations can be refunded up to 7 days before the event ($tag-REFUND).\n\n# Parking\nPark behind the library after 5 p.m.");
$ids[] = Training::save(0, ['title' => "Petty cash $tag", 'audience' => 'staff', 'pinned' => false, 'enabled' => true],
    "Petty cash procedure: count the drawer with a second person and log it ($tag-STAFFONLY).");
$ids[] = Training::save(0, ['title' => "Key facts $tag", 'audience' => 'visitor', 'pinned' => true, 'enabled' => true],
    "Office hours are Tuesday to Saturday, 10 to 4 ($tag-PINNED).");
$ids[] = Training::save(0, ['title' => "Old policy $tag", 'audience' => 'visitor', 'pinned' => false, 'enabled' => false],
    "Refunds are never given ($tag-DISABLED).");
check(count(array_filter($ids, 'is_int')) === 4, 'training entries saved');

// Retrieval by audience.
$visitor = Training::reference('visitor', 'Can I get a refund for my registration?');
check(str_contains($visitor, "$tag-REFUND"), 'visitor question finds the matching passage');
check(str_contains($visitor, "$tag-PINNED"), 'always-included entry comes along');
check(!str_contains($visitor, "$tag-STAFFONLY"), 'staff-only entry never reaches visitors');
check(!str_contains($visitor, "$tag-DISABLED"), 'entries that are turned off are ignored');
$staff = Training::reference('staff', 'What is the petty cash procedure?');
check(str_contains($staff, "$tag-STAFFONLY"), 'staff question finds the staff-only passage');
check(Training::keywords('How do I register?') === Training::keywords('registration'), 'register and registration match');

// A large library where a common word matches hundreds of passages: the
// passage that also matches the specific words must still be found.
$filler = '';
for ($k = 0; $k < 450; $k++) {
    $filler .= "# Session $k\nTo register for session $k, open the calendar and choose Register. " . str_repeat('Bring water and comfortable shoes. ', 9) . "\n\n";
}
$filler .= "# Access\nTo register with wheelchair access, use the ramp entrance on Elm Street ($tag-ACCESS).\n";
$ids[] = $big = Training::save(0, ['title' => "Sessions $tag", 'audience' => 'visitor', 'pinned' => false, 'enabled' => true], $filler);
check(is_int($big), 'large library entry saved' . (is_wp_error($big) ? ': ' . $big->get_error_message() : ''));
check(str_contains(Training::reference('visitor', 'How do I register with wheelchair access?'), "$tag-ACCESS"), 'specific passage found among hundreds that share a common word');

// The visitor chat, end to end through the REST route.
update_option(Companion::OPTION, ['enabled' => 1, 'per_visitor' => 2, 'daily_cap' => 0], false);
$chat = static function (string $q): \WP_REST_Response {
    $req = new \WP_REST_Request('POST', '/bosquegrande/v1/assistant-chat');
    $req->set_param('message', $q);
    $req->set_param('page', home_url('/'));
    return rest_do_request($req);
};
RecordingProvider::$calls = [];
$res = $chat('Can I get a refund for my registration?');
check($res->get_status() === 200 && ($res->get_data()['message'] ?? '') === 'Recorded answer.', 'visitor chat answers (' . $res->get_status() . ')');
$system = RecordingProvider::$calls[0]['system'] ?? '';
check(str_contains($system, "$tag-REFUND") && str_contains($system, 'ORGANIZATION REFERENCE'), 'visitor prompt carries the matching training info');
check(!str_contains($system, "$tag-STAFFONLY"), 'visitor prompt has no staff-only training info');
check(!str_contains($system, 'STAFF REFERENCE') && !str_contains($system, 'BOSQUEGRANDE 5.0 STAFF REFERENCE'), 'visitor prompt has no staff reference');

// Staff assistant in the dashboard.
RecordingProvider::$calls = [];
$c->get('intelligence.assistant')->ask('What is the petty cash procedure?', []);
$staffSystem = RecordingProvider::$calls[0]['system'] ?? '';
check(str_contains($staffSystem, "$tag-STAFFONLY") && str_contains($staffSystem, 'STAFF REFERENCE'), 'staff prompt carries staff-only info and the staff reference');

// Limits.
$chat('Where do I park?');
$third = $chat('One more question?');
check($third->get_status() === 429, 'per-visitor limit stops the third question');
update_option(Companion::OPTION, ['enabled' => 1, 'per_visitor' => 50, 'daily_cap' => 1], false);
$capped = $chat('Where do I park?');
check($capped->get_status() === 429, 'daily limit stops questions once reached');
update_option(Companion::OPTION, ['enabled' => 1, 'per_visitor' => 50, 'daily_cap' => 0], false);
check($chat(str_repeat('x', 501))->get_status() === 400, 'overlong questions are refused');

// Escalation instead of a guess.
RecordingProvider::$reply = '[ESCALATE] Question about something not covered.';
$esc = $chat('Do you rent the hall for weddings?');
check(!str_contains((string) ($esc->get_data()['message'] ?? ''), '[ESCALATE]'), 'escalation marker never reaches visitors');

// Turned off: the route stays closed.
update_option(Companion::OPTION, ['enabled' => 0], false);
check($chat('Hello?')->get_status() === 503, 'chat route is closed while the button is off');

// Clean up.
foreach ($ids as $id) if (is_int($id)) Training::delete($id);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bgca\\_rate\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bgca\\_rate\\_%' OR option_name LIKE 'bgca\\_day\\_%'");
$settings->set('ai_enabled', $before['ai_enabled']);
$settings->set('ai_features', $before['features']);
if ($companionBefore === null) delete_option(Companion::OPTION); else update_option(Companion::OPTION, $companionBefore, false);
finish();
