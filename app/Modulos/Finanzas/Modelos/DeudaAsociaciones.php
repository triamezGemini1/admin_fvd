<?php

declare(strict_types=1);

namespace Fvd\Modulos\Finanzas\Modelos;

/**
 * Homologación de `deuda_asociaciones` con movimientos (`movimiento_torneo` + tarifas `costos`)
 * y abonos (`relacion_pagos.monto_total` en Bs, `monto_dolares` en EUR).
 *
 * Convención: contadores y montos vía {@see MovimientoTorneoContadores} (todos los indicadores = 1 en movimiento).
 * Sin incluir cargos «otros» de `finanza_cargo`, que no tienen torneo.
 */
class DeudaAsociaciones
{
    private const T_DEUDA = 'deuda_asociaciones';

    private const T_RP = 'relacion_pagos';

    public static function tablaDisponible(\PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE '" . self::T_DEUDA . "'");
            if ($st === false) {
                return false;
            }

            return $st->fetch(\PDO::FETCH_NUM) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Suma de abonos registrados en `relacion_pagos` para el par torneo/asociación.
     *
     * @return array{abono_bs: float, abono_eur: float}
     */
    private static function abonosDesdeRelacionPagos(\PDO $pdo, int $torneoId, int $asociacionId): array
    {
        $out = ['abono_bs' => 0.0, 'abono_eur' => 0.0];
        if ($asociacionId < 1) {
            return $out;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(monto_total), 0) AS s_bs, COALESCE(SUM(monto_dolares), 0) AS s_eur
                FROM ' . self::T_RP . ' WHERE torneo_id = :t AND asociacion_id = :a
                AND COALESCE(verificado, 0) = 1'
            );
            $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
            $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
            $stmt->execute();
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($r === false) {
                return $out;
            }
            $out['abono_bs'] = round((float) ($r['s_bs'] ?? 0), 2);
            $out['abono_eur'] = round((float) ($r['s_eur'] ?? 0), 6);

            return $out;
        } catch (Throwable $e) {
            error_log('DeudaAsociaciones::abonosDesdeRelacionPagos: ' . $e->getMessage());

            return $out;
        }
    }

    /**
     * Recalcula y persiste una fila (PK torneo_id + asociacion_id).
     */
    public static function recalcularFila(\PDO $pdo, int $torneoId, int $asociacionId): void
    {
        if ($asociacionId < 1 || !self::tablaDisponible($pdo)) {
            return;
        }

        $c = MovimientoTorneoContadores::contadoresTorneoAsociacion($pdo, $torneoId, $asociacionId);
        $u = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);

        $montoInsc = round($c['n_inscripcion'] * $u['inscripcion'], 2);
        $montoAfil = round($c['n_afiliacion'] * $u['afiliacion'], 2);
        $montoCar = round($c['n_carnet'] * $u['carnet'], 2);
        $montoAnu = round($c['n_anualidad'] * $u['anualidad'], 2);
        $montoTras = round($c['n_traspaso'] * $u['traspaso'], 2);

        $montoTotal = round($montoInsc + $montoAfil + $montoCar + $montoAnu + $montoTras, 2);
        $ab = self::abonosDesdeRelacionPagos($pdo, $torneoId, $asociacionId);

        $sql = 'INSERT INTO ' . self::T_DEUDA . ' (
            torneo_id, asociacion_id,
            total_inscritos, monto_inscritos,
            total_afiliados, monto_afiliados,
            total_carnets, monto_carnets,
            monto_anualidad, total_anualidad,
            total_traspasos, monto_traspasos,
            monto_total, monto_total_eur,
            abono, abono_eur
        ) VALUES (
            :t, :a,
            :ti, :mi,
            :taf, :maf,
            :tc, :mc,
            :manu, :tanu,
            :ttr, :mtr,
            :mtot, :mtoteur,
            :abo, :aboeur
        )
        ON DUPLICATE KEY UPDATE
            total_inscritos = VALUES(total_inscritos),
            monto_inscritos = VALUES(monto_inscritos),
            total_afiliados = VALUES(total_afiliados),
            monto_afiliados = VALUES(monto_afiliados),
            total_carnets = VALUES(total_carnets),
            monto_carnets = VALUES(monto_carnets),
            monto_anualidad = VALUES(monto_anualidad),
            total_anualidad = VALUES(total_anualidad),
            total_traspasos = VALUES(total_traspasos),
            monto_traspasos = VALUES(monto_traspasos),
            monto_total = VALUES(monto_total),
            monto_total_eur = VALUES(monto_total_eur),
            abono = VALUES(abono),
            abono_eur = VALUES(abono_eur)';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':ti', $c['n_inscripcion'], \PDO::PARAM_INT);
        $stmt->bindValue(':mi', $montoInsc, \PDO::PARAM_STR);
        $stmt->bindValue(':taf', $c['n_afiliacion'], \PDO::PARAM_INT);
        $stmt->bindValue(':maf', $montoAfil, \PDO::PARAM_STR);
        $stmt->bindValue(':tc', $c['n_carnet'], \PDO::PARAM_INT);
        $stmt->bindValue(':mc', $montoCar, \PDO::PARAM_STR);
        $stmt->bindValue(':manu', $montoAnu, \PDO::PARAM_STR);
        $stmt->bindValue(':tanu', $c['n_anualidad'], \PDO::PARAM_INT);
        $stmt->bindValue(':ttr', $c['n_traspaso'], \PDO::PARAM_INT);
        $stmt->bindValue(':mtr', $montoTras, \PDO::PARAM_STR);
        $stmt->bindValue(':mtot', $montoTotal, \PDO::PARAM_STR);
        $stmt->bindValue(':mtoteur', $montoTotal, \PDO::PARAM_STR);
        $stmt->bindValue(':abo', $ab['abono_bs'], \PDO::PARAM_STR);
        $stmt->bindValue(':aboeur', $ab['abono_eur'], \PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Tras una inscripción: recalcula la asociación del contexto y las que aparecen en movimiento
     * para los usuarios inscritos (p. ej. traspaso).
     *
     * @param list<int> $usuarioIds
     */
    public static function recalcularTrasInscripcion(\PDO $pdo, int $torneoId, int $asociacionContexto, array $usuarioIds): void
    {
        if (!self::tablaDisponible($pdo) || $torneoId < 0) {
            return;
        }
        $asocs = [$asociacionContexto];
        $usuarioIds = array_values(array_unique(array_map('intval', $usuarioIds)));
        if ($usuarioIds !== []) {
            $named = [];
            foreach ($usuarioIds as $ix => $uid) {
                $named[] = ':u' . $ix;
            }
            $inList = implode(',', $named);
            $st = $pdo->prepare(
                'SELECT DISTINCT asociacion_id FROM movimiento_torneo
                WHERE torneo_id = :t AND id_usuario IN (' . $inList . ')
                AND asociacion_id IS NOT NULL AND asociacion_id > 0'
            );
            $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
            foreach ($usuarioIds as $ix => $uid) {
                $st->bindValue(':u' . $ix, $uid, \PDO::PARAM_INT);
            }
            $st->execute();
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $asocs[] = (int) ($row['asociacion_id'] ?? 0);
            }
        }
        foreach (array_unique(array_filter($asocs, static fn (int $x): bool => $x > 0)) as $aid) {
            self::recalcularFila($pdo, $torneoId, $aid);
        }
    }

    /**
     * Recalcula `deuda_asociaciones` para cada torneo que tenga filas en `movimiento_torneo`.
     *
     * @return array{torneos: list<array{torneo_id: int, asociaciones: int}>, asociaciones_total: int}
     */
    public static function recalcularTodosTorneosConMovimiento(\PDO $pdo): array
    {
        $out = ['torneos' => [], 'asociaciones_total' => 0];
        if (!self::tablaDisponible($pdo)) {
            return $out;
        }
        $st = $pdo->query(
            'SELECT DISTINCT torneo_id FROM movimiento_torneo
             WHERE torneo_id IS NOT NULL AND torneo_id > 0 ORDER BY torneo_id ASC'
        );
        if ($st === false) {
            return $out;
        }
        $total = 0;
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $tid = (int) ($row['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $n = self::recalcularTorneoCompleto($pdo, $tid);
            $total += $n;
            $out['torneos'][] = ['torneo_id' => $tid, 'asociaciones' => $n];
        }
        $out['asociaciones_total'] = $total;

        return $out;
    }

    /**
     * Recalcula todas las asociaciones que tienen movimiento en el torneo (mantenimiento / batch).
     *
     * @return int filas procesadas
     */
    public static function recalcularTorneoCompleto(\PDO $pdo, int $torneoId): int
    {
        if (!self::tablaDisponible($pdo) || $torneoId < 0) {
            return 0;
        }
        $st = $pdo->prepare(
            'SELECT DISTINCT asociacion_id FROM movimiento_torneo
            WHERE torneo_id = :t AND asociacion_id IS NOT NULL AND asociacion_id > 0'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $n = 0;
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $aid = (int) ($row['asociacion_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            self::recalcularFila($pdo, $torneoId, $aid);
            $n++;
        }

        return $n;
    }

    /**
     * Tras cambios en movimiento de un usuario en un torneo (p. ej. afiliación).
     */
    public static function recalcularPorUsuarioTorneo(\PDO $pdo, int $torneoId, int $usuarioId): void
    {
        if (!self::tablaDisponible($pdo) || $torneoId < 0 || $usuarioId < 1) {
            return;
        }
        $st = $pdo->prepare(
            'SELECT DISTINCT asociacion_id FROM movimiento_torneo
            WHERE torneo_id = :t AND id_usuario = :u
            AND asociacion_id IS NOT NULL AND asociacion_id > 0'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->bindValue(':u', $usuarioId, \PDO::PARAM_INT);
        $st->execute();
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $aid = (int) ($row['asociacion_id'] ?? 0);
            if ($aid > 0) {
                self::recalcularFila($pdo, $torneoId, $aid);
            }
        }
    }
}
