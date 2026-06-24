<?php

/**
 * Remote ingest config (GET /api/config) consumer.
 *
 * WordPress sends traces and RUM beacons non-blocking, so it cannot read a per-request
 * 403 the way a blocking SDK does. Instead it fetches the project's canonical signal
 * config (which signals are enabled and at what sample rate), caches it in a transient,
 * and honors it locally. Fails open to conservative local defaults when the config
 * cannot be fetched, but always honors a cached "disabled".
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Config
{
    private const TRANSIENT = 'lookout_remote_config';

    private const NEGATIVE_TTL = 60;

    private const TTL_MIN = 60;

    private const TTL_MAX = 3600;

    /**
     * Conservative local defaults used until/unless the server says otherwise.
     * Errors and front-end JS errors ride at 100%; high-volume traces and RUM at 10%.
     *
     * @var array<string, array{enabled: bool, sample_rate: float}>
     */
    private const DEFAULTS = [
        'errors' => ['enabled' => true, 'sample_rate' => 1.0],
        'logs' => ['enabled' => true, 'sample_rate' => 1.0],
        'traces' => ['enabled' => true, 'sample_rate' => 0.1],
        'rum' => ['enabled' => true, 'sample_rate' => 0.1],
    ];

    /**
     * Resolved enable + sample rate for a signal: the server's value when known,
     * otherwise the local default. Unknown signals default to enabled at 100%.
     *
     * @return array{enabled: bool, sample_rate: float}
     */
    public static function signal(string $type): array
    {
        $default = self::DEFAULTS[$type] ?? ['enabled' => true, 'sample_rate' => 1.0];
        $remote = self::remote_signals();
        if (! isset($remote[$type]) || ! is_array($remote[$type])) {
            return $default;
        }

        return self::merge_signal($default, $remote[$type]);
    }

    public static function is_enabled(string $type): bool
    {
        return self::signal($type)['enabled'];
    }

    public static function sample_rate(string $type): float
    {
        return self::signal($type)['sample_rate'];
    }

    /**
     * Head sampling decision for a signal: disabled signals never send; otherwise keep
     * with probability equal to the sample rate.
     */
    public static function should_sample(string $type): bool
    {
        $signal = self::signal($type);
        if (! $signal['enabled']) {
            return false;
        }

        return self::roll($signal['sample_rate']);
    }

    /**
     * Keep with probability $rate. Pure so it is trivially correct: 0 never keeps,
     * >=1 always keeps, otherwise a uniform draw in [0,1). Uses core mt_rand (not the
     * pluggable wp_rand) so it is safe to call during early plugin load.
     */
    public static function roll(float $rate): bool
    {
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Merge a server-reported signal entry over a local default, clamping the rate.
     *
     * @param  array{enabled: bool, sample_rate: float}  $default
     * @param  array<string, mixed>  $remote
     * @return array{enabled: bool, sample_rate: float}
     */
    public static function merge_signal(array $default, array $remote): array
    {
        $enabled = array_key_exists('enabled', $remote) ? (bool) $remote['enabled'] : $default['enabled'];
        $rate = $default['sample_rate'];
        if (array_key_exists('sample_rate', $remote) && is_numeric($remote['sample_rate'])) {
            $rate = max(0.0, min(1.0, (float) $remote['sample_rate']));
        }

        return ['enabled' => $enabled, 'sample_rate' => $rate];
    }

    /**
     * Cached per-signal config map from the server, or [] when unavailable.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function remote_signals(): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return isset($cached['signals']) && is_array($cached['signals']) ? $cached['signals'] : [];
        }
        if ($cached === 'unavailable') {
            return [];
        }

        $config = self::fetch();
        if ($config === null) {
            set_transient(self::TRANSIENT, 'unavailable', self::NEGATIVE_TTL);

            return [];
        }

        $ttl = isset($config['ttl']) && is_numeric($config['ttl'])
            ? max(self::TTL_MIN, min(self::TTL_MAX, (int) $config['ttl']))
            : 300;
        set_transient(self::TRANSIENT, $config, $ttl);

        return isset($config['signals']) && is_array($config['signals']) ? $config['signals'] : [];
    }

    /**
     * Fetch GET /api/config. Returns the decoded body, or null on any failure.
     *
     * @return array<string, mixed>|null
     */
    private static function fetch(): ?array
    {
        $api_key = (string) get_option('lookout_api_key', '');
        $base = rtrim((string) get_option('lookout_base_url', ''), '/');
        if ($api_key === '' || $base === '') {
            return null;
        }

        $url = $base.'/api/config';
        $args = [
            'timeout' => 2,
            'blocking' => true,
            'headers' => [
                'Accept' => 'application/json',
                'X-Api-Key' => $api_key,
            ],
        ];

        /** Same request-args filter as sends, so SSL/proxy customizations apply to config too. */
        $args = apply_filters('lookout_remote_post_args', $args, [], $url);

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }
}
