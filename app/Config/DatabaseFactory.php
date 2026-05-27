<?php

declare(strict_types=1);

namespace Fvd\Config;

use Fvd\Database\ConnectionException;
use Fvd\Database\ConnectionManager;
use PDO;

/**
 * Conexión PDO centralizada con diagnóstico (sin exponer contraseñas).
 * Delega en ConnectionManager manteniendo la API pública legacy.
 */
final class DatabaseFactory
{
    public static function lastError(): ?string
    {
        return ConnectionManager::lastPortalError();
    }

    public static function envFilePath(): string
    {
        return Env::envPath();
    }

    public static function envFileExists(): bool
    {
        return ConnectionManager::envFileExists();
    }

    public static function connect(): ?PDO
    {
        try {
            return ConnectionManager::getPortalConnection();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Resumen seguro para health (sin contraseñas).
     *
     * @return array<string, mixed>
     */
    public static function configSummary(): array
    {
        $summary = DatabaseConfig::portalSummary();
        $pwd = DatabaseConfig::portal()['password'];

        return array_merge($summary, [
            'password_has_dollar' => str_contains($pwd, '$'),
            'env_warnings' => Env::warnings(),
        ]);
    }

    /**
     * Mensaje seguro para el cliente (login / APIs).
     */
    public static function publicUnavailableMessage(): string
    {
        if (!self::envFileExists()) {
            return 'Configuración incompleta: falta el archivo .env en el servidor. Copie .env.production.example a .env y configure la base de datos.';
        }

        if (Env::get('FVD_APP_DEBUG', '0') === '1' && self::lastError() !== null) {
            return 'Base de datos: ' . self::lastError();
        }

        $pwd = DatabaseConfig::portal()['password'];
        $hint = '';
        if (str_contains($pwd, '$')) {
            $hint = ' Si la clave tiene $, en .env debe ir entre comillas: FVD_DB_PASSWORD="Mimusica$26".';
        } elseif ($pwd !== '' && strlen($pwd) < 8) {
            $hint = ' La contraseña leída es muy corta; revise comillas en .env (el símbolo $ trunca el valor sin comillas).';
        }
        foreach (Env::warnings() as $w) {
            $hint .= ' ' . $w;
        }

        return 'Servicio no disponible. Verifique .env (host, usuario, contraseña y nombre de BD).' . $hint
            . ' Abra api/health.php con FVD_APP_DEBUG=1 para el detalle.';
    }
}
