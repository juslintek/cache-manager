<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class LiteSpeedStrategy implements PurgeStrategyInterface
{
    public function type(): string
    {
        return 'litespeed';
    }

    public function purge(): void
    {
        // LiteSpeed Cache plugin hook (LSCWP)
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            return;
        }

        // Direct LSCWP API
        if (class_exists('\LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
            return;
        }

        // Legacy LiteSpeed Cache class
        if (class_exists('LiteSpeed_Cache_API')) {
            \LiteSpeed_Cache_API::purge_all();
            return;
        }

        // OpenLiteSpeed: send purge header via loopback request
        wp_remote_get(home_url('/'), [
            'timeout'   => 5,
            'headers'   => ['X-LiteSpeed-Purge' => '*'],
            'sslverify' => false,
        ]);
    }
}
