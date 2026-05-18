<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Backup;

/** Stream-first backup source. Never loads full backup into memory. */
interface BackupSourceInterface
{
    public function name(): string;
    /** @return \Generator<string> Yields chunks of backup data */
    public function stream(): \Generator;
    public function estimatedSize(): int;
    public function manifest(): array;
}
