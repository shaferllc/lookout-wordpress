<?php
/**
 * Server-side performance tracing for a single WordPress request.
 *
 * Head-samples at boot (Lookout_Config::should_sample('traces')); only sampled requests pay
 * any overhead. Captures a root request span, lifecycle phase spans, DB query timing, and
 * outbound HTTP egress, then flushes the whole trace once at shutdown via a non-blocking POST
 * to /api/ingest/trace. SQL literals are stripped before send.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Tracer
{
    private const MAX_SPANS = 200;

    private const SLOW_QUERY_LIMIT = 5;

    private static bool $active = false;

    private static string $trace_id = '';

    private static string $root_span_id = '';

    private static float $start = 0.0;

    /** @var array<string, float> Lifecycle hook name => timestamp (seconds). */
    private static array $marks = [];

    private static int $query_count = 0;

    /** @var list<float> Stack of outbound-HTTP start times, paired in dispatch order. */
    private static array $http_starts = [];

    /** @var list<array<string, mixed>> Collected child spans (db, http) built during the request. */
    private static array $child_spans = [];

    /**
     * Decide sampling once and, if kept, wire up the per-request collectors. Safe to call early
     * (plugin load): lifecycle hooks fire afterwards. No-op on unsampled requests.
     */
    public static function boot(): void
    {
        if (self::$active) {
            return;
        }

        $api_key = (string) get_option('lookout_api_key', '');
        $base = (string) get_option('lookout_base_url', '');
        if ($api_key === '' || $base === '' || ! get_option('lookout_enabled', false)) {
            return;
        }

        if (! Lookout_Config::should_sample('traces')) {
            return;
        }

        self::$active = true;
        self::$trace_id = bin2hex(random_bytes(16));
        self::$root_span_id = bin2hex(random_bytes(8));
        self::$start = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        // Full DB query timing on sampled requests only. Must be defined before the main query runs.
        if (! defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }

        add_filter('query', [self::class, 'on_query']);
        foreach (['plugins_loaded', 'init', 'wp_loaded', 'template_redirect'] as $hook) {
            add_action($hook, static function () use ($hook): void {
                self::$marks[$hook] = microtime(true);
            }, PHP_INT_MAX);
        }
        add_filter('pre_http_request', [self::class, 'on_http_start'], 10, 3);
        add_action('http_api_debug', [self::class, 'on_http_finish'], 10, 5);
        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
    }

    /**
     * @param  mixed  $query
     * @return mixed
     */
    public static function on_query($query)
    {
        self::$query_count++;

        return $query;
    }

    /**
     * @param  mixed  $preempt
     * @param  array<string, mixed>  $args
     * @return mixed
     */
    public static function on_http_start($preempt, $args = [], string $url = '')
    {
        self::$http_starts[] = microtime(true);

        return $preempt;
    }

    /**
     * @param  mixed  $response
     * @param  mixed  $context
     * @param  mixed  $transport
     * @param  array<string, mixed>  $args
     */
    public static function on_http_finish($response, $context = '', $transport = '', $args = [], string $url = ''): void
    {
        $start = array_pop(self::$http_starts);
        if ($start === null) {
            return;
        }
        $end = microtime(true);
        $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?: 'external');
        $status = is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : null;

        self::$child_spans[] = self::span('http.client', $host, $start, $end, [
            'http.url' => $url,
            'http.status_code' => $status,
        ]);
    }

    /**
     * Build the trace payload and fire it off. Runs at shutdown on sampled requests only.
     */
    public static function flush(): void
    {
        if (! self::$active) {
            return;
        }
        self::$active = false;

        $end = microtime(true);
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $path = self::request_path();
        $transaction = $method.' '.$path;

        $spans = [self::span('http.server', $transaction, self::$start, $end, [], self::$root_span_id, null)];
        foreach (self::phase_spans() as $phase) {
            $spans[] = $phase;
        }
        $spans[] = self::db_span($end);
        foreach (self::$child_spans as $child) {
            $spans[] = $child;
        }

        $payload = [
            'trace_id' => self::$trace_id,
            'transaction' => $transaction,
            'environment' => (string) get_option('lookout_environment', 'production'),
            'spans' => array_slice($spans, 0, self::MAX_SPANS),
        ];

        Lookout_Client::send_trace($payload);
    }

    /**
     * Spans for each completed lifecycle phase (mark N → mark N+1), children of the root.
     *
     * @return list<array<string, mixed>>
     */
    private static function phase_spans(): array
    {
        $points = array_merge(['request' => self::$start], self::$marks);
        $names = array_keys($points);
        $times = array_values($points);

        $spans = [];
        for ($i = 0; $i < count($times) - 1; $i++) {
            $spans[] = self::span('app.phase', $names[$i + 1], $times[$i], $times[$i + 1]);
        }

        return $spans;
    }

    /**
     * One summary DB span. Includes total time and the slowest queries when SAVEQUERIES data is
     * available; otherwise just the query count from the `query` filter.
     *
     * @return array<string, mixed>
     */
    private static function db_span(float $end): array
    {
        global $wpdb;

        $data = ['db.query_count' => self::$query_count];
        $start = self::$start;

        if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries) && $wpdb->queries !== []) {
            $total = 0.0;
            $rows = [];
            foreach ($wpdb->queries as $q) {
                if (! is_array($q) || ! isset($q[0], $q[1])) {
                    continue;
                }
                $ms = (float) $q[1] * 1000.0;
                $total += $ms;
                $rows[] = ['sql' => self::redact_sql((string) $q[0]), 'duration_ms' => round($ms, 2)];
            }
            usort($rows, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);

            $data['db.query_count'] = count($wpdb->queries);
            $data['db.total_ms'] = round($total, 2);
            $data['db.slowest'] = array_slice($rows, 0, self::SLOW_QUERY_LIMIT);
        }

        return self::span('db', self::$query_count.' queries', $start, $end, $data);
    }

    /**
     * Replace SQL string and numeric literals with `?` so query text carries no values/PII.
     */
    public static function redact_sql(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql) ?? $sql;

        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * Build one span row in the /api/ingest/trace wire shape.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function span(string $op, string $description, float $start, float $end, array $data = [], ?string $span_id = null, ?string $parent = '__root__'): array
    {
        $duration_ms = (int) round(max(0.0, $end - $start) * 1000.0);

        return [
            'span_id' => $span_id ?? bin2hex(random_bytes(8)),
            'parent_span_id' => $parent === '__root__' ? self::$root_span_id : $parent,
            'op' => $op,
            'description' => substr($description, 0, 512),
            'start_timestamp' => round($start, 6),
            'end_timestamp' => round($end, 6),
            'duration_ms' => $duration_ms,
            'data' => $data === [] ? null : $data,
        ];
    }

    private static function request_path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
