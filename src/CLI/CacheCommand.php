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
     * Rodo visų talpyklų būseną.
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
                WP_CLI::success('Redis: prijungtas, ' . ($info['used_memory_human'] ?? '?') . ' naudojama');
                $r->close();
            } else {
                WP_CLI::warning('Redis: nepavyko prisijungti');
            }
        } catch (\Exception $e) {
            WP_CLI::warning('Redis: ' . $e->getMessage());
        }

        if (function_exists('opcache_get_status')) {
            $oc = opcache_get_status(false);
            if ($oc) {
                $scripts  = $oc['opcache_statistics']['num_cached_scripts'] ?? 0;
                $hit_rate = round($oc['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
                WP_CLI::success("OPcache: $scripts failų, {$hit_rate}% pataikymų");
            } else {
                WP_CLI::warning('OPcache: išjungtas');
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
            WP_CLI::warning('Nginx FastCGI: katalogas nerastas');
        }

        $el_dir = WP_CONTENT_DIR . '/uploads/elementor/css/';
        $count  = is_dir($el_dir) ? count(glob($el_dir . '*.css')) : 0;
        WP_CLI::success("Elementor CSS: $count failų");

        WP_CLI::log('Object-cache.php: ' . ($this->plugin->dropin()->isOurs() ? 'VLT (įdiegtas)' : 'kitas arba trūksta'));
    }

    /**
     * Valo talpyklas.
     *
     * ## OPTIONS
     * [--type=<type>]
     * : Tipas: all, nginx, opcache, redis, elementor
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
        WP_CLI::success("Talpykla išvalyta: $type");
    }

    /**
     * Rodo šiandienos statistiką iš žurnalo.
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
        WP_CLI::log("Šiandienos statistika:");
        WP_CLI::log("  Užklausos: {$stats['requests']}");
        WP_CLI::log("  Pataikymai: {$stats['hits']}");
        WP_CLI::log("  Praleidmai: {$stats['misses']}");
        WP_CLI::log("  Santykis: {$ratio}%");
        WP_CLI::log("  Valymo įvykiai: {$stats['purges']}");
    }
}
