<?php

declare(strict_types=1);

namespace Fvd\Modulos\Finanzas\Modelos;

/**
 * Campo `movimiento` en `movimiento_torneo`: estado de supervisión FVD (no confundir con indicadores de cobro).
 *
 * - Indicadores `afiliacion`, `carnet`, `traspaso`, etc. en 1 → cuentan en finanzas ({@see MovimientoTorneoContadores}).
 * - `movimiento` = 9 → carnet o traspaso aprobado en supervisión; los indicadores permanecen en 1.
 */
final class MovimientoTorneoCampo
{
    public const T_TABLA = 'movimiento_torneo';

    /** Aprobación de supervisión (carnet o traspaso). */
    public const APROBADO_SUPERVISION = 9;

    private static ?bool $columnaDisponible = null;

    public static function columnaMovimientoDisponible(\PDO $pdo): bool
    {
        if (self::$columnaDisponible !== null) {
            return self::$columnaDisponible;
        }
        try {
            $st = $pdo->query(
                'SHOW COLUMNS FROM `' . self::T_TABLA . '` LIKE \'movimiento\''
            );
            self::$columnaDisponible = $st !== false && $st->fetch(\PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable $e) {
            self::$columnaDisponible = false;
        }

        return self::$columnaDisponible;
    }

    /**
     * Cola de supervisión: carnet/traspaso aún no aprobados por admin.
     */
    public static function sqlSupervisionPendiente(string $aliasMov = 'm'): string
    {
        $p = rtrim($aliasMov, '.') . '.';

        return 'COALESCE(' . $p . '`movimiento`, 0) <> ' . self::APROBADO_SUPERVISION;
    }

    /** Misma condición sin alias de tabla (cláusulas UPDATE). */
    public static function sqlSupervisionPendienteSinAlias(): string
    {
        return 'COALESCE(`movimiento`, 0) <> ' . self::APROBADO_SUPERVISION;
    }

    public static function sqlSetAprobadoSupervision(): string
    {
        return '`movimiento` = ' . self::APROBADO_SUPERVISION;
    }

    public static function sqlSetMovimientoCero(): string
    {
        return '`movimiento` = 0';
    }
}
