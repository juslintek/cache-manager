<?php declare(strict_types=1);
namespace VLT\CacheManager\Performance;

/**
 * Database optimization — what WP-Optimize charges $49/yr for. Free in Gratis.
 * Cleans revisions, transients, spam, trash, and optimizes tables.
 */
final class DatabaseOptimizer
{
    /** Run all optimizations and return stats. */
    public static function optimize(array $options = []): array
    {
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
    public static function cleanRevisions(int $keep = 5): int
    {
        global $wpdb;
        $deleted = 0;

        $posts = $wpdb->get_col("SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0");
        foreach ($posts as $post_id) {
            $revisions = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC",
                $post_id
            ));
            $to_delete = array_slice($revisions, $keep);
            foreach ($to_delete as $rev_id) {
                wp_delete_post_revision($rev_id);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Delete expired transients. */
    public static function cleanTransients(): int
    {
        global $wpdb;
        $time = time();
        $expired = $wpdb->query(
            "DELETE a, b FROM {$wpdb->options} a
             INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
             WHERE a.option_name LIKE '_transient_%'
             AND b.option_name LIKE '_transient_timeout_%'
             AND CAST(b.option_value AS UNSIGNED) < {$time}"
        );
        return (int) $expired;
    }

    /** Delete spam comments. */
    public static function cleanSpam(): int
    {
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        if ($count > 0) {
            $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        }
        return $count;
    }

    /** Empty trash (posts + comments older than 30 days). */
    public static function cleanTrash(): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $posts = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified < %s", $cutoff
        ));
        $comments = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s", $cutoff
        ));
        return $posts + $comments;
    }

    /** Delete orphaned post/comment/term meta. */
    public static function cleanOrphanMeta(): int
    {
        global $wpdb;
        $pm = (int) $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        $cm = (int) $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
        return $pm + $cm;
    }

    /** Run OPTIMIZE TABLE on all WP tables. */
    public static function optimizeTables(): int
    {
        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE `{$table}`");
        }
        return count($tables);
    }

    /** Get database size stats. */
    public static function getStats(): array
    {
        global $wpdb;
        return [
            'total_size'  => $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()"),
            'revisions'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
            'transients'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'"),
            'spam'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
            'trash_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
            'tables'      => count($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'")),
        ];
    }
}
