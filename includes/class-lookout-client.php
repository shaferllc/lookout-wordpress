<?php
/**
 * HTTP client for Lookout ingest.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Client
{
    private static bool $sending = false;

    public static function is_sending(): bool
    {
        return self::$sending;
    }

    /**
     * POST a JSON payload to /api/ingest.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send(array $payload): void
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        if ($api_key === '' || $base === '') {
            return;
        }

        if (self::$sending) {
            return;
        }

        if (self::should_skip_for_same_host($base)) {
            return;
        }

        self::$sending = true;

        $url = $base.'/api/ingest';
        $args = [
            'timeout' => 2,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ];

        /**
         * Filter wp_remote_post arguments before sending to Lookout.
         *
         * @param  array<string, mixed>  $args
         */
        $args = apply_filters('lookout_remote_post_args', $args, $payload, $url);

        wp_remote_post($url, $args);

        self::$sending = false;
    }

    private static function should_skip_for_same_host(string $base): bool
    {
        $ingest_host = wp_parse_url($base, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (! is_string($ingest_host) || $ingest_host === '' || ! is_string($site_host)) {
            return false;
        }

        return strcasecmp($ingest_host, $site_host) === 0;
    }
}
