<?php

declare(strict_types=1);

namespace VLT\CacheManager\Purge\Strategy;

use VLT\CacheManager\Contracts\PurgeStrategyInterface;

final class ElementorStrategy implements PurgeStrategyInterface
{
    public function purge(): void
    {
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }

    public function type(): string
    {
        return 'elementor';
    }
}
