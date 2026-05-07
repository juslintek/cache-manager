<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

use VLT\CacheManager\Purge\PurgeManager;

/**
 * Targeted cache invalidation — hooks into every WordPress content change event.
 * Purges only affected URLs/tags instead of flushing everything.
 */
final class CacheInvalidator
{
    private PurgeManager $purge;

    public function __construct(PurgeManager $purge)
    {
        $this->purge = $purge;
    }

    public function register(): void
    {
        // ── Posts / Pages / CPTs ──────────────────────────────────────────────
        // Covers: publish, update, trash, delete, restore, future→publish
        foreach (['save_post', 'delete_post', 'trashed_post', 'untrashed_post', 'post_updated'] as $hook) {
            add_action($hook, [$this, 'onPostChange'], 10, 1);
        }
        // Attachment (image/file) changes
        add_action('add_attachment',    [$this, 'onPostChange']);
        add_action('edit_attachment',   [$this, 'onPostChange']);
        add_action('delete_attachment', [$this, 'onPostChange']);

        // ── Taxonomy terms ────────────────────────────────────────────────────
        add_action('created_term',  [$this, 'onTermChange'], 10, 3);
        add_action('edited_term',   [$this, 'onTermChange'], 10, 3);
        add_action('delete_term',   [$this, 'onTermChange'], 10, 3);

        // ── Comments ──────────────────────────────────────────────────────────
        add_action('comment_post',          [$this, 'onCommentChange'], 10, 2);
        add_action('edit_comment',          [$this, 'onCommentChange'], 10, 1);
        add_action('deleted_comment',       [$this, 'onCommentChange'], 10, 1);
        add_action('wp_set_comment_status', [$this, 'onCommentStatus'], 10, 2);
        add_action('spam_comment',          [$this, 'onCommentChange'], 10, 1);
        add_action('unspam_comment',        [$this, 'onCommentChange'], 10, 1);

        // ── Menus ─────────────────────────────────────────────────────────────
        add_action('wp_update_nav_menu',      [$this, 'onMenuChange']);
        add_action('wp_delete_nav_menu',      [$this, 'onMenuChange']);
        add_action('wp_update_nav_menu_item', [$this, 'onMenuChange']);

        // ── Widgets / Sidebars ────────────────────────────────────────────────
        add_action('update_option_sidebars_widgets', [$this, 'onWidgetChange']);
        add_action('widget_update_callback',         [$this, 'onWidgetChange']);

        // ── Options that affect all pages ─────────────────────────────────────
        foreach ([
            'blogname', 'blogdescription', 'siteurl', 'home',
            'permalink_structure', 'category_base', 'tag_base',
            'page_on_front', 'page_for_posts', 'posts_per_page',
        ] as $option) {
            add_action('update_option_' . $option, [$this, 'onGlobalOptionChange']);
        }

        // ── Theme / Customizer ────────────────────────────────────────────────
        add_action('switch_theme',         [$this, 'onGlobalChange']);
        add_action('customize_save_after', [$this, 'onGlobalChange']);
        add_action('after_switch_theme',   [$this, 'onGlobalChange']);

        // ── Plugin activation/deactivation ───────────────────────────────────
        add_action('activated_plugin',   [$this, 'onGlobalChange']);
        add_action('deactivated_plugin', [$this, 'onGlobalChange']);
        add_action('upgrader_process_complete', [$this, 'onUpgrade'], 10, 2);

        // ── User profile changes (author pages) ───────────────────────────────
        add_action('profile_update',  [$this, 'onUserChange']);
        add_action('user_register',   [$this, 'onUserChange']);
        add_action('delete_user',     [$this, 'onUserChange']);

        // ── WooCommerce (if active) ───────────────────────────────────────────
        add_action('woocommerce_product_set_stock',         [$this, 'onWooProduct']);
        add_action('woocommerce_variation_set_stock',       [$this, 'onWooProduct']);
        add_action('woocommerce_product_set_stock_status',  [$this, 'onWooProduct']);
        add_action('woocommerce_update_product',            [$this, 'onPostChange']);
        add_action('woocommerce_new_order',                 [$this, 'onWooOrder']);
        add_action('woocommerce_order_status_changed',      [$this, 'onWooOrder']);

        // ── Elementor ─────────────────────────────────────────────────────────
        add_action('elementor/core/files/clear_cache', [$this, 'onGlobalChange']);
        add_action('elementor/editor/after_save',      [$this, 'onElementorSave'], 10, 2);
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function onPostChange(int $postId): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $post = get_post($postId);
        if (!$post || !in_array($post->post_status, ['publish', 'trash', 'private'], true)) {
            return;
        }
        $this->purgePost($postId, $post);
    }

    public function onTermChange(int $termId, int $ttId, string $taxonomy): void
    {
        // Purge term archive + all posts in this term
        $this->purgeTermArchive($termId, $taxonomy);
    }

    public function onCommentChange(int $commentId): void
    {
        $comment = get_comment($commentId);
        if ($comment) {
            $this->purgePost((int) $comment->comment_post_ID);
        }
    }

    public function onCommentStatus(int $commentId, string $status): void
    {
        if (in_array($status, ['approve', 'hold', 'spam', 'trash'], true)) {
            $this->onCommentChange($commentId);
        }
    }

    public function onMenuChange(): void
    {
        // Menus appear on all pages — full purge but only page cache, not Redis object cache
        $this->purge->purge('nginx');
        $this->purge->purge('litespeed');
    }

    public function onWidgetChange(): void
    {
        $this->purge->purge('nginx');
        $this->purge->purge('litespeed');
    }

    public function onGlobalOptionChange(): void
    {
        $this->purge->purgeAll();
    }

    public function onGlobalChange(): void
    {
        $this->purge->purgeAll();
    }

    public function onUpgrade($upgrader, array $options): void
    {
        $this->purge->purge('opcache');
        $this->purge->purge('nginx');
        $this->purge->purge('litespeed');
        $this->purge->purge('elementor');
    }

    public function onUserChange(int $userId): void
    {
        // Purge author archive page
        $authorUrl = get_author_posts_url($userId);
        if ($authorUrl) {
            LiteSpeedCache::purgeUrl($authorUrl);
        }
        $this->purge->purge('nginx'); // nginx doesn't support URL-level purge
    }

    public function onWooProduct(int|\WC_Product $product): void
    {
        $id = is_object($product) ? $product->get_id() : $product;
        $this->purgePost($id);
    }

    public function onWooOrder(): void
    {
        // Order changes affect shop/cart pages
        $this->purge->purge('nginx');
        $this->purge->purge('litespeed');
    }

    public function onElementorSave(int $postId, array $data): void
    {
        $this->purgePost($postId);
        $this->purge->purge('elementor');
    }

    // ── Targeted purge helpers ────────────────────────────────────────────────

    private function purgePost(int $postId, ?\WP_Post $post = null): void
    {
        $post ??= get_post($postId);
        if (!$post) {
            return;
        }

        // LiteSpeed: purge by tag (post ID + home + archives)
        LiteSpeedCache::purgePost($postId);

        // Nginx: purge by URL (no tag support)
        $this->purge->purge('nginx');

        // Redis object cache: flush post-related groups
        $this->purgeRedisGroups(['posts', 'post_meta', 'terms', 'options']);

        // Elementor CSS if it's an Elementor page
        if (defined('ELEMENTOR_VERSION')) {
            $this->purge->purge('elementor');
        }

        // Purge related: home, category/tag archives
        $this->purgeRelated($post);
    }

    private function purgeTermArchive(int $termId, string $taxonomy): void
    {
        LiteSpeedCache::purgeTag('_T' . $termId);
        $this->purge->purge('nginx');
        $this->purgeRedisGroups(['terms', 'term_meta']);
    }

    private function purgeRelated(\WP_Post $post): void
    {
        // Purge home/front page
        LiteSpeedCache::purgeTag('_H');

        // Purge all taxonomy term archives this post belongs to
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post->ID, $tax);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    LiteSpeedCache::purgeTag('_T' . $term->term_id);
                }
            }
        }
    }

    private function purgeRedisGroups(array $groups): void
    {
        $r = \VLT\CacheManager\Redis\RedisFactory::create(0.5);
        if (!$r) {
            return;
        }
        foreach ($groups as $group) {
            $keys = $r->keys('vlt_' . $group . ':*');
            if ($keys) {
                $r->del(...$keys);
            }
        }
        $r->close();
    }
}
