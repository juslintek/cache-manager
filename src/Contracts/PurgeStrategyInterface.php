<?php

declare(strict_types=1);

namespace Gratis\Cache\Contracts;

interface PurgeStrategyInterface
{
    public function purge(): void;

    public function type(): string;
}
