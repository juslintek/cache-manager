<?php

declare(strict_types=1);

namespace VLT\CacheManager\Async;

use VLT\CacheManager\Redis\RedisFactory;

/**
 * Tracks WP-Cron job execution in real-time via Redis.
 * Wraps every scheduled hook with start/end/error recording.
 */
final class CronMonitor
{
    private const LOG_KEY  = 'vlt_cron_log';   // Redis list — recent executions
    private const STAT_KEY = 'vlt_cron_stat:'; // Redis hash per hook — counts/timing
    private const MAX_LOG  = 200;

    public static function register(): void
    {
        // Wrap every cron hook at the earliest possible point
        add_action('init', [self::class, 'wrapCronHooks'], PHP_INT_MAX);
    }

    public static function wrapCronHooks(): void
    {
        $crons = _get_cron_array() ?: [];
        foreach ($crons as $hooks) {
            foreach (array_keys($hooks) as $hook) {
                // Add a high-priority before/after wrapper
                add_action($hook, [self::class, 'beforeHook'], -9999);
                add_action($hook, [self::class, 'afterHook'],  PHP_INT_MAX);
            }
        }
    }

    public static function beforeHook(): void
    {
        $hook = current_filter();
        self::record($hook, 'running', null, null);
    }

    public static function afterHook(): void
    {
        $hook = current_filter();
        self::record($hook, 'done', null, null);
    }

    /**
     * Record a cron event to Redis.
     * Called by beforeHook/afterHook and also by AsyncQueue for queued jobs.
     */
    public static function record(string $hook, string $status, ?float $duration = null, ?string $error = null): void
    {
        $r = RedisFactory::create(0.3);
        if (!$r) {
            return;
        }

        $entry = [
            'hook'     => $hook,
            'status'   => $status, // queued|running|done|error
            'ts'       => microtime(true),
            'duration' => $duration,
            'error'    => $error,
            'memory'   => memory_get_usage(true),
        ];

        // Push to live log list (capped)
        $r->lPush(self::LOG_KEY, json_encode($entry));
        $r->lTrim(self::LOG_KEY, 0, self::MAX_LOG - 1);

        // Update per-hook stats
        $statKey = self::STAT_KEY . $hook;
        $r->hIncrBy($statKey, 'runs', 1);
        if ($status === 'error') {
            $r->hIncrBy($statKey, 'errors', 1);
        }
        if ($duration !== null) {
            $r->hIncrByFloat($statKey, 'total_ms', $duration * 1000);
        }
        $r->expire($statKey, 86400 * 7);

        $r->close();
    }

    /** @return array[] Recent log entries, newest first */
    public static function recentLog(int $limit = 50, string $since = ''): array
    {
        $r = RedisFactory::create(0.3);
        if (!$r) {
            return [];
        }
        $raw = $r->lRange(self::LOG_KEY, 0, $limit - 1);
        $r->close();

        $entries = array_map(fn($j) => json_decode($j, true), $raw);
        if ($since) {
            $entries = array_filter($entries, fn($e) => ($e['ts'] ?? 0) > (float) $since);
        }
        return array_values($entries);
    }

    /** @return array Per-hook stats */
    public static function hookStats(): array
    {
        $r = RedisFactory::create(0.3);
        if (!$r) {
            return [];
        }
        $keys = $r->keys(self::STAT_KEY . '*');
        $stats = [];
        foreach ($keys as $key) {
            $hook = str_replace(self::STAT_KEY, '', $key);
            $data = $r->hGetAll($key);
            $runs = (int) ($data['runs'] ?? 0);
            $stats[$hook] = [
                'hook'     => $hook,
                'runs'     => $runs,
                'errors'   => (int) ($data['errors'] ?? 0),
                'avg_ms'   => $runs > 0 ? round((float) ($data['total_ms'] ?? 0) / $runs, 1) : 0,
            ];
        }
        $r->close();
        usort($stats, fn($a, $b) => $b['runs'] <=> $a['runs']);
        return $stats;
    }
}
