<?php

/**
 * Bootstrap: hooks, error capture, settings.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Plugin
{
    private static ?self $instance = null;

    /** @var null|callable(Throwable): void */
    private $previous_exception_handler = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_settings_page']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_lookout_send_test', [$this, 'handle_send_test']);
            add_action('admin_post_lookout_consent', [$this, 'handle_consent']);
            add_action('admin_notices', [$this, 'maybe_render_consent_notice']);
        }

        add_action('wp_loaded', [$this, 'maybe_register_error_handlers'], 999);
    }

    public function maybe_register_error_handlers(): void
    {
        if (function_exists('wp_installing') && wp_installing()) {
            return;
        }

        if (! $this->capture_enabled()) {
            return;
        }

        $prev = set_exception_handler([$this, 'handle_exception']);
        if (is_callable($prev)) {
            $this->previous_exception_handler = $prev;
        }
        register_shutdown_function([$this, 'handle_shutdown']);
        add_action('template_redirect', [$this, 'maybe_report_http_not_found'], 999);
    }

    public function maybe_report_http_not_found(): void
    {
        if (! $this->capture_enabled() || ! $this->report_http_404_enabled() || ! Lookout_Config::is_enabled('errors')) {
            return;
        }

        if (! function_exists('is_404') || ! is_404()) {
            return;
        }

        if (Lookout_Client::is_sending()) {
            return;
        }

        try {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
            $path = wp_parse_url($uri, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                $path = '/';
            }
            $url = $this->current_request_url();

            Lookout_Client::send([
                'message' => 'HTTP 404 Not Found: '.$method.' '.$path,
                'exception_class' => 'LookoutWordPressHttpNotFound',
                'level' => 'warning',
                'handled' => true,
                'language' => 'php',
                'route' => $path,
                'url' => $url,
                'environment' => (string) get_option('lookout_environment', 'production'),
                'context' => array_merge($this->base_context(), [
                    'http' => [
                        'method' => $method,
                        'status_code' => 404,
                        'url' => $url,
                        'path' => $path,
                    ],
                ]),
            ]);
        } catch (Throwable $e) {
            error_log('Lookout: failed to report 404: '.$e->getMessage());
        }
    }

    public function report_http_404_enabled(): bool
    {
        return (bool) get_option('lookout_report_http_404', true);
    }

    public function capture_enabled(): bool
    {
        if (! get_option('lookout_enabled', false)) {
            return false;
        }

        $api_key = (string) get_option('lookout_api_key', '');
        $base = (string) get_option('lookout_base_url', '');

        return $api_key !== '' && $base !== '';
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Lookout', 'lookout'),
            __('Lookout', 'lookout'),
            'manage_options',
            'lookout',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('lookout', 'lookout_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => false,
        ]);
        register_setting('lookout', 'lookout_api_key', [
            'type' => 'string',
            'sanitize_callback' => function ($v): string {
                return sanitize_text_field(is_string($v) ? $v : '');
            },
            'default' => '',
        ]);
        register_setting('lookout', 'lookout_base_url', [
            'type' => 'string',
            'sanitize_callback' => function ($v): string {
                $v = is_string($v) ? trim($v) : '';
                $v = esc_url_raw($v);

                return rtrim($v, '/');
            },
            'default' => '',
        ]);
        register_setting('lookout', 'lookout_environment', [
            'type' => 'string',
            'sanitize_callback' => function ($v): string {
                $v = sanitize_text_field(is_string($v) ? $v : '');

                return $v !== '' ? $v : 'production';
            },
            'default' => 'production',
        ]);
        register_setting('lookout', 'lookout_report_http_404', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => true,
        ]);
        register_setting('lookout', 'lookout_traces_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => true,
        ]);
        // CPU profiling rides on sampled traces (Excimer only; no-op without it).
        register_setting('lookout', 'lookout_profiling_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => true,
        ]);
        // Off until the site owner opts in: RUM runs in visitors' browsers, so it needs consent.
        register_setting('lookout', 'lookout_rum_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => false,
        ]);
        // Off by default: monitoring every WP-Cron event can be noisy; opt in per site.
        register_setting('lookout', 'lookout_cron_monitoring', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => false,
        ]);
        // Off by default: the PHP warning/notice stream can be noisy on some sites, so logs are opt-in.
        register_setting('lookout', 'lookout_logs_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($v): bool {
                return $v === true || $v === 1 || $v === '1';
            },
            'default' => false,
        ]);
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        require LOOKOUT_PLUGIN_DIR.'includes/settings-page.php';
    }

    public function handle_send_test(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden.', 'lookout'), '', ['response' => 403]);
        }
        check_admin_referer('lookout_send_test');

        if (! $this->capture_enabled()) {
            wp_safe_redirect(add_query_arg('lookout_test', '0', wp_get_referer() ?: admin_url('options-general.php?page=lookout')));
            exit;
        }

        Lookout_Client::send([
            'message' => 'Lookout WordPress test event',
            'exception_class' => 'LookoutWordPressTest',
            'level' => 'info',
            'language' => 'php',
            'environment' => (string) get_option('lookout_environment', 'production'),
            'url' => home_url('/'),
            'context' => [
                'wordpress' => true,
                'test' => true,
            ],
        ]);

        wp_safe_redirect(add_query_arg('lookout_test', '1', admin_url('options-general.php?page=lookout')));
        exit;
    }

    public function maybe_render_consent_notice(): void
    {
        if (! Lookout_Consent::needs_prompt()) {
            return;
        }

        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field('lookout_consent', '_wpnonce', true, false);
        $message = esc_html__('Lookout can also measure page-load performance in your visitors\' browsers (Web Vitals + front-end JS errors) and attach the signed-in user to error reports. This collects performance and personal data, so it stays off until you accept. Error and fatal reporting work either way.', 'lookout');
        $accept = esc_html__('Enable browser monitoring', 'lookout');
        $decline = esc_html__('Errors only', 'lookout');

        echo '<div class="notice notice-info"><p>'.$message.'</p><p>';
        echo '<form action="'.$action.'" method="post" style="display:inline-block;margin-right:.5rem;">'.$nonce;
        echo '<input type="hidden" name="action" value="lookout_consent" /><input type="hidden" name="lookout_decision" value="accept" />';
        echo '<button type="submit" class="button button-primary">'.$accept.'</button></form>';
        echo '<form action="'.$action.'" method="post" style="display:inline-block;">'.$nonce;
        echo '<input type="hidden" name="action" value="lookout_consent" /><input type="hidden" name="lookout_decision" value="decline" />';
        echo '<button type="submit" class="button">'.$decline.'</button></form>';
        echo '</p></div>';
    }

    public function handle_consent(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden.', 'lookout'), '', ['response' => 403]);
        }
        check_admin_referer('lookout_consent');

        $decision = isset($_POST['lookout_decision']) ? sanitize_text_field(wp_unslash((string) $_POST['lookout_decision'])) : '';
        $user_id = get_current_user_id();
        if ($decision === 'accept') {
            Lookout_Consent::grant($user_id);
        } else {
            Lookout_Consent::decline($user_id);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=lookout'));
        exit;
    }

    /**
     * The signed-in user attached to error reports — only once consent is granted (it is PII).
     * IP is never included, per the plugin's redaction posture.
     *
     * @return array<string, string>|null
     */
    private function current_user_payload(): ?array
    {
        if (! Lookout_Consent::granted() || ! function_exists('wp_get_current_user')) {
            return null;
        }
        $user = wp_get_current_user();
        if (! $user || (int) $user->ID === 0) {
            return null;
        }

        $payload = array_filter([
            'id' => (string) $user->ID,
            'email' => (string) $user->user_email,
            'username' => (string) $user->user_login,
            'name' => (string) $user->display_name,
        ], static fn (string $v): bool => $v !== '');

        return $payload === [] ? null : $payload;
    }

    public function handle_exception(Throwable $e): void
    {
        // Never let reporting throw from inside the exception handler — that would be an unrecoverable
        // fatal. Swallow any failure and still chain/re-throw the original exception below.
        try {
            if ($this->capture_enabled() && Lookout_Config::is_enabled('errors') && ! Lookout_Client::is_sending()) {
                Lookout_Client::send($this->payload_from_throwable($e));
            }
        } catch (Throwable $reportingError) {
            error_log('Lookout: failed to report exception: '.$reportingError->getMessage());
        }

        if ($this->previous_exception_handler !== null) {
            ($this->previous_exception_handler)($e);

            return;
        }

        throw $e;
    }

    public function handle_shutdown(): void
    {
        if (! $this->capture_enabled() || ! Lookout_Config::is_enabled('errors') || Lookout_Client::is_sending()) {
            return;
        }

        $last = error_get_last();
        if ($last === null) {
            return;
        }

        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (! in_array($last['type'], $fatal_types, true)) {
            return;
        }

        try {
            $payload = [
                'message' => $last['message'],
                'exception_class' => 'PHPFatal_'.$last['type'],
                'level' => 'error',
                'file' => $last['file'],
                'line' => $last['line'],
                'stack_trace' => '',
                'language' => 'php',
                'environment' => (string) get_option('lookout_environment', 'production'),
                'url' => $this->current_request_url(),
                'context' => $this->base_context(),
            ];

            $user = $this->current_user_payload();
            if ($user !== null) {
                $payload['user'] = $user;
            }

            if (class_exists('Lookout_Breadcrumbs')) {
                $crumbs = Lookout_Breadcrumbs::all();
                if ($crumbs !== []) {
                    $payload['breadcrumbs'] = $crumbs;
                }
            }

            Lookout_Client::send($payload);
        } catch (Throwable $e) {
            error_log('Lookout: failed to report fatal: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload_from_throwable(Throwable $e): array
    {
        $payload = [
            'message' => $e->getMessage(),
            'exception_class' => $e::class,
            'level' => 'error',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'stack_frames' => self::trace_to_frames($e->getTrace()),
            'language' => 'php',
            'environment' => (string) get_option('lookout_environment', 'production'),
            'url' => $this->current_request_url(),
            'context' => array_merge($this->base_context(), [
                'exception_code' => $e->getCode(),
            ]),
        ];

        $user = $this->current_user_payload();
        if ($user !== null) {
            $payload['user'] = $user;
        }

        if (class_exists('Lookout_Breadcrumbs')) {
            $crumbs = Lookout_Breadcrumbs::all();
            if ($crumbs !== []) {
                $payload['breadcrumbs'] = $crumbs;
            }
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     * @return list<array<string, mixed>>
     */
    public static function trace_to_frames(array $trace): array
    {
        $frames = [];
        $i = 0;
        foreach ($trace as $frame) {
            if ($i >= 200) {
                break;
            }
            if (! is_array($frame)) {
                continue;
            }
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';

            $call = $class !== '' ? $class.$type.$function.'()' : $function.'()';

            $frames[] = [
                'index' => $i,
                'file' => $file,
                'line' => $line,
                'class' => $class,
                'function' => $function,
                'type' => $type,
                'call' => $call,
            ];
            $i++;
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    private function base_context(): array
    {
        // The heavy, slow-changing environment snapshot is cached; per-request flags are live.
        $ctx = self::environment_context();

        $ctx['is_admin'] = is_admin();
        $ctx['is_ajax'] = wp_doing_ajax();
        $ctx['doing_cron'] = wp_doing_cron();
        if (function_exists('is_ssl')) {
            $ctx['is_ssl'] = is_ssl();
        }
        if (defined('WP_CLI') && WP_CLI) {
            $ctx['wp_cli'] = true;
        }
        if (is_multisite()) {
            $ctx['blog_id'] = get_current_blog_id();
        }

        return $ctx;
    }

    /**
     * Public accessor for the cached environment snapshot, so the tracer can attach it to traces
     * (and trace-derived issues like N+1 inherit the same environment detail as error events).
     *
     * @return array<string, mixed>
     */
    public static function environment_snapshot(): array
    {
        return self::environment_context();
    }

    /**
     * Slow-changing environment snapshot (versions, server, plugins). Cached in a transient and
     * memoized so frequent events (e.g. 404 floods) don't re-read plugin headers every time.
     *
     * @return array<string, mixed>
     */
    private static function environment_context(): array
    {
        static $memo = null;
        if (is_array($memo)) {
            return $memo;
        }
        $cached = get_transient('lookout_env_context');
        if (is_array($cached)) {
            $memo = $cached;

            return $memo;
        }

        $memo = self::build_environment_context();
        set_transient('lookout_env_context', $memo, defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        return $memo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_environment_context(): array
    {
        global $wp_version, $wpdb;

        $wpVersion = is_string($wp_version ?? null) ? $wp_version : '';

        $ctx = [
            'platform' => 'wordpress',
            'framework' => 'WordPress',
            'framework_version' => $wpVersion,
            'wordpress_version' => $wpVersion,
            'php_version' => PHP_VERSION,
            'sdk' => [
                'name' => 'lookout-wordpress',
                'version' => defined('LOOKOUT_VERSION') ? LOOKOUT_VERSION : null,
            ],
            'wordpress' => array_filter([
                'version' => $wpVersion,
                'multisite' => is_multisite(),
                'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'locale' => function_exists('get_locale') ? get_locale() : null,
                'memory_limit' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
                'permalinks' => (string) get_option('permalink_structure') !== '' ? get_option('permalink_structure') : 'plain',
                'site_url' => function_exists('site_url') ? site_url() : null,
            ], static fn ($v): bool => $v !== null),
            'runtime' => array_filter([
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit') ?: null,
                'max_execution_time' => ini_get('max_execution_time') ?: null,
                'os' => PHP_OS,
                'extensions' => array_values(array_intersect(
                    ['opcache', 'redis', 'memcached', 'apcu', 'imagick', 'gd', 'curl', 'mbstring', 'intl', 'mysqli'],
                    get_loaded_extensions()
                )),
            ], static fn ($v): bool => $v !== null),
            'server' => array_filter([
                'software' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : null,
                'protocol' => isset($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : null,
            ], static fn ($v): bool => $v !== null),
            'database' => array_filter([
                'server_version' => isset($wpdb) && method_exists($wpdb, 'db_version') ? $wpdb->db_version() : null,
                'charset' => isset($wpdb->charset) && $wpdb->charset !== '' ? $wpdb->charset : null,
            ], static fn ($v): bool => $v !== null),
        ];

        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            if ($theme->exists()) {
                $ctx['theme'] = $theme->get_stylesheet();
                $version = $theme->get('Version');
                if (is_string($version) && $version !== '') {
                    $ctx['theme_version'] = $version;
                }
                $parent = $theme->parent();
                if ($parent && $parent->exists()) {
                    $ctx['theme_parent'] = $parent->get_stylesheet();
                }
            }
        }

        $plugins = self::active_plugins_inventory();
        if ($plugins !== []) {
            $ctx['plugins'] = $plugins;
        }
        $details = self::active_plugins_detailed();
        if ($details !== []) {
            $ctx['plugin_details'] = $details;
        }

        return $ctx;
    }

    /**
     * Active plugins with their names and versions. Heavier than {@see active_plugins_inventory()}
     * (reads plugin headers), so it only runs behind the cached environment snapshot.
     *
     * @return list<array{name: string, version: string, slug: string}>
     */
    private static function active_plugins_detailed(): array
    {
        if (! defined('ABSPATH') || ! function_exists('get_plugin_data')) {
            $file = ABSPATH.'wp-admin/includes/plugin.php';
            if (defined('ABSPATH') && is_readable($file)) {
                require_once $file;
            }
        }
        if (! function_exists('get_plugin_data') || ! defined('WP_PLUGIN_DIR')) {
            return [];
        }

        $out = [];
        foreach (self::active_plugins_inventory() as $slug) {
            $path = WP_PLUGIN_DIR.'/'.$slug;
            if (! is_readable($path)) {
                continue;
            }
            $data = get_plugin_data($path, false, false);
            $out[] = [
                'slug' => $slug,
                'name' => isset($data['Name']) && is_string($data['Name']) && $data['Name'] !== '' ? $data['Name'] : $slug,
                'version' => isset($data['Version']) && is_string($data['Version']) ? $data['Version'] : '',
            ];
            if (count($out) >= 100) {
                break;
            }
        }

        return $out;
    }

    /**
     * Active plugin slugs (single-site + network-active), capped to keep payloads small.
     * Answers "which plugin caused this?" without loading the heavier wp-admin plugin API.
     *
     * @return list<string>
     */
    private static function active_plugins_inventory(): array
    {
        $active = get_option('active_plugins', []);
        $slugs = is_array($active) ? array_values($active) : [];

        if (is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                $slugs = array_merge($slugs, array_keys($network));
            }
        }

        $slugs = array_values(array_unique(array_filter(
            array_map(static fn ($slug): string => is_string($slug) ? $slug : '', $slugs),
            static fn (string $slug): bool => $slug !== ''
        )));

        return array_slice($slugs, 0, 100);
    }

    private function current_request_url(): string
    {
        if (wp_doing_ajax() && isset($_REQUEST['action']) && is_string($_REQUEST['action'])) {
            return admin_url('admin-ajax.php?action='.rawurlencode($_REQUEST['action']));
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';

        return home_url($uri);
    }
}
