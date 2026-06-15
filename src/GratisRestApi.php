<?php declare(strict_types=1);
namespace Gratis\Cache;

/** REST API endpoints for Gratis Cache Manager admin dashboard. */
final class GratisRestApi {

    public static function register(): void {
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

        // Deploy hook — accepts a shared secret, no WP auth needed.
        register_rest_route('gratis-cache/v1', '/deploy-purge', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'deployPurge'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function canManage(): bool {
        return current_user_can('manage_options');
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response {
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

    public static function stats(\WP_REST_Request $request): \WP_REST_Response {
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

    public static function purge(\WP_REST_Request $request): \WP_REST_Response {
        do_action('gratis_cache_purge_all', 'rest-api');
        return new \WP_REST_Response(['purged' => true, 'timestamp' => gmdate('c')]);
    }

    /**
     * Deploy-hook purge — authenticates via a shared secret in the
     * GRATIS_DEPLOY_SECRET constant or gratis_deploy_secret option.
     * Called by CI after git pull to flush all caches.
     */
    public static function deployPurge(\WP_REST_Request $request): \WP_REST_Response {
        $token = $request->get_header('X-Deploy-Token');
        if (empty($token)) {
            $token = sanitize_text_field($request->get_param('token') ?? '');
        }

        $secret = defined('GRATIS_DEPLOY_SECRET')
            ? GRATIS_DEPLOY_SECRET
            : get_option('gratis_deploy_secret', '');

        if (empty($secret) || !hash_equals($secret, $token)) {
            return new \WP_REST_Response(['error' => 'unauthorized'], 403);
        }

        // Purge everything.
        do_action('gratis_cache_purge_all', 'deploy-hook');

        // Also reset OPcache so new PHP files are picked up.
        if (function_exists('opcache_reset')) {
            opcache_reset(); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.opcache_resetRemoved
        }

        return new \WP_REST_Response([
            'purged'    => true,
            'source'    => 'deploy-hook',
            'timestamp' => gmdate('c'),
        ]);
    }
}
