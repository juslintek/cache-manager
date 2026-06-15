<?php declare(strict_types=1);
namespace Gratis\Cache\Contracts\Cache;

/** Maps what cache entries depend on what resources. */
interface CacheDependencyMapperInterface {

    public function addDependency(string $cacheKey, string $dependsOn): void;
    public function getDependents(string $resource): array;
    public function removeDependency(string $cacheKey): void;
    public function clear(): void;
}
