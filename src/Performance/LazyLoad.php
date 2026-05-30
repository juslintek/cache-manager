<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * Lazy load iframes/videos + preload key resources.
 * What WP Rocket and Perfmatters charge for. Free in Gratis.
 */
final class LazyLoad
{
    public static function register(): void
    {
        if (!get_option('vlt_lazy_iframes', true)) return;

        // Lazy load iframes (YouTube, Vimeo, maps)
        add_filter('the_content', [__CLASS__, 'lazyIframes'], 99);
        add_filter('the_content', [__CLASS__, 'lazyVideos'], 99);

        // Preload key resources
        add_action('wp_head', [__CLASS__, 'preloadHints'], 3);
    }

    /** Add loading="lazy" to iframes that don't have it. */
    public static function lazyIframes(string $content): string
    {
        return preg_replace_callback('/<iframe(?![^>]*loading=)([^>]*)>/i', function ($m) {
            return '<iframe loading="lazy"' . $m[1] . '>';
        }, $content);
    }

    /** Wrap videos in a facade for deferred loading. */
    public static function lazyVideos(string $content): string
    {
        // Add loading="lazy" to video elements
        return preg_replace_callback('/<video(?![^>]*loading=)([^>]*)>/i', function ($m) {
            return '<video loading="lazy" preload="none"' . $m[1] . '>';
        }, $content);
    }

    /** Preload critical resources: fonts, LCP image. */
    public static function preloadHints(): void
    {
        // Preload theme fonts
        $fontsDir = get_template_directory() . '/assets/fonts';
        if (is_dir($fontsDir)) {
            $fonts = glob($fontsDir . '/*.{woff2,woff}', GLOB_BRACE) ?: glob($fontsDir . '/*.woff2') ?: [];
            foreach (array_slice($fonts, 0, 2) as $font) {
                $url = get_template_directory_uri() . '/assets/fonts/' . basename($font);
                $type = str_ends_with($font, '.woff2') ? 'font/woff2' : 'font/woff';
                echo '<link rel="preload" href="' . esc_url($url) . '" as="font" type="' . $type . '" crossorigin>' . "\n";
            }
        }
    }
}
