<?php

declare(strict_types=1);

namespace VLT\CacheManager\Log;

use VLT\CacheManager\Redis\RedisFactory;

final class Logger
{
    private string $dir;

    public function __construct()
    {
        $this->dir = get_option('vlt_cm_log_path', WP_CONTENT_DIR . '/uploads/vlt-cache-logs');
    }

    public function log(string $type, mixed $details = ''): void
    {
        if (!get_option('vlt_cm_logging', true)) {
            return;
        }
        $this->initDir();
        $uid  = get_current_user_id();
        $user = $uid ? get_userdata($uid) : null;
        $entry = json_encode([
            'timestamp' => gmdate('c'),
            'type'      => $type,
            'details'   => $details,
            'user_id'   => $uid,
            'user_name' => $user ? $user->display_name : (defined('WP_CLI') ? 'WP-CLI' : 'Sistema'),
            'ip'        => $this->ip(),
            'uri'       => $_SERVER['REQUEST_URI'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $file = $this->dir . '/cache-log-' . gmdate('Y-m-d') . '.json';
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        @chown($file, 'nginx');
        @chgrp($file, 'nginx');
        // Push to Redis for live SSE streaming
        try {
            $r = RedisFactory::create(0.1);
            if ($r) {
                $r->lPush('vlt_logs_live', $entry);
                $r->lTrim('vlt_logs_live', 0, 499);
                $r->expire('vlt_logs_live', 600);
                $r->close();
            }
        } catch (\Throwable $e) {
        }
    }

    public function logCfRequest(): void
    {
        if (!get_option('vlt_cm_cf_tracking', true)) {
            return;
        }
        $ray = $_SERVER['HTTP_CF_RAY'] ?? '';
        if (!$ray) {
            return;
        }
        $uid  = get_current_user_id();
        $user = $uid ? get_userdata($uid) : null;
        $entry = [
            'ts'         => gmdate('c'),
            'ray'        => $ray,
            'country'    => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
            'ip'         => $this->ip(),
            'uri'        => $_SERVER['REQUEST_URI'] ?? '',
            'user_id'    => $uid,
            'user_name'  => $user ? $user->display_name : '',
            'user_email' => $user ? $user->user_email : '',
            'challenged' => isset($_COOKIE['cf_clearance']),
            'ua'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        ];
        try {
            $r = RedisFactory::create(0.2);
            if ($r) {
                $r->lPush('vlt_cf_live', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $r->lTrim('vlt_cf_live', 0, 499);
                $r->expire('vlt_cf_live', 600);
                $r->close();
            }
        } catch (\Throwable $e) {
        }
        $this->log('cloudflare', $entry);
    }

    public function logRequestStats(int $hits, int $misses): void
    {
        if (!get_option('vlt_cm_logging', true) || ($hits === 0 && $misses === 0)) {
            return;
        }
        $this->log('stats', ['hits' => $hits, 'misses' => $misses]);
    }

    public function readLog(string $date): array
    {
        $file = $this->dir . '/cache-log-' . $date . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $lines   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $decode  = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
        $entries = [];
        foreach ($lines as $line) {
            $decoded = $decode($line, true);
            if ($decoded) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    public function getTodayStats(): array
    {
        $entries = $this->readLog(gmdate('Y-m-d'));
        $stats   = ['requests' => 0, 'hits' => 0, 'misses' => 0, 'purges' => 0];
        foreach ($entries as $e) {
            if (($e['type'] ?? '') === 'stats') {
                $stats['requests']++;
                $stats['hits']   += (int) ($e['details']['hits'] ?? 0);
                $stats['misses'] += (int) ($e['details']['misses'] ?? 0);
            } elseif (($e['type'] ?? '') === 'purge') {
                $stats['purges']++;
            }
        }
        return $stats;
    }

    public function rotateLogs(): void
    {
        // Age-based rotation
        $days   = (int) get_option('vlt_cm_log_days', 30);
        $cutoff = gmdate('Y-m-d', strtotime("-{$days} days"));
        foreach (glob($this->dir . '/cache-log-*.json') as $f) {
            if (preg_match('/cache-log-(\d{4}-\d{2}-\d{2})\.json$/', $f, $m) && $m[1] < $cutoff) {
                @unlink($f);
            }
        }
        // Size-based rotation (MB)
        $maxMb = (int) get_option('vlt_cm_log_max_mb', 500);
        if ($maxMb > 0) {
            self::enforceMaxSize($this->dir, 'cache-log-*.json', $maxMb * 1048576);
        }
    }

    public static function enforceMaxSize(string $dir, string $pattern, int $maxBytes): void
    {
        $files = glob($dir . '/' . $pattern) ?: [];
        if (!$files) {
            return;
        }
        sort($files); // oldest first (date in filename)
        $total = array_sum(array_map('filesize', $files));
        while ($total > $maxBytes && count($files) > 1) {
            $oldest = array_shift($files);
            $total -= filesize($oldest);
            @unlink($oldest);
        }
    }

    public function initDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        wp_mkdir_p($this->dir);
        @chown($this->dir, 'nginx');
        @chgrp($this->dir, 'nginx');
        file_put_contents($this->dir . '/.htaccess', "Deny from all\n", LOCK_EX);
        file_put_contents($this->dir . '/index.php', "<?php\n// Silence.\n", LOCK_EX);
    }

    private function ip(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
