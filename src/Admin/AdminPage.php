<?php

declare(strict_types=1);

namespace Gratis\Cache\Admin;

abstract class AdminPage
{
    abstract public function slug(): string;
    abstract public function title(): string;
    abstract public function render(): void;
}
