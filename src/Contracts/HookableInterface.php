<?php

declare(strict_types=1);

namespace Gratis\Cache\Contracts;

defined('ABSPATH') || exit;
interface HookableInterface {

    public function register(): void;
}
