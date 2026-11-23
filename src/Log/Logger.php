<?php

declare(strict_types=1);

namespace Gratis\Cache\Log;

use Gratis\Cache\Redis\RedisFactory;

final class Logger {

    private string $dir;

    public function __construct() {
        $this->dir = get_option('vlt_cm_log_path', WP_CONTENT_DIR . '/uploads/vlt-cache-logs');
    }

    public function log(string $type, mixed $details = ''): void {
        if (!get_option('vlt_cm_logging', true)) {
            return;
        }
        $this->initDir();
        $uid  = get_current_user_id();
        $user = $uid ? get_userdata($uid) : null;
        $entry = wp_json_encode([
            'timestamp' => gmdate('c'),
            'type'      => $type,
            'details'   => $details,
            'user_id'   => $uid,
            'user_name' => $user ? $user->display_name : (defined('WP_CLI') ? 'WP-CLI' : 'Sistema'),
            'ip'        => $this->ip(),
            'uri'       => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $file = $this->dir . '/cache-log-' . gmdate('Y-m-d') . '.json';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic append to local cache log file.
        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chown -- Align cache log ownership with nginx worker when permitted.
        @chown($file, 'nginx');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- Align cache log group with nginx worker when permitted.
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
            // Redis live log push is optional best-effort.
            unset($e);
        }
    }

    public function logCfRequest(): void {
        if (!get_option('vlt_cm_cf_tracking', true)) {
            return;
        }
        $ray = isset($_SERVER['HTTP_CF_RAY']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_RAY'])) : '';
        if (!$ray) {
            return;
        }
        $uid  = get_current_user_id();
        $user = $uid ? get_userdata($uid) : null;
        $entry = [
            'ts'         => gmdate('c'),
            'ray'        => $ray,
            'country'    => isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])) : '',
            'ip'         => $this->ip(),
            'uri'        => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'user_id'    => $uid,
            'user_name'  => $user ? $user->display_name : '',
            'user_email' => $user ? $user->user_email : '',
            'challenged' => isset($_COOKIE['cf_clearance']),
            'ua'         => substr(isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', 0, 300),
        ];
        try {
            $r = RedisFactory::create(0.2);
            if ($r) {
                $r->lPush('vlt_cf_live', (string) wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $r->lTrim('vlt_cf_live', 0, 499);
                $r->expire('vlt_cf_live', 600);
                $r->close();
            }
        } catch (\Throwable $e) {
            // Redis live Cloudflare push is optional best-effort.
            unset($e);
        }
        $this->log('cloudflare', $entry);
    }

    public function logRequestStats(int $hits, int $misses): void {
        if (!get_option('vlt_cm_logging', true) || ($hits === 0 && $misses === 0)) {
            return;
        }
        $this->log('stats', ['hits' => $hits, 'misses' => $misses]);
    }

    public function readLog(string $date): array {
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

    public function getTodayStats(): array {
        $file  = $this->dir . '/cache-log-' . gmdate('Y-m-d') . '.json';
        $stats = ['requests' => 0, 'hits' => 0, 'misses' => 0, 'purges' => 0];
        if (!file_exists($file)) {
            return $stats;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of local daily JSONL log.
        $fh = @fopen($file, 'r');
        if (!$fh) {
            return $stats;
        }
        $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
        for ($line = fgets($fh); false !== $line; $line = fgets($fh)) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $e = $decode($line, true);
            if (!$e) {
                continue;
            }
            if (($e['type'] ?? '') === 'stats') {
                ++$stats['requests'];
                $stats['hits']   += (int) ($e['details']['hits'] ?? 0);
                $stats['misses'] += (int) ($e['details']['misses'] ?? 0);
            } elseif (($e['type'] ?? '') === 'purge') {
                ++$stats['purges'];
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local streaming handle.
        fclose($fh);
        return $stats;
    }

    public function getRecentPurges(int $limit = 10): array {
        $file = $this->dir . '/cache-log-' . gmdate('Y-m-d') . '.json';
        if (!file_exists($file)) {
            return [];
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming tail read of local daily JSONL log.
        $fh = @fopen($file, 'r');
        if (!$fh) {
            return [];
        }
        $size   = filesize($file);
        $offset = max(0, ($size ?: 0) - 204800);
        fseek($fh, $offset);
        if ($offset > 0) {
            fgets($fh);
        }
        $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
        $purges = [];
        for ($line = fgets($fh); false !== $line; $line = fgets($fh)) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '"purge"')) {
                continue;
            }
            $e = $decode($line, true);
            if ($e && ($e['type'] ?? '') === 'purge') {
                $purges[] = $e;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local streaming handle.
        fclose($fh);
        return array_slice(array_reverse($purges), 0, $limit);
    }

    public function rotateLogs(): void {
        // Age-based rotation
        $days   = (int) get_option('vlt_cm_log_days', 30);
        $cutoff = gmdate('Y-m-d', strtotime("-{$days} days"));
        foreach (glob($this->dir . '/cache-log-*.json') as $f) {
            if (preg_match('/cache-log-(\d{4}-\d{2}-\d{2})\.json$/', $f, $m) && $m[1] < $cutoff) {
                wp_delete_file($f);
            }
        }
        // Size-based rotation (MB)
        $maxMb = (int) get_option('vlt_cm_log_max_mb', 500);
        if ($maxMb > 0) {
            self::enforceMaxSize($this->dir, 'cache-log-*.json', $maxMb * 1048576);
        }
    }

    public static function enforceMaxSize(string $dir, string $pattern, int $maxBytes): void {
        $files = glob($dir . '/' . $pattern) ?: [];
        if (!$files) {
            return;
        }
        sort($files); // oldest first (date in filename)
        $total     = array_sum(array_map('filesize', $files));
        $remaining = count($files);
        while ($total > $maxBytes && $remaining > 1) {
            $oldest = array_shift($files);
            --$remaining;
            $total -= filesize($oldest);
            wp_delete_file($oldest);
        }
    }

    public function initDir(): void {
        if (is_dir($this->dir)) {
            return;
        }
        if (!wp_mkdir_p($this->dir) && !is_dir($this->dir)) {
            return;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Ensure local log directory permissions.
        @chmod($this->dir, 0755);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protect local log directory from HTTP access.
        @file_put_contents($this->dir . '/.htaccess', "Deny from all\n", LOCK_EX);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Silence directory listing for local log directory.
        @file_put_contents($this->dir . '/index.php', "<?php\n// Silence.\n", LOCK_EX);
    }

    private function ip(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return '';
    }
}
