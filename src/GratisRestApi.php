<?php declare(strict_types=1);
namespace VLT\CacheManager;

/** REST API endpoints for Gratis Cache Manager admin dashboard. */
final class GratisRestApi
{
    public static function register(): void
    {
        register_rest_route('gratis-cache/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'status'],
            'permission_callback' => [__CLASS__, 'canManage'],
        ]);

        register_rest_route('gratis-cache/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'stats'],
            'permission_callback' => [__CLASS__, 'canManage'],
        ]);

        register_rest_route('gratis-cache/v1', '/purge', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'purge'],
            'permission_callback' => [__CLASS__, 'canManage'],
        ]);
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        $caps = Diagnostics\CapabilityDetector::detect();
        return new \WP_REST_Response([
            'php'        => PHP_VERSION,
            'wordpress'  => get_bloginfo('version'),
            'theme'      => get_stylesheet(),
            'volatile'   => Diagnostics\CapabilityDetector::bestVolatileBackend(),
            'persistent' => Diagnostics\CapabilityDetector::bestPersistentStore(),
            'serializer' => Diagnostics\CapabilityDetector::bestSerializer(),
            'extensions' => $caps,
            'debug_meta' => apply_filters('gratis_cache_debug_meta', []),
        ]);
    }

    public static function stats(\WP_REST_Request $request): \WP_REST_Response
    {
        $logger = new Log\Logger();
        $stats = $logger->getTodayStats();
        $total = $stats['hits'] + $stats['misses'];

        return new \WP_REST_Response([
            'requests'  => $stats['requests'],
            'hits'      => $stats['hits'],
            'misses'    => $stats['misses'],
            'ratio'     => $total > 0 ? round($stats['hits'] / $total * 100, 1) : 0,
            'purges'    => $stats['purges'],
            'date'      => gmdate('Y-m-d'),
        ]);
    }

    public static function purge(\WP_REST_Request $request): \WP_REST_Response
    {
        do_action('gratis_cache_purge_all', 'rest-api');
        return new \WP_REST_Response(['purged' => true, 'timestamp' => gmdate('c')]);
    }
}
