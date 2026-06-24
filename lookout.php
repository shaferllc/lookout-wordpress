<?php

/**
 * Plugin Name: Lookout
 * Description: Send errors, fatals, 404s, performance traces, CPU profiles, PHP logs, cron check-ins, and browser RUM to Lookout.
 * Version: 0.4.0
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

define('LOOKOUT_VERSION', '0.4.0');
define('LOOKOUT_PLUGIN_FILE', __FILE__);
define('LOOKOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-consent.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-config.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-client.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-profiler.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-tracer.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-rum.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-cron-monitor.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-logger.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-plugin.php';

Lookout_Plugin::instance()->boot();
Lookout_Tracer::boot();
Lookout_Cron_Monitor::boot();
Lookout_Logger::boot();
add_action('wp_footer', ['Lookout_Rum', 'render'], PHP_INT_MAX);
