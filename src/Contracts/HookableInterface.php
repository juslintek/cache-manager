<?php

declare(strict_types=1);

namespace VLT\CacheManager\Contracts;

interface HookableInterface
{
    public function register(): void;
}
