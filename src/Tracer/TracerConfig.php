<?php

declare(strict_types=1);

namespace VLT\CacheManager\Tracer;

final class TracerConfig
{
    public const VLT_TR_KEY = 'vlt_traces';
    public const VLT_TR_TTL = 300;
    public const VLT_TR_MAX = 200;

    public static function getDir(): string
    {
        $default = WP_CONTENT_DIR . '/uploads/vlt-traces';
        return get_option('vlt_cm_trace_path', $default) ?: $default;
    }

    public static function shouldTrace(): bool
    {
        if (isset($_COOKIE['vlt_trace']) || isset($_GET['vlt_trace'])) {
            return true;
        }
        $rateFile = self::getDir() . '/.sample_rate';
        $rate = file_exists($rateFile) ? (int) file_get_contents($rateFile) : 0;
        return $rate > 0 && mt_rand(1, 100) <= $rate;
    }

    public static function getMaxTraces(): int
    {
        $file = self::getDir() . '/.max_traces';
        return file_exists($file) ? max(10, (int) file_get_contents($file)) : self::VLT_TR_MAX;
    }
}
