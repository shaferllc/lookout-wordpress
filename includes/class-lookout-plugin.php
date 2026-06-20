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
        if (! $this->capture_enabled() || ! $this->report_http_404_enabled()) {
            return;
        }

        if (! function_exists('is_404') || ! is_404()) {
            return;
        }

        if (Lookout_Client::is_sending()) {
            return;
        }

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

    public function handle_exception(Throwable $e): void
    {
        if ($this->capture_enabled() && ! Lookout_Client::is_sending()) {
            Lookout_Client::send($this->payload_from_throwable($e));
        }

        if ($this->previous_exception_handler !== null) {
            ($this->previous_exception_handler)($e);

            return;
        }

        throw $e;
    }

    public function handle_shutdown(): void
    {
        if (! $this->capture_enabled() || Lookout_Client::is_sending()) {
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

        Lookout_Client::send([
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload_from_throwable(Throwable $e): array
    {
        return [
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
        global $wp_version;

        $ctx = [
            'wordpress_version' => is_string($wp_version ?? null) ? $wp_version : '',
            'php_version' => PHP_VERSION,
            'is_admin' => is_admin(),
            'is_ajax' => wp_doing_ajax(),
            'doing_cron' => wp_doing_cron(),
        ];

        if (defined('WP_CLI') && WP_CLI) {
            $ctx['wp_cli'] = true;
        }

        if (is_multisite()) {
            $ctx['blog_id'] = get_current_blog_id();
        }

        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            if ($theme->exists()) {
                $ctx['theme'] = $theme->get_stylesheet();
            }
        }

        return $ctx;
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
