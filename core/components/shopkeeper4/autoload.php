<?php
/** Shopkeeper 4 · rwolfin · GPL-3.0-only */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Shopkeeper4\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});
