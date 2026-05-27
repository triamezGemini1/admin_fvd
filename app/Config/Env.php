<?php

declare(strict_types=1);

namespace Fvd\Config;

/**
 * Carga variables desde .env en la raíz del proyecto (putenv / $_ENV).
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $variables = [];

    /** @var list<string> */
    private static array $warnings = [];

    public static function envPath(): string
    {
        if (!defined('FVD_ROOT')) {
            return '';
        }

        return FVD_ROOT . DIRECTORY_SEPARATOR . '.env';
    }

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        if (!defined('FVD_ROOT')) {
            self::$loaded = true;

            return;
        }

        $path = $path ?? self::envPath();
        if (!is_file($path)) {
            self::$loaded = true;

            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$loaded = true;

            return;
        }
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        if ($lines === false) {
            self::$loaded = true;

            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $value = self::parseValue(trim($value));
            if (isset(self::$variables[$key]) && str_starts_with($key, 'FVD_')) {
                self::$warnings[] = "La variable {$key} está definida más de una vez; se usa el último valor (revise que no mezcle FVD_DB_* con FVD_PERSONA_DB_*).";
            }
            self::$variables[$key] = $value;
            $_ENV[$key] = $value;
        }

        self::$loaded = true;
        self::initApp();
    }

    public static function isProduction(): bool
    {
        $env = strtolower(trim(self::get('FVD_APP_ENV', 'development') ?? 'development'));

        return $env === 'production' || $env === 'prod';
    }

    private static function initApp(): void
    {
        $appFile = __DIR__ . DIRECTORY_SEPARATOR . 'App.php';
        if (!is_file($appFile)) {
            error_log('FVD: no se encontró app/Config/App.php. Suba ese archivo al servidor (git pull o FTP).');

            return;
        }
        require_once $appFile;
        if (class_exists(App::class)) {
            App::init();
        }
    }

    /** @return list<string> */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        $v = getenv($key);
        if ($v !== false) {
            return (string) $v;
        }

        return $default;
    }

    private static function parseValue(string $value): string
    {
        if (
            (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"')
            || (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'")
        ) {
            $value = substr($value, 1, -1);
        } else {
            $hash = strpos($value, '#');
            if ($hash !== false) {
                $value = trim(substr($value, 0, $hash));
            }
        }

        return $value;
    }
}
