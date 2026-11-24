<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(function (string $class_name): void {
    $prefix = 'Gratis\\Cache\\';
    if (strncmp($class_name, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class_name, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
