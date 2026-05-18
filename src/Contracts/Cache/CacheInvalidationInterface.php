<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Cache;

interface CacheInvalidationInterface
{
    public function invalidate(string $tag): void;
    public function invalidateUrl(string $url, string $reason = ''): void;
    public function invalidateAll(string $reason = ''): void;
}
