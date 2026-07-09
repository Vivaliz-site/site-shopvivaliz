<?php
declare(strict_types=1);

namespace Core;

/**
 * Autoloader para blocos do editor visual
 */
class BlockAutoloader {
    private static bool $loaded = false;

    public static function register(): void {
        if (self::$loaded) {
            return;
        }

        spl_autoload_register([self::class, 'autoload']);
        self::$loaded = true;
    }

    public static function autoload(string $class): void {
        // Converter namespace para caminho de arquivo
        $prefix = 'Blocks\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../blocks/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    }
}

// Registrar autoloader ao incluir este arquivo
BlockAutoloader::register();
