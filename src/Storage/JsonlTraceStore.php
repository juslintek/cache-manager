<?php declare(strict_types=1);
namespace VLT\CacheManager\Storage;

use VLT\CacheManager\Contracts\Storage\TraceStoreInterface;

/** Append-only JSONL file store for traces and history. */
final class JsonlTraceStore implements TraceStoreInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
    }

    public function record(string $channel, array $event): void
    {
        $event['_ts'] = $event['_ts'] ?? time();
        $line = json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->file($channel), $line, FILE_APPEND | LOCK_EX);
    }

    public function tail(string $channel, int $limit = 50): array
    {
        $file = $this->file($channel);
        if (!file_exists($file)) return [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map('json_decode', array_slice($lines, -$limit), array_fill(0, min(count($lines), $limit), true));
    }

    public function since(string $channel, int $timestamp): array
    {
        return array_filter($this->tail($channel, 1000), fn($e) => ($e['_ts'] ?? 0) >= $timestamp);
    }

    public function prune(string $channel, int $olderThan): int
    {
        $file = $this->file($channel);
        if (!file_exists($file)) return 0;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $kept = [];
        $pruned = 0;
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (($e['_ts'] ?? 0) >= $olderThan) $kept[] = $line;
            else $pruned++;
        }
        file_put_contents($file, implode("\n", $kept) . ($kept ? "\n" : ""), LOCK_EX);
        return $pruned;
    }

    private function file(string $channel): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_-]/', '_', $channel) . '.jsonl';
    }
}
