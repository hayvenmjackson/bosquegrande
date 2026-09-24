<?php
// Keyring file formats: plain, sealed, resealing, wrong and malformed seal keys, rotation.
require __DIR__ . '/bootstrap.php';
use BosqueGrande\Core\KeyStore;

// These checks are about the file formats themselves, so start without any
// seal key a hosting plugin may supply.
remove_all_filters('bg_keyring_seal_key');
$dir = sys_get_temp_dir() . '/bgks-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
$seal = random_bytes(32);
$filter = static fn() => $seal;

// 1. Plain ring without a seal key.
$plain = new KeyStore("$dir/plain.php");
check($plain->ensure(), 'plain ring created');
check(str_starts_with((string) file_get_contents("$dir/plain.php"), "<?php\nif (!defined('ABSPATH')) exit;"), 'plain ring is a guarded PHP file');
$id = $plain->activeId(); $index = $plain->indexKey(); $sign = $plain->signingKey('x');
check(strlen($plain->activeKey()) === 32 && strlen($index) === 32 && strlen($sign) === 32, 'plain ring keys are 32 bytes');
check($plain->signingKey('a') !== $plain->signingKey('b'), 'signing keys differ by purpose');

// 2. Sealed ring.
add_filter('bg_keyring_seal_key', $filter);
$sealed = new KeyStore("$dir/sealed.php");
check($sealed->ensure(), 'sealed ring created');
$raw = (string) file_get_contents("$dir/sealed.php");
check(str_starts_with($raw, "<?php exit; ?>\nBGSEALED1\n"), 'sealed ring has the sealed header');
check(!str_contains($raw, base64_encode($sealed->indexKey())), 'sealed ring does not contain key material in the clear');
$again = new KeyStore("$dir/sealed.php");
check($again->ensure() && $again->activeKey() === $sealed->activeKey(), 'sealed ring reads back');

// 3. An existing plain ring is resealed when a seal key appears.
$re = new KeyStore("$dir/plain.php");
check($re->ensure() && $re->activeId() === $id && $re->indexKey() === $index, 'plain ring still readable with a seal key');
check(str_starts_with((string) file_get_contents("$dir/plain.php"), "<?php exit; ?>\nBGSEALED1\n"), 'plain ring was resealed');

// 4. A wrong seal key fails closed and never overwrites the file.
remove_filter('bg_keyring_seal_key', $filter);
$other = random_bytes(32);
add_filter('bg_keyring_seal_key', static fn() => $other);
$before = (string) file_get_contents("$dir/sealed.php");
$wrong = new KeyStore("$dir/sealed.php");
check(!$wrong->ensure() && $wrong->status() === 'invalid-file', 'wrong seal key fails closed');
check((string) file_get_contents("$dir/sealed.php") === $before, 'sealed file untouched after a failed read');

// 5. A malformed seal key is refused.
remove_all_filters('bg_keyring_seal_key');
add_filter('bg_keyring_seal_key', static fn() => 'short');
$bad = new KeyStore("$dir/new.php");
check(!$bad->ensure() && !file_exists("$dir/new.php"), 'malformed seal key refused, nothing written');

// 6. Rotation keeps old keys.
remove_all_filters('bg_keyring_seal_key');
add_filter('bg_keyring_seal_key', $filter);
$rot = new KeyStore("$dir/sealed.php");
$oldId = $rot->activeId(); $oldKey = $rot->activeKey();
$newId = $rot->rotate();
check($newId !== null && $newId !== $oldId && $rot->key($oldId) === $oldKey, 'rotation adds a key and keeps the old one');

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);
finish();
