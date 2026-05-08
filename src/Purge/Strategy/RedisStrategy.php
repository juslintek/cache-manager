<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Cache\SmartPurge;
use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class RedisStrategy implements PurgeStrategyInterface
{
    public function purge(): void
    {
        // Use SmartPurge to skip state/excluded keys
        $groups = ['posts', 'post_meta', 'terms', 'term_meta', 'options', 'users', 'comment'];
        foreach ($groups as $group) {
            SmartPurge::purgeGroup($group, 'cache_clear');
        }

        // Flush remaining WP object cache (in-memory)
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    public function type(): string
    {
        return 'redis';
    }
}
