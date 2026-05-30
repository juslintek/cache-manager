<?php declare(strict_types=1);
namespace VLT\CacheManager\Cache;

/**
 * Full-page HTML cache — what WP Rocket ($59/yr) and WP Super Cache do.
 * Stores rendered HTML to disk, serves it on subsequent requests without loading WordPress.
 * Free in Gratis.
 */
final class PageCache
{
    private static string $cacheDir;
    private static bool $enabled = false;

    public static function register(): void
    {
        if (!get_option('vlt_page_cache_enabled')) return;

        self::$cacheDir = WP_CONTENT_DIR . '/cache/gratis-pages';
        self::$enabled = true;

        // Try to serve from cache early (must be called before output)
        add_action('template_redirect', [__CLASS__, 'maybeServe'], -9999);

        // Capture output at shutdown
        add_action('template_redirect', [__CLASS__, 'startCapture'], 0);

        // Invalidation hooks
        add_action('save_post', [__CLASS__, 'purgePost']);
        add_action('comment_post', [__CLASS__, 'purgePost']);
        add_action('switch_theme', [__CLASS__, 'purgeAll']);
        add_action('gratis_cache_purge_all', [__CLASS__, 'purgeAll']);
        add_action('gratis_cache_purge_url', [__CLASS__, 'purgeUrl']);
    }

    public static function maybeServe(): void
    {
        if (!self::shouldCache()) return;

        $file = self::cacheFile();
        if (!file_exists($file)) return;

        // Check TTL (default 1 hour)
        $ttl = (int) get_option('vlt_page_cache_ttl', 3600);
        if (time() - filemtime($file) > $ttl) {
            @unlink($file);
            return;
        }

        // Serve cached page
        header('X-Gratis-Cache: HIT');
        header('X-Gratis-Cache-Age: ' . (time() - filemtime($file)));
        readfile($file);
        exit;
    }

    public static function startCapture(): void
    {
        if (!self::shouldCache()) return;
        ob_start([__CLASS__, 'saveOutput']);
    }

    public static function saveOutput(string $html): string
    {
        if (strlen($html) < 255) return $html; // Don't cache error pages
        if (http_response_code() !== 200) return $html;

        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // Add cache signature
        $html .= "\n<!-- Gratis Page Cache: " . gmdate('c') . " -->";
        @file_put_contents($file, $html, LOCK_EX);

        header('X-Gratis-Cache: MISS');
        return $html;
    }

    public static function purgePost($postId): void
    {
        $url = get_permalink($postId);
        if ($url) self::purgeUrl($url);
        // Also purge homepage and archives
        self::purgeUrl(home_url('/'));
    }

    public static function purgeUrl(string $url): void
    {
        $file = self::cacheFileForUrl($url);
        if (file_exists($file)) @unlink($file);
    }

    public static function purgeAll(): void
    {
        if (!is_dir(self::$cacheDir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }

    public static function getStats(): array
    {
        if (!is_dir(self::$cacheDir)) return ['files' => 0, 'size' => 0];
        $files = 0; $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$cacheDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { $files++; $size += $f->getSize(); }
        return ['files' => $files, 'size' => $size];
    }

    private static function shouldCache(): bool
    {
        if (!self::$enabled) return false;
        if (is_admin()) return false;
        if (is_user_logged_in()) return false;
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') return false;
        if (!empty($_GET)) return false; // Don't cache query strings
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return false;
        return true;
    }

    private static function cacheFile(): string
    {
        return self::cacheFileForUrl(
            (is_ssl() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
        );
    }

    private static function cacheFileForUrl(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'default';
        $path = trim($parsed['path'] ?? '/', '/') ?: 'index';
        return self::$cacheDir . "/{$host}/{$path}.html";
    }
}
