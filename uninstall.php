<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('lookout_enabled');
delete_option('lookout_api_key');
delete_option('lookout_base_url');
delete_option('lookout_environment');
delete_option('lookout_report_http_404');
delete_option('lookout_rum_enabled');
delete_option('lookout_consent');
delete_transient('lookout_remote_config');
