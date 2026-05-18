<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Storage;

/** Hot cache layer (Redis, Memcached, APCu). May lose data on restart. */
interface VolatileCacheBackendInterface extends StorageInterface
{
    public function isAvailable(): bool;
    public function info(): array;
}
