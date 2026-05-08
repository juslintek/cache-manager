<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

use VLT\CacheManager\Redis\RedisFactory;

/**
 * Smart purge: tracks Redis key metadata (source, state, version hash).
 * Purge only invalidates keys whose SOURCE has changed.
 * STATE keys (counters, sessions, transient data) are excluded from purge
 * unless explicitly updated.
 *
 * Key types:
 *   source  — page/post content, theme, options → purge on content change
 *   state   — counters, view counts, user sessions → never purge on cache clear
 *   exclude — explicitly excluded from all purges
 */
final class SmartPurge
{
    private const META_PREFIX = 'vlt_meta:';
    private const EXCL_KEY    = 'vlt_purge_exclude';

    // ── Tag a key with metadata ───────────────────────────────────────────────

    /**
     * Tag a Redis key with its type and a version hash.
     * Call this when setting a cache value.
     *
     * @param string $key     Redis key (without prefix)
     * @param string $type    'source' | 'state' | 'exclude'
     * @param string $version Hash of the source data (e.g. md5 of post content)
     */
    public static function tag(string $key, string $type = 'source', string $version = ''): void
    {
        $r = RedisFactory::create(0.2);
        if (!$r) {
            return;
        }
        $r->hMSet(self::META_PREFIX . $key, [
            'type'    => $type,
            'version' => $version ?: md5($key . time()),
            'ts'      => time(),
        ]);
        $r->expire(self::META_PREFIX . $key, 86400 * 7);

        if ($type === 'exclude') {
            $r->sAdd(self::EXCL_KEY, $key);
        }
        $r->close();
    }

    /**
     * Check if a key's source has changed (new version != stored version).
     * Returns true if the key should be purged.
     */
    public static function hasChanged(string $key, string $newVersion): bool
    {
        $r = RedisFactory::create(0.2);
        if (!$r) {
            return true; // Assume changed if Redis unavailable
        }
        $meta = $r->hGetAll(self::META_PREFIX . $key);
        $r->close();

        if (empty($meta)) {
            return true; // No metadata = treat as changed
        }
        if (($meta['type'] ?? 'source') === 'state') {
            return false; // State keys never purge
        }
        return ($meta['version'] ?? '') !== $newVersion;
    }

    /**
     * Get all keys that should be excluded from purge.
     */
    public static function excludedKeys(): array
    {
        $r = RedisFactory::create(0.2);
        if (!$r) {
            return [];
        }
        $keys = $r->sMembers(self::EXCL_KEY) ?: [];
        $r->close();
        return $keys;
    }

    /**
     * Smart Redis group purge — skips state and excluded keys.
     * Marks purged keys with a 'purge_reason' in metadata.
     */
    public static function purgeGroup(string $group, string $reason = 'cache_clear'): int
    {
        $r = RedisFactory::create(0.5);
        if (!$r) {
            return 0;
        }

        $excluded = self::excludedKeys();
        $keys     = $r->keys('vlt_' . $group . ':*');
        $purged   = 0;

        foreach ($keys as $fullKey) {
            // Strip the vlt_ prefix to get the bare key for metadata lookup
            $bareKey = substr($fullKey, 4);

            // Skip excluded keys
            if (in_array($bareKey, $excluded, true)) {
                continue;
            }

            // Check metadata
            $meta = $r->hGetAll(self::META_PREFIX . $bareKey);
            $type = $meta['type'] ?? 'source';

            if ($type === 'state' || $type === 'exclude') {
                continue; // Never purge state/excluded keys
            }

            $r->del($fullKey);
            $r->del(self::META_PREFIX . $bareKey);
            $purged++;
        }

        // Log purge event with reason
        $r->lPush('vlt_purge_events', json_encode([
            'group'  => $group,
            'reason' => $reason,
            'purged' => $purged,
            'ts'     => time(),
        ]));
        $r->lTrim('vlt_purge_events', 0, 499);
        $r->close();

        return $purged;
    }

    /**
     * Mark a key as updated — this signals that a purge IS needed.
     * Called when content actually changes (post save, option update, etc.)
     */
    public static function markUpdated(string $key, string $newVersion = ''): void
    {
        $r = RedisFactory::create(0.2);
        if (!$r) {
            return;
        }
        $r->hMSet(self::META_PREFIX . $key, [
            'type'    => 'source',
            'version' => $newVersion ?: md5($key . time()),
            'ts'      => time(),
            'updated' => '1',
        ]);
        $r->expire(self::META_PREFIX . $key, 86400 * 7);
        $r->close();
    }

    /**
     * Get purge event log.
     */
    public static function purgeEvents(int $limit = 50): array
    {
        $r = RedisFactory::create(0.2);
        if (!$r) {
            return [];
        }
        $raw    = $r->lRange('vlt_purge_events', 0, $limit - 1);
        $decode = function_exists('simdjson_decode') ? 'simdjson_decode' : 'json_decode';
        $r->close();
        return array_map(fn($j) => $decode($j, true), $raw);
    }
}
