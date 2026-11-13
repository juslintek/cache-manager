<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge\Strategy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Gratis\Cache\Contracts\PurgeStrategyInterface;

final class NginxStrategy implements PurgeStrategyInterface {

    public function purge(): void {
        $dir = defined('GRATIS_CACHE_NGINX_CACHE') ? GRATIS_CACHE_NGINX_CACHE : '/var/cache/nginx/wordpress';
        if (!is_dir($dir) || !is_readable($dir)) {
            return;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $item) {
                try {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }

    public function type(): string {
        return 'nginx';
    }
}
