<?php

/**
 * HTTP client for Lookout ingest.
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

        // Drop errors ignored on the dashboard so they stop re-ingesting (matched on the payload's
        // class + message, exactly what the server stored and keyed off).
        $exception_class = isset($payload['exception_class']) && is_string($payload['exception_class']) ? $payload['exception_class'] : '';
        $message = isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : '';
        if (Lookout_Config::is_error_suppressed($exception_class, $message)) {
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
     * POST a trace payload to /api/ingest/trace. The caller flushes the client connection first
     * (see Lookout_Tracer::flush), so this send happens after the visitor already has the page and
     * its latency is invisible. It is a real blocking request with a short timeout: fire-and-forget
     * non-blocking sends are unreliable to an HTTPS endpoint (the TLS handshake outlives the tiny
     * timeout and the request is dropped). The X-Lookout-Client-Sampled header tells the server the
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
            'timeout' => 3,
            'blocking' => true,
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

    /**
     * POST a CPU/wall profile to /api/ingest/profile. Like {@see self::send_trace()}, the caller has
     * already flushed the page to the visitor, so this short, blocking request is off the critical
     * path. X-Lookout-Client-Sampled tells the server the SDK already head-sampled this profile.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send_profile(array $payload): void
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        if ($api_key === '' || $base === '' || self::should_skip_for_same_host($base)) {
            return;
        }

        $url = $base.'/api/ingest/profile';
        $args = [
            'timeout' => 3,
            'blocking' => true,
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

    /**
     * POST a cron monitor check-in to /api/ingest/cron and return the server's check_in_id
     * (so an in_progress start can be paired with a later ok/error), or null on failure.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send_cron(array $payload): ?string
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        if ($api_key === '' || $base === '' || self::should_skip_for_same_host($base)) {
            return null;
        }

        $url = $base.'/api/ingest/cron';
        $args = [
            'timeout' => 3,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ];

        /** This filter is documented on lookout_remote_post_args. */
        $args = apply_filters('lookout_remote_post_args', $args, $payload, $url);

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($decoded) && isset($decoded['check_in_id']) && is_string($decoded['check_in_id'])) {
            return $decoded['check_in_id'];
        }

        return null;
    }

    /**
     * POST a batch of log entries to /api/ingest/log. Sent once per request at shutdown (after the
     * page is flushed), as a short blocking request. Logs are not head-sampled on the client, so the
     * server applies the project's configured log sample rate — no X-Lookout-Client-Sampled header.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function send_logs(array $payload): void
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');

        if ($api_key === '' || $base === '' || self::should_skip_for_same_host($base)) {
            return;
        }

        $url = $base.'/api/ingest/log';
        $args = [
            'timeout' => 3,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $api_key,
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
