<?php

declare(strict_types=1);

namespace VLT\CacheManager\Redis;

use Redis;

final class RedisFactory
{
    public static function create(float $timeout = 1.0): ?Redis
    {
        // 1. Use manually saved config
        $socket = get_option('vlt_redis_socket', '');
        $host   = get_option('vlt_redis_host', '');
        $port   = (int) get_option('vlt_redis_port', 0);

        if ($socket) {
            return self::connectSocket($socket, $timeout);
        }
        if ($host && $port) {
            return self::connectTcp($host, $port, $timeout);
        }

        // 2. Auto-detect
        $detected = RedisDetector::detect();
        if ($detected['connected']) {
            if ($detected['method'] === 'socket') {
                return self::connectSocket($detected['socket'], $timeout);
            }
            return self::connectTcp($detected['host'], $detected['port'], $timeout);
        }

        return null;
    }

    private static function connectSocket(string $path, float $timeout): ?Redis
    {
        try {
            $r = new Redis();
            if (@$r->connect($path, 0, $timeout)) {
                return $r;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private static function connectTcp(string $host, int $port, float $timeout): ?Redis
    {
        try {
            $r = new Redis();
            if (@$r->connect($host, $port, $timeout)) {
                return $r;
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
