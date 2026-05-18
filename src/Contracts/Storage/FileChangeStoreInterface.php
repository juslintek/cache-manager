<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Storage;

/** Tracks filesystem changes for cache invalidation. */
interface FileChangeStoreInterface
{
    public function recordChange(string $path, string $type, int $timestamp): void;
    public function changesSince(int $timestamp): array;
    public function lastChange(?string $pathPrefix = null): ?array;
}
