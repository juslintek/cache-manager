<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

/**
 * Native LiteSpeed/OpenLiteSpeed cache control.
 * Communicates with the server via X-LiteSpeed-Cache-Control response headers —
 * the same mechanism LSCWP uses, but built-in, no external plugin needed.
 *
 * Header reference: https://docs.litespeedtech.com/lscache/devguide/headers/
 */
final class LiteSpeedCache
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || !get_option('vlt_ls_cache_enabled', true)) {
            return;
        }
        self::$registered = true;

        // Mark pages cacheable on the wp hook (same as LSCWP)
        add_action('wp', [self::class, 'markCacheable'], 5);

        // Never cache logged-in users or pages with active sessions
        add_action('wp', [self::class, 'checkNocache'], 10);

        // Purge on content changes
        add_action('save_post',    [self::class, 'purgePost'], 10, 1);
        add_action('delete_post',  [self::class, 'purgePost'], 10, 1);
        add_action('comment_post', [self::class, 'purgeOnComment'], 10, 2);

        // Send cache headers at shutdown
        add_action('shutdown', [self::class, 'sendHeaders'], 0);
    }

    // ── Cache control ─────────────────────────────────────────────────────────

    public static function markCacheable(): void
    {
        // Don't cache if already marked no-cache
        if (self::isNocache()) {
            return;
        }

        // Don't cache logged-in users (unless option enabled)
        if (is_user_logged_in() && !get_option('vlt_ls_cache_logged_in', false)) {
            self::setNocache('logged-in user');
            return;
        }

        // Don't cache password-protected posts
        if (post_password_required()) {
            self::setNocache('password protected');
            return;
        }

        // Don't cache search results (unless option enabled)
        if (is_search() && !get_option('vlt_ls_cache_search', false)) {
            self::setNocache('search page');
            return;
        }

        // Don't cache 404 pages (unless option enabled)
        if (is_404() && !get_option('vlt_ls_cache_404', false)) {
            self::setNocache('404 page');
            return;
        }

        self::setCacheable();
    }

    public static function checkNocache(): void
    {
        // WooCommerce: never cache cart, checkout, account
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            self::setNocache('woocommerce dynamic page');
        }

        // Active WP session cookie
        foreach (array_keys($_COOKIE) as $cookie) {
            if (str_starts_with($cookie, 'wordpress_logged_in_') || str_starts_with($cookie, 'woocommerce_')) {
                self::setNocache('session cookie: ' . $cookie);
                return;
            }
        }
    }

    // ── Header sending ────────────────────────────────────────────────────────

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $ttl = (int) get_option('vlt_ls_cache_ttl', 86400);

        if (self::isCacheable()) {
            // Tell LiteSpeed to cache this response
            header('X-LiteSpeed-Cache-Control: public,max-age=' . $ttl);

            // Add cache tags for targeted purging (post ID, post type, home)
            $tags = self::buildTags();
            if ($tags) {
                header('X-LiteSpeed-Tag: ' . implode(',', $tags));
            }
        } else {
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    // ── Purge ─────────────────────────────────────────────────────────────────

    public static function purgePost(int $postId): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        // Purge by tag: post ID + home + archives
        $tags = ['_P' . $postId, '_H', '_A'];
        self::sendPurgeHeader(implode(',', $tags));
    }

    public static function purgeOnComment(int $commentId, $approved): void
    {
        if ($approved !== 1) {
            return;
        }
        $comment = get_comment($commentId);
        if ($comment) {
            self::purgePost((int) $comment->comment_post_ID);
        }
    }

    public static function purgeAll(): void
    {
        self::sendPurgeHeader('*');
    }

    private static function sendPurgeHeader(string $tags): void
    {
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: ' . $tags);
        } else {
            // Loopback request to trigger purge
            wp_remote_get(home_url('/'), [
                'timeout'   => 3,
                'headers'   => ['X-LiteSpeed-Purge' => $tags],
                'sslverify' => false,
            ]);
        }
    }

    // ── Cache tags ────────────────────────────────────────────────────────────

    private static function buildTags(): array
    {
        $tags = [];
        if (is_singular()) {
            $tags[] = '_P' . get_the_ID();
            $tags[] = '_PT.' . get_post_type();
        }
        if (is_home() || is_front_page()) {
            $tags[] = '_H';
        }
        if (is_archive() || is_category() || is_tag()) {
            $tags[] = '_A';
        }
        return $tags;
    }

    // ── State ─────────────────────────────────────────────────────────────────

    private static ?bool $cacheable = null;

    public static function setCacheable(): void  { self::$cacheable = true; }
    public static function setNocache(string $reason = ''): void { self::$cacheable = false; }
    public static function isCacheable(): bool   { return self::$cacheable === true; }
    public static function isNocache(): bool     { return self::$cacheable === false; }
}
