<?php

/**
 * Breadcrumb trail.
 *
 * Records a bounded ring buffer of recent events during a request (outbound HTTP calls, redirects,
 * and anything plugins/themes add via lookout_breadcrumb()), and attaches the trail to error and
 * fatal reports so you can see what led up to a failure. Cheap by design: only low-frequency events
 * are auto-captured, so there is no per-query overhead.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Breadcrumbs
{
    private const MAX = 50;

    private const SENSITIVE = ['token', 'key', 'password', 'secret', 'api_key', 'auth', 'access_token'];

    /** @var list<array<string, mixed>> */
    private static array $crumbs = [];

    public static function boot(): void
    {
        add_action('http_api_debug', [self::class, 'on_http'], 9, 5);
        add_filter('wp_redirect', [self::class, 'on_redirect'], 9, 2);
    }

    /**
     * Append a breadcrumb. Oldest is dropped past the cap. Always safe to call.
     *
     * @param  array<string, mixed>  $data
     */
    public static function add(string $type, string $category, string $message, array $data = [], string $level = 'info'): void
    {
        try {
            self::$crumbs[] = array_filter([
                'type' => substr($type, 0, 64),
                'category' => substr($category, 0, 128),
                'message' => substr($message, 0, 2000),
                'level' => substr($level, 0, 32),
                'timestamp' => microtime(true),
                'data' => $data !== [] ? array_slice($data, 0, 16, true) : null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            if (count(self::$crumbs) > self::MAX) {
                array_shift(self::$crumbs);
            }
        } catch (Throwable $e) {
            // Never let breadcrumb capture affect the request.
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$crumbs;
    }

    /**
     * @param  mixed  $response
     * @param  mixed  $context
     * @param  mixed  $transport
     * @param  array<string, mixed>  $args
     */
    public static function on_http($response, $context = '', $transport = '', $args = [], string $url = ''): void
    {
        try {
            $method = is_array($args) && isset($args['method']) ? strtoupper((string) $args['method']) : 'GET';
            $isError = function_exists('is_wp_error') && is_wp_error($response);
            $status = is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : null;

            self::add(
                'http',
                'http.client',
                $method.' '.self::redact_url($url),
                array_filter(['status' => $status, 'error' => $isError ? 'yes' : null], static fn ($v): bool => $v !== null),
                ($isError || ($status !== null && $status >= 400)) ? 'error' : 'info'
            );
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * @param  mixed  $location
     * @param  mixed  $status
     * @return mixed
     */
    public static function on_redirect($location, $status = 302)
    {
        self::add('navigation', 'redirect', 'Redirect '.(int) $status.' → '.self::redact_url((string) $location));

        return $location;
    }

    private static function redact_url(string $url): string
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (! is_array($parts)) {
            return substr($url, 0, 512);
        }
        $base = (isset($parts['scheme']) ? $parts['scheme'].'://' : '').($parts['host'] ?? '').($parts['path'] ?? '');
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $q);
            foreach ($q as $k => $v) {
                if (in_array(strtolower((string) $k), self::SENSITIVE, true)) {
                    $q[$k] = '***';
                }
            }
            $base .= '?'.http_build_query($q);
        }

        return substr($base, 0, 512);
    }
}

if (! function_exists('lookout_breadcrumb')) {
    /**
     * Record a breadcrumb that will be attached to the next error/fatal report. Safe to call anywhere.
     *
     * @param  array<string, mixed>  $data
     */
    function lookout_breadcrumb(string $message, string $category = 'manual', array $data = [], string $level = 'info'): void
    {
        if (class_exists('Lookout_Breadcrumbs')) {
            Lookout_Breadcrumbs::add('default', $category, $message, $data, $level);
        }
    }
}
