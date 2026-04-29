<?php

declare(strict_types=1);

namespace VLT\CacheManager\Cache;

final class DropinInstaller
{
    private string $path;
    private DropinGenerator $generator;

    public function __construct(DropinGenerator $generator)
    {
        $this->path = WP_CONTENT_DIR . '/object-cache.php';
        $this->generator = $generator;
    }

    public function isOurs(): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }
        $header = file_get_contents($this->path, false, null, 0, 200);
        return str_contains($header, 'VLT Object Cache');
    }

    public function install(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
        file_put_contents($this->path, $this->generator->generate(), LOCK_EX);
        @chown($this->path, 'nginx');
        @chgrp($this->path, 'nginx');
    }
}
