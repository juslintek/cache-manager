<?php
/**
 * Uninstall gratis-cache.
 *
 * Removes all plugin data: options, custom tables, and scheduled events.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Remove plugin options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'gratis_cache_%'));
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'vlt_%'));

// Clear scheduled events.
wp_clear_scheduled_hook('vlt_cm_log_rotate');
wp_clear_scheduled_hook('vlt_trace_rotate');
wp_clear_scheduled_hook('vlt_trace_worker_check');
