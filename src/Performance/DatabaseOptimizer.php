<?php declare(strict_types=1);
namespace Gratis\Cache\Performance;

defined('ABSPATH') || exit;
/**
 * Database optimization — what WP-Optimize charges $49/yr for. Free in Gratis.
 * Cleans revisions, transients, spam, trash, and optimizes tables.
 */
final class DatabaseOptimizer {

    /** Run all optimizations and return stats. */
    public static function optimize(array $options = []): array {
        global $wpdb;
        $stats = [];

        if ($options['revisions'] ?? true) {
            $keep = (int) ($options['keep_revisions'] ?? 5);
            $stats['revisions'] = self::cleanRevisions($keep);
        }

        if ($options['transients'] ?? true) {
            $stats['transients'] = self::cleanTransients();
        }

        if ($options['spam'] ?? true) {
            $stats['spam'] = self::cleanSpam();
        }

        if ($options['trash'] ?? true) {
            $stats['trash'] = self::cleanTrash();
        }

        if ($options['orphans'] ?? true) {
            $stats['orphan_meta'] = self::cleanOrphanMeta();
        }

        if ($options['optimize_tables'] ?? true) {
            $stats['tables_optimized'] = self::optimizeTables();
        }

        return $stats;
    }

    /** Delete old revisions, keeping N most recent per post. */
    public static function cleanRevisions(int $keep = 5): int {
        global $wpdb;
        $deleted = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $posts = $wpdb->get_col("SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0");
        foreach ($posts as $post_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
            $revisions = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
                $post_id
            ));
            $to_delete = array_slice($revisions, $keep);
            foreach ($to_delete as $rev_id) {
                wp_delete_post_revision($rev_id);
                ++$deleted;
            }
        }
        wp_cache_delete('gratis_cache_db_stats', 'gratis_cache');
        return $deleted;
    }

    /** Delete expired transients (compatible with both MySQL and SQLite). */
    public static function cleanTransients(): int {
        global $wpdb;
        $time    = time();
        $prefix  = '_transient_timeout_';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $expired = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND CAST(option_value AS UNSIGNED) < %d",
                $wpdb->esc_like($prefix) . '%',
                $time
            )
        );
        if (empty($expired)) {
            return 0;
        }

        $names = [];
        foreach ($expired as $timeout_name) {
            $names[] = (string) $timeout_name;
            $names[] = '_transient_' . substr((string) $timeout_name, strlen($prefix));
        }

        $deleted = 0;
        foreach (array_chunk($names, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic %s placeholder list matches chunk count.
            $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", $chunk));
        }
        wp_cache_delete('gratis_cache_db_stats', 'gratis_cache');
        return $deleted;
    }

    /** Delete spam comments. */
    public static function cleanSpam(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        if ($count > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
            $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
            wp_cache_delete('gratis_cache_db_stats', 'gratis_cache');
        }
        return $count;
    }

    /** Empty trash (posts + comments older than 30 days). */
    public static function cleanTrash(): int {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $posts = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified < %s", $cutoff
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $comments = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s", $cutoff
        ));
        wp_cache_delete('gratis_cache_db_stats', 'gratis_cache');
        return $posts + $comments;
    }

    /** Delete orphaned post/comment/term meta. */
    public static function cleanOrphanMeta(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $pm = (int) $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin maintenance cleanup.
        $cm = (int) $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
        return $pm + $cm;
    }

    /** Run OPTIMIZE TABLE on all WP tables. */
    public static function optimizeTables(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin table optimization.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin table optimization.
            $wpdb->query($wpdb->prepare('OPTIMIZE TABLE %i', $table));
        }
        return count($tables);
    }

    /** Get database size stats. */
    public static function getStats(): array {
        $cached = wp_cache_get('gratis_cache_db_stats', 'gratis_cache');
        if (is_array($cached)) {
            return $cached;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $total_size = $wpdb->get_var('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $transients = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_') . '%'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $spam = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $trash_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached aggregate DB telemetry.
        $tables = count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')));
        $stats = [
            'total_size'  => $total_size,
            'revisions'   => $revisions,
            'transients'  => $transients,
            'spam'        => $spam,
            'trash_posts' => $trash_posts,
            'tables'      => $tables,
        ];
        wp_cache_set('gratis_cache_db_stats', $stats, 'gratis_cache', 60);
        return $stats;
    }
}
