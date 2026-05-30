<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * Critical CSS extraction — inlines above-the-fold CSS and defers the rest.
 * What WP Rocket charges $59/yr for. Free in Gratis.
 */
final class CriticalCSS
{
    private static string $cacheDir;

    public static function register(): void
    {
        if (!get_option('vlt_critical_css_enabled')) return;

        self::$cacheDir = WP_CONTENT_DIR . '/cache/critical-css';
        if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0755, true);

        add_action('wp_head', [__CLASS__, 'inlineCritical'], 1);
        add_filter('style_loader_tag', [__CLASS__, 'deferStylesheet'], 10, 4);
    }

    /** Inline critical CSS in <head> if cached. */
    public static function inlineCritical(): void
    {
        $key = self::cacheKey();
        $file = self::$cacheDir . "/{$key}.css";
        if (!file_exists($file)) return;

        $css = file_get_contents($file);
        if ($css) {
            echo "<style id=\"gratis-critical-css\">{$css}</style>\n";
        }
    }

    /** Convert render-blocking stylesheets to async loading. */
    public static function deferStylesheet(string $html, string $handle, string $href, string $media): string
    {
        // Don't defer admin styles or critical CSS itself
        if (is_admin() || str_contains($handle, 'critical')) return $html;

        $key = self::cacheKey();
        $file = self::$cacheDir . "/{$key}.css";
        if (!file_exists($file)) return $html; // Only defer if we have critical CSS

        // Convert to async loading with print media swap
        return str_replace(
            "media='{$media}'",
            "media='print' onload=\"this.media='{$media}'\"",
            $html
        ) . "<noscript>{$html}</noscript>\n";
    }

    /**
     * Generate critical CSS for a URL (called via WP-CLI or cron).
     * Extracts CSS rules that match above-the-fold elements.
     */
    public static function generate(string $url): string
    {
        // Fetch the page HTML
        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) return '';

        $html = wp_remote_retrieve_body($response);

        // Collect all stylesheet URLs
        preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\']/', $html, $matches);
        $stylesheets = $matches[1] ?? [];

        // Fetch and combine all CSS
        $allCSS = '';
        foreach ($stylesheets as $sheetUrl) {
            if (str_starts_with($sheetUrl, '/')) {
                $sheetUrl = home_url($sheetUrl);
            }
            $cssResponse = wp_remote_get($sheetUrl, ['timeout' => 5]);
            if (!is_wp_error($cssResponse)) {
                $allCSS .= wp_remote_retrieve_body($cssResponse) . "\n";
            }
        }

        // Extract critical rules: keep selectors that match common above-fold elements
        $critical = self::extractCritical($allCSS);

        // Cache it
        $key = self::cacheKeyForUrl($url);
        if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0755, true);
        file_put_contents(self::$cacheDir . "/{$key}.css", $critical, LOCK_EX);

        return $critical;
    }

    private static function extractCritical(string $css): string
    {
        // Keep: body, html, header, nav, h1-h3, .wp-block-cover, .site-title, above-fold patterns
        $critical = '';
        $aboveFold = '/^(html|body|\*|:root|header|nav|h[1-3]|\.wp-block-cover|\.wp-block-navigation|\.wp-block-site-title|\.gratis-header|\.wp-block-group|\.has-text-align-center|\.wp-block-heading|\.wp-block-button|\.alignfull)/i';

        // Simple rule extraction (no full CSS parser needed for 80% case)
        preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            $selector = trim($rule[1]);
            if (preg_match($aboveFold, $selector)) {
                $critical .= $selector . '{' . trim($rule[2]) . "}\n";
            }
        }

        // Also keep @font-face rules
        preg_match_all('/@font-face\s*\{[^}]+\}/', $css, $fontFaces);
        $critical = implode("\n", $fontFaces[0] ?? []) . "\n" . $critical;

        // Keep CSS custom properties from :root
        preg_match_all('/:root\s*\{[^}]+\}/', $css, $roots);
        $critical = implode("\n", $roots[0] ?? []) . "\n" . $critical;

        return trim($critical);
    }

    private static function cacheKey(): string
    {
        if (is_front_page()) return 'front';
        if (is_singular()) return 'singular-' . get_post_type();
        if (is_archive()) return 'archive';
        return 'default';
    }

    private static function cacheKeyForUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if ($path === '/') return 'front';
        return sanitize_file_name(trim($path, '/')) ?: 'default';
    }
}
