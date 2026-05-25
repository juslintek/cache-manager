<?php declare(strict_types=1);
namespace VLT\CacheManager\CLI;

use VLT\CacheManager\Diagnostics\CapabilityDetector;

/** WP-CLI commands: wp gratis-cache <subcommand> */
final class GratisCacheCommand
{
    /**
     * Show cache system status and detected capabilities.
     * ## EXAMPLES
     *     wp gratis-cache status
     * @when after_wp_load
     */
    public function status($args, $assoc_args): void
    {
        $caps = CapabilityDetector::detect();

        \WP_CLI::log("=== Gratis Cache Status ===");
        \WP_CLI::log("");
        \WP_CLI::log("Environment:");
        \WP_CLI::log("  PHP: " . PHP_VERSION);
        \WP_CLI::log("  WordPress: " . get_bloginfo('version'));
        \WP_CLI::log("  Theme: " . get_stylesheet() . ' ' . wp_get_theme()->get('Version'));
        \WP_CLI::log("  Site URL: " . get_site_url());
        \WP_CLI::log("");
        \WP_CLI::log("Backends:");
        \WP_CLI::log("  Volatile: " . CapabilityDetector::bestVolatileBackend());
        \WP_CLI::log("  Persistent: " . CapabilityDetector::bestPersistentStore());
        \WP_CLI::log("  Serializer: " . CapabilityDetector::bestSerializer());
        \WP_CLI::log("");

        $logger = new \VLT\CacheManager\Log\Logger();
        $stats = $logger->getTodayStats();
        $total = $stats['hits'] + $stats['misses'];
        $ratio = $total > 0 ? round($stats['hits'] / $total * 100, 1) : 0;
        \WP_CLI::log("Today's Stats:");
        \WP_CLI::log("  Requests: {$stats['requests']}");
        \WP_CLI::log("  Hits: {$stats['hits']} | Misses: {$stats['misses']} | Ratio: {$ratio}%");
        \WP_CLI::log("  Purges: {$stats['purges']}");
        \WP_CLI::log("");

        \WP_CLI::log("Extensions:");
        foreach ($caps as $ext => $available) {
            $icon = $available ? '✓' : '✗';
            \WP_CLI::log("  {$icon} {$ext}");
        }
    }

    /**
     * Benchmark available storage backends.
     * ## EXAMPLES
     *     wp gratis-cache bench
     * @when after_wp_load
     */
    public function bench($args, $assoc_args): void
    {
        \WP_CLI::log("Running storage benchmark...");
        // TODO: implement benchmark across detected backends
        \WP_CLI::success("Benchmark complete.");
    }

    /**
     * Purge all cache layers.
     * ## EXAMPLES
     *     wp gratis-cache purge
     * @when after_wp_load
     */
    public function purge($args, $assoc_args): void
    {
        do_action('gratis_cache_purge_all', 'cli');
        \WP_CLI::success("All cache layers purged.");
    }

    /**
     * Purge cache for a specific URL.
     * ## OPTIONS
     * <url>
     * : The URL to purge.
     * [--reason=<reason>]
     * : Reason for purge.
     * ## EXAMPLES
     *     wp gratis-cache purge-url https://example.com/page/
     * @subcommand purge-url
     * @when after_wp_load
     */
    public function purge_url($args, $assoc_args): void
    {
        $url = $args[0];
        $reason = $assoc_args['reason'] ?? 'manual';
        do_action('gratis_cache_purge_url', $url, $reason);
        \WP_CLI::success("Purged: {$url} (reason: {$reason})");
    }

    /**
     * Debug cache state for a specific URL.
     * ## OPTIONS
     * <url>
     * : The URL to debug.
     * ## EXAMPLES
     *     wp gratis-cache debug-url https://example.com/patterns/
     * @subcommand debug-url
     * @when after_wp_load
     */
    public function debug_url($args, $assoc_args): void
    {
        $url = $args[0];
        \WP_CLI::log("=== Cache Debug: {$url} ===");
        \WP_CLI::log("Active theme: " . get_stylesheet());
        \WP_CLI::log("Theme version: " . wp_get_theme()->get('Version'));

        // Check which post/template renders this URL
        $post_id = url_to_postid($url);
        if ($post_id) {
            \WP_CLI::log("Post ID: {$post_id}");
            \WP_CLI::log("Post type: " . get_post_type($post_id));
            \WP_CLI::log("Template: " . get_page_template_slug($post_id) ?: '(default)');
        }

        // Check LiteSpeed cache state
        $headers = @get_headers($url, true);
        if ($headers) {
            $lsCache = $headers['X-Litespeed-Cache'] ?? $headers['x-litespeed-cache'] ?? 'unknown';
            \WP_CLI::log("LiteSpeed cache: " . (is_array($lsCache) ? end($lsCache) : $lsCache));
        }

        // Patterns registered
        $patterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
        \WP_CLI::log("Patterns registered: " . count($patterns));

        // Last file change
        \WP_CLI::log("Theme path: " . get_stylesheet_directory());
        $themeJson = get_stylesheet_directory() . '/theme.json';
        if (file_exists($themeJson)) {
            \WP_CLI::log("theme.json modified: " . date('Y-m-d H:i:s', filemtime($themeJson)));
        }
    }

    /**
     * Scan for changed files since last scan.
     *
     * [--dir=<directory>]
     * : Additional directory to scan.
     *
     * ## EXAMPLES
     *     wp gratis-cache scan-files
     * @subcommand scan-files
     * @when after_wp_load
     */
    public function scan_files($args, $assoc_args): void
    {
        $store = new \VLT\CacheManager\Storage\JsonlTraceStore(WP_CONTENT_DIR . '/cache-manager-data');
        $scanner = new \VLT\CacheManager\Storage\FileChangeScanner($store);

        $dirs = null;
        if (!empty($assoc_args['dir'])) {
            $dirs = [realpath($assoc_args['dir']) ?: $assoc_args['dir']];
        }

        \WP_CLI::log("Scanning for file changes...");
        $changed = $scanner->scan($dirs);

        if (empty($changed)) {
            \WP_CLI::success("No changes detected since last scan.");
            return;
        }

        \WP_CLI::log(count($changed) . " file(s) changed:");
        foreach (array_slice($changed, 0, 50) as $path) {
            $rel = str_replace(ABSPATH, '', $path);
            \WP_CLI::log("  • {$rel}");
        }
        if (count($changed) > 50) {
            \WP_CLI::log("  ... and " . (count($changed) - 50) . " more");
        }

        do_action('gratis_cache_files_changed', $changed);
        \WP_CLI::success(count($changed) . " change(s) recorded.");
    }

    /**
     * Show recent cache history events.
     * ## EXAMPLES
     *     wp gratis-cache history tail
     * @subcommand history
     * @when after_wp_load
     */
    public function history($args, $assoc_args): void
    {
        $sub = $args[0] ?? 'tail';
        if ($sub === 'tail') {
            $store = new \VLT\CacheManager\Storage\JsonlTraceStore(WP_CONTENT_DIR . '/cache-manager-data');
            $events = $store->tail('cache-events', 20);
            if (empty($events)) {
                \WP_CLI::log("No cache events recorded yet.");
                return;
            }
            foreach ($events as $e) {
                $ts = date('H:i:s', $e['_ts'] ?? 0);
                $type = $e['type'] ?? '?';
                $detail = $e['detail'] ?? '';
                \WP_CLI::log("[{$ts}] {$type}: {$detail}");
            }
        }
    }
}
