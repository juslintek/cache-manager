<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * DNS prefetch + preconnect for external resources.
 * Scans enqueued scripts/styles and adds resource hints automatically.
 */
final class ResourceHints
{
    public static function register(): void
    {
        if (!get_option('vlt_resource_hints_enabled', true)) return;
        add_action('wp_head', [__CLASS__, 'output'], 2);
    }

    public static function output(): void
    {
        $domains = self::detectExternalDomains();
        $siteHost = parse_url(home_url(), PHP_URL_HOST);

        foreach ($domains as $domain) {
            if ($domain === $siteHost) continue;
            echo '<link rel="dns-prefetch" href="//' . esc_attr($domain) . '">' . "\n";
            echo '<link rel="preconnect" href="https://' . esc_attr($domain) . '" crossorigin>' . "\n";
        }
    }

    private static function detectExternalDomains(): array
    {
        $domains = [];

        // Common CDNs and services
        $known = [
            'fonts.googleapis.com', 'fonts.gstatic.com',
            'cdn.jsdelivr.net', 'cdnjs.cloudflare.com',
            'www.google-analytics.com', 'www.googletagmanager.com',
            'connect.facebook.net', 'platform.twitter.com',
        ];

        // Check enqueued styles
        global $wp_styles, $wp_scripts;
        $siteHost = parse_url(home_url(), PHP_URL_HOST);

        if ($wp_styles) {
            foreach ($wp_styles->registered as $style) {
                if (!$style->src) continue;
                $host = parse_url($style->src, PHP_URL_HOST);
                if ($host && $host !== $siteHost) $domains[] = $host;
            }
        }

        if ($wp_scripts) {
            foreach ($wp_scripts->registered as $script) {
                if (!$script->src) continue;
                $host = parse_url($script->src, PHP_URL_HOST);
                if ($host && $host !== $siteHost) $domains[] = $host;
            }
        }

        return array_unique(array_merge($domains, array_intersect($known, $domains)));
    }
}
