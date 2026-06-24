<?php

/**
 * WP-Cron monitoring: reports each due scheduled event to Lookout's cron check-in endpoint.
 *
 * WordPress has no single "around each cron callback" hook, so on a cron request we read the due
 * events from the cron array and wrap each due hook with a PHP_INT_MIN (start) and PHP_INT_MAX
 * (finish) action. Start sends in_progress; finish sends ok with the runtime; a fatal that prevents
 * finish is reported as error from the shutdown handler. The hook's schedule (interval) is sent so
 * the server can detect missed runs. Opt-in via the "WP-Cron monitoring" setting.
 *
 * Sites running real system cron (DISABLE_WP_CRON) still hit wp-cron.php, which sets DOING_CRON, so
 * this covers both the loopback spawn and external cron. Alternatively, use the keyless ping URL.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Cron_Monitor
{
    /** @var array<string, array{id: ?string, start: float}> hook => in-flight check-in. */
    private static array $in_flight = [];

    private static bool $registered = false;

    /**
     * Core/maintenance hooks that fire constantly and would just be noise. Override with the
     * {@see 'lookout_should_monitor_cron'} filter (return true to monitor, false to skip any hook).
     *
     * @var list<string>
     */
    private const IGNORED_HOOKS = [
        'wp_version_check',
        'wp_update_plugins',
        'wp_update_themes',
        'wp_scheduled_delete',
        'wp_scheduled_auto_draft_delete',
        'delete_expired_transients',
        'recovery_mode_clean_expired_keys',
        'wp_https_detection',
        'wp_update_user_counts',
        'wp_site_health_scheduled_check',
        'wp_privacy_delete_old_export_files',
    ];

    public static function boot(): void
    {
        if (self::$registered) {
            return;
        }

        $doing_cron = (defined('DOING_CRON') && DOING_CRON)
            || (function_exists('wp_doing_cron') && wp_doing_cron());
        if (! $doing_cron || ! self::enabled()) {
            return;
        }

        self::$registered = true;

        // Wrap due events before WordPress runs them, and catch fatals that skip the finish ping.
        add_action('wp_loaded', [__CLASS__, 'attach_due_event_monitors'], PHP_INT_MIN);
        register_shutdown_function([__CLASS__, 'flush_unfinished']);
    }

    public static function enabled(): bool
    {
        if (! get_option('lookout_enabled', false) || ! get_option('lookout_cron_monitoring', false)) {
            return false;
        }

        return (string) get_option('lookout_api_key', '') !== '' && (string) get_option('lookout_base_url', '') !== '';
    }

    public static function attach_due_event_monitors(): void
    {
        if (! function_exists('_get_cron_array')) {
            return;
        }

        $crons = _get_cron_array();
        if (! is_array($crons)) {
            return;
        }

        $now = time();
        $watched = [];
        foreach ($crons as $timestamp => $hooks) {
            if (! ctype_digit((string) $timestamp) || (int) $timestamp > $now || ! is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                // Wrap each due hook once, even if several timestamps for it are due at the same time.
                if (! is_string($hook) || isset($watched[$hook]) || ! self::should_monitor($hook)) {
                    continue;
                }
                $watched[$hook] = true;
                self::watch_hook($hook, self::schedule_config_for(is_array($events) ? $events : []));
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private static function watch_hook(string $hook, ?array $config): void
    {
        add_action($hook, static function () use ($hook, $config): void {
            self::on_start($hook, $config);
        }, PHP_INT_MIN, 99);

        add_action($hook, static function () use ($hook): void {
            self::on_finish($hook, 'ok');
        }, PHP_INT_MAX, 99);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public static function on_start(string $hook, ?array $config): void
    {
        if (isset(self::$in_flight[$hook])) {
            return; // A burst of same-hook events counts as one logical run.
        }

        $payload = [
            'slug' => self::slug_for($hook),
            'status' => 'in_progress',
            'environment' => (string) get_option('lookout_environment', 'production'),
        ];
        if ($config !== null) {
            $payload['monitor_config'] = $config;
        }

        $id = Lookout_Client::send_cron($payload);
        self::$in_flight[$hook] = ['id' => $id, 'start' => microtime(true)];
    }

    public static function on_finish(string $hook, string $status): void
    {
        if (! isset(self::$in_flight[$hook])) {
            return;
        }

        $entry = self::$in_flight[$hook];
        unset(self::$in_flight[$hook]);

        $payload = [
            'slug' => self::slug_for($hook),
            'status' => $status,
            'environment' => (string) get_option('lookout_environment', 'production'),
            'duration' => round(max(0.0, microtime(true) - $entry['start']), 3),
        ];
        if (is_string($entry['id']) && $entry['id'] !== '') {
            $payload['check_in_id'] = $entry['id'];
        }

        Lookout_Client::send_cron($payload);
    }

    /**
     * Any hook still in flight at shutdown started but never reached its finish ping — almost always
     * a fatal inside the cron callback. Report it as an error so the monitor shows the failure.
     */
    public static function flush_unfinished(): void
    {
        foreach (array_keys(self::$in_flight) as $hook) {
            self::on_finish($hook, 'error');
        }
    }

    private static function should_monitor(string $hook): bool
    {
        $default = ! in_array($hook, self::IGNORED_HOOKS, true);

        return (bool) apply_filters('lookout_should_monitor_cron', $default, $hook);
    }

    /**
     * Map a due hook's recurring interval (seconds) to monitor config (interval minutes) so the
     * server can flag missed runs. Single (one-off) events carry no schedule.
     *
     * @param  array<string, mixed>  $events
     * @return array<string, mixed>|null
     */
    private static function schedule_config_for(array $events): ?array
    {
        foreach ($events as $event) {
            if (is_array($event) && isset($event['interval']) && is_numeric($event['interval']) && (int) $event['interval'] > 0) {
                $minutes = max(1, min(525600, (int) round((int) $event['interval'] / 60)));

                return ['schedule' => ['type' => 'interval', 'value' => $minutes, 'unit' => 'minute']];
            }
        }

        return null;
    }

    /**
     * Turn a hook name into a check-in slug matching the server's pattern
     * ({@code ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$}), capped at 128 characters.
     */
    public static function slug_for(string $hook): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $hook) ?? '';
        $slug = ltrim($slug, '-._');
        if ($slug === '') {
            $slug = 'wp-cron-'.substr(md5($hook), 0, 12);
        }

        return substr($slug, 0, 128);
    }
}
