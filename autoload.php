<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'VLT\\CacheManager\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
