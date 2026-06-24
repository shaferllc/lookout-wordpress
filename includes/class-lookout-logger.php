<?php

/**
 * Forward non-fatal PHP log output (warnings, notices, deprecations) to Lookout's Logs stream.
 *
 * WordPress has no first-class application log; the practical "logs" for a site are the entries
 * PHP writes to debug.log. This installs an error handler that mirrors that stream into Lookout,
 * buffering entries per request and flushing them once at shutdown as a single batch POST to
 * /api/ingest/log. It is opt-in (lookout_logs_enabled, default off) because on some sites this
 * stream is noisy, and it deliberately ignores fatal-class errors — those are already reported as
 * error events by {@see Lookout_Plugin::handle_shutdown()}, so logs never duplicate issues.
 *
 * The handler always chains to the previous handler and returns false for the PHP default, so
 * existing debug.log writing and any other error handling continue unchanged.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Logger
{
    private const MAX_ENTRIES = 100;

    private const MAX_MESSAGE = 8192;

    private static bool $active = false;

    /** @var list<array{level: string, message: string, logger: string, attributes: array<string, mixed>}> */
    private static array $buffer = [];

    /** @var null|callable(int, string, string, int): bool */
    private static $previous_error_handler = null;

    /**
     * Head-decide whether to collect this request's logs and, if so, install the error handler.
     * Safe to call early at plugin load so warnings emitted during the rest of the request are seen.
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

        if (! get_option('lookout_logs_enabled', false)) {
            return;
        }

        if (! Lookout_Config::is_enabled('logs')) {
            return;
        }

        self::$active = true;

        $prev = set_error_handler([self::class, 'handle_error']);
        if (is_callable($prev)) {
            self::$previous_error_handler = $prev;
        }

        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);
    }

    /**
     * Capture a non-fatal PHP error as a buffered log entry, then defer to the previous/default
     * handler. Returns the previous handler's result, or false so PHP's own logging still runs.
     */
    public static function handle_error(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        // Honor `@` suppression and the site's error_reporting() configuration: don't capture what
        // the site has chosen to silence.
        if ((error_reporting() & $errno) === 0) {
            return self::chain($errno, $message, $file, $line);
        }

        // Fatal-class errors become error events via the shutdown handler; logs cover only the
        // non-fatal stream so the two never duplicate.
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

        // Guarded: an error handler that throws breaks PHP's error handling, so capture failures are
        // swallowed and we always fall through to chaining the previous handler.
        try {
            if (self::$active
                && ! in_array($errno, $fatal, true)
                && count(self::$buffer) < self::MAX_ENTRIES
                && ! Lookout_Client::is_sending()
            ) {
                self::$buffer[] = [
                    'level' => self::level_for($errno),
                    'message' => self::format_message($message, $file, $line),
                    'logger' => 'php',
                    'attributes' => array_filter([
                        'php.errno' => self::label_for($errno),
                        'file' => $file !== '' ? $file : null,
                        'line' => $line > 0 ? $line : null,
                    ], static fn ($v): bool => $v !== null),
                ];
            }
        } catch (Throwable $e) {
            // Swallow: never let log capture disrupt PHP error handling.
        }

        return self::chain($errno, $message, $file, $line);
    }

    /**
     * Flush the buffered entries as one batch. Runs at shutdown, after the page is delivered, so the
     * blocking send is off the visitor's critical path. No-op when nothing was captured.
     */
    public static function flush(): void
    {
        if (! self::$active) {
            return;
        }
        // Stop buffering before sending: warnings emitted by the send itself must not re-enter.
        self::$active = false;

        if (self::$buffer === []) {
            return;
        }

        $entries = self::$buffer;
        self::$buffer = [];

        try {
            $environment = (string) get_option('lookout_environment', 'production');
            $hostname = self::hostname();

            $payload = ['entries' => []];
            foreach ($entries as $entry) {
                $payload['entries'][] = array_filter([
                    'message' => $entry['message'],
                    'level' => $entry['level'],
                    'logger' => $entry['logger'],
                    'source' => 'php',
                    'environment' => $environment,
                    'hostname' => $hostname,
                    'attributes' => $entry['attributes'] !== [] ? $entry['attributes'] : null,
                ], static fn ($v): bool => $v !== null && $v !== '');
            }

            // Hand the page to the visitor before the blocking send (FPM only); harmless no-op if the
            // tracer already flushed on this request.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            Lookout_Client::send_logs($payload);
        } catch (Throwable $e) {
            error_log('Lookout: log flush failed: '.$e->getMessage());
        }
    }

    /**
     * Invoke the previously-registered error handler if there was one, otherwise return false so the
     * built-in PHP handler runs (preserving debug.log writes and normal error display).
     */
    private static function chain(int $errno, string $message, string $file, int $line): bool
    {
        if (self::$previous_error_handler !== null) {
            return (bool) call_user_func(self::$previous_error_handler, $errno, $message, $file, $line);
        }

        return false;
    }

    /**
     * Map a PHP error severity onto a Lookout log level (trace|debug|info|warn|error|fatal).
     */
    private static function level_for(int $errno): string
    {
        return match ($errno) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING,
            E_DEPRECATED, E_USER_DEPRECATED => 'warn',
            default => 'info',
        };
    }

    /**
     * Human-readable PHP error-type label, attached as an attribute for filtering.
     */
    private static function label_for(int $errno): string
    {
        return match ($errno) {
            E_WARNING => 'E_WARNING',
            E_NOTICE => 'E_NOTICE',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            default => 'E_'.$errno,
        };
    }

    /**
     * Render the entry message in PHP's familiar "message in file on line N" shape, capped in size.
     */
    private static function format_message(string $message, string $file, int $line): string
    {
        $message = trim($message);
        if ($file !== '' && $line > 0) {
            $message .= ' in '.$file.' on line '.$line;
        }

        return substr($message, 0, self::MAX_MESSAGE);
    }

    private static function hostname(): ?string
    {
        $host = function_exists('gethostname') ? gethostname() : false;

        return is_string($host) && $host !== '' ? substr($host, 0, 255) : null;
    }
}
