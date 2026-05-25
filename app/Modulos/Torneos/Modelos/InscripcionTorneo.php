<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

/**
 * Panel de inscripciones: torneo activo, disponibles vs inscritos en `movimiento_torneo`, alta de inscripción.
 */
class InscripcionTorneo
{
    private const T_U = 'usuarios';

    private const T_M = 'movimiento_torneo';

    private const T_T = 'torneosact';

    /**
     * @return array<string, mixed>|null fila torneo (PK `torneo`)
     */
    public static function torneoActivo(\PDO $pdo): ?array
    {
        $sql = 'SELECT * FROM ' . self::T_T . ' WHERE finalizado_en IS NULL ORDER BY fechator DESC, torneo DESC LIMIT 1';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return 'individual'|'parejas'|'equipos'|null
     */
    public static function modalidadDesdeTorneo(?array $torneo): ?string
    {
        if ($torneo === null) {
            return null;
        }
        $clase = isset($torneo['clase']) ? (int) $torneo['clase'] : 0;
        if ($clase === 1) {
            return 'individual';
        }
        if ($clase === 2) {
            return 'parejas';
        }
        if ($clase === 3) {
            return 'equipos';
        }

        return null;
    }

    /**
     * Filtro sexo según `torneosact.tipo`: 1=M, 2=F, 3=Mixto (cualquiera).
     *
     * @return array{sql: string, params: array<string, int>}
     */
    public static function filtroSexoSql(int $tipoTorneo): array
    {
        if ($tipoTorneo === 1) {
            return ['sql' => ' AND u.sexo = :sx ', 'params' => [':sx' => 1]];
        }
        if ($tipoTorneo === 2) {
            return ['sql' => ' AND u.sexo = :sx ', 'params' => [':sx' => 2]];
        }

        return ['sql' => ' AND u.sexo IN (1, 2) ', 'params' => []];
    }

    /**
     * `torneosact.tipo`: 1=M, 2=F; otro o null → mixto (sin restricción M/F más allá de IN 1,2).
     */
    public static function normalizarTipoTorneo(mixed $tipo): int
    {
        $t = (int) $tipo;

        return ($t === 1 || $t === 2) ? $t : 3;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarDisponibles(\PDO $pdo, int $torneoId, int $asociacionId, int $tipoTorneo): array
    {
        $f = self::filtroSexoSql($tipoTorneo);
        $sql = 'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.fechnac, u.email
            FROM ' . self::T_U . ' u
            WHERE u.asociacion_id = :aid
            ' . $f['sql'] . "
            AND EXISTS (
                SELECT 1 FROM " . self::T_M . " m0
                WHERE m0.id_usuario = u.id AND m0.torneo_id = :tid
                AND m0.anualidad >= 1 AND m0.carnet >= 1
            )
            AND NOT EXISTS (
                SELECT 1 FROM " . self::T_M . " m
                WHERE m.id_usuario = u.id AND m.torneo_id = :tid2 AND m.inscripcion = 1
            )
            ORDER BY u.nombre ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':tid2', $torneoId, \PDO::PARAM_INT);
        foreach ($f['params'] as $k => $v) {
            $stmt->bindValue($k, $v, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarInscritos(\PDO $pdo, int $torneoId, int $asociacionId): array
    {
        $sql = 'SELECT m.id AS mov_id, m.id_usuario, m.cedula, m.numfvd, m.sexo, m.inscripcion, m.torneo_id,
                m.grupo_nombre, m.grupo_id, m.afiliacion, m.anualidad, m.carnet, m.traspaso,
                u.nombre AS nombre_usuario, u.email, u.asociacion_id AS usuario_asociacion_id
            FROM ' . self::T_M . ' m
            INNER JOIN ' . self::T_U . " u ON u.id = m.id_usuario
            WHERE m.torneo_id = :tid AND m.inscripcion = 1
            AND (m.asociacion_id = :aid OR u.asociacion_id = :aid2)
            ORDER BY m.grupo_id IS NULL, m.grupo_id, m.grupo_nombre IS NULL, m.grupo_nombre, u.nombre";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid2', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @param list<string> $busquedas cédula, nombre o email por línea
     * @return list<int> ids usuario
     */
    public static function resolverUsuariosPorLineas(\PDO $pdo, array $busquedas, int $asociacionId): array
    {
        $ids = [];
        foreach ($busquedas as $raw) {
            $q = trim((string) $raw);
            if ($q === '') {
                throw new InvalidArgumentException('Hay líneas de búsqueda vacías.');
            }
            $u = self::buscarUnUsuarioPreferenciaAsociacion($pdo, $q, $asociacionId);
            if ($u === null) {
                throw new InvalidArgumentException('No se encontró usuario para: ' . $q);
            }
            $id = (int) $u['id'];
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException('No repita el mismo atleta: ' . $q);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Búsqueda predictiva (mín. 3 caracteres en API): usuarios de la asociación.
     *
     * @return list<array{id: int, cedula: string, nombre: string, numfvd: int, sexo: int, email: string}>
     */
    public static function buscarUsuariosParaAutocomplete(\PDO $pdo, string $termino, ?int $asociacionIdFiltro, int $limite = 15, ?int $torneoIdSoloElegibles = null): array
    {
        $termino = trim($termino);
        if ($termino === '' || $limite < 1) {
            return [];
        }
        $limite = min($limite, 30);
        $eleg = '';
        if ($torneoIdSoloElegibles !== null && $torneoIdSoloElegibles > 0) {
            $eleg = ' AND EXISTS (
                SELECT 1 FROM ' . self::T_M . ' m
                WHERE m.id_usuario = u.id AND m.torneo_id = :tid_eleg
                AND m.anualidad >= 1 AND m.carnet >= 1
                AND COALESCE(m.inscripcion, 0) <> 1
            )';
        }
        $asocSql = $asociacionIdFiltro !== null && $asociacionIdFiltro > 0 ? ' AND u.asociacion_id = :aid ' : '';
        $sql = 'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.email
            FROM ' . self::T_U . " u
            WHERE u.status IN (1, 9)
            {$asocSql}
            AND (
                u.cedula LIKE CONCAT('%', :qb, '%')
                OR u.email LIKE CONCAT('%', :qb2, '%')
                OR u.nombre LIKE CONCAT('%', :qb3, '%')
            )
            {$eleg}
            ORDER BY u.nombre ASC
            LIMIT " . (int) $limite;
        $stmt = $pdo->prepare($sql);
        if ($asociacionIdFiltro !== null && $asociacionIdFiltro > 0) {
            $stmt->bindValue(':aid', $asociacionIdFiltro, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':qb', $termino, \PDO::PARAM_STR);
        $stmt->bindValue(':qb2', $termino, \PDO::PARAM_STR);
        $stmt->bindValue(':qb3', $termino, \PDO::PARAM_STR);
        if ($torneoIdSoloElegibles !== null && $torneoIdSoloElegibles > 0) {
            $stmt->bindValue(':tid_eleg', $torneoIdSoloElegibles, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @param list<int> $usuarioIds
     * @return array{had_traspaso: bool}
     */
    public static function inscribir(
        PDO $pdo,
        int $torneoId,
        int $asociacionId,
        string $modalidad,
        array $usuarioIds,
        ?string $grupoNombre
    ): array {
        $expected = ['individual' => 1, 'parejas' => 2, 'equipos' => 4];
        if (!isset($expected[$modalidad]) || count($usuarioIds) !== $expected[$modalidad]) {
            throw new InvalidArgumentException('Cantidad de atletas no coincide con la modalidad.');
        }
        $torneo = self::obtenerTorneoPorId($pdo, $torneoId);
        if ($torneo === null) {
            throw new InvalidArgumentException('Torneo no válido.');
        }
        TorneoMovimientoLock::assertEdicionPermitida($pdo, $torneoId);
        if (self::modalidadDesdeTorneo($torneo) !== $modalidad) {
            throw new InvalidArgumentException('La modalidad no coincide con el torneo activo.');
        }
        $tipoTorneo = self::normalizarTipoTorneo($torneo['tipo'] ?? null);
        $hadTraspaso = false;
        $pdo->beginTransaction();
        try {
            foreach ($usuarioIds as $uid) {
                $u = self::obtenerUsuario($pdo, (int) $uid);
                if ($u === null) {
                    throw new InvalidArgumentException('Usuario no encontrado.');
                }
                self::validarSexoUsuario((int) $u['sexo'], $tipoTorneo);
                self::assertMovimientoAnualidadCarnetOk($pdo, (int) $uid, $torneoId, (string) $u['nombre']);
            }
            $grupoId = $modalidad !== 'individual' ? self::allocarGrupoId($pdo, $torneoId) : null;
            foreach ($usuarioIds as $uid) {
                $u = self::obtenerUsuario($pdo, (int) $uid);
                if ($u === null) {
                    throw new InvalidArgumentException('Usuario no encontrado.');
                }
                if (self::upsertInscripcion($pdo, (int) $uid, $u, $torneoId, $asociacionId, $grupoNombre, $grupoId)) {
                    $hadTraspaso = true;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['had_traspaso' => $hadTraspaso];
    }

    private static function validarSexoUsuario(int $sexo, int $tipoTorneo): void
    {
        if ($tipoTorneo === 1 && $sexo !== 1) {
            throw new InvalidArgumentException('El torneo es masculino: sexo del atleta no válido.');
        }
        if ($tipoTorneo === 2 && $sexo !== 2) {
            throw new InvalidArgumentException('El torneo es femenino: sexo del atleta no válido.');
        }
        if ($tipoTorneo === 3 && $sexo !== 1 && $sexo !== 2) {
            throw new InvalidArgumentException('Sexo del atleta no válido para torneo mixto.');
        }
    }

    /**
     * Exige fila en `movimiento_torneo` para este torneo con tridente de anualidad y carnet al día (valor 1).
     */
    private static function assertMovimientoAnualidadCarnetOk(\PDO $pdo, int $userId, int $torneoId, string $nombreAtleta): void
    {
        $stmt = $pdo->prepare(
            'SELECT anualidad, carnet FROM ' . self::T_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException(
                'Sin movimiento en este torneo para «' . $nombreAtleta . '»: el atleta debe estar afiliado al torneo activo antes de inscribir.'
            );
        }
        if ((int) ($row['anualidad'] ?? 0) < 1 || (int) ($row['carnet'] ?? 0) < 1) {
            throw new InvalidArgumentException(
                'Anualidad o carnet no vigentes para «' . $nombreAtleta . '» (revise movimiento en este torneo).'
            );
        }
    }

    /**
     * Identificador común para pareja/equipo dentro del mismo torneo (resultados agrupados).
     */
    private static function allocarGrupoId(\PDO $pdo, int $torneoId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(grupo_id), 0) + 1 FROM ' . self::T_M . ' WHERE torneo_id = :t');
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $n = (int) $stmt->fetchColumn();

        return $n > 0 ? $n : 1;
    }

    /**
     * @param array<string, mixed> $u fila usuario
     * @return bool true si se marcó traspaso (atleta de otra asociación inscrito bajo esta asociación)
     */
    private static function upsertInscripcion(\PDO $pdo, int $userId, array $u, int $torneoId, int $asociacionId, ?string $grupoNombre, ?int $grupoId): bool
    {
        $cedula = trim((string) $u['cedula']);
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $origenAid = (int) ($u['asociacion_id'] ?? 0);
        $traspaso = ($origenAid > 0 && $origenAid !== $asociacionId) ? 1 : 0;
        $gn = $grupoNombre !== null ? trim($grupoNombre) : null;
        $gn = $gn === '' ? null : $gn;
        $gid = $grupoId !== null && $grupoId > 0 ? $grupoId : null;

        $chk = $pdo->prepare('SELECT id FROM ' . self::T_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1');
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        $mid = $chk->fetchColumn();
        if ($mid !== false) {
            $sql = 'UPDATE ' . self::T_M . ' SET inscripcion = 1, cedula = :c, numfvd = :n, sexo = :s, asociacion_id = :a, grupo_nombre = :g, grupo_id = :gid, traspaso = :tp WHERE id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $stmt->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $stmt->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
            $stmt->bindValue(':g', $gn, $gn === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $stmt->bindValue(':gid', $gid, $gid === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindValue(':tp', $traspaso, \PDO::PARAM_INT);
            $stmt->bindValue(':id', (int) $mid, \PDO::PARAM_INT);
            $stmt->execute();
            if ($traspaso === 1) {
                error_log(sprintf(
                    '[FVD][TRASPASO_INSCRIPCION] torneo_id=%d asociacion_inscripcion=%d usuario_id=%d asociacion_atleta=%d',
                    $torneoId,
                    $asociacionId,
                    $userId,
                    $origenAid
                ));
            }

            return $traspaso === 1;
        }

        throw new InvalidArgumentException(
            'No existe movimiento de torneo para este usuario: complete afiliación y tridente (anualidad/carnet) antes de inscribir.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioEnAsociacion(\PDO $pdo, string $q, int $asociacionId): ?array
    {
        $q2 = '%' . $q . '%';
        $stmt = $pdo->prepare(
            'SELECT id, cedula, nombre, numfvd, sexo, asociacion_id, email FROM ' . self::T_U . '
            WHERE asociacion_id = :aid AND (cedula = :c OR email = :e OR nombre LIKE :n)
            ORDER BY (cedula = :c2) DESC, (email = :e2) DESC
            LIMIT 1'
        );
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':c', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $q2, \PDO::PARAM_STR);
        $stmt->bindValue(':c2', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e2', $q, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Resolución de texto: primero en la asociación del contexto; si no hay coincidencia, búsqueda global (traspaso).
     *
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioPreferenciaAsociacion(\PDO $pdo, string $q, int $contextoAsociacionId): ?array
    {
        $local = self::buscarUnUsuarioEnAsociacion($pdo, $q, $contextoAsociacionId);
        if ($local !== null) {
            return $local;
        }

        return self::buscarUnUsuarioGlobal($pdo, $q);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioGlobal(\PDO $pdo, string $q): ?array
    {
        $q2 = '%' . $q . '%';
        $stmt = $pdo->prepare(
            'SELECT id, cedula, nombre, numfvd, sexo, asociacion_id, email FROM ' . self::T_U . '
            WHERE (cedula = :c OR email = :e OR nombre LIKE :n)
            ORDER BY (cedula = :c2) DESC, (email = :e2) DESC
            LIMIT 1'
        );
        $stmt->bindValue(':c', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $q2, \PDO::PARAM_STR);
        $stmt->bindValue(':c2', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e2', $q, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Inscripciones con posible traspaso pendiente de revisión por administración general.
     */
    public static function contarTraspasosInscripcionPendientes(\PDO $pdo, int $torneoId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::T_M . ' WHERE torneo_id = :t AND inscripcion = 1 AND traspaso = 1'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inscripciones con traspaso pendiente de supervisión FVD, en la nómina de la asociación de inscripción.
     *
     * @return list<array<string, mixed>>
     */
    public static function listarSolicitudesTraspasoInscripcion(\PDO $pdo, int $torneoId, int $asociacionInscripcionId, ?string $q): array
    {
        $sql = 'SELECT m.id AS movimiento_id, m.cedula, m.numfvd, m.sexo, m.grupo_nombre,
            u.id AS usuario_id, u.nombre AS nombre_usuario, u.email AS usuario_email,
            u.asociacion_id AS usuario_asociacion_id,
            COALESCE(ao.nombre, \'—\') AS asociacion_origen_nombre
            FROM ' . self::T_M . ' m
            INNER JOIN ' . self::T_U . ' u ON u.id = m.id_usuario
            LEFT JOIN asociaciones ao ON ao.id = u.asociacion_id
            WHERE m.torneo_id = :tid AND m.asociacion_id = :aid
              AND m.inscripcion = 1 AND m.traspaso = 1';
        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $sql .= ' AND (u.cedula LIKE :qc OR u.nombre LIKE :qn OR u.email LIKE :qe)';
        }
        $sql .= ' ORDER BY u.nombre ASC, m.cedula ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid', $asociacionInscripcionId, \PDO::PARAM_INT);
        if ($qTrim !== '') {
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qTrim);
            $like = '%' . $esc . '%';
            $stmt->bindValue(':qc', $like, \PDO::PARAM_STR);
            $stmt->bindValue(':qn', $like, \PDO::PARAM_STR);
            $stmt->bindValue(':qe', $like, \PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return array{inscritos: int, costo_bs: float|null, tasa_eur_bs: float, total_bs: float|null, total_eur: float|null}
     */
    public static function resumenFinanzasInscripcion(\PDO $pdo, int $torneoId, int $asociacionId, ?float $costoTorneoBs, float $tasaEurBs): array
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::T_M . ' m
            INNER JOIN ' . self::T_U . ' u ON u.id = m.id_usuario
            WHERE m.torneo_id = :t AND m.inscripcion = 1
            AND (m.asociacion_id = :a OR u.asociacion_id = :a2)'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':a2', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $n = (int) $stmt->fetchColumn();
        $costo = $costoTorneoBs !== null ? (float) $costoTorneoBs : null;
        $totalBs = $costo !== null ? round($n * $costo, 2) : null;
        $totalEur = ($totalBs !== null && $tasaEurBs > 0) ? round($totalBs / $tasaEurBs, 2) : null;

        return [
            'inscritos' => $n,
            'costo_bs' => $costo,
            'tasa_eur_bs' => $tasaEurBs,
            'total_bs' => $totalBs,
            'total_eur' => $totalEur,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerUsuario(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_U . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerTorneoPorId(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_T . ' WHERE torneo = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
