<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge;

use Gratis\Cache\Contracts\PurgeStrategyInterface;
use Gratis\Cache\Log\Logger;

final class PurgeManager {

    private Logger $logger;
    /** @var PurgeStrategyInterface[] */
    private array $strategies = [];
    private array $purged = [];
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(Logger $logger, PurgeStrategyInterface ...$strategies) {
        $this->logger = $logger;
        foreach ($strategies as $s) {
            $this->strategies[$s->type()] = $s;
        }
    }

    public function purge(string $type): bool {
        if ($type === 'all') {
            return $this->purgeAll();
        }
        if (isset($this->purged[$type])) {
            return true;
        }
        if (!isset($this->strategies[$type])) {
            return false;
        }
        @ini_set('memory_limit', '512M');
        try {
            $this->strategies[$type]->purge();
        } catch (\Throwable $e) {
            $this->errors[$type] = $e->getMessage();
            $this->logger->log('purge_error', ['type' => $type, 'error' => $e->getMessage()]);
            return false;
        }
        unset($this->errors[$type]);
        $this->purged[$type] = true;
        $this->logger->log('purge', $type);
        return true;
    }

    public function purgeAll(): bool {
        // Purge one strategy at a time (best-effort) so one failure does not block others.
        $allOk = true;
        foreach ($this->strategies as $type => $strategy) {
            if (!$this->purge($type)) {
                $allOk = false;
            }
            // Free memory between strategies
            gc_collect_cycles();
        }
        return $allOk;
    }

    public function getError(?string $type = null): ?string {
        if ($type !== null) {
            return $this->errors[$type] ?? null;
        }
        return $this->errors ? implode('; ', $this->errors) : null;
    }

    /** @return array<string, string> */
    public function getErrors(): array {
        return $this->errors;
    }

    /** @return \Generator<string> Yields each purged type — use for batched async purging */
    public function purgeAllGenerator(): \Generator {
        foreach (array_keys($this->strategies) as $type) {
            if (!isset($this->purged[$type])) {
                $this->purge($type);
                yield $type;
            }
        }
    }

    public function types(): array {
        return array_keys($this->strategies);
    }
}
