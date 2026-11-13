<?php

declare(strict_types=1);

namespace Gratis\Cache\Tracer;

final class TracerConfig {

    public const VLT_TR_KEY = 'vlt_traces';
    public const VLT_TR_TTL = 300;
    public const VLT_TR_MAX = 200;

    public static function getDir(): string {
        $default = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir()) . '/uploads/vlt-traces';
        if (!function_exists('get_option')) {
            return $default;
        }
        return get_option('vlt_cm_trace_path', $default) ?: $default;
    }

    public static function shouldTrace(): bool {
        if (isset($_COOKIE['vlt_trace']) || isset($_GET['vlt_trace'])) {
            return true;
        }
        $rateFile = self::getDir() . '/.sample_rate';
        $rate = file_exists($rateFile) ? (int) file_get_contents($rateFile) : 0;
        return $rate > 0 && mt_rand(1, 100) <= $rate;
    }

    public static function getMaxTraces(): int {
        $file = self::getDir() . '/.max_traces';
        return file_exists($file) ? max(10, (int) file_get_contents($file)) : self::VLT_TR_MAX;
    }

    /**
     * Read paginated trace history from the tail of a daily JSONL file.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function readHistory(string $date, int $limit = 200, int $offset = 0): array {
        $file = self::getDir() . '/trace-' . $date . '.json';
        if (!file_exists($file)) {
            return ['rows' => [], 'total' => 0];
        }
        $fh = @fopen($file, 'r');
        if (!$fh) {
            return ['rows' => [], 'total' => 0];
        }
        $size    = filesize($file) ?: 0;
        $maxRead = min($size, 10 * 1024 * 1024);
        fseek($fh, max(0, $size - $maxRead));
        if ($size > $maxRead) {
            fgets($fh);
        }
        $lines = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        fclose($fh);

        $total  = count($lines);
        $slice  = array_slice(array_reverse($lines), $offset, $limit);
        $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
        $rows   = [];
        foreach ($slice as $line) {
            $decoded = $decode($line, true);
            if ($decoded) {
                $rows[] = $decoded;
            }
        }
        return ['rows' => $rows, 'total' => $total];
    }
}
