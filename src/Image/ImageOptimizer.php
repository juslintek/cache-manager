<?php

declare(strict_types=1);

namespace VLT\CacheManager\Image;

/**
 * Local image optimization: converts JPEG/PNG to WebP (and optionally AVIF)
 * on upload, and serves WebP via the_content / wp_get_attachment_image filters.
 *
 * If LiteSpeed Cache plugin is active, delegates to its image optimization
 * instead of running locally.
 */
final class ImageOptimizer
{
    public static function register(): void
    {
        if (!get_option('vlt_img_optm_enabled')) {
            return;
        }

        // Hook into new uploads
        add_filter('wp_generate_attachment_metadata', [self::class, 'onUpload'], 10, 2);

        // Serve WebP in content if browser supports it
        if (get_option('vlt_img_optm_serve_webp')) {
            add_filter('the_content', [self::class, 'rewriteContentImages'], 20);
            add_filter('wp_get_attachment_image_src', [self::class, 'rewriteAttachmentSrc'], 10, 4);
        }
    }

    // ── Upload hook ──────────────────────────────────────────────────────────

    public static function onUpload(array $metadata, int $attachmentId): array
    {
        $file = get_attached_file($attachmentId);
        if (!$file || !self::isOptimizable($file)) {
            return $metadata;
        }

        // If LSCWP is active, let it handle optimization
        if (self::lscwpActive()) {
            return $metadata;
        }

        self::convertToWebP($file);

        // Convert all generated sizes too
        $uploadDir = wp_upload_dir();
        $baseDir   = $uploadDir['basedir'];
        foreach ($metadata['sizes'] ?? [] as $size) {
            $sizePath = $baseDir . '/' . ($size['file'] ?? '');
            if (file_exists($sizePath)) {
                self::convertToWebP($sizePath);
            }
        }

        return $metadata;
    }

    // ── Content rewriting ────────────────────────────────────────────────────

    public static function rewriteContentImages(string $content): string
    {
        if (!self::browserSupportsWebP()) {
            return $content;
        }

        return preg_replace_callback(
            '/<img([^>]+)src=["\']([^"\']+\.(jpe?g|png))["\']([^>]*)>/i',
            function (array $m) {
                $webp = self::webpPath($m[2]);
                if (!$webp) {
                    return $m[0];
                }
                // Wrap in <picture> for graceful fallback
                return '<picture>'
                    . '<source srcset="' . esc_attr($webp) . '" type="image/webp">'
                    . $m[0]
                    . '</picture>';
            },
            $content
        ) ?? $content;
    }

    public static function rewriteAttachmentSrc(array|false $image, int $attachmentId, mixed $size, bool $icon): array|false
    {
        if (!$image || !self::browserSupportsWebP()) {
            return $image;
        }
        $webp = self::webpPath($image[0]);
        if ($webp) {
            $image[0] = $webp;
        }
        return $image;
    }

    // ── Bulk optimization ────────────────────────────────────────────────────

    /** @return array{processed:int, skipped:int, errors:int} */
    public static function runBulk(int $limit = 50): array
    {
        if (self::lscwpActive()) {
            // Trigger LSCWP image optimization via its cron action
            do_action('litespeed_img_optm_new_req');
            return ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'delegated' => 'lscwp'];
        }

        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_vlt_webp_done',
                'compare' => 'NOT EXISTS',
            ]],
        ]);

        $uploadDir = wp_upload_dir();

        foreach ($attachments as $id) {
            $file = get_attached_file($id);
            if (!$file || !file_exists($file)) {
                $stats['skipped']++;
                continue;
            }
            $result = self::convertToWebP($file);
            if ($result) {
                update_post_meta($id, '_vlt_webp_done', 1);
                $stats['processed']++;

                // Convert sizes
                $meta = wp_get_attachment_metadata($id);
                foreach ($meta['sizes'] ?? [] as $size) {
                    $sizePath = $uploadDir['basedir'] . '/' . ($size['file'] ?? '');
                    if (file_exists($sizePath)) {
                        self::convertToWebP($sizePath);
                    }
                }
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /** @return array{total:int, optimized:int, pending:int, lscwp:bool, gd:bool, imagick:bool} */
    public static function status(): array
    {
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png','image/jpg')"
        );
        $optimized = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_vlt_webp_done'"
        );
        return [
            'total'     => $total,
            'optimized' => $optimized,
            'pending'   => max(0, $total - $optimized),
            'lscwp'     => self::lscwpActive(),
            'gd'        => function_exists('imagewebp'),
            'imagick'   => class_exists('Imagick') && in_array('WEBP', \Imagick::queryFormats(), true),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function convertToWebP(string $sourcePath): bool
    {
        $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $sourcePath);
        if (!$webpPath || file_exists($webpPath)) {
            return true; // already done
        }

        $quality = (int) get_option('vlt_img_optm_quality', 82);

        // Try Imagick first (better quality)
        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                $im->setImageFormat('WEBP');
                $im->setImageCompressionQuality($quality);
                $im->stripImage();
                $im->writeImage($webpPath);
                $im->destroy();
                return true;
            } catch (\Throwable) {
            }
        }

        // Fall back to GD
        if (!function_exists('imagewebp')) {
            return false;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
            'png'         => @imagecreatefrompng($sourcePath),
            default       => false,
        };

        if (!$src) {
            return false;
        }

        $ok = imagewebp($src, $webpPath, $quality);
        imagedestroy($src);
        return $ok;
    }

    private static function webpPath(string $url): string
    {
        $webpUrl = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);
        if (!$webpUrl || $webpUrl === $url) {
            return '';
        }
        // Verify the file exists on disk
        $uploadDir = wp_upload_dir();
        $localPath = str_replace($uploadDir['baseurl'], $uploadDir['basedir'], $webpUrl);
        return file_exists($localPath) ? $webpUrl : '';
    }

    private static function isOptimizable(string $path): bool
    {
        return (bool) preg_match('/\.(jpe?g|png)$/i', $path);
    }

    private static function browserSupportsWebP(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'image/webp');
    }

    private static function lscwpActive(): bool
    {
        return defined('LSCWP_V') || class_exists('LiteSpeed\Core') || class_exists('LiteSpeed_Cache');
    }
}
