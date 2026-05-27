<?php

declare(strict_types=1);

namespace Fvd\Config;

use InvalidArgumentException;

/**
 * Resuelve credenciales de BD Portal y Personas desde URL o variables legacy FVD_*.
 * Las contraseñas nunca se exponen fuera de este módulo.
 */
final class DatabaseConfig
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $portalCache = null;

    /** @var array<string, array<string, string>>|null */
    private static ?array $personasCache = null;

    /**
     * Configuración BD Portal (fvdmasteradmin).
     *
     * @return array{
     *   host: string,
     *   port: string,
     *   database: string,
     *   username: string,
     *   password: string,
     *   socket: string,
     *   charset: string,
     *   source: string
     * }
     */
    public static function portal(): array
    {
        if (self::$portalCache !== null) {
            return self::$portalCache;
        }

        $url = trim(Env::get('DB_PORTAL_URL', '') ?? '');
        if ($url !== '') {
            self::$portalCache = self::parseDatabaseUrl($url, 'DB_PORTAL_URL');
            self::$portalCache['socket'] = trim(Env::get('FVD_DB_SOCKET', '') ?? '');
            self::$portalCache = self::applySharedCredentials(self::$portalCache);

            return self::$portalCache;
        }

        self::$portalCache = [
            'host' => trim(Env::get('FVD_DB_HOST', '127.0.0.1') ?? '127.0.0.1'),
            'port' => trim(Env::get('FVD_DB_PORT', '3306') ?? '3306'),
            'database' => trim(Env::get('FVD_DB_DATABASE', '') ?? ''),
            'username' => trim(Env::get('FVD_DB_USERNAME', '') ?? ''),
            'password' => Env::get('FVD_DB_PASSWORD', '') ?? '',
            'socket' => trim(Env::get('FVD_DB_SOCKET', '') ?? ''),
            'charset' => 'utf8mb4',
            'source' => 'legacy',
        ];
        self::$portalCache = self::applySharedCredentials(self::$portalCache);

        if (!Env::isProduction()) {
            self::$portalCache = self::applyLocalDevDefaults(self::$portalCache);
        }

        return self::$portalCache;
    }

    /**
     * Configuración BD Personas remota (dbo_persona).
     *
     * @return array{
     *   host: string,
     *   port: string,
     *   database: string,
     *   username: string,
     *   password: string,
     *   socket: string,
     *   charset: string,
     *   table: string,
     *   source: string
     * }
     */
    public static function personas(): array
    {
        if (self::$personasCache !== null) {
            return self::$personasCache;
        }

        $url = trim(Env::get('DB_PERSONAS_URL', '') ?? '');
        if ($url !== '') {
            self::$personasCache = self::parseDatabaseUrl($url, 'DB_PERSONAS_URL');
            self::$personasCache['socket'] = trim(Env::get('FVD_PERSONA_DB_SOCKET', '') ?? '');
            self::$personasCache['table'] = trim(Env::get('FVD_PERSONA_DB_TABLE', 'dbo_persona') ?? 'dbo_persona');
            self::$personasCache = self::applySharedCredentials(self::$personasCache);

            return self::$personasCache;
        }

        $personaHost = trim(Env::get('FVD_PERSONA_DB_HOST', '') ?? '');
        if ($personaHost === '') {
            $personaHost = trim(Env::get('FVD_PERSONA_DB_DOMAIN', '') ?? '');
        }

        self::$personasCache = [
            'host' => $personaHost !== '' ? $personaHost : 'localhost',
            'port' => trim(Env::get('FVD_PERSONA_DB_PORT', '3306') ?? '3306'),
            'database' => trim(Env::get('FVD_PERSONA_DB_DATABASE', '') ?? ''),
            'username' => trim(Env::get('FVD_PERSONA_DB_USERNAME', '') ?? ''),
            'password' => Env::get('FVD_PERSONA_DB_PASSWORD', '') ?? '',
            'socket' => trim(Env::get('FVD_PERSONA_DB_SOCKET', '') ?? ''),
            'charset' => 'utf8mb4',
            'table' => trim(Env::get('FVD_PERSONA_DB_TABLE', 'dbo_persona') ?? 'dbo_persona'),
            'source' => 'legacy',
        ];
        self::$personasCache = self::applySharedCredentials(self::$personasCache);

        return self::$personasCache;
    }

    public static function isPersonasDisabled(): bool
    {
        return Env::get('FVD_PERSONA_DB_DISABLED', '0') === '1';
    }

    public static function isPersonasConfigured(): bool
    {
        if (self::isPersonasDisabled()) {
            return false;
        }

        $cfg = self::personas();

        return $cfg['database'] !== '';
    }

    public static function isPortalConfigured(): bool
    {
        $cfg = self::portal();

        return $cfg['database'] !== '' && $cfg['username'] !== '';
    }

    /**
     * Resumen seguro para health / diagnóstico (sin contraseñas).
     *
     * @return array<string, mixed>
     */
    public static function portalSummary(): array
    {
        $cfg = self::portal();

        return [
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'database' => $cfg['database'],
            'username' => $cfg['username'],
            'password_set' => $cfg['password'] !== '',
            'config_source' => $cfg['source'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function personasSummary(): array
    {
        $cfg = self::personas();

        return [
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'database' => $cfg['database'],
            'username' => $cfg['username'],
            'table' => $cfg['table'],
            'password_set' => $cfg['password'] !== '',
            'config_source' => $cfg['source'],
        ];
    }

    /**
     * Parsea mysql://usuario:contraseña@host:3306/base?charset=utf8mb4
     *
     * @return array{host: string, port: string, database: string, username: string, password: string, socket: string, charset: string, source: string}
     */
    private static function parseDatabaseUrl(string $url, string $label): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidArgumentException("{$label}: URL de base de datos inválida.");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'mysql') {
            throw new InvalidArgumentException("{$label}: solo se admite esquema mysql://.");
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException("{$label}: falta el host en la URL.");
        }

        $port = isset($parts['port']) ? (string) $parts['port'] : '3306';
        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($database === '') {
            throw new InvalidArgumentException("{$label}: falta el nombre de la base de datos en la URL.");
        }

        $username = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';

        $charset = 'utf8mb4';
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (isset($query['charset']) && is_string($query['charset']) && $query['charset'] !== '') {
                $charset = $query['charset'];
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'socket' => '',
            'charset' => $charset,
            'source' => 'url',
        ];
    }

    public static function hasPlaceholderPortalCredentials(): bool
    {
        $cfg = self::portal();
        $db = strtolower($cfg['database']);
        $user = strtolower($cfg['username']);
        $pwd = $cfg['password'];

        return str_contains($db, 'su_prefijo')
            || str_contains($user, 'su_usuario')
            || $pwd === 'su_contraseña'
            || str_contains($pwd, 'su_contraseña');
    }

    /**
     * Usuario/clave compartidos entre ambas BD (mismo MySQL en dos dominios).
     * Orden: valor específico del bloque → FVD_MYSQL_* → FVD_DB_* (portal).
     *
     * @param array<string, string> $cfg
     * @return array<string, string>
     */
    private static function applySharedCredentials(array $cfg): array
    {
        $sharedUser = trim(Env::get('FVD_MYSQL_USERNAME', '') ?? '');
        $sharedPass = Env::get('FVD_MYSQL_PASSWORD', '') ?? '';
        $portalUser = trim(Env::get('FVD_DB_USERNAME', '') ?? '');
        $portalPass = Env::get('FVD_DB_PASSWORD', '') ?? '';

        if ($cfg['username'] === '') {
            $cfg['username'] = $sharedUser !== '' ? $sharedUser : $portalUser;
        }
        if ($cfg['password'] === '') {
            $cfg['password'] = $sharedPass !== '' ? $sharedPass : $portalPass;
        }

        return $cfg;
    }

    /**
     * Valores WAMP/local cuando no hay .env o faltan claves (solo development).
     *
     * @param array{host: string, port: string, database: string, username: string, password: string, socket: string, charset: string, source: string} $cfg
     * @return array{host: string, port: string, database: string, username: string, password: string, socket: string, charset: string, source: string}
     */
    private static function applyLocalDevDefaults(array $cfg): array
    {
        if ($cfg['database'] === '') {
            $cfg['database'] = 'fvdmasteradmin';
        }
        if ($cfg['username'] === '') {
            $cfg['username'] = 'root';
        }

        return $cfg;
    }

    /** Solo para pruebas unitarias / diagnóstico. */
    public static function resetCache(): void
    {
        self::$portalCache = null;
        self::$personasCache = null;
    }
}

