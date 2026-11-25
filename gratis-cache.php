<?php
/**
 * Plugin Name: Gratis Cache
 * Plugin URI: https://github.com/juslintek/cache-manager
 * Description: Full-stack cache management: Redis object cache, Nginx FastCGI, OPcache, Elementor CSS, Cloudflare monitoring, request tracer with Excimer profiler.
 * Version: 3.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-cache
 */

defined('ABSPATH') || exit;

if (!defined('GRATIS_CACHE_NGINX_CACHE')) {
    define('GRATIS_CACHE_NGINX_CACHE', '/var/cache/nginx/wordpress');
}

require_once __DIR__ . '/autoload.php';

// Tracer — skip on admin/cron/CLI/REST and only boot when sampling or explicitly enabled
(static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tracer bypass check; action handlers verify nonces.
    $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
    $skip   = is_admin()
        || str_starts_with($action, 'vlt_')
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('DOING_CRON') && DOING_CRON)
        || (defined('WP_CLI') && WP_CLI);
    if (!$skip && \Gratis\Cache\Tracer\TracerConfig::shouldTrace()) {
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        \Gratis\Cache\Tracer\Tracer::boot();

add_action('plugins_loaded', fn() => \Gratis\Cache\Tracer\Tracer::begin('plugins_loaded'), -9999);
add_action('plugins_loaded', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('init', fn() => \Gratis\Cache\Tracer\Tracer::begin('init'), -9999);
add_action('init', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('wp_loaded', fn() => \Gratis\Cache\Tracer\Tracer::begin('wp_loaded'), -9999);
add_action('wp_loaded', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('parse_request', fn() => \Gratis\Cache\Tracer\Tracer::begin('parse_request'), -9999);
add_action('parse_request', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('wp', fn() => \Gratis\Cache\Tracer\Tracer::begin('wp'), -9999);
add_action('wp', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('template_redirect', fn() => \Gratis\Cache\Tracer\Tracer::begin('template_redirect'), -9999);
add_action('wp_head', fn() => \Gratis\Cache\Tracer\Tracer::begin('wp_head'), -9999);
add_action('wp_head', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
add_filter('the_content', fn($c) => (\Gratis\Cache\Tracer\Tracer::begin('the_content')) ?: $c, -9999);
add_filter('the_content', function ($c) {
 \Gratis\Cache\Tracer\Tracer::end(); return $c; }, PHP_INT_MAX);
add_action('wp_footer', fn() => \Gratis\Cache\Tracer\Tracer::begin('wp_footer'), -9999);
add_action('wp_footer', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);

if (is_admin()) {
    add_action('admin_init', fn() => \Gratis\Cache\Tracer\Tracer::begin('admin_init'), -9999);
    add_action('admin_init', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
    add_action('admin_menu', fn() => \Gratis\Cache\Tracer\Tracer::begin('admin_menu'), -9999);
    add_action('admin_menu', fn() => \Gratis\Cache\Tracer\Tracer::end(), PHP_INT_MAX);
}

add_filter('template_include', function ($tpl) {
    \Gratis\Cache\Tracer\Tracer::begin('template:' . basename($tpl));
    return $tpl;
}, PHP_INT_MAX);

add_action('shutdown', function () {
    try {
        \Gratis\Cache\Tracer\Tracer::finish();
    } catch (\Throwable $e) {
        // Never let tracer crash affect HTTP response code.
        unset($e);
    }
    }, 0);
    } // end if (!$skip)
})();

// Boot the plugin
add_action('plugins_loaded', [\Gratis\Cache\Plugin::class, 'boot']);

// One-time migration: disable autoload on growing option.
add_action('plugins_loaded', function () {
    if (!get_option('gratis_cache_autoload_fixed')) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => 'gratis_redirects']);
        update_option('gratis_cache_autoload_fixed', 1);
    }
});

// Run server detection on activation (stored in wp_options, no overhead after)
register_activation_hook(__FILE__, function () {
    \Gratis\Cache\ServerDetector::runAndStore();
});
