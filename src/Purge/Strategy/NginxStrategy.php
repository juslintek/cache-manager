<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge\Strategy;

use FilesystemIterator;
use Gratis\Cache\Contracts\PurgeStrategyInterface;

final class NginxStrategy implements PurgeStrategyInterface {

    public function purge(): void {
        $dir = defined('GRATIS_CACHE_NGINX_CACHE') ? GRATIS_CACHE_NGINX_CACHE : '/var/cache/nginx/wordpress';
        if (!is_dir($dir)) {
            return;
        }
        if (!is_readable($dir)) {
            throw new \RuntimeException('Nginx cache directory is not readable: ' . esc_html($dir));
        }

        $failures = 0;
        $this->purgeDir($dir, $failures, false);
        if ($failures > 0) {
            throw new \RuntimeException('Incomplete Nginx cache purge: ' . (int) $failures . ' item(s) could not be removed or read.');
        }
    }

    private function purgeDir(string $dir, int &$failures, bool $removeSelf): void {
        if (!is_readable($dir)) {
            ++$failures;
            return;
        }
        try {
            $it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
            foreach ($it as $item) {
                $path = $item->getPathname();
                if ($item->isDir() && !$item->isLink()) {
                    $this->purgeDir($path, $failures, true);
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct Nginx FastCGI cache file deletion outside WP_Filesystem.
                } elseif (!@unlink($path) && file_exists($path)) {
                    ++$failures;
                }
            }
        } catch (\Throwable) {
            ++$failures;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct Nginx FastCGI cache directory removal outside WP_Filesystem.
        if ($removeSelf && !@rmdir($dir) && is_dir($dir)) {
            ++$failures;
        }
    }

    public function type(): string {
        return 'nginx';
    }
}
