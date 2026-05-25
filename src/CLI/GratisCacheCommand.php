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
     *
     * [--iterations=<n>]
     * : Number of iterations per backend.
     * ---
     * default: 1000
     * ---
     *
     * ## EXAMPLES
     *     wp gratis-cache bench
     *     wp gratis-cache bench --iterations=5000
     * @when after_wp_load
     */
    public function bench($args, $assoc_args): void
    {
        $iterations = (int) ($assoc_args['iterations'] ?? 1000);
        \WP_CLI::log("Benchmarking {$iterations} iterations per backend...\n");

        $results = [];

        // Array (baseline)
        $results[] = $this->benchArray($iterations);

        // Redis
        if (extension_loaded('redis')) {
            $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
            $port = defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379;
            try {
                $r = new \Redis();
                if ($r->connect($host, $port, 1.0)) {
                    $results[] = $this->benchRedis($r, $iterations);
                    $r->close();
                }
            } catch (\Throwable $e) {}
        }

        // SQLite
        if (extension_loaded('sqlite3')) {
            $results[] = $this->benchSqlite($iterations);
        }

        // File
        $results[] = $this->benchFile($iterations);

        // Output
        \WP_CLI\Utils\format_items('table', $results, ['Backend', 'Write', 'Read', 'Delete', 'Ops/sec']);
        \WP_CLI::success("Benchmark complete.");
    }

    private function benchArray(int $n): array
    {
        $cache = [];
        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) $cache["key_{$i}"] = str_repeat('x', 100);
        $write = microtime(true) - $s;

        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) $_ = $cache["key_{$i}"];
        $read = microtime(true) - $s;

        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) unset($cache["key_{$i}"]);
        $del = microtime(true) - $s;

        return ['Backend' => 'array', 'Write' => round($write * 1000, 1) . 'ms', 'Read' => round($read * 1000, 1) . 'ms', 'Delete' => round($del * 1000, 1) . 'ms', 'Ops/sec' => number_format((int) ($n * 3 / ($write + $read + $del)))];
    }

    private function benchRedis(\Redis $r, int $n): array
    {
        $r->setOption(\Redis::OPT_PREFIX, 'bench_');
        $val = str_repeat('x', 100);

        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) $r->set("key_{$i}", $val);
        $write = microtime(true) - $s;

        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) $r->get("key_{$i}");
        $read = microtime(true) - $s;

        $s = microtime(true);
        for ($i = 0; $i < $n; $i++) $r->del("key_{$i}");
        $del = microtime(true) - $s;

        return ['Backend' => 'redis', 'Write' => round($write * 1000, 1) . 'ms', 'Read' => round($read * 1000, 1) . 'ms', 'Delete' => round($del * 1000, 1) . 'ms', 'Ops/sec' => number_format((int) ($n * 3 / ($write + $read + $del)))];
    }

    private function benchSqlite(int $n): array
    {
        $db = new \SQLite3(':memory:');
        $db->exec('CREATE TABLE bench (k TEXT PRIMARY KEY, v BLOB)');
        $val = str_repeat('x', 100);

        $s = microtime(true);
        $db->exec('BEGIN');
        $stmt = $db->prepare('INSERT OR REPLACE INTO bench (k, v) VALUES (:k, :v)');
        for ($i = 0; $i < $n; $i++) { $stmt->bindValue(':k', "key_{$i}"); $stmt->bindValue(':v', $val); $stmt->execute(); $stmt->reset(); }
        $db->exec('COMMIT');
        $write = microtime(true) - $s;

        $s = microtime(true);
        $stmt = $db->prepare('SELECT v FROM bench WHERE k = :k');
        for ($i = 0; $i < $n; $i++) { $stmt->bindValue(':k', "key_{$i}"); $r = $stmt->execute(); $r->fetchArray(); $stmt->reset(); }
        $read = microtime(true) - $s;

        $s = microtime(true);
        $db->exec('BEGIN');
        $stmt = $db->prepare('DELETE FROM bench WHERE k = :k');
        for ($i = 0; $i < $n; $i++) { $stmt->bindValue(':k', "key_{$i}"); $stmt->execute(); $stmt->reset(); }
        $db->exec('COMMIT');
        $del = microtime(true) - $s;

        $db->close();
        return ['Backend' => 'sqlite', 'Write' => round($write * 1000, 1) . 'ms', 'Read' => round($read * 1000, 1) . 'ms', 'Delete' => round($del * 1000, 1) . 'ms', 'Ops/sec' => number_format((int) ($n * 3 / ($write + $read + $del)))];
    }

    private function benchFile(int $n): array
    {
        $dir = sys_get_temp_dir() . '/gratis-bench-' . getmypid();
        @mkdir($dir, 0755, true);
        $val = str_repeat('x', 100);

        $s = microtime(true);
        for ($i = 0; $i < min($n, 500); $i++) file_put_contents("{$dir}/key_{$i}", $val);
        $write = microtime(true) - $s;
        $fileN = min($n, 500);

        $s = microtime(true);
        for ($i = 0; $i < $fileN; $i++) @file_get_contents("{$dir}/key_{$i}");
        $read = microtime(true) - $s;

        $s = microtime(true);
        for ($i = 0; $i < $fileN; $i++) @unlink("{$dir}/key_{$i}");
        $del = microtime(true) - $s;

        @rmdir($dir);
        return ['Backend' => 'file', 'Write' => round($write * 1000, 1) . 'ms', 'Read' => round($read * 1000, 1) . 'ms', 'Delete' => round($del * 1000, 1) . 'ms', 'Ops/sec' => number_format((int) ($fileN * 3 / ($write + $read + $del)))];
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
        $store = new \VLT\CacheManager\Storage\JsonlTraceStore(WP_CONTENT_DIR . '/cache-manager-data');

        if ($sub === 'tail') {
            // Show cache events + file changes combined
            $events = $store->tail('cache-events', 20);
            $changes = $store->tail('file-changes', 10);
            $all = array_merge(
                array_map(fn($e) => array_merge($e, ['_source' => 'cache']), $events),
                array_map(fn($e) => array_merge($e, ['_source' => 'file']), $changes)
            );
            usort($all, fn($a, $b) => ($a['_ts'] ?? 0) <=> ($b['_ts'] ?? 0));
            $all = array_slice($all, -25);

            if (empty($all)) {
                \WP_CLI::log("No events recorded yet. Run 'wp gratis-cache scan-files' to generate some.");
                return;
            }

            foreach ($all as $e) {
                $ts = date('H:i:s', $e['_ts'] ?? 0);
                if (($e['_source'] ?? '') === 'file') {
                    $type = $e['type'] ?? '?';
                    $path = basename($e['path'] ?? '');
                    \WP_CLI::log("[{$ts}] file.{$type}: {$path}");
                } else {
                    $type = $e['type'] ?? '?';
                    $detail = is_array($e['detail'] ?? '') ? json_encode($e['detail']) : ($e['detail'] ?? '');
                    \WP_CLI::log("[{$ts}] {$type}: {$detail}");
                }
            }
        }
    }

    /**
     * Install or remove the object-cache.php drop-in.
     *
     * ## OPTIONS
     * <action>
     * : Action: install, remove, status
     *
     * ## EXAMPLES
     *     wp gratis-cache dropin install
     *     wp gratis-cache dropin remove
     *     wp gratis-cache dropin status
     *
     * @when after_wp_load
     */
    public function dropin($args, $assoc_args): void
    {
        $action = $args[0] ?? 'status';
        $installer = new \VLT\CacheManager\Cache\DropinInstaller(
            new \VLT\CacheManager\Cache\DropinGenerator()
        );

        switch ($action) {
            case 'status':
                $path = WP_CONTENT_DIR . '/object-cache.php';
                if (!file_exists($path)) {
                    \WP_CLI::log("object-cache.php: not installed");
                } elseif ($installer->isOurs()) {
                    \WP_CLI::log("object-cache.php: installed (Gratis/VLT)");
                } else {
                    \WP_CLI::log("object-cache.php: third-party drop-in present");
                }
                break;

            case 'install':
                if ($installer->isOurs()) {
                    \WP_CLI::log("Already installed. Regenerating...");
                }
                $installer->install();
                \WP_CLI::success("object-cache.php installed with Redis support.");
                break;

            case 'remove':
                $path = WP_CONTENT_DIR . '/object-cache.php';
                if (!file_exists($path)) {
                    \WP_CLI::warning("No object-cache.php to remove.");
                } elseif (!$installer->isOurs()) {
                    \WP_CLI::error("object-cache.php is not ours. Remove manually if intended.");
                } else {
                    @unlink($path);
                    \WP_CLI::success("object-cache.php removed.");
                }
                break;

            default:
                \WP_CLI::error("Unknown action: {$action}. Use: install, remove, status");
        }
    }
}
