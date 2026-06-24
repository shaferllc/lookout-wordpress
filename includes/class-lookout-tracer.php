<?php

/**
 * Server-side performance tracing for a single WordPress request.
 *
 * Head-samples at boot (Lookout_Config::should_sample('traces')); only sampled requests pay
 * any overhead. Captures a root request span, lifecycle phase spans, DB query timing, and
 * outbound HTTP egress, then flushes the whole trace once at shutdown via a non-blocking POST
 * to /api/ingest/trace. SQL literals are stripped before send.
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

    /** @var array<string, list<array<string, mixed>>> Redacted SQL => caller frames (file/line/function), first seen. */
    private static array $query_callers = [];

    /** @var list<float> Stack of outbound-HTTP start times, paired in dispatch order. */
    private static array $http_starts = [];

    /** @var list<array<string, mixed>> Collected child spans (db, http) built during the request. */
    private static array $child_spans = [];

    /** Main template render: basename + start (template_include) + end (wp_footer). */
    private static string $template_name = '';

    private static float $template_start = 0.0;

    private static float $template_end = 0.0;

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

        if (! get_option('lookout_traces_enabled', true)) {
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

        // Profile this sampled request too (Excimer only; no-op otherwise), so the trace carries a
        // linkable CPU profile. Started here, stopped + shipped in flush().
        if (class_exists('Lookout_Profiler')) {
            Lookout_Profiler::start();
        }

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
        // Main template render: starts when WP resolves the template, ends at the footer.
        add_filter('template_include', [self::class, 'on_template'], PHP_INT_MAX);
        add_action('wp_footer', [self::class, 'on_footer'], PHP_INT_MAX);
        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
    }

    /**
     * @param  mixed  $template
     * @return mixed
     */
    public static function on_template($template)
    {
        // This is the filter that picks the template to load: always return it unchanged.
        try {
            if (self::$template_start === 0.0) {
                self::$template_start = microtime(true);
                self::$template_name = is_string($template) ? basename($template) : '';
            }
        } catch (Throwable $e) {
            // Swallow.
        }

        return $template;
    }

    public static function on_footer(): void
    {
        if (self::$template_end === 0.0) {
            self::$template_end = microtime(true);
        }
    }

    /**
     * @param  mixed  $query
     * @return mixed
     */
    public static function on_query($query)
    {
        // This filter runs for every DB query: it must never alter or break the query, so all
        // instrumentation is wrapped and the original $query is always returned untouched.
        try {
            self::$query_count++;

            // Capture the calling code (with file/line) the first time we see each distinct query, so
            // an N+1's offending query can point at where it originates. First-seen only, to bound cost.
            if (defined('SAVEQUERIES') && SAVEQUERIES) {
                $key = self::redact_sql((string) $query);
                if (! isset(self::$query_callers[$key])) {
                    self::$query_callers[$key] = self::capture_caller_frames();
                }
            }
        } catch (Throwable $e) {
            // Swallow: telemetry must never affect a query.
        }

        return $query;
    }

    /**
     * A trimmed backtrace (file/line/function) of the code that issued the current query, skipping
     * the instrumentation and WordPress's hook/wpdb plumbing.
     *
     * @return list<array<string, mixed>>
     */
    private static function capture_caller_frames(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
        $skipFn = ['on_query', 'capture_caller_frames', 'apply_filters', 'apply_filters_ref_array', 'do_action'];
        $skipClass = ['WP_Hook', 'wpdb', 'Lookout_Tracer'];

        $frames = [];
        foreach ($trace as $frame) {
            $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
            $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';
            if (in_array($function, $skipFn, true) || ($class !== '' && in_array($class, $skipClass, true))) {
                continue;
            }
            $type = is_string($frame['type'] ?? null) ? $frame['type'] : '';
            $call = $class !== '' ? $class.$type.$function : $function;

            $row = ['function' => $function, 'call' => $call];
            if (isset($frame['class']) && $class !== '') {
                $row['class'] = $class;
            }
            if (isset($frame['file']) && is_string($frame['file'])) {
                $row['file'] = $frame['file'];
            }
            if (isset($frame['line'])) {
                $row['line'] = (int) $frame['line'];
            }
            // Read the surrounding source here, on the server that has the files, so Lookout can
            // render it without filesystem access to this site.
            if (isset($row['file'], $row['line'])) {
                $row = array_merge($row, self::source_context((string) $row['file'], (int) $row['line']));
            }
            $frames[] = $row;
            if (count($frames) >= 8) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Read a few source lines around $line of $file as pre/context/post, so they can travel with the
     * frame. Bounded to readable, reasonably-sized files; returns [] when unavailable.
     *
     * @return array{pre_context?: list<string>, context_line?: string, post_context?: list<string>}
     */
    public static function source_context(string $file, int $line, int $pad = 5): array
    {
        if ($file === '' || $line < 1 || ! is_readable($file)) {
            return [];
        }
        $size = @filesize($file);
        if ($size === false || $size > 1_048_576) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $total = count($lines);
        if ($line > $total) {
            return [];
        }

        $idx = $line - 1;
        $pre = [];
        for ($i = max(0, $idx - $pad); $i < $idx; $i++) {
            $pre[] = substr((string) $lines[$i], 0, 1024);
        }
        $post = [];
        for ($i = $idx + 1; $i <= min($total - 1, $idx + $pad); $i++) {
            $post[] = substr((string) $lines[$i], 0, 1024);
        }

        return [
            'pre_context' => $pre,
            'context_line' => substr((string) $lines[$idx], 0, 1024),
            'post_context' => $post,
        ];
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
        try {
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
        } catch (Throwable $e) {
            // Swallow: outbound-HTTP instrumentation must never break a request.
        }
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

        // Runs at shutdown; never let trace assembly or sending surface an error to the request.
        try {
            self::flush_unsafe();
        } catch (Throwable $e) {
            error_log('Lookout: trace flush failed: '.$e->getMessage());
        }
    }

    private static function flush_unsafe(): void
    {
        // Stop profiling before building/sending the trace so the SDK's own shutdown work is not
        // profiled; the captured profile is shipped after the page is flushed (below).
        if (class_exists('Lookout_Profiler')) {
            Lookout_Profiler::stop();
        }

        $end = microtime(true);
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $path = self::request_path();
        $transaction = $method.' '.$path;

        $db = self::db_stats();
        $http = self::http_client_stats();

        // The dashboard's request detail reads everything off the root span's data, so roll the
        // request metadata, DB rollup, outbound-HTTP rollup, client, and user onto it. Child spans
        // (phases, DB summary, HTTP egress) remain for the trace waterfall.
        $rootData = self::root_data($method, $db, $http);
        $spans = [self::span('http.server', $transaction, self::$start, $end, $rootData, self::$root_span_id, null)];
        foreach (self::phase_spans() as $phase) {
            $spans[] = $phase;
        }
        $spans[] = self::db_span($end, $db);
        $view = self::view_span($end);
        if ($view !== null) {
            $spans[] = $view;
        }
        foreach (self::$child_spans as $child) {
            $spans[] = $child;
        }

        $environment = (string) get_option('lookout_environment', 'production');

        $payload = [
            'trace_id' => self::$trace_id,
            'transaction' => $transaction,
            'environment' => $environment,
            'spans' => array_slice($spans, 0, self::MAX_SPANS),
        ];

        // Hand the finished page to the visitor before the blocking trace send (FPM only); on
        // other SAPIs the send runs inline at shutdown, after output is already generated.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        Lookout_Client::send_trace($payload);

        // Ship the CPU profile (if one was captured) tagged with this trace, after the page is flushed.
        // With Excimer that's a real speedscope profile; without it, fall back to a synthetic profile
        // built from the DB call sites we already recorded, so the trace still carries a profile.
        if (class_exists('Lookout_Profiler')) {
            if (! Lookout_Profiler::ship(self::$trace_id, $transaction, $environment)) {
                $frames = self::synthetic_profile_frames();
                if ($frames !== []) {
                    $meta = [
                        'profiler' => 'wordpress.db_call_sites',
                        'note' => 'Synthetic profile (no Excimer extension): DB query hotspots by call site, weighted by query execution count.',
                        'db_query_count' => $db['count'],
                    ];
                    Lookout_Profiler::ship_synthetic($frames, $meta, self::$trace_id, $transaction, $environment);
                }
            }
        }
    }

    /**
     * Synthetic profile frames for hosts without Excimer: each distinct query's execution count is
     * attributed to the innermost application call site that issued it (captured in on_query), so the
     * resulting hotspots table surfaces the lines responsible for the most DB work — e.g. an N+1's
     * offending call site. Returns [] when there is nothing to attribute.
     *
     * @return list<array{file: string, line: int, samples: int}>
     */
    private static function synthetic_profile_frames(): array
    {
        global $wpdb;

        if (! (defined('SAVEQUERIES') && SAVEQUERIES) || ! isset($wpdb->queries) || ! is_array($wpdb->queries)) {
            return [];
        }

        // Execution count per normalized (redacted) query.
        $counts = [];
        foreach ($wpdb->queries as $q) {
            if (! is_array($q) || ! isset($q[0])) {
                continue;
            }
            $sql = self::redact_sql((string) $q[0]);
            $counts[$sql] = ($counts[$sql] ?? 0) + 1;
        }

        // Attribute each query's count to the innermost app frame captured for it (file:line).
        $by_location = [];
        foreach ($counts as $sql => $count) {
            $frames = self::$query_callers[$sql] ?? [];
            $frame = $frames[0] ?? null;
            if (! is_array($frame)) {
                continue;
            }
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) ? (int) $frame['line'] : 0;
            if ($file === '' || $line <= 0) {
                continue;
            }
            $key = $file."\n".$line;
            if (! isset($by_location[$key])) {
                $by_location[$key] = ['file' => $file, 'line' => $line, 'samples' => 0];
            }
            $by_location[$key]['samples'] += $count;
        }

        if ($by_location === []) {
            return [];
        }

        $frames = array_values($by_location);
        usort($frames, static fn (array $a, array $b): int => $b['samples'] <=> $a['samples']);

        return array_slice($frames, 0, 100);
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
     * The main template render span (template_include → footer), tagged with the template basename,
     * so the waterfall shows which theme template rendered and how long it took. Null when no
     * front-end template rendered (e.g. REST/admin/AJAX).
     *
     * @return array<string, mixed>|null
     */
    private static function view_span(float $end): ?array
    {
        if (self::$template_start <= 0.0 || self::$template_name === '') {
            return null;
        }
        $finish = self::$template_end > self::$template_start ? self::$template_end : $end;

        return self::span('view.render', self::$template_name, self::$template_start, $finish, [
            'view.template' => self::$template_name,
        ]);
    }

    /**
     * Rich root-span data: request metadata + DB / outbound-HTTP rollups + client + user, using
     * the canonical keys the dashboard's HttpRequestSpanSummary reads.
     *
     * @param  array{count: int, total_ms: ?float, slowest: list<array<string, mixed>>, max_repeat: ?int}  $db
     * @param  array{count: int, time_ms: float}  $http
     * @return array<string, mixed>
     */
    private static function root_data(string $method, array $db, array $http): array
    {
        $data = [
            'http.method' => $method,
            'php.memory_peak_bytes' => memory_get_peak_usage(true),
            'db.query_count' => $db['count'],
        ];

        $status = function_exists('http_response_code') ? http_response_code() : null;
        if (is_int($status) && $status > 0) {
            $data['http.status_code'] = $status;
        }

        $route = self::wp_route();
        if ($route !== null) {
            $data['http.route'] = $route;
        }

        $responseType = self::response_content_type();
        if ($responseType !== null) {
            $data['http.response.content_type'] = $responseType;
        }

        $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($query !== '') {
            $data['http.query_string'] = self::redact_query($query);
        }

        if ($db['total_ms'] !== null) {
            $data['db.total_duration_ms'] = $db['total_ms'];
        }
        if ($db['max_repeat'] !== null) {
            $data['db.repeat_query_max'] = $db['max_repeat'];
        }
        // Pinpoint the N+1: the query that repeated most and the code path that triggered it.
        if ($db['max_repeat'] !== null && $db['max_repeat'] >= 2 && $db['repeat_query'] !== null) {
            $data['db.n_plus_one_query'] = $db['repeat_query'];
            if ($db['repeat_caller'] !== null) {
                $data['db.n_plus_one_caller'] = $db['repeat_caller'];
            }
            if ($db['repeat_frames'] !== []) {
                $data['db.n_plus_one_frames'] = $db['repeat_frames'];
            }
        }

        if ($http['count'] > 0) {
            $data['http.client.count'] = $http['count'];
            $data['http.client.time_ms'] = $http['time_ms'];
        }

        $ip = self::client_ip();
        if ($ip !== null) {
            $data['http.client_ip'] = $ip;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if ($ua !== '') {
            $data['http.user_agent'] = substr($ua, 0, 1024);
        }
        $ct = isset($_SERVER['CONTENT_TYPE']) ? trim((string) $_SERVER['CONTENT_TYPE']) : '';
        if ($ct !== '') {
            $data['http.request.content_type'] = substr($ct, 0, 256);
        }

        foreach (self::user_data() as $key => $value) {
            $data[$key] = $value;
        }

        // The environment snapshot (framework/runtime/server/plugins) so trace-derived issues such
        // as N+1 carry the same detail as error events. Cached, so this is cheap.
        if (class_exists('Lookout_Plugin')) {
            $data['app.context'] = Lookout_Plugin::environment_snapshot();
        }

        return $data;
    }

    /**
     * DB rollup from SAVEQUERIES (count, total time, slowest N redacted, and the max repeat count
     * of any single normalized query, which the server uses for N+1 detection). Falls back to the
     * `query`-filter count when SAVEQUERIES data is unavailable.
     *
     * @return array{count: int, total_ms: ?float, slowest: list<array<string, mixed>>, max_repeat: ?int, repeat_query: ?string, repeat_caller: ?string, repeat_frames: list<array<string, mixed>>}
     */
    private static function db_stats(): array
    {
        global $wpdb;

        $stats = [
            'count' => self::$query_count,
            'total_ms' => null,
            'slowest' => [],
            'max_repeat' => null,
            'repeat_query' => null,
            'repeat_caller' => null,
            'repeat_frames' => [],
        ];

        if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries) && $wpdb->queries !== []) {
            $total = 0.0;
            $rows = [];
            $normalized = [];
            $callers = [];
            foreach ($wpdb->queries as $q) {
                if (! is_array($q) || ! isset($q[0], $q[1])) {
                    continue;
                }
                $ms = (float) $q[1] * 1000.0;
                $total += $ms;
                $sql = self::redact_sql((string) $q[0]);
                $rows[] = ['sql' => $sql, 'duration_ms' => round($ms, 2)];
                $normalized[$sql] = ($normalized[$sql] ?? 0) + 1;
                if (! isset($callers[$sql]) && isset($q[2]) && is_string($q[2]) && $q[2] !== '') {
                    $callers[$sql] = $q[2];
                }
            }
            usort($rows, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);

            $stats['count'] = count($wpdb->queries);
            $stats['total_ms'] = round($total, 2);
            $stats['slowest'] = array_slice($rows, 0, self::SLOW_QUERY_LIMIT);

            if ($normalized !== []) {
                arsort($normalized);
                $topSql = (string) array_key_first($normalized);
                $stats['max_repeat'] = $normalized[$topSql];
                $stats['repeat_query'] = $topSql;
                $stats['repeat_caller'] = self::clean_caller($callers[$topSql] ?? null);
                $stats['repeat_frames'] = self::$query_callers[$topSql] ?? [];
            }
        }

        return $stats;
    }

    /**
     * Tidy WordPress's SAVEQUERIES caller chain (comma-separated function list, outermost-first)
     * into the most specific frames, so the N+1 issue points at the code that triggered it.
     */
    private static function clean_caller(?string $caller): ?string
    {
        if ($caller === null || trim($caller) === '') {
            return null;
        }
        $frames = array_map('trim', explode(',', $caller));
        // The innermost (closest to the query) frames are the most actionable; keep the last few.
        $frames = array_slice($frames, -6);

        return substr(implode(' → ', $frames), 0, 800);
    }

    /**
     * Outbound-HTTP rollup from the collected http.client child spans.
     *
     * @return array{count: int, time_ms: float}
     */
    private static function http_client_stats(): array
    {
        $count = 0;
        $time = 0.0;
        foreach (self::$child_spans as $span) {
            if (($span['op'] ?? '') === 'http.client') {
                $count++;
                $time += (float) ($span['duration_ms'] ?? 0);
            }
        }

        return ['count' => $count, 'time_ms' => round($time, 2)];
    }

    /**
     * Anonymized client IP (last IPv4 octet / IPv6 group zeroed), per the plugin's IP posture —
     * coarse enough to drop precise identity but still city-level geo-locatable.
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

    /**
     * Logged-in WP user for the root span, only once consent is granted (PII).
     *
     * @return array<string, string>
     */
    private static function user_data(): array
    {
        if (! class_exists('Lookout_Consent') || ! Lookout_Consent::granted() || ! function_exists('wp_get_current_user')) {
            return [];
        }
        $user = wp_get_current_user();
        if (! $user || (int) $user->ID === 0) {
            return [];
        }

        $out = ['http.user_id' => (string) $user->ID];
        if (is_string($user->user_email) && $user->user_email !== '') {
            $out['http.user_email'] = $user->user_email;
        }
        if (is_string($user->display_name) && $user->display_name !== '') {
            $out['http.user_name'] = $user->display_name;
        }

        return $out;
    }

    /**
     * A stable WordPress "route" identity for smart grouping — WP has no named routes, so derive a
     * low-cardinality label from the resolved request (template conditionals, REST route, admin
     * screen) instead of the high-cardinality permalink. The dashboard groups the By-endpoint view
     * on this when present. Runs at shutdown, after the main query, so the conditionals are set.
     */
    private static function wp_route(): ?string
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';

            return 'ajax:'.($action !== '' ? $action : 'unknown');
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
            $route = (string) preg_replace('#/\d+#', '/{id}', $route);

            return 'rest:'.($route !== '' ? $route : 'unknown');
        }

        if (function_exists('is_admin') && is_admin()) {
            global $pagenow;
            $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

            return 'admin:'.((string) ($pagenow ?: 'admin')).($page !== '' ? '?page='.$page : '');
        }

        if (! function_exists('is_singular')) {
            return null;
        }

        return match (true) {
            is_front_page() => 'front-page',
            is_home() => 'blog-home',
            is_singular() => 'single:'.((string) (get_post_type() ?: 'post')),
            is_search() => 'search',
            is_category() => 'archive:category',
            is_tag() => 'archive:tag',
            is_tax() => 'archive:taxonomy',
            is_author() => 'archive:author',
            is_date() => 'archive:date',
            is_post_type_archive() => 'archive:'.((string) (get_query_var('post_type') ?: 'post')),
            is_archive() => 'archive',
            is_404() => '404',
            is_page() => 'page',
            default => null,
        };
    }

    /**
     * The response Content-Type (sans charset) from the emitted headers, when available.
     */
    private static function response_content_type(): ?string
    {
        if (! function_exists('headers_list')) {
            return null;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $value = trim(substr($header, strlen('content-type:')));
                $value = trim(explode(';', $value)[0]);

                return $value !== '' ? substr($value, 0, 256) : null;
            }
        }

        return null;
    }

    /**
     * Strip values of known-sensitive query params before the query string is sent.
     */
    private static function redact_query(string $query): string
    {
        parse_str($query, $params);
        $sensitive = ['token', 'key', 'password', 'secret', 'api_key', 'auth', 'access_token'];
        foreach ($params as $name => $value) {
            if (in_array(strtolower((string) $name), $sensitive, true)) {
                $params[$name] = '***';
            }
        }

        return substr(http_build_query($params), 0, 1024);
    }

    /**
     * One summary DB span for the trace waterfall (the request detail reads DB rollup off the root).
     *
     * @param  array{count: int, total_ms: ?float, slowest: list<array<string, mixed>>, max_repeat: ?int}  $db
     * @return array<string, mixed>
     */
    private static function db_span(float $end, array $db): array
    {
        $data = ['db.query_count' => $db['count']];
        if ($db['total_ms'] !== null) {
            $data['db.total_ms'] = $db['total_ms'];
        }
        if ($db['slowest'] !== []) {
            $data['db.slowest'] = $db['slowest'];
        }

        return self::span('db', $db['count'].' queries', self::$start, $end, $data);
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
