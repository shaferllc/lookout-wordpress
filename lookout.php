<?php

/**
 * Plugin Name: Lookout
 * Description: Send errors, fatals, 404s, performance traces, CPU profiles, PHP logs, cron check-ins, and browser RUM to Lookout.
 * Version: 0.5.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Lookout
 * License: MIT
 * Text Domain: lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LOOKOUT_VERSION', '0.5.0');
define('LOOKOUT_PLUGIN_FILE', __FILE__);
define('LOOKOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-consent.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-config.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-breadcrumbs.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-client.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-profiler.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-tracer.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-rum.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-cron-monitor.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-logger.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-mail.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-metrics.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-plugin.php';

// Golden rule: monitoring must never break the host site. Each subsystem boots in isolation so a
// failure in one disables only that feature, and the whole bootstrap is guarded so a fatal here can
// never white-screen WordPress.
foreach (
    [
        static fn () => Lookout_Breadcrumbs::boot(),
        static fn () => Lookout_Plugin::instance()->boot(),
        static fn () => Lookout_Tracer::boot(),
        static fn () => Lookout_Cron_Monitor::boot(),
        static fn () => Lookout_Logger::boot(),
        static fn () => Lookout_Mail::boot(),
        static fn () => add_action('wp_footer', ['Lookout_Rum', 'render'], PHP_INT_MAX),
    ] as $lookout_boot
) {
    try {
        $lookout_boot();
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('Lookout: boot failed: '.$e->getMessage());
        }
    }
}
