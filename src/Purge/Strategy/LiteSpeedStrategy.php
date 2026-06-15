<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge\Strategy;

use Gratis\Cache\Contracts\PurgeStrategyInterface;

final class LiteSpeedStrategy implements PurgeStrategyInterface {

    public function type(): string {
        return 'litespeed';
    }

    public function purge(): void {
        // Use our native cache control first
        \Gratis\Cache\Cache\LiteSpeedCache::purgeAll();

        // Also trigger LSCWP if active (belt and suspenders)
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        } elseif (class_exists('\LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
        }
    }
}
