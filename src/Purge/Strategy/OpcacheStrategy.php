<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge\Strategy;

use Gratis\Cache\Contracts\PurgeStrategyInterface;

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
