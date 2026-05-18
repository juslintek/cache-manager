<?php declare(strict_types=1);
namespace VLT\CacheManager\Storage;

use RuntimeException;
use SQLite3;
use SQLite3Stmt;
use VLT\CacheManager\Contracts\Storage\PersistentStoreInterface;

/** SQLite WAL-mode persistent store for concurrent-read workloads. */
final class SqliteWalStore implements PersistentStoreInterface
{
    private SQLite3 $db;

    public function __construct(string $path = '')
    {
        if (!extension_loaded('sqlite3')) {
            throw new RuntimeException('ext-sqlite3 is required for SqliteWalStore but is not loaded.');
        }

        if ($path === '') {
            $path = WP_CONTENT_DIR . '/cache-manager-data/store.sqlite';
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->db = new SQLite3($path);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS kv (
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expires_at INTEGER NOT NULL DEFAULT 0
            )'
        );
    }

    public function get(string $key): mixed
    {
        $this->gc($key);
        $stmt = $this->db->prepare('SELECT value FROM kv WHERE key = :k AND (expires_at = 0 OR expires_at > :t)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_NUM);
        return $result ? unserialize($result[0]) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO kv (key, value, expires_at) VALUES (:k, :v, :e)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', serialize($value), SQLITE3_BLOB);
        $stmt->bindValue(':e', $expires, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function delete(string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM kv WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    public function has(string $key): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM kv WHERE key = :k AND (expires_at = 0 OR expires_at > :t)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
    }

    public function flush(): bool
    {
        return $this->db->exec('DELETE FROM kv');
    }

    public function append(string $key, mixed $value): bool
    {
        $existing = $this->get($key);
        if (is_array($existing)) {
            $existing[] = $value;
        } elseif ($existing === null) {
            $existing = [$value];
        } else {
            $existing = [$existing, $value];
        }
        return $this->set($key, $existing);
    }

    public function scan(string $prefix): iterable
    {
        $stmt = $this->db->prepare('SELECT key, value FROM kv WHERE key LIKE :p AND (expires_at = 0 OR expires_at > :t)');
        $stmt->bindValue(':p', $this->escapeLike($prefix) . '%', SQLITE3_TEXT);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            yield $row[0] => unserialize($row[1]);
        }
    }

    public function size(): int
    {
        $result = $this->db->querySingle('SELECT COUNT(*) FROM kv WHERE expires_at = 0 OR expires_at > ' . time());
        return (int) $result;
    }

    private function gc(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM kv WHERE key = :k AND expires_at > 0 AND expires_at <= :t');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $s);
    }
}
