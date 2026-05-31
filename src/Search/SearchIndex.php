<?php declare(strict_types=1);
namespace VLT\CacheManager\Search;

/**
 * Local search indexing — what Elasticsearch/Algolia charge $29-99/mo for.
 * Uses SQLite FTS5 for full-text search with relevance ranking.
 * Free in Gratis.
 */
final class SearchIndex
{
    private static ?\SQLite3 $db = null;

    public static function register(): void
    {
        if (!extension_loaded('sqlite3')) return;

        // Re-index on post save
        add_action('save_post', [__CLASS__, 'indexPost'], 20, 2);
        add_action('delete_post', [__CLASS__, 'removePost']);

        // Override default WordPress search
        if (get_option('vlt_search_index_enabled')) {
            add_filter('posts_search', [__CLASS__, 'interceptSearch'], 10, 2);
            add_filter('posts_pre_query', [__CLASS__, 'searchQuery'], 10, 2);
        }
    }

    public static function db(): \SQLite3
    {
        if (self::$db) return self::$db;
        $dir = WP_CONTENT_DIR . '/gratis-search';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        self::$db = new \SQLite3($dir . '/index.db');
        self::$db->exec('PRAGMA journal_mode=WAL');
        self::$db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(post_id, title, content, post_type, tokenize="porter unicode61")');
        return self::$db;
    }

    public static function indexPost(int $postId, \WP_Post $post): void
    {
        if ($post->post_status !== 'publish') { self::removePost($postId); return; }
        if (in_array($post->post_type, ['revision', 'nav_menu_item', 'gratis_form_entry'])) return;

        $db = self::db();
        // Remove existing entry
        $db->exec("DELETE FROM search_index WHERE post_id = {$postId}");
        // Insert new
        $stmt = $db->prepare('INSERT INTO search_index (post_id, title, content, post_type) VALUES (:id, :title, :content, :type)');
        $stmt->bindValue(':id', $postId);
        $stmt->bindValue(':title', $post->post_title);
        $stmt->bindValue(':content', strip_tags($post->post_content));
        $stmt->bindValue(':type', $post->post_type);
        $stmt->execute();
    }

    public static function removePost(int $postId): void
    {
        self::db()->exec("DELETE FROM search_index WHERE post_id = {$postId}");
    }

    /** Search the index. Returns array of [post_id, rank]. */
    public static function search(string $query, int $limit = 20): array
    {
        $db = self::db();
        $stmt = $db->prepare("SELECT post_id, rank FROM search_index WHERE search_index MATCH :q ORDER BY rank LIMIT :limit");
        $stmt->bindValue(':q', $query);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $results = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }
        return $results;
    }

    /** Override WP search with our index. */
    public static function searchQuery(?array $posts, \WP_Query $query): ?array
    {
        if (!$query->is_search() || is_admin()) return $posts;

        $term = $query->get('s');
        if (empty($term)) return $posts;

        $results = self::search($term, $query->get('posts_per_page') ?: 20);
        if (empty($results)) return $posts;

        $ids = array_column($results, 'post_id');
        $query->set('s', ''); // Disable default search
        $query->set('post__in', $ids);
        $query->set('orderby', 'post__in');

        return null; // Let WP run the modified query
    }

    public static function interceptSearch(string $search, \WP_Query $query): string
    {
        if ($query->is_search() && !is_admin() && get_option('vlt_search_index_enabled')) {
            return ''; // We handle search via posts_pre_query
        }
        return $search;
    }

    /** Rebuild the entire index. */
    public static function rebuild(): int
    {
        $db = self::db();
        $db->exec('DELETE FROM search_index');

        $posts = get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1]);
        $count = 0;
        foreach ($posts as $post) {
            if (in_array($post->post_type, ['revision', 'nav_menu_item', 'gratis_form_entry'])) continue;
            self::indexPost($post->ID, $post);
            $count++;
        }
        return $count;
    }
}
