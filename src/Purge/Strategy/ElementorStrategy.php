<?php

declare(strict_types=1);

namespace Gratis\Cache\Purge\Strategy;

use Gratis\Cache\Contracts\PurgeStrategyInterface;

final class ElementorStrategy implements PurgeStrategyInterface {

    public function purge(): void {
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch (\Throwable) {
                // Elementor may fail in WP-CLI (FTP filesystem not available).
            }
        }
    }

    public function type(): string {
        return 'elementor';
    }
}
