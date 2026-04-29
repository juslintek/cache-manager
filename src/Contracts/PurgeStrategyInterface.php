<?php

declare(strict_types=1);

namespace VLT\CacheManager\Contracts;

interface PurgeStrategyInterface
{
    public function purge(): void;

    public function type(): string;
}
