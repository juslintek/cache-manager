<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class OpcacheStrategy implements PurgeStrategyInterface
{
    public function purge(): void
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    public function type(): string
    {
        return 'opcache';
    }
}
