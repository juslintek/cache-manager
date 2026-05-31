<?php declare(strict_types=1);
namespace VLT\CacheManager\Scale;

/**
 * Horizontal scaling readiness checker.
 * Verifies WordPress is stateless and ready for multi-server deployment.
 */
final class StatelessChecker
{
    public static function check(): array
    {
        $checks = [];

        // 1. Sessions in Redis?
        $checks['sessions'] = [
            'label'  => 'Sessions stored externally',
            'pass'   => ini_get('session.save_handler') === 'redis' || defined('WP_REDIS_HOST'),
            'detail' => ini_get('session.save_handler') === 'redis' ? 'Redis sessions' : (defined('WP_REDIS_HOST') ? 'Redis available (configure session handler)' : 'File-based sessions (not scalable)'),
        ];

        // 2. Object cache external?
        $checks['object_cache'] = [
            'label'  => 'Object cache is external',
            'pass'   => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
            'detail' => file_exists(WP_CONTENT_DIR . '/object-cache.php') ? 'Drop-in installed' : 'No persistent object cache',
        ];

        // 3. Uploads on shared storage?
        $checks['uploads'] = [
            'label'  => 'Uploads on shared/cloud storage',
            'pass'   => defined('GRATIS_S3_BUCKET') || defined('AS3CF_SETTINGS'),
            'detail' => defined('GRATIS_S3_BUCKET') ? 'S3 configured' : 'Local filesystem (configure S3 for multi-server)',
        ];

        // 4. No file-based page cache?
        $checks['page_cache'] = [
            'label'  => 'Page cache is shareable',
            'pass'   => defined('WP_REDIS_HOST'), // Redis-based cache is shared
            'detail' => defined('WP_REDIS_HOST') ? 'Redis available for shared cache' : 'File-based cache (per-server only)',
        ];

        // 5. Health check endpoint
        $checks['health_endpoint'] = [
            'label'  => 'Health check endpoint available',
            'pass'   => true, // We provide it
            'detail' => rest_url('gratis-cache/v1/health'),
        ];

        // 6. Cron externalized?
        $checks['cron'] = [
            'label'  => 'WP-Cron disabled (use system cron)',
            'pass'   => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'detail' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'System cron recommended' : 'WP-Cron active (OK for single server)',
        ];

        $passed = count(array_filter($checks, fn($c) => $c['pass']));
        return ['checks' => $checks, 'score' => $passed, 'total' => count($checks), 'ready' => $passed >= 4];
    }
}
