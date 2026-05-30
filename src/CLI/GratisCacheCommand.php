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
        \WP_CLI::log("=== Cache Debug: {$url} ===\n");

        // 1. Theme & Template
        \WP_CLI::log("── Theme ──");
        \WP_CLI::log("  Active: " . get_stylesheet() . ' ' . wp_get_theme()->get('Version'));
        $themeJson = get_stylesheet_directory() . '/theme.json';
        if (file_exists($themeJson)) {
            \WP_CLI::log("  theme.json: " . substr(md5_file($themeJson), 0, 8) . ' (modified ' . date('Y-m-d H:i:s', filemtime($themeJson)) . ')');
        }

        // 2. URL Resolution
        \WP_CLI::log("\n── URL Resolution ──");
        $post_id = url_to_postid($url);
        if ($post_id) {
            \WP_CLI::log("  Post ID: {$post_id}");
            \WP_CLI::log("  Post type: " . get_post_type($post_id));
            \WP_CLI::log("  Template: " . (get_page_template_slug($post_id) ?: '(default)'));
        } else {
            \WP_CLI::log("  Post ID: none (may be archive, taxonomy, or custom route)");
        }

        // 3. Object Cache Layer
        \WP_CLI::log("\n── Object Cache ──");
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        if (file_exists($dropin)) {
            $header = file_get_contents($dropin, false, null, 0, 300);
            if (str_contains($header, 'VLT')) {
                \WP_CLI::log("  Drop-in: Gratis/VLT (Redis)");
                try {
                    $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
                    $r = new \Redis();
                    if ($r->connect($host, 6379, 1.0)) {
                        $info = $r->info();
                        $keys = $r->dbSize();
                        \WP_CLI::log("  Redis: connected ({$info['used_memory_human']} used, {$keys} keys)");
                        $r->close();
                    } else {
                        \WP_CLI::log("  Redis: connection failed");
                    }
                } catch (\Throwable $e) {
                    \WP_CLI::log("  Redis: " . $e->getMessage());
                }
            } else {
                \WP_CLI::log("  Drop-in: third-party");
            }
        } else {
            \WP_CLI::log("  Drop-in: not installed (using WP default)");
        }
        if (function_exists('wp_cache_get')) {
            $found = false;
            wp_cache_get('alloptions', 'options', false, $found);
            \WP_CLI::log("  alloptions cached: " . ($found ? 'yes (HIT)' : 'no (MISS)'));
        }

        // 4. OPcache
        \WP_CLI::log("\n── OPcache ──");
        if (function_exists('opcache_get_status')) {
            $oc = @opcache_get_status(false);
            if ($oc && ($oc['opcache_enabled'] ?? false)) {
                $scripts = $oc['opcache_statistics']['num_cached_scripts'] ?? 0;
                $hitRate = round($oc['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
                \WP_CLI::log("  Status: enabled ({$scripts} scripts, {$hitRate}% hit rate)");
            } else {
                \WP_CLI::log("  Status: disabled");
            }
        } else {
            \WP_CLI::log("  Status: not available");
        }

        // 5. Page Cache (Nginx/LiteSpeed/Cloudflare)
        \WP_CLI::log("\n── Page Cache ──");
        $headers = @get_headers($url, true);
        if ($headers) {
            // Nginx FastCGI
            $nginx = $headers['X-FastCGI-Cache'] ?? $headers['x-fastcgi-cache'] ?? null;
            if ($nginx) \WP_CLI::log("  Nginx FastCGI: " . (is_array($nginx) ? end($nginx) : $nginx));

            // LiteSpeed
            $ls = $headers['X-Litespeed-Cache'] ?? $headers['x-litespeed-cache'] ?? null;
            if ($ls) \WP_CLI::log("  LiteSpeed: " . (is_array($ls) ? end($ls) : $ls));

            // Cloudflare
            $cf = $headers['CF-Cache-Status'] ?? $headers['cf-cache-status'] ?? null;
            if ($cf) \WP_CLI::log("  Cloudflare: " . (is_array($cf) ? end($cf) : $cf));

            // Generic cache-control
            $cc = $headers['Cache-Control'] ?? $headers['cache-control'] ?? null;
            if ($cc) \WP_CLI::log("  Cache-Control: " . (is_array($cc) ? end($cc) : $cc));

            if (!$nginx && !$ls && !$cf) {
                \WP_CLI::log("  No page cache headers detected");
            }
        } else {
            \WP_CLI::log("  Could not fetch headers (URL may not be reachable from CLI)");
        }

        // 6. Patterns & Templates
        \WP_CLI::log("\n── Patterns & Templates ──");
        $patterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
        \WP_CLI::log("  Patterns registered: " . count($patterns));
        $themePatterns = array_filter($patterns, fn($p) => str_starts_with($p['name'] ?? '', 'gratis'));
        \WP_CLI::log("  Gratis patterns: " . count($themePatterns));

        // 7. File Change History
        \WP_CLI::log("\n── Recent Changes ──");
        $store = new \VLT\CacheManager\Storage\JsonlTraceStore(WP_CONTENT_DIR . '/cache-manager-data');
        $scanner = new \VLT\CacheManager\Storage\FileChangeScanner($store);
        $last = $scanner->lastChange(get_stylesheet_directory());
        if ($last) {
            \WP_CLI::log("  Last theme change: " . date('Y-m-d H:i:s', $last['_ts'] ?? 0) . " ({$last['type']}: " . basename($last['path'] ?? '') . ")");
        } else {
            \WP_CLI::log("  No theme file changes recorded");
        }

        // 8. Debug meta from integrations
        $meta = apply_filters('gratis_cache_debug_meta', []);
        if (!empty($meta)) {
            \WP_CLI::log("\n── Integration Meta ──");
            foreach ($meta as $source => $data) {
                \WP_CLI::log("  [{$source}] " . json_encode($data, JSON_UNESCAPED_SLASHES));
            }
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

    /**
     * Optimize images: convert to WebP/AVIF.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Max images to process.
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *     wp gratis-cache optimize-images
     *     wp gratis-cache optimize-images --limit=200
     *
     * @subcommand optimize-images
     * @when after_wp_load
     */
    public function optimize_images($args, $assoc_args): void
    {
        $limit = (int) ($assoc_args['limit'] ?? 50);
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => $limit,
            'meta_query'     => [['key' => '_gratis_webp_done', 'compare' => 'NOT EXISTS']],
        ]);

        if (empty($attachments)) {
            \WP_CLI::success("All images already optimized.");
            return;
        }

        \WP_CLI::log("Optimizing " . count($attachments) . " image(s)...");
        $saved = 0;

        foreach ($attachments as $att) {
            $file = get_attached_file($att->ID);
            if (!$file || !file_exists($file)) continue;

            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
            if (!file_exists($webp)) {
                if (function_exists('imagewebp')) {
                    $img = @imagecreatefromstring(file_get_contents($file));
                    if ($img) {
                        imagewebp($img, $webp, 82);
                        imagedestroy($img);
                        $saved += filesize($file) - filesize($webp);
                    }
                }
            }
            update_post_meta($att->ID, '_gratis_webp_done', time());
        }

        $mb = round($saved / 1048576, 2);
        \WP_CLI::success(count($attachments) . " images processed. Saved ~{$mb} MB.");
    }

    /**
     * Generate critical CSS for a URL.
     *
     * ## OPTIONS
     * [<url>]
     * : URL to generate critical CSS for. Defaults to homepage.
     *
     * ## EXAMPLES
     *     wp gratis-cache critical-css
     *     wp gratis-cache critical-css https://example.com/about/
     *
     * @subcommand critical-css
     * @when after_wp_load
     */
    public function critical_css($args, $assoc_args): void
    {
        $url = $args[0] ?? home_url('/');
        \WP_CLI::log("Generating critical CSS for: {$url}");

        $css = \VLT\CacheManager\Performance\CriticalCSS::generate($url);
        if (empty($css)) {
            \WP_CLI::warning("Could not generate critical CSS (URL unreachable or no stylesheets found).");
            return;
        }

        $kb = round(strlen($css) / 1024, 1);
        \WP_CLI::success("Critical CSS generated: {$kb} KB. Enable with: wp option update vlt_critical_css_enabled 1");
    }

    /**
     * Optimize database: clean revisions, transients, spam, trash.
     *
     * ## OPTIONS
     * [--keep-revisions=<n>]
     * : Revisions to keep per post.
     * ---
     * default: 5
     * ---
     * [--dry-run]
     * : Show what would be cleaned without doing it.
     *
     * ## EXAMPLES
     *     wp gratis-cache optimize-db
     *     wp gratis-cache optimize-db --keep-revisions=3
     *
     * @subcommand optimize-db
     * @when after_wp_load
     */
    public function optimize_db($args, $assoc_args): void
    {
        $dryRun = isset($assoc_args['dry-run']);

        if ($dryRun) {
            $stats = \VLT\CacheManager\Performance\DatabaseOptimizer::getStats();
            \WP_CLI::log("=== Database Status (dry run) ===");
            \WP_CLI::log("  Size: " . round(($stats['total_size'] ?? 0) / 1048576, 1) . " MB");
            \WP_CLI::log("  Revisions: {$stats['revisions']}");
            \WP_CLI::log("  Transients: {$stats['transients']}");
            \WP_CLI::log("  Spam comments: {$stats['spam']}");
            \WP_CLI::log("  Trash posts: {$stats['trash_posts']}");
            \WP_CLI::log("  Tables: {$stats['tables']}");
            return;
        }

        \WP_CLI::log("Optimizing database...");
        $result = \VLT\CacheManager\Performance\DatabaseOptimizer::optimize([
            'keep_revisions' => (int) ($assoc_args['keep-revisions'] ?? 5),
        ]);

        \WP_CLI::log("  Revisions deleted: " . ($result['revisions'] ?? 0));
        \WP_CLI::log("  Transients cleaned: " . ($result['transients'] ?? 0));
        \WP_CLI::log("  Spam removed: " . ($result['spam'] ?? 0));
        \WP_CLI::log("  Trash emptied: " . ($result['trash'] ?? 0));
        \WP_CLI::log("  Orphan meta removed: " . ($result['orphan_meta'] ?? 0));
        \WP_CLI::log("  Tables optimized: " . ($result['tables_optimized'] ?? 0));
        \WP_CLI::success("Database optimized.");
    }

    /**
     * Warm up the page cache by pre-fetching all public URLs.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Max pages to warm.
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *     wp gratis-cache warmup
     *
     * @when after_wp_load
     */
    public function warmup($args, $assoc_args): void
    {
        $limit = (int) ($assoc_args['limit'] ?? 50);
        $urls = [home_url('/')];

        // Get published pages
        $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids']);
        foreach ($pages as $id) $urls[] = get_permalink($id);

        // Get recent posts
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids']);
        foreach ($posts as $id) $urls[] = get_permalink($id);

        $urls = array_unique(array_slice($urls, 0, $limit));
        \WP_CLI::log("Warming " . count($urls) . " URL(s)...");

        $warmed = 0;
        foreach ($urls as $url) {
            $r = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $warmed++;
            }
        }

        \WP_CLI::success("{$warmed}/" . count($urls) . " pages warmed.");
    }
}
