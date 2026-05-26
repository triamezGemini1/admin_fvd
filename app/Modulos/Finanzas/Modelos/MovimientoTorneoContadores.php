<?php

declare(strict_types=1);

namespace Fvd\Modulos\Finanzas\Modelos;

/**
 * Contadores y tarifas unificados desde `movimiento_torneo` × `costos`.
 *
 * Regla financiera: cada renglón cuenta solo filas con el indicador estrictamente en 1
 * (`SUM(campo = 1)`). Valores 0, 2, 20 u otros distintos de 1 no entran en estadísticas ni montos.
 * La supervisión FVD puede filtrar colas por Nº FVD pendiente; aquí no se excluyen cargos por eso.
 */
class MovimientoTorneoContadores
{
    private const T_MOV = 'movimiento_torneo';

    private const T_COSTOS = 'costos';

    /** @var list<string> */
    public const INDICADORES = ['afiliacion', 'anualidad', 'carnet', 'traspaso', 'inscripcion'];

    /**
     * Fragmentos SELECT para agregación (alias de tabla movimiento, p. ej. `m`).
     */
    public static function sqlSelectAgregados(string $aliasMov = 'm'): string
    {
        $p = rtrim($aliasMov, '.') . '.';

        return 'COALESCE(SUM(' . $p . '`afiliacion` = 1), 0) AS n_afiliacion,
            COALESCE(SUM(' . $p . '`carnet` = 1), 0) AS n_carnet,
            COALESCE(SUM(' . $p . '`traspaso` = 1), 0) AS n_traspaso,
            COALESCE(SUM(' . $p . '`anualidad` = 1), 0) AS n_anualidad,
            COALESCE(SUM(' . $p . '`inscripcion` = 1), 0) AS n_inscripcion';
    }

    /**
     * @return array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}
     */
    public static function filaVacia(): array
    {
        return [
            'n_afiliacion' => 0,
            'n_carnet' => 0,
            'n_traspaso' => 0,
            'n_anualidad' => 0,
            'n_inscripcion' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}
     */
    public static function normalizarFilaAgregada(array $row): array
    {
        return [
            'n_afiliacion' => (int) ($row['n_afiliacion'] ?? 0),
            'n_carnet' => (int) ($row['n_carnet'] ?? 0),
            'n_traspaso' => (int) ($row['n_traspaso'] ?? 0),
            'n_anualidad' => (int) ($row['n_anualidad'] ?? 0),
            'n_inscripcion' => (int) ($row['n_inscripcion'] ?? 0),
        ];
    }

    /**
     * @return array<string, array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}>
     */
    public static function contadoresPorAsociacion(\PDO $pdo, ?int $torneoId): array
    {
        $sql = 'SELECT m.asociacion_id AS aid, ' . self::sqlSelectAgregados('m') . '
            FROM ' . self::T_MOV . ' m
            WHERE m.asociacion_id IS NOT NULL AND m.asociacion_id > 0';
        if ($torneoId !== null && $torneoId > 0) {
            $sql .= ' AND m.torneo_id = :tid';
        }
        $sql .= ' GROUP BY m.asociacion_id';
        $stmt = $pdo->prepare($sql);
        if ($torneoId !== null && $torneoId > 0) {
            $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['aid'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $out[(string) $aid] = self::normalizarFilaAgregada($r);
        }

        return $out;
    }

    /**
     * Agregación por asociación limitada a varios torneos (campeonato).
     *
     * @param list<int> $torneoIds
     *
     * @return array<string, array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}>
     */
    public static function contadoresPorAsociacionTorneos(\PDO $pdo, array $torneoIds): array
    {
        $ids = [];
        foreach ($torneoIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        if (count($ids) === 1) {
            return self::contadoresPorAsociacion($pdo, $ids[0]);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT m.asociacion_id AS aid, ' . self::sqlSelectAgregados('m') . '
            FROM ' . self::T_MOV . ' m
            WHERE m.asociacion_id IS NOT NULL AND m.asociacion_id > 0
              AND m.torneo_id IN (' . $ph . ')
            GROUP BY m.asociacion_id';
        $stmt = $pdo->prepare($sql);
        foreach ($ids as $i => $tid) {
            $stmt->bindValue($i + 1, $tid, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['aid'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $out[(string) $aid] = self::normalizarFilaAgregada($r);
        }

        return $out;
    }

    /**
     * Totales globales (sin agrupar por asociación) en uno o varios torneos, o en toda la nómina.
     *
     * @param list<int>|null $torneoIds si se indica, limita a esos torneos (prioridad sobre $torneoId)
     *
     * @return array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}
     */
    public static function contadoresGlobales(
        \PDO $pdo,
        ?int $torneoId = null,
        ?array $torneoIds = null,
        ?int $asociacionId = null
    ): array {
        $sql = 'SELECT ' . self::sqlSelectAgregados('m') . ' FROM ' . self::T_MOV . ' m WHERE 1=1';
        /** @var array<string, int> $bind */
        $bind = [];

        if ($asociacionId !== null && $asociacionId > 0) {
            $sql .= ' AND m.asociacion_id = :aid';
            $bind[':aid'] = $asociacionId;
        }

        if ($torneoIds !== null) {
            $ids = [];
            foreach ($torneoIds as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $ids[$n] = true;
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                return self::filaVacia();
            }
            if (count($ids) === 1) {
                $sql .= ' AND m.torneo_id = :tid';
                $bind[':tid'] = $ids[0];
            } else {
                $ph = [];
                foreach ($ids as $i => $tid) {
                    $key = ':t' . $i;
                    $ph[] = $key;
                    $bind[$key] = $tid;
                }
                $sql .= ' AND m.torneo_id IN (' . implode(',', $ph) . ')';
            }
        } elseif ($torneoId !== null && $torneoId > 0) {
            $sql .= ' AND m.torneo_id = :tid';
            $bind[':tid'] = $torneoId;
        }

        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $r === false ? self::filaVacia() : self::normalizarFilaAgregada($r);
    }

    /**
     * @return array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}
     */
    public static function contadoresTorneoAsociacion(\PDO $pdo, int $torneoId, int $asociacionId): array
    {
        if ($torneoId < 0 || $asociacionId < 1) {
            return self::filaVacia();
        }
        $sql = 'SELECT ' . self::sqlSelectAgregados('m') . '
            FROM ' . self::T_MOV . ' m
            WHERE m.asociacion_id = :a AND m.torneo_id = :t';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $r === false ? self::filaVacia() : self::normalizarFilaAgregada($r);
    }

    /**
     * @return array{afiliacion: float, carnet: float, traspaso: float, anualidad: float, inscripcion: float}
     */
    public static function tarifaCostosMasReciente(\PDO $pdo): array
    {
        $defaults = [
            'afiliacion' => 0.0,
            'carnet' => 0.0,
            'traspaso' => 0.0,
            'anualidad' => 0.0,
            'inscripcion' => 0.0,
        ];
        try {
            $stmt = $pdo->query(
                'SELECT afiliacion, anualidad, carnets, traspasos, inscripciones FROM '
                . self::T_COSTOS . ' ORDER BY fecha DESC, id DESC LIMIT 1'
            );
            if ($stmt === false) {
                return $defaults;
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return $defaults;
            }

            return [
                'afiliacion' => round((float) ($row['afiliacion'] ?? 0), 2),
                'carnet' => round((float) ($row['carnets'] ?? 0), 2),
                'traspaso' => round((float) ($row['traspasos'] ?? 0), 2),
                'anualidad' => round((float) ($row['anualidad'] ?? 0), 2),
                'inscripcion' => round((float) ($row['inscripciones'] ?? 0), 2),
            ];
        } catch (Throwable $e) {
            error_log('MovimientoTorneoContadores::tarifaCostosMasReciente: ' . $e->getMessage());

            return $defaults;
        }
    }

    /**
     * @param array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int} $c
     * @param array{afiliacion: float, carnet: float, traspaso: float, anualidad: float, inscripcion: float} $units
     *
     * @return array{afiliacion: float, carnet: float, traspaso: float, anualidad: float, inscripcion: float}
     */
    public static function montosPorTarifaYContadores(array $c, array $units): array
    {
        return [
            'afiliacion' => round($c['n_afiliacion'] * $units['afiliacion'], 2),
            'carnet' => round($c['n_carnet'] * $units['carnet'], 2),
            'traspaso' => round($c['n_traspaso'] * $units['traspaso'], 2),
            'anualidad' => round($c['n_anualidad'] * $units['anualidad'], 2),
            'inscripcion' => round($c['n_inscripcion'] * $units['inscripcion'], 2),
        ];
    }

    /**
     * @param array{afiliacion: float, carnet: float, traspaso: float, anualidad: float, inscripcion: float} $montos
     */
    public static function totalNominaDesdeMontos(array $montos): float
    {
        return round(
            (float) ($montos['afiliacion'] ?? 0)
            + (float) ($montos['carnet'] ?? 0)
            + (float) ($montos['traspaso'] ?? 0)
            + (float) ($montos['anualidad'] ?? 0)
            + (float) ($montos['inscripcion'] ?? 0),
            2
        );
    }
}
