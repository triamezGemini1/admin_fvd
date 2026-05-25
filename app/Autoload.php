<?php

declare(strict_types=1);

namespace Fvd;

/**
 * Autoload PSR-4 para clases bajo el prefijo Fvd\.
 * Ejemplo: Fvd\Modulos\Torneos\Modelos\Torneo → app/Modulos/Torneos/Modelos/Torneo.php
 */
final class Autoload
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }

    public static function loadClass(string $class): void
    {
        $prefix = 'Fvd\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}
