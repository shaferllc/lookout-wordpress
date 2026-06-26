<?php

/**
 * Standalone test for the WP plugin's authentication monitoring payload construction.
 * No WordPress runtime needed — the WP functions and the ingest client are stubbed so each handler
 * can be invoked directly and its captured payload inspected.
 * Run: php packages/lookout-wordpress/tests/auth-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

$GLOBALS['__sent'] = [];
$GLOBALS['__options'] = [
    'lookout_environment' => 'production',
];

function get_option(string $key, $default = false)
{
    return $GLOBALS['__options'][$key] ?? $default;
}

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    return true;
}

function get_userdata($user_id)
{
    return $GLOBALS['__users'][(int) $user_id] ?? false;
}

function wp_get_current_user()
{
    return $GLOBALS['__current_user'] ?? null;
}

/** Stub client that captures the auth payload instead of POSTing it. */
final class Lookout_Client
{
    public static function send_auth(array $payload): void
    {
        $GLOBALS['__sent'][] = $payload;
    }
}

require __DIR__.'/../includes/class-lookout-auth.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL').' — '.$label."\n";
    if (! $ok) {
        $failures++;
    }
};

$make_user = static function (int $id, string $email, string $login): object {
    $u = new stdClass;
    $u->ID = $id;
    $u->user_email = $email;
    $u->user_login = $login;

    return $u;
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test)';

// (a) Successful login carries the user id + email label and the right event type.
$GLOBALS['__sent'] = [];
Lookout_Auth::on_login('bob', $make_user(42, 'bob@example.com', 'bob'));
$login = $GLOBALS['__sent'][0] ?? [];
$check('login event_type is login', ($login['event_type'] ?? null) === 'login');
$check('login carries user id as string', ($login['auth_user_id'] ?? null) === '42');
$check('login label is the email', ($login['auth_user_label'] ?? null) === 'bob@example.com');

// (b) Failed login carries the attempted label and no user id.
$GLOBALS['__sent'] = [];
Lookout_Auth::on_login_failed('attacker@example.com');
$failed = $GLOBALS['__sent'][0] ?? [];
$check('failed event_type is failed', ($failed['event_type'] ?? null) === 'failed');
$check('failed label is the attempted username', ($failed['auth_user_label'] ?? null) === 'attacker@example.com');
$check('failed has no user id', ! array_key_exists('auth_user_id', $failed));

// (c) Password reset NEVER includes the new password anywhere in the payload.
$GLOBALS['__sent'] = [];
Lookout_Auth::on_password_reset($make_user(7, 'carol@example.com', 'carol'), 'SuperSecret123!');
$reset = $GLOBALS['__sent'][0] ?? [];
$check('password_reset event_type is password_reset', ($reset['event_type'] ?? null) === 'password_reset');
$check('password_reset never leaks the new password', strpos(wp_json_encode_test($reset), 'SuperSecret123!') === false);

// (d) IP is anonymized (last octet zeroed) and UA is captured.
$check('ip is anonymized', ($login['ip_address'] ?? null) === '203.0.113.0');
$check('user agent captured', ($login['user_agent'] ?? null) === 'Mozilla/5.0 (Test)');

// (e) Logout falls back to the current user when no id is passed.
$GLOBALS['__sent'] = [];
$GLOBALS['__current_user'] = $make_user(99, 'dave@example.com', 'dave');
Lookout_Auth::on_logout();
$logout = $GLOBALS['__sent'][0] ?? [];
$check('logout resolves the current user', ($logout['auth_user_id'] ?? null) === '99');
$check('logout event_type is logout', ($logout['event_type'] ?? null) === 'logout');

echo $failures === 0 ? "\nOK\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);

/** Local JSON encoder so the test has no WordPress dependency. */
function wp_json_encode_test(array $data): string
{
    return (string) json_encode($data);
}
