<?php declare(strict_types=1);

namespace VLT\CacheManager\Storage;

use VLT\CacheManager\Contracts\Storage\FileChangeStoreInterface;

/** Mtime-based file change detection backed by JsonlTraceStore. */
final class FileChangeScanner implements FileChangeStoreInterface
{
    private const CHANNEL = 'file-changes';
    private JsonlTraceStore $store;
    private string $stateFile;

    public function __construct(JsonlTraceStore $store, ?string $stateFile = null)
    {
        $this->store = $store;
        $this->stateFile = $stateFile ?? WP_CONTENT_DIR . '/cache-manager-data/file-scan-state.json';
    }

    public function recordChange(string $path, string $type, int $timestamp): void
    {
        $this->store->record(self::CHANNEL, [
            'path' => $path,
            'type' => $type,
            '_ts'  => $timestamp,
        ]);
    }

    public function changesSince(int $timestamp): array
    {
        return array_values($this->store->since(self::CHANNEL, $timestamp));
    }

    public function lastChange(?string $pathPrefix = null): ?array
    {
        $entries = $this->store->tail(self::CHANNEL, 100);
        if ($pathPrefix !== null) {
            $entries = array_filter($entries, fn($e) => str_starts_with($e['path'] ?? '', $pathPrefix));
        }
        return $entries ? end($entries) : null;
    }

    /** @return string[] List of changed file paths */
    public function scan(?array $directories = null): array
    {
        $directories ??= $this->defaultDirectories();
        $previous = $this->loadState();
        $current = [];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $path = $file->getPathname();
                $current[$path] = $file->getMTime();
            }
        }

        $changed = [];
        $now = time();

        foreach ($current as $path => $mtime) {
            if (!isset($previous[$path])) {
                $this->recordChange($path, 'created', $now);
                $changed[] = $path;
            } elseif ($previous[$path] !== $mtime) {
                $this->recordChange($path, 'modified', $now);
                $changed[] = $path;
            }
        }

        foreach ($previous as $path => $mtime) {
            if (!isset($current[$path])) {
                $this->recordChange($path, 'deleted', $now);
                $changed[] = $path;
            }
        }

        $this->saveState($current);
        return $changed;
    }

    private function defaultDirectories(): array
    {
        return array_filter([
            defined('WP_CONTENT_DIR') ? get_stylesheet_directory() : null,
            defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : null),
        ]);
    }

    private function loadState(): array
    {
        if (!file_exists($this->stateFile)) return [];
        $data = json_decode(file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($this->stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
