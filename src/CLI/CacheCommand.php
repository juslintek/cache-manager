<?php

declare(strict_types=1);

namespace VLT\CacheManager\CLI;

use VLT\CacheManager\Plugin;
use WP_CLI;

final class CacheCommand
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Show cache status across all layers.
     *
     * ## EXAMPLES
     *     wp vlt-cache status
     *
     * @subcommand status
     */
    public function status($args, $assoc): void
    {
        try {
            $r = new \Redis();
            if ($r->connect('127.0.0.1', 6379, 1.0)) {
                $info = $r->info();
                WP_CLI::success('Redis: connected, ' . ($info['used_memory_human'] ?? '?') . ' used');
                $r->close();
            } else {
                WP_CLI::warning('Redis: connection failed');
            }
        } catch (\Exception $e) {
            WP_CLI::warning('Redis: ' . $e->getMessage());
        }

        if (function_exists('opcache_get_status')) {
            $oc = opcache_get_status(false);
            if ($oc) {
                $scripts  = $oc['opcache_statistics']['num_cached_scripts'] ?? 0;
                $hit_rate = round($oc['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
                WP_CLI::success("OPcache: {$scripts} scripts, {$hit_rate}% hit rate");
            } else {
                WP_CLI::warning('OPcache: disabled');
            }
        }

        if (is_dir(VLT_CM_NGINX_CACHE)) {
            $size = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(VLT_CM_NGINX_CACHE, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $size += $f->getSize();
            }
            $mb = round($size / 1048576, 1);
            WP_CLI::success("Nginx FastCGI: {$mb} MB");
        } else {
            WP_CLI::warning('Nginx FastCGI: cache directory not found');
        }

        $el_dir = WP_CONTENT_DIR . '/uploads/elementor/css/';
        $count  = is_dir($el_dir) ? count(glob($el_dir . '*.css')) : 0;
        WP_CLI::success("Elementor CSS: {$count} files");

        WP_CLI::log('Object-cache.php: ' . ($this->plugin->dropin()->isOurs() ? 'installed (ours)' : 'missing or third-party'));
    }

    /**
     * Purge caches.
     *
     * ## OPTIONS
     * [--type=<type>]
     * : Type: all, nginx, opcache, redis, elementor
     * ---
     * default: all
     * ---
     *
     * ## EXAMPLES
     *     wp vlt-cache purge
     *     wp vlt-cache purge --type=nginx
     *
     * @subcommand purge
     */
    public function purge($args, $assoc): void
    {
        $type = $assoc['type'] ?? 'all';
        if ($type === 'all') {
            $this->plugin->purge()->purgeAll();
        } else {
            $this->plugin->purge()->purge($type);
        }
        WP_CLI::success("Cache purged: {$type}");
    }

    /**
     * Show today's cache statistics.
     *
     * ## EXAMPLES
     *     wp vlt-cache stats
     *
     * @subcommand stats
     */
    public function stats($args, $assoc): void
    {
        $stats = $this->plugin->logger()->getTodayStats();
        $total = $stats['hits'] + $stats['misses'];
        $ratio = $total > 0 ? round($stats['hits'] / $total * 100, 1) : 0;
        WP_CLI::log("Today's statistics:");
        WP_CLI::log("  Requests: {$stats['requests']}");
        WP_CLI::log("  Hits: {$stats['hits']}");
        WP_CLI::log("  Misses: {$stats['misses']}");
        WP_CLI::log("  Hit ratio: {$ratio}%");
        WP_CLI::log("  Purge events: {$stats['purges']}");
    }
}
