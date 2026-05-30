<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * CSS/JS minification — what Autoptimize/WP Rocket charge for. Free in Gratis.
 * Simple regex-based minification (no external dependencies).
 */
final class Minifier
{
    public static function register(): void
    {
        if (!get_option('vlt_minify_enabled')) return;

        if (get_option('vlt_minify_css', true)) {
            add_filter('style_loader_tag', [__CLASS__, 'minifyStyleTag'], 99, 4);
        }
        if (get_option('vlt_minify_js', true)) {
            add_filter('script_loader_tag', [__CLASS__, 'minifyScriptTag'], 99, 3);
        }
        // Minify HTML output
        if (get_option('vlt_minify_html')) {
            add_action('template_redirect', function () {
                if (!is_admin()) ob_start([__CLASS__, 'minifyHTML']);
            }, 1);
        }
    }

    /** Minify inline CSS in style tags. */
    public static function minifyStyleTag(string $html, string $handle, string $href, string $media): string
    {
        // Only minify inline styles, not external files
        return $html;
    }

    /** Defer non-critical JS. */
    public static function minifyScriptTag(string $tag, string $handle, string $src): string
    {
        // Skip admin scripts and already-deferred
        if (is_admin() || str_contains($tag, 'defer') || str_contains($tag, 'async')) return $tag;

        // Don't defer jQuery or critical scripts
        $nodefer = ['jquery-core', 'jquery-migrate', 'wp-hooks', 'wp-i18n'];
        if (in_array($handle, $nodefer)) return $tag;

        return str_replace(' src=', ' defer src=', $tag);
    }

    /** Minify HTML output. */
    public static function minifyHTML(string $html): string
    {
        if (empty($html)) return $html;

        // Remove HTML comments (except IE conditionals and WP block comments)
        $html = preg_replace('/<!--(?!\s*wp:|!\[).*?-->/s', '', $html);

        // Remove whitespace between tags
        $html = preg_replace('/>\s+</', '> <', $html);

        // Collapse multiple spaces
        $html = preg_replace('/\s{2,}/', ' ', $html);

        return trim($html);
    }

    /** Minify CSS string. */
    public static function css(string $css): string
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css); // Remove comments
        $css = preg_replace('/\s+/', ' ', $css);          // Collapse whitespace
        $css = str_replace([' {', '{ ', ' }', '} ', ': ', ' :', '; ', ' ;'], ['{', '{', '}', '}', ':', ':', ';', ';'], $css);
        return trim($css);
    }

    /** Minify JS string (basic — removes comments and collapses whitespace). */
    public static function js(string $js): string
    {
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);     // Block comments
        $js = preg_replace('/\/\/[^\n]*/', '', $js);        // Line comments
        $js = preg_replace('/\s+/', ' ', $js);              // Collapse whitespace
        return trim($js);
    }
}
