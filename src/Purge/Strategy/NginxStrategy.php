<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class NginxStrategy implements PurgeStrategyInterface
{
    private const CACHE_DIR = '/var/cache/nginx/wordpress';

    public function purge(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            return;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::CACHE_DIR, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $item) {
                try {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function type(): string
    {
        return 'nginx';
    }
}
