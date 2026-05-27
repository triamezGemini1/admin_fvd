<?php

declare(strict_types=1);

namespace Fvd\Config;

use Fvd\Database\ConnectionException;
use Fvd\Database\ConnectionManager;
use PDO;
use PDOException;

/**
 * Conexión a la BD externa de personas (dbo_persona), independiente de la BD principal del portal.
 * Delega en ConnectionManager manteniendo la API pública legacy.
 */
final class PersonaDatabaseFactory
{
    public static function lastError(): ?string
    {
        return ConnectionManager::lastPersonasError();
    }

    public static function isDisabled(): bool
    {
        return ConnectionManager::isPersonasDisabled();
    }

    public static function isConfigured(): bool
    {
        return ConnectionManager::isPersonasConfigured();
    }

    public static function connect(): ?PDO
    {
        try {
            return ConnectionManager::getPersonasConnection();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Prueba lectura de la tabla configurada (1 fila máximo).
     *
     * @return array<string, mixed>
     */
    public static function probeTable(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        $pdo = self::connect();
        if ($pdo === null) {
            return ['ok' => false, 'reason' => 'connection_failed', 'error' => self::lastError()];
        }

        $table = DatabaseConfig::personas()['table'];
        $safe = '`' . str_replace('`', '', $table) . '`';
        try {
            $pdo->query('SELECT 1 FROM ' . $safe . ' LIMIT 1');

            return ['ok' => true, 'table' => $table];
        } catch (PDOException $e) {
            return ['ok' => false, 'reason' => 'table_query_failed', 'error' => $e->getMessage(), 'table' => $table];
        }
    }
}
