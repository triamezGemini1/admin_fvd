<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

/**
 * Bloqueo de edición en `movimiento_torneo` cuando el torneo ya no admite cambios de nómina:
 * - `finalizado_en` distinto de nulo (torneo cerrado en acta), o
 * - `fecha_limite_cambios` definida y la fecha de hoy es posterior a ese límite (solo consulta).
 *
 * Inscripciones en sitio (alta, retiro, teléfono): no aplican bloqueo por fechas (`estadoParaInscripciones`).
 */
class TorneoMovimientoLock
{
    /**
     * @return array{bloqueado: bool, motivo: ?string, finalizado: bool, limite_pasada: bool}
     */
    public static function estado(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return ['bloqueado' => false, 'motivo' => null, 'finalizado' => false, 'limite_pasada' => false];
        }
        $st = $pdo->prepare(
            'SELECT finalizado_en, fecha_limite_cambios FROM torneosact WHERE torneo = :t LIMIT 1'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['bloqueado' => false, 'motivo' => null, 'finalizado' => false, 'limite_pasada' => false];
        }
        $fin = $row['finalizado_en'] ?? null;
        $finalizado = $fin !== null && trim((string) $fin) !== '';
        if ($finalizado) {
            return [
                'bloqueado' => true,
                'motivo' => 'El torneo está finalizado (fecha de cierre registrada); no se puede modificar la nómina de movimiento.',
                'finalizado' => true,
                'limite_pasada' => false,
            ];
        }
        $lim = $row['fecha_limite_cambios'] ?? null;
        if ($lim === null || $lim === '') {
            return ['bloqueado' => false, 'motivo' => null, 'finalizado' => false, 'limite_pasada' => false];
        }
        $limStr = substr(trim((string) $lim), 0, 10);
        $hoy = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $limitePasada = $limStr !== '' && $hoy > $limStr;
        if ($limitePasada) {
            return [
                'bloqueado' => true,
                'motivo' => 'La fecha límite de cambios de nómina ya venció; solo consulta. Actualice deudas desde Informes si hace falta.',
                'finalizado' => false,
                'limite_pasada' => true,
            ];
        }

        return ['bloqueado' => false, 'motivo' => null, 'finalizado' => false, 'limite_pasada' => false];
    }

    public static function edicionBloqueada(\PDO $pdo, int $torneoId): bool
    {
        return self::estado($pdo, $torneoId)['bloqueado'];
    }

    public static function assertEdicionPermitida(\PDO $pdo, int $torneoId): void
    {
        $e = self::estado($pdo, $torneoId);
        if ($e['bloqueado']) {
            throw new \InvalidArgumentException($e['motivo'] ?? 'Movimiento de torneo no editable.');
        }
    }

    /**
     * Inscripciones / retiros en sitio: sin límite por `finalizado_en` ni `fecha_limite_cambios`.
     *
     * @return array{bloqueado: bool, motivo: ?string, finalizado: bool, limite_pasada: bool}
     */
    public static function estadoParaInscripciones(\PDO $pdo, int $torneoId): array
    {
        return ['bloqueado' => false, 'motivo' => null, 'finalizado' => false, 'limite_pasada' => false];
    }

    public static function assertInscripcionPermitida(\PDO $pdo, int $torneoId): void
    {
        // Sin bloqueo por fechas en el módulo de inscripciones en sitio.
    }

    /**
     * @return array{movimiento_torneo_bloqueado: bool, movimiento_bloqueo_motivo: ?string}
     */
    public static function flagsJsonInscripciones(\PDO $pdo, ?int $torneoId): array
    {
        return ['movimiento_torneo_bloqueado' => false, 'movimiento_bloqueo_motivo' => null];
    }

    /**
     * Recalcula `deuda_asociaciones` para el torneo (idempotente).
     *
     * @return int filas de asociación procesadas
     */
    public static function recalcularDeudaTorneo(\PDO $pdo, int $torneoId): int
    {
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DeudaAsociaciones.php';
        if (!\DeudaAsociaciones::tablaDisponible($pdo) || $torneoId < 1) {
            return 0;
        }

        return \DeudaAsociaciones::recalcularTorneoCompleto($pdo, $torneoId);
    }

    /**
     * @return array{movimiento_torneo_bloqueado: bool, movimiento_bloqueo_motivo: ?string}
     */
    public static function flagsJson(\PDO $pdo, ?int $torneoId): array
    {
        if ($torneoId === null || $torneoId < 1) {
            return ['movimiento_torneo_bloqueado' => false, 'movimiento_bloqueo_motivo' => null];
        }
        $e = self::estado($pdo, $torneoId);

        return [
            'movimiento_torneo_bloqueado' => $e['bloqueado'],
            'movimiento_bloqueo_motivo' => $e['motivo'],
        ];
    }
}
