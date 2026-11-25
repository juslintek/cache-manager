<?php declare(strict_types=1);
namespace Gratis\Cache\Contracts\Cache;

defined('ABSPATH') || exit;
interface CacheInvalidationInterface {

    public function invalidate(string $tag): void;
    public function invalidateUrl(string $url, string $reason = ''): void;
    public function invalidateAll(string $reason = ''): void;
}
