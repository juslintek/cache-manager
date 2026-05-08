<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;
use VLT\CacheManager\Redis\RedisFactory;

final class RedisStrategy implements PurgeStrategyInterface
{
    /**
     * Keys matching these prefixes are NEVER purged.
     * They are pipeline/queue/stream keys used for async communication —
     * purging them would break job queues, cron, live streams, and push notifications.
     */
    private const PROTECTED_PREFIXES = [
        'vlt_async_',       // Job queue, worker state, job results
        'vlt_cron_',        // Cron monitor log and stats
        'vlt_trace_',       // Trace worker stream and heartbeat
        'vlt_purge_',       // Purge log and event history
        'vlt_cf_live',      // Cloudflare live SSE stream
        'vlt_logs_live',    // Log live SSE stream
        'vlt_traces',       // Live trace list for Tracer page
        'vlt_server_',      // Server detection cache
    ];

    public function purge(): void
    {
        $r = RedisFactory::create(1.0);
        if (!$r) {
            // Fallback: flush only WP object cache in-memory
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            return;
        }

        // Get all vlt_ keys
        $keys    = $r->keys('vlt_*');
        $deleted = 0;

        foreach ($keys as $key) {
            if (self::isProtected($key)) {
                continue;
            }
            $r->del($key);
            $deleted++;
        }

        $r->close();

        // Flush in-memory WP object cache (does not touch Redis)
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    private static function isProtected(string $key): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function type(): string
    {
        return 'redis';
    }
}
