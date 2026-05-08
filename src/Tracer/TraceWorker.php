<?php

declare(strict_types=1);

namespace VLT\CacheManager\Tracer;

use VLT\CacheManager\Redis\RedisFactory;

/**
 * Background trace worker.
 * Main request pushes raw trace JSON to Redis stream (non-blocking).
 * This worker runs as a detached background process via WP-Cron or direct spawn,
 * consumes the stream, and writes to disk.
 *
 * Control:
 *   TraceWorker::ensureRunning()  — start if not running (called from cron)
 *   TraceWorker::status()         — check PID file + Redis heartbeat
 *   TraceWorker::stop()           — kill by PID
 */
final class TraceWorker
{
    private const STREAM_KEY   = 'vlt_trace_stream';
    private const PID_KEY      = 'vlt_trace_worker_pid';
    private const HEARTBEAT_KEY = 'vlt_trace_worker_hb';
    private const PID_FILE     = '/tmp/vlt-trace-worker.pid';

    // ── Main request: push trace to Redis stream (non-blocking) ──────────────

    public static function push(array $trace): void
    {
        $r = RedisFactory::create(0.1);
        if (!$r) {
            return;
        }
        // xAdd to Redis stream — O(1), non-blocking
        $r->xAdd(self::STREAM_KEY, '*', ['data' => json_encode($trace)]);
        // Cap stream at 1000 entries
        $r->xTrim(self::STREAM_KEY, 1000);
        $r->close();
    }

    // ── Worker process ────────────────────────────────────────────────────────

    /**
     * Run the worker loop. Called by the spawned background process.
     * Reads from Redis stream, writes traces to disk files.
     */
    public static function run(): void
    {
        // Write PID
        file_put_contents(self::PID_FILE, getmypid());

        $r = RedisFactory::create(5.0);
        if (!$r) {
            exit(1);
        }

        $lastId = '0'; // Start from beginning on first run
        $dir    = TracerConfig::getDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        while (true) {
            // Heartbeat every 10s
            $r->setEx(self::HEARTBEAT_KEY, 30, time());

            // Block-read from stream (5s timeout)
            $entries = $r->xRead([self::STREAM_KEY => $lastId], 50, 5000);

            if ($entries && isset($entries[self::STREAM_KEY])) {
                foreach ($entries[self::STREAM_KEY] as $id => $fields) {
                    $lastId = $id;
                    $trace  = json_decode($fields['data'] ?? '{}', true);
                    if (!$trace) {
                        continue;
                    }
                    // Write to daily file
                    $file = $dir . '/trace-' . gmdate('Y-m-d') . '.json';
                    file_put_contents($file, json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
                    // Acknowledge by trimming
                    $r->xDel(self::STREAM_KEY, [$id]);
                }
            }

            // Exit if no more entries and stream is empty (one-shot mode)
            if (!$entries || empty($entries[self::STREAM_KEY])) {
                // Check if we should keep running (daemon mode)
                if (!$r->exists(self::PID_KEY)) {
                    break; // Stopped externally
                }
            }
        }

        $r->close();
        @unlink(self::PID_FILE);
    }

    // ── Process management ────────────────────────────────────────────────────

    public static function ensureRunning(): void
    {
        if (self::isRunning()) {
            return;
        }
        self::spawn();
    }

    public static function spawn(): void
    {
        $php  = PHP_BINARY ?: '/usr/local/php84/bin/php';
        $self = __FILE__;
        $wp   = defined('ABSPATH') ? ABSPATH : '';

        // Write a runner script to /tmp
        $runner = '/tmp/vlt-trace-runner.php';
        file_put_contents($runner, <<<PHP
<?php
define('ABSPATH', '$wp');
define('WPINC', 'wp-includes');
\$_SERVER['HTTP_HOST'] = parse_url(get_option('siteurl'), PHP_URL_HOST) ?? 'localhost';
require '$wp/wp-load.php';
VLT\CacheManager\Tracer\TraceWorker::run();
PHP);

        // Mark as running in Redis before spawn
        $r = RedisFactory::create(0.5);
        if ($r) {
            $r->setEx(self::PID_KEY, 3600, '1');
            $r->close();
        }

        // Spawn detached background process
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' > /tmp/vlt-trace-worker.log 2>&1 &';
        exec($cmd);
    }

    public static function stop(): void
    {
        $r = RedisFactory::create(0.5);
        if ($r) {
            $r->del(self::PID_KEY); // Signal worker to stop
            $r->close();
        }
        if (file_exists(self::PID_FILE)) {
            $pid = (int) file_get_contents(self::PID_FILE);
            if ($pid > 0) {
                posix_kill($pid, SIGTERM);
            }
            @unlink(self::PID_FILE);
        }
    }

    public static function isRunning(): bool
    {
        // Check Redis heartbeat (updated every 10s, expires in 30s)
        $r = RedisFactory::create(0.3);
        if (!$r) {
            return false;
        }
        $hb = $r->exists(self::HEARTBEAT_KEY);
        $r->close();
        if ($hb) {
            return true;
        }
        // Fallback: check PID file
        if (file_exists(self::PID_FILE)) {
            $pid = (int) file_get_contents(self::PID_FILE);
            return $pid > 0 && file_exists('/proc/' . $pid);
        }
        return false;
    }

    public static function status(): array
    {
        $r  = RedisFactory::create(0.3);
        $hb = 0;
        $queueLen = 0;
        if ($r) {
            $hb = (int) ($r->get(self::HEARTBEAT_KEY) ?: 0);
            $info = $r->xInfo('STREAM', self::STREAM_KEY);
            $queueLen = $info['length'] ?? 0;
            $r->close();
        }
        return [
            'running'    => self::isRunning(),
            'heartbeat'  => $hb ? gmdate('H:i:s', $hb) : null,
            'queue_len'  => $queueLen,
            'pid_file'   => file_exists(self::PID_FILE) ? (int) file_get_contents(self::PID_FILE) : null,
        ];
    }
}
