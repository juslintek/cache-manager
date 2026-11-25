<?php

declare(strict_types=1);

namespace Gratis\Cache\Cache;

defined('ABSPATH') || exit;
final class DropinInstaller {

    private string $path;
    private DropinGenerator $generator;

    public function __construct(DropinGenerator $generator) {
        $this->path = WP_CONTENT_DIR . '/object-cache.php';
        $this->generator = $generator;
    }

    public function isOurs(): bool {
        if (!file_exists($this->path)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads first 200 bytes of local object-cache.php drop-in.
        $header = file_get_contents($this->path, false, null, 0, 200);
        return str_contains($header, 'VLT Object Cache');
    }

    public function install(): void {
        if (file_exists($this->path)) {
            wp_delete_file($this->path);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic LOCK_EX write of wp-content/object-cache.php drop-in.
        file_put_contents($this->path, $this->generator->generate(), LOCK_EX);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chown -- Best-effort web user ownership when invoked via root WP-CLI.
        @chown($this->path, 'nginx');
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- Best-effort web group ownership when invoked via root WP-CLI.
        @chgrp($this->path, 'nginx');
    }
}
