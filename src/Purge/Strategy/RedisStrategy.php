<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class RedisStrategy implements PurgeStrategyInterface
{
    public function purge(): void
    {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    public function type(): string
    {
        return 'redis';
    }
}
