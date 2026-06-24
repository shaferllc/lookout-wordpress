<?php

/**
 * Standalone test for the client error-suppression key + remote "suppress" list in the WP plugin.
 * No WordPress runtime needed — get_transient is stubbed to a fixed config.
 * Run: php packages/lookout-wordpress/tests/suppression-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

/** @var array<string, mixed> $GLOBALS_test_config */
$GLOBALS['__test_config'] = [];

function get_transient(string $key): mixed
{
    return $GLOBALS['__test_config'];
}

function set_transient(string $key, mixed $value, int $ttl): bool
{
    return true;
}

require __DIR__.'/../includes/class-lookout-config.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL').' — '.$label."\n";
    if (! $ok) {
        $failures++;
    }
};

// Byte-for-byte match with App\Support\ErrorSuppressionKey::compute (cross-checked value).
$check(
    'key matches the server recipe',
    Lookout_Config::suppression_key('App\\Exceptions\\Boom', 'User 4212 not found') === '39ef0cde2ebd1a398fdb287a1c103895',
);

// Volatile tokens collapse different occurrences to one key.
$check(
    'volatile tokens collapse occurrences',
    Lookout_Config::suppression_key('X', 'HTTP 404 Not Found: GET /a/01ktb3fn7n0me349kth61ydqy6rvqos.png')
        === Lookout_Config::suppression_key('X', 'HTTP 404 Not Found: GET /a/02abckj9zz8x71239aaa00bbb1zzzzz.png'),
);

$key = Lookout_Config::suppression_key('App\\Exceptions\\Boom', 'Boom happened');

$GLOBALS['__test_config'] = ['suppress' => [$key]];
$check('ignored error is suppressed', Lookout_Config::is_error_suppressed('App\\Exceptions\\Boom', 'Boom happened'));
$check('other error is not suppressed', ! Lookout_Config::is_error_suppressed('App\\Exceptions\\Other', 'Different'));

$GLOBALS['__test_config'] = ['signals' => []];
$check('not suppressed when no suppress list', ! Lookout_Config::is_error_suppressed('App\\Exceptions\\Boom', 'Boom happened'));

echo $failures === 0 ? "\nOK\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
