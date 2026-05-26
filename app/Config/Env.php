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

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        if (!defined('FVD_ROOT')) {
            self::$loaded = true;

            return;
        }

        $path = $path ?? (FVD_ROOT . DIRECTORY_SEPARATOR . '.env');
        if (!is_file($path)) {
            self::$loaded = true;

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;

            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
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
            self::$variables[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        self::$loaded = true;
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
            return substr($value, 1, -1);
        }

        return $value;
    }
}
