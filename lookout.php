<?php
/**
 * Plugin Name: Lookout
 * Description: Report PHP errors and uncaught exceptions to Lookout (POST /api/ingest).
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Lookout
 * License: MIT
 * Text Domain: lookout
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LOOKOUT_VERSION', '0.1.0');
define('LOOKOUT_PLUGIN_FILE', __FILE__);
define('LOOKOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-client.php';
require_once LOOKOUT_PLUGIN_DIR.'includes/class-lookout-plugin.php';

Lookout_Plugin::instance()->boot();
