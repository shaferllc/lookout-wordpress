<?php

/**
 * Authentication monitoring.
 *
 * Reports WordPress auth lifecycle events (login, logout, failed login, registration, password
 * reset) to /api/ingest/auth, so the Authentication watcher shows who signed in, failed attempts,
 * and account changes. Opt-in via the "Authentication monitoring" setting. Only the user's id and a
 * display label (email/username) are sent — never passwords or any other credential. Every callback
 * is wrapped so a failure can never disrupt the login/logout flow it observes.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Auth
{
    public static function boot(): void
    {
        if (! self::enabled()) {
            return;
        }

        add_action('wp_login', [self::class, 'on_login'], 10, 2);
        add_action('wp_logout', [self::class, 'on_logout'], 10, 1);
        add_action('wp_login_failed', [self::class, 'on_login_failed'], 10, 1);
        add_action('user_register', [self::class, 'on_user_register'], 10, 1);
        add_action('after_password_reset', [self::class, 'on_password_reset'], 10, 2);
    }

    private static function enabled(): bool
    {
        if (! get_option('lookout_enabled', false)) {
            return false;
        }

        if ((string) get_option('lookout_api_key', '') === '' || (string) get_option('lookout_base_url', '') === '') {
            return false;
        }

        // Controlled from the dashboard via remote config (signals.auth.enabled, off by default), with
        // the local "Authentication monitoring" setting as an offline force-on override.
        $remote = class_exists('Lookout_Config') && Lookout_Config::is_enabled('auth');

        return $remote || (bool) get_option('lookout_auth_monitoring', false);
    }

    /**
     * @param  mixed  $user_login
     * @param  mixed  $user  WP_User
     */
    public static function on_login($user_login, $user = null): void
    {
        try {
            self::report('login', is_object($user) ? $user : null);
        } catch (Throwable $e) {
            // Never let auth monitoring break sign-in.
        }
    }

    /**
     * @param  mixed  $user_id  Passed since WP 5.5; older releases call this with no argument.
     */
    public static function on_logout($user_id = null): void
    {
        try {
            $user = null;
            if (is_numeric($user_id) && (int) $user_id > 0 && function_exists('get_userdata')) {
                $loaded = get_userdata((int) $user_id);
                $user = is_object($loaded) ? $loaded : null;
            }
            if ($user === null && function_exists('wp_get_current_user')) {
                $current = wp_get_current_user();
                $user = (is_object($current) && (int) ($current->ID ?? 0) > 0) ? $current : null;
            }
            self::report('logout', $user);
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * @param  mixed  $username  The submitted username/email of the failed attempt.
     */
    public static function on_login_failed($username): void
    {
        try {
            $label = is_string($username) && $username !== '' ? $username : null;
            self::report('failed', null, $label);
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * @param  mixed  $user_id
     */
    public static function on_user_register($user_id): void
    {
        try {
            $user = null;
            if (is_numeric($user_id) && function_exists('get_userdata')) {
                $loaded = get_userdata((int) $user_id);
                $user = is_object($loaded) ? $loaded : null;
            }
            self::report('registered', $user);
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * @param  mixed  $user  WP_User whose password was reset.
     * @param  mixed  $new_pass  The new plaintext password — NEVER reported.
     */
    public static function on_password_reset($user, $new_pass = null): void
    {
        try {
            self::report('password_reset', is_object($user) ? $user : null);
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * Build the auth event payload and hand it to the client. $user is a WP_User (or null for events
     * with no resolved account, e.g. a failed login). $label_override supplies the display label when
     * there is no user object (the attempted username on a failed login).
     *
     * @param  mixed  $user
     */
    private static function report(string $event_type, $user, ?string $label_override = null): void
    {
        $user_id = null;
        $label = $label_override;
        if (is_object($user)) {
            $id = isset($user->ID) ? (int) $user->ID : 0;
            if ($id > 0) {
                $user_id = (string) $id;
            }
            if ($label === null) {
                $email = isset($user->user_email) ? (string) $user->user_email : '';
                $login = isset($user->user_login) ? (string) $user->user_login : '';
                $label = $email !== '' ? $email : ($login !== '' ? $login : null);
            }
        }

        $payload = array_filter([
            'event_type' => $event_type,
            'auth_user_id' => $user_id,
            'auth_user_label' => $label !== null ? substr($label, 0, 255) : null,
            'ip_address' => self::client_ip(),
            'user_agent' => self::user_agent(),
            'environment' => (string) get_option('lookout_environment', 'production'),
            'occurred_at' => gmdate('c'),
        ], static fn ($v): bool => $v !== null && $v !== '');

        Lookout_Client::send_auth($payload);
    }

    /**
     * Anonymized client IP (last IPv4 octet / IPv6 group zeroed), matching the tracer's IP posture.
     */
    private static function client_ip(): ?string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($ip === '') {
            return null;
        }
        if (strpos($ip, '.') !== false) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (strpos($ip, ':') !== false) {
            return preg_replace('/[0-9a-fA-F]+$/', '0', $ip);
        }

        return null;
    }

    private static function user_agent(): ?string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';

        return $ua !== '' ? substr($ua, 0, 512) : null;
    }
}
