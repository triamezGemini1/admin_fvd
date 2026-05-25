<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

/**
 * Lectura y mantenimiento de `torneosact` (PK = torneo).
 */
class AdminTorneo
{
    /**
     * Listado paginado con filtros opcionales.
     *
     * @param ?string $fase ''|null = todos; 'en_proceso' = `finalizado_en` nulo; 'realizados' = finalizado.
     * @param ?int    $grupoEventoId filtro por `grupo_evento_id` (solo debe enviarse desde UI admin. gral.).
     * @param bool    $soloSinGrupo solo torneos sin `grupo_evento_id` (solo admin. gral.).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function listar(\PDO $pdo, int $page, int $perPage, ?string $fase = null, ?int $grupoEventoId = null, bool $soloSinGrupo = false): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $where = [];
        $bind = [];
        $f = $fase !== null ? trim($fase) : '';
        if ($f === 'en_proceso') {
            $where[] = 'finalizado_en IS NULL';
        } elseif ($f === 'realizados') {
            $where[] = 'finalizado_en IS NOT NULL';
        }

        if ($grupoEventoId !== null && $grupoEventoId > 0) {
            $where[] = 'grupo_evento_id = :gid';
            $bind[':gid'] = [$grupoEventoId, \PDO::PARAM_INT];
        } elseif ($soloSinGrupo) {
            $where[] = '(grupo_evento_id IS NULL OR grupo_evento_id = 0)';
        }

        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM torneosact ' . $sqlWhere);
        foreach ($bind as $param => [$val, $type]) {
            $stmtCount->bindValue($param, $val, $type);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT * FROM torneosact ' . $sqlWhere . ' ORDER BY fechator DESC, torneo DESC LIMIT :lim OFFSET :off'
        );
        foreach ($bind as $param => [$val, $type]) {
            $stmt->bindValue($param, $val, $type);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['items' => $items === false ? [] : $items, 'total' => $total];
    }

    /**
     * Grupos de evento / campeonato ya usados en torneos (para asociar torneos al mismo ID).
     *
     * @return list<array{grupo_evento_id: int, etiqueta: string, n_torneos: int}>
     */
    public static function listarGruposEvento(\PDO $pdo): array
    {
        $sql = 'SELECT t.grupo_evento_id AS grupo_evento_id,
                       MIN(t.nombre) AS etiqueta,
                       COUNT(*) AS n_torneos
                FROM torneosact t
                WHERE t.grupo_evento_id IS NOT NULL AND t.grupo_evento_id > 0
                GROUP BY t.grupo_evento_id
                ORDER BY MAX(t.fechator) DESC, t.grupo_evento_id DESC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'grupo_evento_id' => (int) ($r['grupo_evento_id'] ?? 0),
                'etiqueta' => (string) ($r['etiqueta'] ?? ''),
                'n_torneos' => (int) ($r['n_torneos'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obtener(\PDO $pdo, int $torneoId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM torneosact WHERE torneo = :id LIMIT 1');
        $stmt->bindValue(':id', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Alta desde panel (sin archivos; invitación/afiche quedan null).
     *
     * @param array<string, mixed> $data
     */
    public static function crearDesdePanel(\PDO $pdo, array $data): int
    {
        $orgId = OrganizacionFvd::idOrganizadoraActiva($pdo);
        $data['organizacion_id'] = $orgId;

        return Torneo::crear($pdo, $data, null, null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function actualizar(\PDO $pdo, int $torneoId, array $data): void
    {
        $allowed = [
            'grupo_evento_id', 'apertura_anual', 'finalizado_en', 'organizacion_id', 'clavetor',
            'nombre', 'lugar', 'fechator', 'tipo', 'clase', 'tiempo', 'puntos', 'rondas', 'estatus',
            'costotor', 'ranking', 'pareclub', 'invitacion', 'afiche', 'publicar_landing',
            'invitaciones_despachadas', 'fecha_limite_cambios',
        ];
        $sets = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $sets[] = '`' . $col . '` = :' . $col;
        }
        if ($sets === []) {
            throw new InvalidArgumentException('No hay campos para actualizar.');
        }
        $sql = 'UPDATE torneosact SET ' . implode(', ', $sets) . ' WHERE torneo = :torneo LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':torneo', $torneoId, \PDO::PARAM_INT);
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            self::bindTorneoColumn($stmt, ':' . $col, $col, $data[$col]);
        }
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No se actualizó ningún registro.');
        }

        if (TorneoMovimientoLock::edicionBloqueada($pdo, $torneoId)) {
            TorneoMovimientoLock::recalcularDeudaTorneo($pdo, $torneoId);
        }
    }

    public static function eliminar(\PDO $pdo, int $torneoId): void
    {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM movimiento_torneo WHERE torneo_id = :t');
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        if ((int) $chk->fetchColumn() > 0) {
            throw new InvalidArgumentException('No se puede eliminar: hay movimientos de torneo vinculados.');
        }
        $stmt = $pdo->prepare('DELETE FROM torneosact WHERE torneo = :t LIMIT 1');
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No se eliminó ningún registro.');
        }
    }

    /**
     * Bloques de torneos listos para agrupar: pendientes (`finalizado_en` nulo), sin `grupo_evento_id`,
     * misma fecha (día) y mismo lugar (comparación normalizada).
     *
     * @return list<array{fechator: string, lugar: string, torneos: list<array<string, mixed>>}>
     */
    public static function candidatosAgrupacion(\PDO $pdo): array
    {
        $sql = 'SELECT torneo, nombre, clavetor, fechator, lugar
            FROM torneosact
            WHERE finalizado_en IS NULL
              AND (grupo_evento_id IS NULL OR grupo_evento_id = 0)
              AND fechator IS NOT NULL
              AND TRIM(CAST(fechator AS CHAR)) != \'\'
              AND lugar IS NOT NULL
              AND TRIM(CAST(lugar AS CHAR)) != \'\'
            ORDER BY fechator DESC, lugar ASC, torneo ASC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || $rows === []) {
            return [];
        }

        $buckets = [];
        foreach ($rows as $r) {
            $ft = trim((string) ($r['fechator'] ?? ''));
            $dateKey = strlen($ft) >= 10 ? substr($ft, 0, 10) : $ft;
            $lug = trim((string) ($r['lugar'] ?? ''));
            $lk = function_exists('mb_strtolower') ? mb_strtolower($lug, 'UTF-8') : strtolower($lug);
            $key = $dateKey . "\0" . $lk;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'fechator' => $dateKey,
                    'lugar' => $lug,
                    'torneos' => [],
                ];
            }
            $buckets[$key]['torneos'][] = $r;
        }

        $out = [];
        foreach ($buckets as $b) {
            if (count($b['torneos']) < 2) {
                continue;
            }
            $out[] = [
                'fechator' => $b['fechator'],
                'lugar' => $b['lugar'],
                'torneos' => $b['torneos'],
            ];
        }

        return $out;
    }

    /**
     * Asigna un mismo `grupo_evento_id` nuevo a entre 2 y 3 torneos (mismo día y lugar, pendientes, sin grupo).
     *
     * @param list<int|string> $ids
     * @return array{grupo_evento_id: int, torneo_ids: list<int>}
     */
    public static function agruparTorneosPorIds(\PDO $pdo, array $ids): array
    {
        $ids = [];
        foreach ($ids as $v) {
            $i = (int) $v;
            if ($i > 0 && !in_array($i, $ids, true)) {
                $ids[] = $i;
            }
        }
        if (count($ids) < 2 || count($ids) > 3) {
            throw new InvalidArgumentException('Debe seleccionar entre 2 y 3 torneos.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            'SELECT torneo, fechator, lugar, finalizado_en, grupo_evento_id FROM torneosact WHERE torneo IN (' . $placeholders . ')'
        );
        foreach ($ids as $k => $id) {
            $stmt->bindValue($k + 1, $id, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || count($rows) !== count($ids)) {
            throw new InvalidArgumentException('Uno o más torneos no existen.');
        }

        $dateKey = null;
        $lugarKey = null;
        foreach ($rows as $r) {
            $fin = $r['finalizado_en'] ?? null;
            if ($fin !== null && trim((string) $fin) !== '') {
                throw new InvalidArgumentException('Solo torneos pendientes (sin fecha de cierre).');
            }
            $gid = (int) ($r['grupo_evento_id'] ?? 0);
            if ($gid > 0) {
                throw new InvalidArgumentException('Los torneos no deben estar ya asociados a un campeonato (grupo).');
            }
            $ft = trim((string) ($r['fechator'] ?? ''));
            if ($ft === '') {
                throw new InvalidArgumentException('Falta fecha en uno de los torneos.');
            }
            $d = strlen($ft) >= 10 ? substr($ft, 0, 10) : $ft;
            $lug = trim((string) ($r['lugar'] ?? ''));
            if ($lug === '') {
                throw new InvalidArgumentException('Falta lugar en uno de los torneos.');
            }
            $lk = function_exists('mb_strtolower') ? mb_strtolower($lug, 'UTF-8') : strtolower($lug);
            if ($dateKey === null) {
                $dateKey = $d;
                $lugarKey = $lk;
            } elseif ($dateKey !== $d || $lugarKey !== $lk) {
                throw new InvalidArgumentException('Todos los torneos deben tener la misma fecha y el mismo lugar.');
            }
        }

        $pdo->beginTransaction();
        try {
            $stmtMax = $pdo->query('SELECT COALESCE(MAX(grupo_evento_id), 0) + 1 AS g FROM torneosact');
            if ($stmtMax === false) {
                throw new RuntimeException('No se pudo calcular el grupo de evento.');
            }
            $newG = (int) $stmtMax->fetchColumn();
            if ($newG < 1) {
                $newG = 1;
            }

            $upd = $pdo->prepare(
                'UPDATE torneosact SET grupo_evento_id = :g
                 WHERE torneo = :t AND finalizado_en IS NULL AND (grupo_evento_id IS NULL OR grupo_evento_id = 0)
                 LIMIT 1'
            );
            foreach ($ids as $tid) {
                $upd->bindValue(':g', $newG, \PDO::PARAM_INT);
                $upd->bindValue(':t', $tid, \PDO::PARAM_INT);
                $upd->execute();
                if ($upd->rowCount() < 1) {
                    throw new RuntimeException('No se pudo actualizar el torneo ' . $tid . ' (estado cambió o no elegible).');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['grupo_evento_id' => $newG, 'torneo_ids' => $ids];
    }

    /**
     * @param mixed $value
     */
    private static function bindTorneoColumn(\PDOStatement $stmt, string $param, string $col, $value): void
    {
        $intCols = [
            'grupo_evento_id', 'apertura_anual', 'organizacion_id', 'tipo', 'clase', 'tiempo', 'puntos',
            'rondas', 'estatus', 'ranking', 'pareclub', 'publicar_landing', 'invitaciones_despachadas',
        ];
        if ($col === 'finalizado_en') {
            $v = $value === null || $value === '' ? null : (string) $value;
            if ($v === null) {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, $v, \PDO::PARAM_STR);
            }

            return;
        }
        if ($col === 'costotor') {
            if ($value === null || $value === '') {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (string) (float) $value, \PDO::PARAM_STR);
            }

            return;
        }
        if ($col === 'fechator' || $col === 'fecha_limite_cambios') {
            $v = $value === null || $value === '' ? null : (string) $value;
            if ($v === null) {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, $v, \PDO::PARAM_STR);
            }

            return;
        }
        if (in_array($col, $intCols, true)) {
            if ($value === null || $value === '') {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (int) $value, \PDO::PARAM_INT);
            }

            return;
        }
        $s = $value === null ? null : trim((string) $value);
        if ($s === null || $s === '') {
            $stmt->bindValue($param, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $s, \PDO::PARAM_STR);
        }
    }
}
