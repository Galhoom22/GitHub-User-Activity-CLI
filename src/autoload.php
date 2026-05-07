<?php

declare(strict_types=1);

// Maps App\ namespace to the src/ directory (PSR-4 style, no Composer).
spl_autoload_register(function (string $class): void {
    $prefix  = 'App\\';
    $baseDir = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
