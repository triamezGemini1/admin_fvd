<?php

declare(strict_types=1);

namespace Fvd\Modulos\Atletas\Modelos;

use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoContadores;
use RuntimeException;
use Throwable;

/**
 * Sincroniza en `movimiento_torneo` los campos afiliacion, anualidad, carnet,
 * traspaso e inscripcion desde `atletas`, emparejando solo por `numfvd`.
 * Atleta canónico por `numfvd`: fila con `MIN(id)` si hubiera duplicados.
 * Actualiza con prepared statements solo filas donde algún campo difiere.
 *
 * Estadísticas:
 * - leidos.movimiento_torneo: filas en movimiento con numfvd > 0 leídas.
 * - seleccionados: enlazan con atleta canónico y (si aplica) el atleta tiene movimiento por indicadores.
 * - por_tipo_seleccionados: por cada fila seleccionada, suma 1 por cada indicador activo en atletas
 *   (en `movimiento_torneo` se guardan como 0/1; en `atletas` legacy pueden ser 5, 20, 6, etc.).
 *   (una fila puede sumar en varios tipos; la suma puede ser > seleccionados).
 * - por_tipo_actualizados: por cada columna que realmente cambió en BD al escribir (dry-run: ceros).
 */
class SyncMovimientoTorneoTridenteDesdeAtletas
{
    private const T_M = 'movimiento_torneo';

    private const T_A = 'atletas';

    /** @var list<string> */
    private const CAMPOS = ['afiliacion', 'anualidad', 'carnet', 'traspaso', 'inscripcion'];

    /**
     * Condición SQL: atleta con movimiento según indicadores (sin columna `movimiento` en `atletas`).
     * Valores legacy habituales: afiliación/anualidad 5, carnet 20, traspaso 6; también acepta 1.
     * `anualidad` = 9 se trata como sin anualidad (no cuenta como movimiento).
     */
    public static function sqlWhereAtletaConMovimiento(string $alias = 'a'): string
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return '(' . $p . '`afiliacion` IN (1, 5) OR ' . $p . '`anualidad` IN (1, 5) OR '
            . $p . '`carnet` IN (1, 20) OR ' . $p . '`traspaso` IN (1, 6) OR ' . $p . '`inscripcion` = 1)';
    }

    /**
     * Indica si el valor del indicador en `atletas` cuenta como solicitud/movimiento activo.
     */
    public static function indicadorAtletaActivo(string $campo, int $valor): bool
    {
        if (!in_array($campo, self::CAMPOS, true)) {
            return false;
        }

        switch ($campo) {
            case 'afiliacion':
            case 'anualidad':
                return in_array($valor, [1, 5], true);
            case 'carnet':
                return in_array($valor, [1, 20], true);
            case 'traspaso':
                return in_array($valor, [1, 6], true);
            case 'inscripcion':
                return $valor === 1;
            default:
                return false;
        }
    }

    /** Normaliza indicador legacy de `atletas` a bandera 0/1 de `movimiento_torneo`. */
    public static function normalizarIndicadorMovimientoTorneo(string $campo, int $valorAtleta): int
    {
        return self::indicadorAtletaActivo($campo, $valorAtleta) ? 1 : 0;
    }

    /**
     * Expresión SQL: cédula sin espacios ni guiones (misma regla que {@see AfiliacionAtleta::normalizarCedula()}).
     */
    public static function sqlCedulaNormalizada(string $columna): string
    {
        return "REPLACE(REPLACE(TRIM({$columna}), '-', ''), ' ', '')";
    }

    /**
     * Fragmentos SELECT para contar indicadores activos en `atletas` (valores legacy 5, 20, 6…).
     */
    public static function sqlSelectConteoIndicadoresAtletas(string $alias = 'a'): string
    {
        $p = rtrim($alias, '.') . '.';

        return 'COALESCE(SUM(' . $p . '`afiliacion` IN (1, 5)), 0) AS n_afiliacion,
            COALESCE(SUM(' . $p . '`carnet` IN (1, 20)), 0) AS n_carnet,
            COALESCE(SUM(' . $p . '`traspaso` IN (1, 6)), 0) AS n_traspaso,
            COALESCE(SUM(' . $p . '`anualidad` IN (1, 5)), 0) AS n_anualidad,
            COALESCE(SUM(' . $p . '`inscripcion` = 1), 0) AS n_inscripcion';
    }

    /**
     * Conteos en tabla `atletas` (canónico MIN(id) por cédula si $soloCanon).
     *
     * @return array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int}
     */
    public static function conteosTablaAtletas(\PDO $pdo, bool $soloCanon = false): array
    {
        $from = '`' . self::T_A . '` a';
        if ($soloCanon) {
            $from .= ' INNER JOIN (
                SELECT MIN(`id`) AS `min_id` FROM `' . self::T_A . '` GROUP BY ' . self::sqlCedulaNormalizada('`cedula`') . '
            ) canon ON canon.`min_id` = a.`id`';
        }
        $st = $pdo->query('SELECT ' . self::sqlSelectConteoIndicadoresAtletas('a') . ' FROM ' . $from);
        if ($st === false) {
            return MovimientoTorneoContadores::filaVacia();
        }
        $r = $st->fetch(\PDO::FETCH_ASSOC);

        return $r === false ? MovimientoTorneoContadores::filaVacia() : MovimientoTorneoContadores::normalizarFilaAgregada($r);
    }

    public static function atletaCumpleCriterioIndicador(array $a): bool
    {
        foreach (self::CAMPOS as $c) {
            if (self::indicadorAtletaActivo($c, (int) ($a[$c] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public static function contarIndicadoresPorTipoEnAtleta(array $a): array
    {
        $out = [];
        foreach (self::CAMPOS as $c) {
            $out[$c] = self::normalizarIndicadorMovimientoTorneo($c, (int) ($a[$c] ?? 0));
        }

        return $out;
    }

    /**
     * @return list<array{numfvd:int,cnt:int}>
     */
    public static function numfvdDuplicadosEnAtletas(\PDO $pdo): array
    {
        $sql = 'SELECT `numfvd`, COUNT(*) AS cnt FROM `' . self::T_A . '`
                WHERE `numfvd` > 0 GROUP BY `numfvd` HAVING cnt > 1 ORDER BY cnt DESC';
        $st = $pdo->query($sql);

        return $st === false ? [] : $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param bool $soloSiIndicadorEnAtleta Si true, solo filas cuyo atleta (canónico) cumple OR de flags en 1
     * @param bool $dryRun Si true, no escribe; devuelve estadísticas igualmente
     *
     * @return array{
     *   leidos: array{movimiento_torneo:int, atletas_canon:int},
     *   seleccionados: int,
     *   seleccionados_distintos_numfvd: int,
     *   descartados: array{movimiento_sin_atleta:int, atleta_sin_indicador:int, movimiento_numfvd_cero:int},
     *   sin_cambio: int,
     *   actualizados: int,
     *   pendientes_escritura: int,
     *   por_tipo_seleccionados: array<string,int>,
     *   por_tipo_actualizados: array<string,int>,
     *   atletas_filas_criterio: int,
     *   atletas_cedulas_distintas_criterio: int,
     *   duplicados_numfvd_en_atletas: int,
     *   dry_run: bool
     * }
     */
    public static function ejecutar(\PDO $pdo, bool $soloSiIndicadorEnAtleta = true, bool $dryRun = false): array
    {
        $dups = self::numfvdDuplicadosEnAtletas($pdo);
        $nDup = count($dups);

        $wAt = self::sqlWhereAtletaConMovimiento('');
        $stAt = $pdo->query(
            'SELECT COUNT(*) AS filas, COUNT(DISTINCT TRIM(`cedula`)) AS cedulas FROM `' . self::T_A . '` WHERE ' . $wAt
        );
        $rowAt = $stAt === false ? [] : $stAt->fetch(\PDO::FETCH_ASSOC);

        $atletasCanon = (int) $pdo->query(
            'SELECT COUNT(*) FROM (SELECT `numfvd` FROM `' . self::T_A . '` WHERE `numfvd` > 0 GROUP BY `numfvd`) t'
        )->fetchColumn();

        $movNumfvdCero = (int) $pdo->query(
            'SELECT COUNT(*) FROM `' . self::T_M . '` WHERE `numfvd` IS NULL OR `numfvd` <= 0'
        )->fetchColumn();

        $sql = 'SELECT
                m.`id` AS mov_id,
                m.`numfvd` AS m_numfvd,
                m.`afiliacion` AS m_afiliacion,
                m.`anualidad` AS m_anualidad,
                m.`carnet` AS m_carnet,
                m.`traspaso` AS m_traspaso,
                m.`inscripcion` AS m_inscripcion,
                a.`id` AS atleta_id,
                a.`afiliacion` AS a_afiliacion,
                a.`anualidad` AS a_anualidad,
                a.`carnet` AS a_carnet,
                a.`traspaso` AS a_traspaso,
                a.`inscripcion` AS a_inscripcion
            FROM `' . self::T_M . '` m
            LEFT JOIN (
                SELECT a1.*
                FROM `' . self::T_A . '` a1
                INNER JOIN (
                    SELECT `numfvd`, MIN(`id`) AS `min_id`
                    FROM `' . self::T_A . '`
                    WHERE `numfvd` > 0
                    GROUP BY `numfvd`
                ) pick ON pick.`min_id` = a1.`id`
            ) a ON m.`numfvd` = a.`numfvd` AND m.`numfvd` > 0
            WHERE m.`numfvd` > 0';

        $st = $pdo->query($sql);
        if ($st === false) {
            throw new RuntimeException('No se pudo leer movimiento_torneo / atletas.');
        }

        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $porTipoSel = array_fill_keys(self::CAMPOS, 0);
        $porTipoAct = array_fill_keys(self::CAMPOS, 0);

        $leidosMov = count($rows);
        $sinAtleta = 0;
        $sinIndicador = 0;
        $seleccionados = 0;
        $sinCambio = 0;
        $actualizados = 0;
        $numfvdSeleccionados = [];

        /** @var list<array{mov_id:int, a: array<string,int>, columnas_cambiadas: list<string>}> */
        $pendientes = [];

        foreach ($rows as $r) {
            $nf = (int) ($r['m_numfvd'] ?? 0);

            $atletaId = $r['atleta_id'] ?? null;
            if ($atletaId === null || $atletaId === '') {
                $sinAtleta++;

                continue;
            }

            $aRaw = [
                'afiliacion' => $r['a_afiliacion'],
                'anualidad' => $r['a_anualidad'],
                'carnet' => $r['a_carnet'],
                'traspaso' => $r['a_traspaso'],
                'inscripcion' => $r['a_inscripcion'],
            ];

            if ($soloSiIndicadorEnAtleta && !self::atletaCumpleCriterioIndicador($aRaw)) {
                $sinIndicador++;

                continue;
            }

            $seleccionados++;
            $numfvdSeleccionados[$nf] = true;
            $a = self::contarIndicadoresPorTipoEnAtleta($aRaw);
            foreach ($a as $tipo => $uno) {
                if ($uno === 1) {
                    $porTipoSel[$tipo]++;
                }
            }

            $columnasCambiadas = [];
            foreach (self::CAMPOS as $c) {
                $mv = (int) ($r['m_' . $c] ?? 0);
                $av = (int) ($a[$c] ?? 0);
                if ($mv !== $av) {
                    $columnasCambiadas[] = $c;
                }
            }

            if ($columnasCambiadas === []) {
                $sinCambio++;

                continue;
            }

            $pendientes[] = [
                'mov_id' => (int) $r['mov_id'],
                'a' => $a,
                'columnas_cambiadas' => $columnasCambiadas,
            ];
        }

        if (!$dryRun && count($pendientes) > 0) {
            $pdo->beginTransaction();
            try {
                $sqlUp = 'UPDATE `' . self::T_M . '`
                    SET `afiliacion` = :af, `anualidad` = :an, `carnet` = :ca, `traspaso` = :tr, `inscripcion` = :in
                    WHERE `id` = :id';
                $stmt = $pdo->prepare($sqlUp);
                foreach ($pendientes as $p) {
                    $a = $p['a'];
                    $stmt->bindValue(':af', (int) $a['afiliacion'], \PDO::PARAM_INT);
                    $stmt->bindValue(':an', (int) $a['anualidad'], \PDO::PARAM_INT);
                    $stmt->bindValue(':ca', (int) $a['carnet'], \PDO::PARAM_INT);
                    $stmt->bindValue(':tr', (int) $a['traspaso'], \PDO::PARAM_INT);
                    $stmt->bindValue(':in', (int) $a['inscripcion'], \PDO::PARAM_INT);
                    $stmt->bindValue(':id', $p['mov_id'], \PDO::PARAM_INT);
                    $stmt->execute();
                    $actualizados++;
                    foreach ($p['columnas_cambiadas'] as $col) {
                        $porTipoAct[$col]++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return [
            'leidos' => [
                'movimiento_torneo' => $leidosMov,
                'atletas_canon' => $atletasCanon,
            ],
            'seleccionados' => $seleccionados,
            'seleccionados_distintos_numfvd' => count($numfvdSeleccionados),
            'descartados' => [
                'movimiento_sin_atleta' => $sinAtleta,
                'atleta_sin_indicador' => $sinIndicador,
                'movimiento_numfvd_cero' => $movNumfvdCero,
            ],
            'sin_cambio' => $sinCambio,
            'actualizados' => $dryRun ? 0 : $actualizados,
            'pendientes_escritura' => count($pendientes),
            'por_tipo_seleccionados' => $porTipoSel,
            'por_tipo_actualizados' => $dryRun ? array_fill_keys(self::CAMPOS, 0) : $porTipoAct,
            'atletas_filas_criterio' => (int) ($rowAt['filas'] ?? 0),
            'atletas_cedulas_distintas_criterio' => (int) ($rowAt['cedulas'] ?? 0),
            'duplicados_numfvd_en_atletas' => $nDup,
            'dry_run' => $dryRun,
        ];
    }
}
