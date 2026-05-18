<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Storage;

/** Append-only event/trace log store. */
interface TraceStoreInterface
{
    public function record(string $channel, array $event): void;
    public function tail(string $channel, int $limit = 50): array;
    public function since(string $channel, int $timestamp): array;
    public function prune(string $channel, int $olderThan): int;
}
