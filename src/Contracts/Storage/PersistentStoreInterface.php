<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Storage;

/** Durable local store (SQLite, file segments). Survives restarts. */
interface PersistentStoreInterface extends StorageInterface
{
    public function append(string $key, mixed $value): bool;
    public function scan(string $prefix): iterable;
    public function size(): int;
}
