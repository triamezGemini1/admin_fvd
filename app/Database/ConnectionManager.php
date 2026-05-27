<?php

declare(strict_types=1);

namespace Fvd\Database;

use Fvd\Config\DatabaseConfig;
use Fvd\Config\Env;
use PDO;
use PDOException;

/**
 * Gestor centralizado de conexiones PDO con lazy loading estricto para BD Personas.
 */
final class ConnectionManager
{
    private static ?PDO $portalConnection = null;

    private static ?PDO $personasConnection = null;

    private static ?string $portalLastError = null;

    private static ?string $personasLastError = null;

    private const PERSONAS_CONNECT_TIMEOUT = 5;

    /**
     * Conexión BD Portal. Singleton: se crea en la primera invocación del script.
     *
     * @throws ConnectionException
     */
    public static function getPortalConnection(): PDO
    {
        if (self::$portalConnection !== null) {
            return self::$portalConnection;
        }

        self::$portalLastError = null;

        if (!self::envFileExists() && Env::isProduction()) {
            self::$portalLastError = 'Archivo .env no encontrado en ' . Env::envPath();
            throw new ConnectionException(self::$portalLastError, 'portal');
        }

        $cfg = DatabaseConfig::portal();
        if ($cfg['database'] === '' || $cfg['username'] === '') {
            self::$portalLastError = 'FVD_DB_DATABASE o FVD_DB_USERNAME vacíos en .env';
            throw new ConnectionException(self::$portalLastError, 'portal');
        }

        if (self::looksLikePersonaDatabaseMisconfigured($cfg['database'])) {
            self::$portalLastError = 'FVD_DB_DATABASE parece ser la BD de personas. Use FVD_PERSONA_DB_* / DB_PERSONAS_URL para laestaci… y FVD_DB_* / DB_PORTAL_URL solo para fvdmasteradmin.';
            throw new ConnectionException(self::$portalLastError, 'portal');
        }

        $lastException = null;
        foreach (self::hostsToTry($cfg['host']) as $tryHost) {
            try {
                self::$portalConnection = self::createPdo(
                    $tryHost,
                    $cfg['port'],
                    $cfg['database'],
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['socket'],
                    $cfg['charset'],
                    null
                );
                if ($tryHost !== $cfg['host']) {
                    error_log('ConnectionManager: portal OK con host alternativo ' . $tryHost . ' (configurado ' . $cfg['host'] . ')');
                }

                return self::$portalConnection;
            } catch (PDOException $e) {
                $lastException = $e;
                self::$portalLastError = $e->getMessage();
                error_log('ConnectionManager::getPortalConnection — host=' . $tryHost . ' db=' . $cfg['database'] . ' — ' . $e->getMessage());
            }
        }

        $msg = self::$portalLastError ?? ($lastException !== null ? $lastException->getMessage() : 'Conexión portal fallida');
        throw new ConnectionException($msg, 'portal', $lastException);
    }

    /**
     * Intenta obtener conexión portal; retorna null en lugar de lanzar excepción.
     */
    public static function tryGetPortalConnection(): ?PDO
    {
        try {
            return self::getPortalConnection();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Conexión BD Personas remota. ESTRICTAMENTE lazy: null hasta la primera invocación explícita.
     *
     * @throws ConnectionException
     */
    public static function getPersonasConnection(): PDO
    {
        if (self::$personasConnection !== null) {
            return self::$personasConnection;
        }

        self::$personasLastError = null;

        if (DatabaseConfig::isPersonasDisabled()) {
            self::$personasLastError = 'BD personas deshabilitada (FVD_PERSONA_DB_DISABLED=1)';
            throw new ConnectionException(self::$personasLastError, 'personas');
        }

        if (!DatabaseConfig::isPersonasConfigured()) {
            self::$personasLastError = 'BD personas no configurada';
            throw new ConnectionException(self::$personasLastError, 'personas');
        }

        $cfg = DatabaseConfig::personas();

        try {
            self::$personasConnection = self::createPdo(
                $cfg['host'],
                $cfg['port'],
                $cfg['database'],
                $cfg['username'],
                $cfg['password'],
                $cfg['socket'],
                $cfg['charset'],
                self::PERSONAS_CONNECT_TIMEOUT
            );
            self::$personasConnection->query('SELECT 1');

            return self::$personasConnection;
        } catch (PDOException $e) {
            self::$personasLastError = $e->getMessage();
            self::$personasConnection = null;
            error_log(
                'ConnectionManager::getPersonasConnection — host=' . $cfg['host']
                . ' db=' . $cfg['database'] . ' — ' . $e->getMessage()
            );
            throw new ConnectionException('Base de datos de personas no disponible', 'personas', $e);
        }
    }

    /**
     * Intenta obtener conexión personas; retorna null si no está configurada o falla.
     */
    public static function tryGetPersonasConnection(): ?PDO
    {
        try {
            return self::getPersonasConnection();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public static function lastPortalError(): ?string
    {
        return self::$portalLastError;
    }

    public static function lastPersonasError(): ?string
    {
        return self::$personasLastError;
    }

    public static function isPersonasConfigured(): bool
    {
        return DatabaseConfig::isPersonasConfigured();
    }

    public static function isPersonasDisabled(): bool
    {
        return DatabaseConfig::isPersonasDisabled();
    }

    public static function envFileExists(): bool
    {
        return is_file(Env::envPath());
    }

    /**
     * Libera conexiones cacheadas (diagnóstico / pruebas).
     */
    public static function resetConnections(): void
    {
        self::$portalConnection = null;
        self::$personasConnection = null;
        self::$portalLastError = null;
        self::$personasLastError = null;
    }

    /**
     * @return list<string>
     */
    private static function hostsToTry(string $host): array
    {
        $host = trim($host);
        if ($host === '127.0.0.1') {
            return ['127.0.0.1', 'localhost'];
        }
        if ($host === 'localhost') {
            return ['localhost', '127.0.0.1'];
        }

        return [$host];
    }

    private static function createPdo(
        string $host,
        string $port,
        string $dbName,
        string $username,
        string $password,
        string $socket,
        string $charset,
        ?int $connectTimeout
    ): PDO {
        if ($socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $dbName, $charset);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $port,
                $dbName,
                $charset
            );
        }

        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ];

        if ($connectTimeout !== null && defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $connectTimeout;
        }

        return new PDO($dsn, $username, $password, $opts);
    }

    private static function looksLikePersonaDatabaseMisconfigured(string $dbName): bool
    {
        $personaDb = DatabaseConfig::personas()['database'];
        if ($personaDb !== '' && strcasecmp($dbName, $personaDb) === 0) {
            return true;
        }

        $lower = strtolower($dbName);

        return str_contains($lower, 'fvdadmin')
            && !str_contains($lower, 'master')
            && !str_contains($lower, 'fvdmaster');
    }
}
