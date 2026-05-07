<?php
/**
 * Plugin Name: Juslintek Cache Manager
 * Plugin URI: https://github.com/juslintek/cache-manager
 * Description: Full-stack cache management: Redis object cache, Nginx FastCGI, OPcache, Elementor CSS, Cloudflare monitoring, request tracer with Excimer profiler.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: juslintek-cache-manager
 */

defined('ABSPATH') || exit;

if (!defined('VLT_CM_NGINX_CACHE')) {
    define('VLT_CM_NGINX_CACHE', '/var/cache/nginx/wordpress');
}

require_once __DIR__ . '/autoload.php';

// Tracer — always on, captures all requests
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

\VLT\CacheManager\Tracer\Tracer::boot();

add_action('plugins_loaded', fn() => \VLT\CacheManager\Tracer\Tracer::begin('plugins_loaded'), -9999);
add_action('plugins_loaded', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('init', fn() => \VLT\CacheManager\Tracer\Tracer::begin('init'), -9999);
add_action('init', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('wp_loaded', fn() => \VLT\CacheManager\Tracer\Tracer::begin('wp_loaded'), -9999);
add_action('wp_loaded', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('parse_request', fn() => \VLT\CacheManager\Tracer\Tracer::begin('parse_request'), -9999);
add_action('parse_request', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('wp', fn() => \VLT\CacheManager\Tracer\Tracer::begin('wp'), -9999);
add_action('wp', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_action('template_redirect', fn() => \VLT\CacheManager\Tracer\Tracer::begin('template_redirect'), -9999);
add_action('wp_head', fn() => \VLT\CacheManager\Tracer\Tracer::begin('wp_head'), -9999);
add_action('wp_head', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
add_filter('the_content', fn($c) => (\VLT\CacheManager\Tracer\Tracer::begin('the_content')) ?: $c, -9999);
add_filter('the_content', function ($c) { \VLT\CacheManager\Tracer\Tracer::end(); return $c; }, PHP_INT_MAX);
add_action('wp_footer', fn() => \VLT\CacheManager\Tracer\Tracer::begin('wp_footer'), -9999);
add_action('wp_footer', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);

if (is_admin()) {
    add_action('admin_init', fn() => \VLT\CacheManager\Tracer\Tracer::begin('admin_init'), -9999);
    add_action('admin_init', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
    add_action('admin_menu', fn() => \VLT\CacheManager\Tracer\Tracer::begin('admin_menu'), -9999);
    add_action('admin_menu', fn() => \VLT\CacheManager\Tracer\Tracer::end(), PHP_INT_MAX);
}

add_filter('template_include', function ($tpl) {
    \VLT\CacheManager\Tracer\Tracer::begin('template:' . basename($tpl));
    return $tpl;
}, PHP_INT_MAX);

add_action('shutdown', [\VLT\CacheManager\Tracer\Tracer::class, 'finish'], 0);

// Boot the plugin
add_action('plugins_loaded', [\VLT\CacheManager\Plugin::class, 'boot']);

// Run server detection on activation (stored in wp_options, no overhead after)
register_activation_hook(__FILE__, function () {
    \VLT\CacheManager\ServerDetector::runAndStore();
});
