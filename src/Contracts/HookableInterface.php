<?php

declare(strict_types=1);

namespace Gratis\Cache\Contracts;

interface HookableInterface {

    public function register(): void;
}
