<?php declare(strict_types=1);
namespace VLT\CacheManager\Scale;

final class HealthEndpoint
{
    public static function register(): void
    {
        register_rest_route('gratis-cache/v1', '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'check'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function check(): \WP_REST_Response
    {
        global $wpdb;
        $dbOk = (bool) $wpdb->get_var("SELECT 1");
        $status = $dbOk ? 200 : 503;
        return new \WP_REST_Response([
            'status'    => $dbOk ? 'healthy' : 'unhealthy',
            'db'        => $dbOk,
            'timestamp' => gmdate('c'),
            'php'       => PHP_VERSION,
            'memory'    => round(memory_get_usage(true) / 1048576, 1) . 'MB',
        ], $status);
    }
}
