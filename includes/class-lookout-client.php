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

    /**
     * Fire-and-forget POST of a trace payload to /api/ingest/trace. Non-blocking so it adds
     * near-zero latency to the page; the X-Lookout-Client-Sampled header tells the server the
     * SDK already head-sampled so it does not sample again.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send_trace(array $payload): void
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        if ($api_key === '' || $base === '' || self::should_skip_for_same_host($base)) {
            return;
        }

        $url = $base.'/api/ingest/trace';
        $args = [
            'timeout' => 0.01,
            'blocking' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $api_key,
                'X-Lookout-Client-Sampled' => '1',
            ],
            'body' => wp_json_encode($payload),
        ];

        /** This filter is documented on lookout_remote_post_args. */
        $args = apply_filters('lookout_remote_post_args', $args, $payload, $url);

        wp_remote_post($url, $args);
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
