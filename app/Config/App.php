<?php

declare(strict_types=1);

namespace Fvd\Config;

/**
 * Configuración global del portal (entorno, ruta pública, sesión, PHP en producción).
 */
final class App
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::applyPhpEnvironment();
        self::configureSession();
        self::$initialized = true;
    }

    public static function isProduction(): bool
    {
        $env = strtolower(trim(Env::get('FVD_APP_ENV', 'development') ?? 'development'));

        return $env === 'production' || $env === 'prod';
    }

    /**
     * Ruta URL del proyecto bajo el dominio (con barras inicial y final).
     * Ej.: /admin_fvd/ en https://federacionvenezolanadedomino.com/admin_fvd/
     */
    public static function basePath(): string
    {
        $raw = trim(Env::get('FVD_BASE_PATH', '') ?? '');
        if ($raw === '' || $raw === '/') {
            return '/';
        }
        $path = '/' . trim($raw, '/');

        return $path . '/';
    }

    /**
     * URL pública absoluta sin barra final (opcional; si no está en .env se deduce del request).
     */
    public static function publicUrl(): string
    {
        $configured = trim(Env::get('FVD_PUBLIC_URL', '') ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = self::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(self::basePath(), '/');
        if ($base === '') {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . $base;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    private static function applyPhpEnvironment(): void
    {
        if (!self::isProduction()) {
            return;
        }

        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('log_errors', '1');
    }

    private static function configureSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $base = self::basePath();
        $path = $base === '/' ? '/' : rtrim($base, '/');
        $secure = self::isHttps() || self::isProduction();
        $domain = trim(Env::get('FVD_SESSION_DOMAIN', '') ?? '');

        $params = [
            'lifetime' => 0,
            'path' => $path,
            'domain' => $domain !== '' ? $domain : '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        $name = trim(Env::get('FVD_SESSION_NAME', '') ?? '');
        if ($name !== '') {
            session_name($name);
        }
    }
}
