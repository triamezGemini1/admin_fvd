<?php

declare(strict_types=1);

namespace Fvd\Servicios;

use Fvd\Config\Env;
use PDO;
use PDOException;

/**
 * Consulta de personas en BD externa (tabla dbo_persona / equivalentes).
 * Credenciales: variables FVD_PERSONA_DB_* en .env
 */
final class PersonaService
{
    private const CONNECTION_TIMEOUT = 3;

    /** @var array<string, PDO> */
    private static array $pool = [];

    private static bool $dbUnavailable = false;

    private static ?int $dbUnavailableTime = null;

    private const UNAVAILABLE_RESET_SEC = 60;

    /**
     * @return array{nacionalidad: string, cedula: string}|null
     */
    public static function parseIdentificacion(string $input): ?array
    {
        $q = preg_replace('/[\s\-\.]+/', '', trim($input)) ?? '';
        if ($q === '') {
            return null;
        }
        if (preg_match('/^([VEJP])(\d{4,})$/i', $q, $m)) {
            return [
                'nacionalidad' => strtoupper($m[1]),
                'cedula' => $m[2],
            ];
        }
        if (preg_match('/^\d{4,}$/', $q)) {
            return ['nacionalidad' => 'V', 'cedula' => $q];
        }

        return null;
    }

    /**
     * @return array{
     *   encontrado: bool,
     *   fuente?: string,
     *   persona?: array{
     *     cedula: string,
     *     nacionalidad: string,
     *     nombre: string,
     *     sexo: int,
     *     fechnac: string|null
     *   },
     *   error?: string
     * }
     */
    public static function buscarPorIdentificacion(string $nacionalidad, string $cedula): array
    {
        if (!self::isConfigured()) {
            return ['encontrado' => false, 'error' => 'Búsqueda de personas no configurada'];
        }

        if (self::isTemporarilyUnavailable()) {
            return ['encontrado' => false, 'error' => 'Búsqueda externa deshabilitada temporalmente'];
        }

        $cedula = preg_replace('/\D/', '', trim($cedula)) ?? '';
        $nacionalidad = strtoupper(trim($nacionalidad));
        if ($cedula === '' || !in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
            return ['encontrado' => false, 'error' => 'Cédula o nacionalidad inválida'];
        }

        $pdo = self::getConnection();
        if ($pdo === null) {
            return ['encontrado' => false, 'error' => 'Base de datos de personas no disponible'];
        }

        foreach (self::tableCandidates() as $table) {
            try {
                $row = self::queryPersona($pdo, $table, $nacionalidad, $cedula);
                if ($row !== null) {
                    return [
                        'encontrado' => true,
                        'fuente' => 'externa',
                        'persona' => self::mapRowToPersona($row, $nacionalidad, $cedula),
                    ];
                }
            } catch (PDOException $e) {
                if (self::isMissingTableError($e)) {
                    continue;
                }
                error_log('PersonaService: ' . $e->getMessage());
                self::markUnavailable();

                return ['encontrado' => false, 'error' => 'Error al consultar registro de personas'];
            }
        }

        return ['encontrado' => false, 'error' => 'No se encontró persona con esa cédula'];
    }

    /**
     * Acepta cédula con o sin prefijo V/E/J/P; nacionalidad opcional.
     *
     * @return array{
     *   encontrado: bool,
     *   fuente?: string,
     *   persona?: array<string, mixed>,
     *   error?: string
     * }
     */
    public static function buscarDesdeTextoCedula(string $cedulaInput, ?string $nacionalidad = null): array
    {
        $parsed = self::parseIdentificacion($cedulaInput);
        $nac = $nacionalidad !== null && $nacionalidad !== ''
            ? strtoupper(trim($nacionalidad))
            : ($parsed['nacionalidad'] ?? 'V');
        if ($parsed !== null) {
            return self::buscarPorIdentificacion($nac, $parsed['cedula']);
        }

        $soloDigitos = preg_replace('/\D/', '', trim($cedulaInput)) ?? '';
        if (strlen($soloDigitos) >= 4) {
            return self::buscarPorIdentificacion($nac, $soloDigitos);
        }

        return ['encontrado' => false, 'error' => 'Formato de cédula no válido para búsqueda externa'];
    }

    public static function isConfigured(): bool
    {
        if (self::env('FVD_PERSONA_DB_DISABLED', '0') === '1') {
            return false;
        }

        return self::env('FVD_PERSONA_DB_DATABASE') !== '';
    }

    private static function env(string $key, string $default = ''): string
    {
        if ($key === 'FVD_PERSONA_DB_DISABLED') {
            $v = Env::get($key);

            return $v !== null && $v !== '' ? $v : $default;
        }

        $v = Env::get($key);
        if ($v !== null && $v !== '') {
            return $v;
        }

        $aliases = [
            'FVD_PERSONA_DB_HOST' => 'DB_SECONDARY_HOST',
            'FVD_PERSONA_DB_PORT' => 'DB_SECONDARY_PORT',
            'FVD_PERSONA_DB_DATABASE' => 'DB_SECONDARY_DATABASE',
            'FVD_PERSONA_DB_USERNAME' => 'DB_SECONDARY_USERNAME',
            'FVD_PERSONA_DB_PASSWORD' => 'DB_SECONDARY_PASSWORD',
            'FVD_PERSONA_DB_TABLE' => 'DB_SECONDARY_TABLE',
        ];
        if (isset($aliases[$key])) {
            $alt = Env::get($aliases[$key]);
            if ($alt !== null && $alt !== '') {
                return $alt;
            }
        }

        /** Valores WAMP/local si no existe .env (misma convención que mistorneos dev). */
        $devDefaults = [
            'FVD_PERSONA_DB_HOST' => '127.0.0.1',
            'FVD_PERSONA_DB_PORT' => '3306',
            'FVD_PERSONA_DB_DATABASE' => 'personas',
            'FVD_PERSONA_DB_USERNAME' => 'root',
            'FVD_PERSONA_DB_PASSWORD' => '',
            'FVD_PERSONA_DB_TABLE' => 'dbo_persona',
        ];

        return $devDefaults[$key] ?? $default;
    }

    private static function isTemporarilyUnavailable(): bool
    {
        if (!self::$dbUnavailable || self::$dbUnavailableTime === null) {
            return false;
        }
        if (time() - self::$dbUnavailableTime > self::UNAVAILABLE_RESET_SEC) {
            self::$dbUnavailable = false;
            self::$dbUnavailableTime = null;

            return false;
        }

        return true;
    }

    private static function markUnavailable(): void
    {
        self::$dbUnavailable = true;
        self::$dbUnavailableTime = time();
    }

    /**
     * @return list<string>
     */
    private static function tableCandidates(): array
    {
        $primary = self::env('FVD_PERSONA_DB_TABLE', 'dbo_persona');
        $list = [$primary, 'dbo_persona', 'persona'];

        return array_values(array_unique(array_filter($list)));
    }

    private static function getConnection(): ?PDO
    {
        $host = self::env('FVD_PERSONA_DB_HOST', '127.0.0.1');
        $port = self::env('FVD_PERSONA_DB_PORT', '3306');
        $name = self::env('FVD_PERSONA_DB_DATABASE');
        $user = self::env('FVD_PERSONA_DB_USERNAME', 'root');
        $pass = self::env('FVD_PERSONA_DB_PASSWORD', '');

        if (trim($name) === '') {
            return null;
        }

        $key = $host . ':' . $port . ':' . $name;
        if (isset(self::$pool[$key])) {
            try {
                self::$pool[$key]->query('SELECT 1');

                return self::$pool[$key];
            } catch (PDOException $e) {
                unset(self::$pool[$key]);
            }
        }

        $prevTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) self::CONNECTION_TIMEOUT);

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = self::CONNECTION_TIMEOUT;
            }
            $pdo = new PDO($dsn, $user, $pass, $options);
            self::$pool[$key] = $pdo;

            return $pdo;
        } catch (PDOException $e) {
            error_log('PersonaService conexión: ' . $e->getMessage());
            if (self::strHas($e->getMessage(), 'Unknown database') || self::strHas($e->getMessage(), 'Access denied')) {
                self::markUnavailable();
            }

            return null;
        } finally {
            ini_set('default_socket_timeout', (string) $prevTimeout);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function qualifyTableSql(string $table): string
    {
        $clean = str_replace('`', '', $table);
        if (strpos($clean, '.') !== false) {
            $parts = explode('.', $clean, 2);

            return '`' . $parts[0] . '`.`' . $parts[1] . '`';
        }

        return '`' . $clean . '`';
    }

    private static function strHas(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function queryPersona(PDO $pdo, string $table, string $nacionalidad, string $cedula): ?array
    {
        $tableSql = self::qualifyTableSql($table);

        $sql = "SELECT Nombre1, Nombre2, Apellido1, Apellido2, FNac, Sexo, Nac
            FROM {$tableSql}
            WHERE IDUsuario = :cedula AND Nac = :nac
            LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cedula', $cedula, PDO::PARAM_STR);
        $stmt->bindValue(':nac', $nacionalidad, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }

        $sql2 = "SELECT Nombre1, Nombre2, Apellido1, Apellido2, FNac, Sexo, Nac
            FROM {$tableSql}
            WHERE IDUsuario = :cedula
            LIMIT 1";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->bindValue(':cedula', $cedula, PDO::PARAM_STR);
        $stmt2->execute();
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        return $row2 === false ? null : $row2;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{cedula: string, nacionalidad: string, nombre: string, sexo: int, fechnac: string|null}
     */
    private static function mapRowToPersona(array $row, string $nacionalidadFallback, string $cedula): array
    {
        $parts = array_filter([
            trim((string) ($row['Nombre1'] ?? '')),
            trim((string) ($row['Nombre2'] ?? '')),
            trim((string) ($row['Apellido1'] ?? '')),
            trim((string) ($row['Apellido2'] ?? '')),
        ], static fn (string $p): bool => $p !== '');
        $nombre = trim(implode(' ', $parts));

        $fechnac = null;
        if (!empty($row['FNac'])) {
            $ts = strtotime((string) $row['FNac']);
            if ($ts !== false) {
                $fechnac = date('Y-m-d', $ts);
            }
        }

        $nac = isset($row['Nac']) ? strtoupper(trim((string) $row['Nac'])) : $nacionalidadFallback;
        if (!in_array($nac, ['V', 'E', 'J', 'P'], true)) {
            $nac = $nacionalidadFallback;
        }

        return [
            'cedula' => $cedula,
            'nacionalidad' => $nac,
            'nombre' => $nombre,
            'sexo' => self::sexoToInt($row['Sexo'] ?? $row['sexo'] ?? null),
            'fechnac' => $fechnac,
        ];
    }

    private static function sexoToInt(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        $s = strtoupper(trim((string) $raw));
        if (in_array($s, ['M', '1', 'MASCULINO', 'MALE'], true)) {
            return 1;
        }
        if (in_array($s, ['F', '2', 'FEMENINO', 'FEMALE'], true)) {
            return 2;
        }

        return 0;
    }

    private static function isMissingTableError(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return self::strHas($msg, "doesn't exist")
            || self::strHas($msg, 'Base table or view not found')
            || self::strHas($msg, '42S02');
    }
}
