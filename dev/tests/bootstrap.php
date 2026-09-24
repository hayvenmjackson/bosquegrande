<?php
// Shared setup for the dev tests. Loads WordPress from the folder given as the
// first argument and refuses to run unless the site is marked disposable,
// because the tests create and delete records.
if (getenv('BG_TEST_DISPOSABLE') !== '1') {
    fwrite(STDERR, "These tests write to the database. Run them only against a disposable test site, with BG_TEST_DISPOSABLE=1.\n");
    exit(2);
}
$dir = $argv[1] ?? '';
if ($dir === '' || !is_file($dir . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php " . basename($argv[0]) . " /path/to/wordpress\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = getenv('BG_TEST_HOST') ?: 'localhost';
// On a multisite network, BG_TEST_PATH picks the site (for example /riverbend).
$_SERVER['REQUEST_URI'] = rtrim((string) getenv('BG_TEST_PATH'), '/') . ($bgTestUri ?? '/');
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
require $dir . '/wp-load.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fail++;
}

function finish(): void
{
    global $fail;
    echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
    exit($fail === 0 ? 0 : 1);
}
