<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;
use VLT\CacheManager\Log\Logger;

final class PurgeManager
{
    private Logger $logger;
    /** @var PurgeStrategyInterface[] */
    private array $strategies = [];
    private array $purged = [];

    public function __construct(Logger $logger, PurgeStrategyInterface ...$strategies)
    {
        $this->logger = $logger;
        foreach ($strategies as $s) {
            $this->strategies[$s->type()] = $s;
        }
    }

    public function purge(string $type): void
    {
        if ($type === 'all') {
            $this->purgeAll();
            return;
        }
        if (isset($this->purged[$type])) {
            return;
        }
        if (!isset($this->strategies[$type])) {
            return;
        }
        @ini_set('memory_limit', '512M');
        $this->strategies[$type]->purge();
        $this->purged[$type] = true;
        $this->logger->log('purge', $type);
    }

    public function purgeAll(): void
    {
        // Purge one strategy at a time to avoid OOM on full purge
        foreach ($this->strategies as $type => $strategy) {
            $this->purge($type);
            // Free memory between strategies
            gc_collect_cycles();
        }
    }

    /** @return \Generator<string> Yields each purged type — use for batched async purging */
    public function purgeAllGenerator(): \Generator
    {
        foreach (array_keys($this->strategies) as $type) {
            if (!isset($this->purged[$type])) {
                $this->purge($type);
                yield $type;
            }
        }
    }

    public function types(): array
    {
        return array_keys($this->strategies);
    }
}
