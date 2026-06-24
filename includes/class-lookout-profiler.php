<?php

/**
 * CPU/wall profiling for a single sampled WordPress request, tied to the trace sampling decision:
 * Lookout_Tracer::boot() starts a profile only on sampled requests, and Lookout_Tracer::flush()
 * stops it (before building the trace, so the SDK's own work is not profiled) and ships it tagged
 * with the same trace_id. The result is that every sampled trace carries a linkable profile.
 *
 * Three tiers, best-available wins:
 *   1. Excimer extension  → a real sampled speedscope CPU/wall profile.
 *   2. Cooperative sampler → no extension: the call stack is sampled on the WP 'all' hook, throttled
 *      to ~once per 10ms, emitting Lookout's lookout.samples.v1 format. Broad coverage of the whole
 *      request; tight non-hook loops may be under-sampled.
 *   3. DB call-site synthetic (Lookout_Tracer::synthetic_profile_frames) → last resort.
 *
 * Hard guarantees: each entry point self-gates and swallows exceptions, so profiling never affects
 * the host request. Excimer (EXCIMER_*, \ExcimerProfiler) is referenced only behind self::available().
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Profiler
{
    /** Excimer sampling period in microseconds (10ms). */
    private const PERIOD_US = 10_000;

    /** Cooperative sampler: minimum wall seconds between samples (~10ms). */
    private const COOP_PERIOD_S = 0.01;

    /** Cooperative sampler: caps that bound payload size well under the ingest limit. */
    private const COOP_MAX_SAMPLES = 400;

    private const COOP_MAX_FRAMES = 12;

    /** '' | 'excimer' | 'cooperative' */
    private static string $mode = '';

    private static bool $running = false;

    /** @var object|null Active \ExcimerProfiler while profiling (excimer mode). */
    private static $profiler = null;

    private static bool $coop_sampling = false;

    private static float $coop_started_at = 0.0;

    private static float $coop_last_sample = 0.0;

    /** @var list<array{t: float, frames: list<array<string, mixed>>}> */
    private static array $coop_samples = [];

    /** @var array{agent: string, format: string, data: array<string, mixed>}|null Captured profile awaiting ship. */
    private static ?array $shipment = null;

    public static function is_running(): bool
    {
        return self::$running;
    }

    public static function available(): bool
    {
        return extension_loaded('excimer');
    }

    /**
     * Begin profiling the current request: Excimer when present, otherwise a cooperative WP-hook
     * sampler. Safe to call unconditionally on a sampled request: it self-gates and never throws.
     */
    public static function start(): void
    {
        if (self::$running) {
            return;
        }
        if (! get_option('lookout_profiling_enabled', true)) {
            return;
        }

        try {
            if (self::available()) {
                $event_type = ((string) get_option('lookout_profiling_event_type', 'wall')) === 'cpu'
                    ? EXCIMER_CPU
                    : EXCIMER_REAL;

                $profiler = new ExcimerProfiler;
                $profiler->setPeriod(self::PERIOD_US / 1_000_000);
                $profiler->setEventType($event_type);
                $profiler->start();

                self::$profiler = $profiler;
                self::$mode = 'excimer';
                self::$running = true;

                return;
            }

            // Escape hatch for the per-hook overhead of cooperative sampling (no UI; advanced opt-out).
            if (! get_option('lookout_profiling_cooperative_enabled', true)) {
                return;
            }

            self::$coop_started_at = microtime(true);
            self::$coop_last_sample = 0.0;
            self::$coop_samples = [];
            self::$coop_sampling = true;
            self::$mode = 'cooperative';
            self::$running = true;
            add_action('all', [self::class, 'coop_tick']);
        } catch (Throwable $e) {
            self::$profiler = null;
            self::$coop_sampling = false;
            self::$mode = '';
            self::$running = false;
        }
    }

    /**
     * Cooperative sampling tick: fired on every WP hook, it records a stack sample at most once per
     * {@see self::COOP_PERIOD_S}. Public so it can be an add_action callback. Never throws.
     */
    public static function coop_tick(): void
    {
        if (! self::$coop_sampling) {
            return;
        }

        try {
            $now = microtime(true);
            if (($now - self::$coop_last_sample) < self::COOP_PERIOD_S) {
                return;
            }
            self::$coop_last_sample = $now;

            if (count(self::$coop_samples) >= self::COOP_MAX_SAMPLES) {
                self::$coop_sampling = false; // Bound payload; stop adding samples.

                return;
            }

            self::$coop_samples[] = ['t' => $now, 'frames' => self::coop_frames()];
        } catch (Throwable $e) {
            self::$coop_sampling = false;
        }
    }

    /**
     * The trimmed application call stack at this instant: WP hook plumbing and the sampler's own
     * frames are skipped, so the resulting hotspots reflect application code, not WP_Hook dispatch.
     *
     * @return list<array<string, mixed>>
     */
    private static function coop_frames(): array
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::COOP_MAX_FRAMES + 16);
        $skip_fn = [
            'coop_tick', 'coop_frames',
            'apply_filters', 'apply_filters_ref_array', 'apply_filters_deprecated',
            'do_action', 'do_action_ref_array', 'do_action_deprecated',
        ];
        $skip_class = ['WP_Hook', 'Lookout_Profiler'];

        $frames = [];
        foreach ($bt as $f) {
            $function = is_string($f['function'] ?? null) ? $f['function'] : '';
            $class = is_string($f['class'] ?? null) ? $f['class'] : '';
            if (in_array($function, $skip_fn, true) || ($class !== '' && in_array($class, $skip_class, true))) {
                continue;
            }

            $frames[] = [
                'file' => isset($f['file']) && is_string($f['file']) ? $f['file'] : '',
                'line' => isset($f['line']) ? (int) $f['line'] : 0,
                'function' => $function,
                'class' => $class !== '' ? $class : null,
                'type' => isset($f['type']) && is_string($f['type']) ? $f['type'] : null,
            ];
            if (count($frames) >= self::COOP_MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Stop sampling and stash the captured profile for {@see self::ship()}. Call before building the
     * trace so the SDK's own shutdown work is not profiled. No-op when idle; never throws.
     */
    public static function stop(): void
    {
        if (! self::$running) {
            return;
        }
        $mode = self::$mode;
        self::$running = false;
        self::$mode = '';

        try {
            if ($mode === 'excimer' && self::$profiler !== null) {
                $profiler = self::$profiler;
                self::$profiler = null;
                $profiler->stop();
                $log = $profiler->getLog();
                if (is_object($log) && method_exists($log, 'getSpeedscopeData')) {
                    $data = $log->getSpeedscopeData();
                    if (is_array($data) && $data !== []) {
                        self::$shipment = ['agent' => 'excimer', 'format' => 'speedscope', 'data' => $data];
                    }
                }

                return;
            }

            if ($mode === 'cooperative') {
                self::$coop_sampling = false;
                if (function_exists('remove_action')) {
                    remove_action('all', [self::class, 'coop_tick']);
                }
                $samples = self::$coop_samples;
                self::$coop_samples = [];
                if ($samples !== []) {
                    self::$shipment = [
                        'agent' => 'php.manual_pulse',
                        'format' => 'lookout.samples.v1',
                        'data' => [
                            'started_at' => self::$coop_started_at,
                            'ended_at' => microtime(true),
                            'sample_count' => count($samples),
                            'samples' => $samples,
                            'meta' => [
                                'profiler' => 'wordpress.cooperative',
                                'note' => 'Cooperative wall-clock sampler (no Excimer): stack sampled on WP hooks ~every 10ms. Tight non-hook loops may be under-sampled.',
                            ],
                        ],
                    ];
                }
            }
        } catch (Throwable $e) {
            self::$shipment = null;
        }
    }

    /**
     * Ship the captured profile (Excimer speedscope or cooperative samples) to /api/ingest/profile,
     * tagged with the trace it belongs to. Call after the page is flushed to the visitor. Returns
     * true when a profile was shipped, false when none was captured so the caller can fall back to a
     * synthetic profile. Never throws.
     */
    public static function ship(string $trace_id, string $transaction, string $environment): bool
    {
        $s = self::$shipment;
        self::$shipment = null;
        if (! is_array($s) || ! isset($s['agent'], $s['format'], $s['data'])) {
            return false;
        }

        try {
            $payload = array_filter([
                'agent' => $s['agent'],
                'format' => $s['format'],
                'data' => $s['data'],
                'trace_id' => $trace_id !== '' ? $trace_id : null,
                'transaction' => $transaction !== '' ? $transaction : null,
                'environment' => $environment !== '' ? $environment : null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            Lookout_Client::send_profile($payload);

            return true;
        } catch (Throwable $e) {
            // Swallow: profiling must never affect the host request.
            return false;
        }
    }

    /**
     * Ship a synthetic aggregate profile (Lookout v1 "hotspots") built from data the request already
     * collected — the last-resort tier when neither Excimer nor the cooperative sampler produced a
     * profile. The dashboard renders these frames as a hotspots table; $meta documents how they were
     * derived. No-op when profiling is disabled or there are no frames; never throws.
     *
     * @param  list<array{file: string, line: int, samples: int}>  $frames
     * @param  array<string, mixed>  $meta
     */
    public static function ship_synthetic(array $frames, array $meta, string $trace_id, string $transaction, string $environment): void
    {
        if (! get_option('lookout_profiling_enabled', true)) {
            return;
        }

        $frames = array_values(array_filter(
            $frames,
            static fn ($f): bool => is_array($f) && isset($f['file'], $f['line'], $f['samples'])
        ));
        if ($frames === []) {
            return;
        }

        try {
            $data = ['schema_version' => 1, 'frames' => $frames];
            if ($meta !== []) {
                $data['meta'] = $meta;
            }

            $payload = array_filter([
                'agent' => 'lookout',
                'format' => 'lookout.v1',
                'data' => $data,
                'trace_id' => $trace_id !== '' ? $trace_id : null,
                'transaction' => $transaction !== '' ? $transaction : null,
                'environment' => $environment !== '' ? $environment : null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            Lookout_Client::send_profile($payload);
        } catch (Throwable $e) {
            // Swallow: profiling must never affect the host request.
        }
    }

    public static function reset_for_testing(): void
    {
        self::$mode = '';
        self::$running = false;
        self::$profiler = null;
        self::$coop_sampling = false;
        self::$coop_started_at = 0.0;
        self::$coop_last_sample = 0.0;
        self::$coop_samples = [];
        self::$shipment = null;
    }
}
