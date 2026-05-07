<?php

declare(strict_types=1);

namespace VLT\CacheManager\Async;

use VLT\CacheManager\Redis\RedisFactory;

/**
 * Redis-backed async job queue for WordPress.
 *
 * Jobs are pushed to a Redis list and processed by a background worker
 * triggered via a non-blocking loopback HTTP request (no pcntl needed).
 *
 * PHP 8.1+ Fibers are used for concurrent I/O within a single request.
 * Falls back to sequential execution on older PHP.
 */
final class AsyncQueue
{
    private const QUEUE_KEY   = 'vlt_async_queue';
    private const RUNNING_KEY = 'vlt_async_running';
    private const RESULT_KEY  = 'vlt_async_result:';
    private const MAX_WORKERS = 3;

    // ── Push jobs ─────────────────────────────────────────────────────────────

    /**
     * Push a job to the async queue.
     * Job will be executed in the background — caller does not wait.
     *
     * @param string $hook  WP action hook name to fire
     * @param array  $args  Arguments passed to the hook
     * @param int    $delay Seconds to delay (0 = immediate)
     */
    public static function push(string $hook, array $args = [], int $delay = 0): string
    {
        $jobId = uniqid('job_', true);
        $job   = [
            'id'        => $jobId,
            'hook'      => $hook,
            'args'      => $args,
            'run_at'    => time() + $delay,
            'queued_at' => time(),
            'status'    => 'queued',
        ];

        $r = RedisFactory::create();
        if (!$r) {
            // Fallback: run synchronously
            do_action($hook, ...$args);
            return $jobId;
        }

        $r->rPush(self::QUEUE_KEY, json_encode($job));
        $r->close();

        // Trigger background worker (non-blocking loopback)
        self::triggerWorker();

        return $jobId;
    }

    /**
     * Push multiple jobs and run them concurrently via Fibers (PHP 8.1+).
     * Returns when ALL jobs complete. Use for parallel I/O that the request needs.
     *
     * @param array[] $jobs  [['hook' => '...', 'args' => [...]], ...]
     * @return array  Results keyed by hook name
     */
    public static function parallel(array $jobs): array
    {
        if (PHP_VERSION_ID >= 80100 && class_exists('Fiber')) {
            return self::runWithFibers($jobs);
        }
        // Fallback: sequential
        $results = [];
        foreach ($jobs as $job) {
            ob_start();
            do_action($job['hook'], ...($job['args'] ?? []));
            $results[$job['hook']] = ob_get_clean();
        }
        return $results;
    }

    // ── Worker ────────────────────────────────────────────────────────────────

    /**
     * Process jobs from the queue. Called by the background worker endpoint.
     * Runs up to MAX_WORKERS jobs concurrently via Fibers.
     */
    public static function processQueue(): void
    {
        $r = RedisFactory::create();
        if (!$r) {
            return;
        }

        // Prevent concurrent workers
        if (!$r->set(self::RUNNING_KEY, 1, ['NX', 'EX' => 60])) {
            $r->close();
            return;
        }

        $batch = [];
        $now   = time();

        // Pull up to MAX_WORKERS ready jobs
        while (count($batch) < self::MAX_WORKERS) {
            $raw = $r->lPop(self::QUEUE_KEY);
            if (!$raw) {
                break;
            }
            $job = json_decode($raw, true);
            if (!$job) {
                continue;
            }
            if (($job['run_at'] ?? 0) > $now) {
                // Not ready yet — put back
                $r->lPush(self::QUEUE_KEY, $raw);
                break;
            }
            $batch[] = $job;
        }

        $r->del(self::RUNNING_KEY);
        $r->close();

        if (empty($batch)) {
            return;
        }

        // Execute batch concurrently via Fibers
        if (PHP_VERSION_ID >= 80100 && class_exists('Fiber') && count($batch) > 1) {
            self::runJobsWithFibers($batch);
        } else {
            foreach ($batch as $job) {
                self::runJob($job);
            }
        }
    }

    // ── Fiber execution ───────────────────────────────────────────────────────

    private static function runWithFibers(array $jobs): array
    {
        $fibers  = [];
        $results = [];

        foreach ($jobs as $job) {
            $fiber = new \Fiber(function () use ($job): mixed {
                ob_start();
                do_action($job['hook'], ...($job['args'] ?? []));
                return ob_get_clean();
            });
            $fiber->start();
            $fibers[$job['hook']] = $fiber;
        }

        // Drain all fibers
        $pending = $fibers;
        while (!empty($pending)) {
            foreach ($pending as $hook => $fiber) {
                if ($fiber->isTerminated()) {
                    $results[$hook] = $fiber->getReturn();
                    unset($pending[$hook]);
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }

        return $results;
    }

    private static function runJobsWithFibers(array $jobs): void
    {
        $fibers = [];
        foreach ($jobs as $job) {
            $fiber = new \Fiber(function () use ($job): void {
                self::runJob($job);
            });
            $fiber->start();
            $fibers[] = $fiber;
        }
        // Drain
        $pending = $fibers;
        while (!empty($pending)) {
            foreach ($pending as $k => $fiber) {
                if ($fiber->isTerminated()) {
                    unset($pending[$k]);
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }
    }

    private static function runJob(array $job): void
    {
        $r = RedisFactory::create();
        try {
            do_action($job['hook'], ...($job['args'] ?? []));
            if ($r) {
                $r->setEx(self::RESULT_KEY . $job['id'], 3600, json_encode(['status' => 'done', 'ts' => time()]));
            }
        } catch (\Throwable $e) {
            if ($r) {
                $r->setEx(self::RESULT_KEY . $job['id'], 3600, json_encode(['status' => 'error', 'error' => $e->getMessage()]));
            }
        } finally {
            $r?->close();
        }
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public static function status(): array
    {
        $r = RedisFactory::create();
        if (!$r) {
            return ['queue_length' => 0, 'running' => false, 'redis' => false];
        }
        $len     = (int) $r->lLen(self::QUEUE_KEY);
        $running = (bool) $r->exists(self::RUNNING_KEY);
        $r->close();
        return ['queue_length' => $len, 'running' => $running, 'redis' => true];
    }

    public static function jobStatus(string $jobId): array
    {
        $r = RedisFactory::create();
        if (!$r) {
            return ['status' => 'unknown'];
        }
        $raw = $r->get(self::RESULT_KEY . $jobId);
        $r->close();
        return $raw ? json_decode($raw, true) : ['status' => 'queued'];
    }

    // ── WP Cron offload ───────────────────────────────────────────────────────

    /**
     * Replace WP-Cron with Redis queue.
     * Hook into 'schedule_event' to push cron jobs to Redis instead.
     */
    public static function offloadCron(): void
    {
        if (!get_option('vlt_async_offload_cron')) {
            return;
        }
        // Disable WP's built-in cron HTTP trigger
        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }
        // Intercept wp_schedule_single_event
        add_filter('pre_schedule_event', function ($pre, $event) {
            if ($pre !== null) {
                return $pre;
            }
            $delay = max(0, $event->timestamp - time());
            self::push($event->hook, (array) ($event->args ?? []), $delay);
            return true; // Prevent WP from scheduling normally
        }, 10, 2);
    }

    // ── Background worker trigger ─────────────────────────────────────────────

    private static function triggerWorker(): void
    {
        // Non-blocking loopback HTTP — WordPress's own async pattern
        $url  = add_query_arg('vlt_async_worker', '1', admin_url('admin-ajax.php'));
        $args = [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'cookies'   => [],
            'headers'   => ['X-VLT-Async' => '1'],
        ];
        wp_remote_post($url, $args);
    }
}
