<?php

declare(strict_types=1);

namespace VLT\CacheManager\Redis;

use Redis;

final class RedisFactory
{
    public static function create(float $timeout = 1.0): ?Redis
    {
        try {
            $redis = new Redis();
            if ($redis->connect('127.0.0.1', 6379, $timeout)) {
                return $redis;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }
}
