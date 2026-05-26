<?php

declare(strict_types=1);

namespace Fvd\Modulos\Delegados\Modelos;

use Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta;
use Fvd\Modulos\Auth\Modelos\Auth;
use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;
use InvalidArgumentException;

/**
 * Operaciones del delegado sobre `movimiento_torneo` por torneo seleccionado.
 *
 * Modelo de datos: los reportes y el estado operativo del torneo se leen de `movimiento_torneo`.
 * Cada solicitud delegada queda además referenciada en `fvd_solicitudes_delegado` (pendiente → aprobada/rechazada en supervisión FVD).
 * Identidad y asociación del afiliado siguen en `usuarios`; cada acción delegada debe dejar reflejo
 * coherente en `movimiento_torneo` (inscripción, carnet, traspaso, alta nueva).
 *
 * Alta nueva (`upsertNuevoAfiliado`): con `usuarios.numfvd = 0` se registran solicitudes con
 * `afiliacion`, `anualidad` y `carnet` en 1; la aprobación por administración general asigna el Nº FVD
 * en `usuarios` y **solo** actualiza `numfvd` en `movimiento_torneo` (la marca `afiliacion` permanece en 1).
 */
class DelegadoMovimientoTorneo
{
    private const T_M = 'movimiento_torneo';

    private const T_U = 'usuarios';

    private const T_T = 'torneosact';

    private const T_A = 'asociaciones';

    /** Auditoría opcional (volcado / migración `012_torneo_movimiento_historico.sql`). */
    private const T_HIST = 'torneo_movimiento_historico';

    private const STATUS_PENDIENTE = 9;

    public static function historicoMovimientoTablaDisponible(\PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE '" . self::T_HIST . "'");
            if ($st === false) {
                return false;
            }

            return $st->fetch(\PDO::FETCH_NUM) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param 'carnet'|'traspaso'|'afiliacion'|'anualidad'|'inscripcion_bandera' $tipo
     */
    public static function historicoMovimientoAppend(
        \PDO $pdo,
        int $torneoId,
        int $idUsuario,
        int $numfvd,
        string $tipo,
        ?string $valorAnterior,
        string $valorNuevo,
        ?string $notas = null
    ): void {
        if (!self::historicoMovimientoTablaDisponible($pdo)) {
            return;
        }
        if ($torneoId < 1 || $idUsuario < 1 || $tipo === '') {
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO `' . self::T_HIST . '` (`torneo_id`, `atleta_id`, `numfvd`, `tipo`, `valor_anterior`, `valor_nuevo`, `notas`)
            VALUES (:tid, :uid, :nf, :tipo, :va, :vn, :no)'
        );
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->bindValue(':uid', $idUsuario, \PDO::PARAM_INT);
        $st->bindValue(':nf', $numfvd, \PDO::PARAM_INT);
        $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
        $st->bindValue(':va', $valorAnterior, $valorAnterior === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':vn', $valorNuevo, \PDO::PARAM_STR);
        $st->bindValue(':no', $notas, $notas === null || $notas === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        try {
            $st->execute();
        } catch (Throwable $e) {
            error_log('DelegadoMovimientoTorneo::historicoMovimientoAppend: ' . $e->getMessage());
        }
    }

    /**
     * Registra en `torneo_movimiento_historico` la solicitud de traspaso delegada (carnet/traspaso en movimiento).
     *
     * @param array{asociacion_id: int, carnet: int, traspaso: int, afiliacion: int} $prevMov
     */
    private static function registrarHistorialSolicitudTraspaso(
        \PDO $pdo,
        int $torneoId,
        int $userId,
        int $numfvd,
        int $movimientoId,
        int $asociacionOrigenDelegado,
        int $asociacionDestinoId,
        array $prevMov
    ): void {
        $notas = sprintf(
            'delegado_traspaso;movimiento_id=%d;user_id=%d;asoc_origen_delegado=%d;asoc_destino=%d;asoc_movimiento_antes=%d',
            $movimientoId,
            $userId,
            $asociacionOrigenDelegado,
            $asociacionDestinoId,
            (int) ($prevMov['asociacion_id'] ?? 0)
        );
        $pc = (int) ($prevMov['carnet'] ?? 0);
        $pt = (int) ($prevMov['traspaso'] ?? 0);
        self::historicoMovimientoAppend($pdo, $torneoId, $userId, $numfvd, 'carnet', (string) $pc, '1', $notas);
        self::historicoMovimientoAppend($pdo, $torneoId, $userId, $numfvd, 'traspaso', (string) $pt, '1', $notas);
        $paf = (int) ($prevMov['afiliacion'] ?? 0);
        if ($paf !== 0) {
            self::historicoMovimientoAppend($pdo, $torneoId, $userId, $numfvd, 'afiliacion', (string) $paf, '0', $notas);
        }
    }

    /**
     * @return list<array{torneo: int, nombre: string, fechator: ?string}>
     */
    /**
     * Torneo en curso para inscripciones (el más reciente sin cerrar).
     */
    public static function torneoActivoId(\PDO $pdo): ?int
    {
        $fila = self::filaTorneoActivoFallback($pdo);

        return $fila !== null ? (int) ($fila['torneo'] ?? 0) : null;
    }

    /**
     * Variantes activas del mismo campeonato (`grupo_evento_id`) que el torneo indicado.
     *
     * @return list<array{torneo: int, nombre: string, fechator: ?string, tipo: int, grupo_evento_id: int}>
     */
    public static function variantesCampeonatoActivas(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT `grupo_evento_id` FROM `' . self::T_T . '` WHERE `torneo` = :id AND `finalizado_en` IS NULL LIMIT 1'
        );
        $st->bindValue(':id', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $base = $st->fetch(\PDO::FETCH_ASSOC);
        if ($base === false) {
            return [];
        }
        $gid = (int) ($base['grupo_evento_id'] ?? 0);
        if ($gid < 1) {
            return [];
        }
        $st2 = $pdo->prepare(
            'SELECT `torneo`, `nombre`, `fechator`, `tipo`, `grupo_evento_id` FROM `' . self::T_T . '`
             WHERE `finalizado_en` IS NULL AND `grupo_evento_id` = :g
             ORDER BY `tipo` ASC, `torneo` ASC'
        );
        $st2->bindValue(':g', $gid, \PDO::PARAM_INT);
        $st2->execute();
        $rows = $st2->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || count($rows) < 2) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'torneo' => (int) ($r['torneo'] ?? 0),
                'nombre' => trim((string) ($r['nombre'] ?? '')),
                'fechator' => isset($r['fechator']) && $r['fechator'] !== null && $r['fechator'] !== ''
                    ? (string) $r['fechator'] : null,
                'tipo' => (int) ($r['tipo'] ?? 0),
                'grupo_evento_id' => (int) ($r['grupo_evento_id'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Torneo de jornada: siempre el activo, salvo cambio explícito a otra variante del mismo campeonato.
     */
    public static function resolverTorneoIdJornada(\PDO $pdo, ?int $solicitado = null): int
    {
        $activoId = self::torneoActivoId($pdo);
        if ($activoId === null || $activoId < 1) {
            return 0;
        }
        $candidato = $solicitado ?? 0;
        if ($candidato < 1 && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if ($candidato < 1 && isset($_SESSION['fvd_jornada_torneo_id'])) {
            $candidato = (int) $_SESSION['fvd_jornada_torneo_id'];
        }
        if ($candidato < 1 && isset($_SESSION['fvd_delegado_torneo_id'])) {
            $candidato = (int) $_SESSION['fvd_delegado_torneo_id'];
        }
        if ($candidato < 1) {
            return $activoId;
        }
        if ($candidato === $activoId) {
            return $activoId;
        }
        $variantes = self::variantesCampeonatoActivas($pdo, $activoId);
        if ($variantes === []) {
            return $activoId;
        }
        $ids = array_map(static fn (array $v): int => (int) ($v['torneo'] ?? 0), $variantes);
        if (in_array($candidato, $ids, true)) {
            return $candidato;
        }

        return $activoId;
    }

    public static function listarTorneosActivos(\PDO $pdo): array
    {
        $sql = 'SELECT `torneo`, `nombre`, `fechator` FROM `' . self::T_T . '`
            WHERE `finalizado_en` IS NULL ORDER BY `fechator` DESC, `torneo` DESC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : array_map(static function (array $r): array {
            return [
                'torneo' => (int) ($r['torneo'] ?? 0),
                'nombre' => trim((string) ($r['nombre'] ?? '')),
                'fechator' => isset($r['fechator']) && $r['fechator'] !== null && $r['fechator'] !== ''
                    ? (string) $r['fechator'] : null,
            ];
        }, $rows);
    }

    public static function persistirTorneoJornadaSesion(int $torneoId): void
    {
        if ($torneoId < 1) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['fvd_jornada_torneo_id'] = $torneoId;
            $_SESSION['fvd_delegado_torneo_id'] = (string) $torneoId;
        }
    }

    /**
     * Torneo de trabajo de la jornada (persistido en sesión PHP para toda la sesión del panel).
     *
     * @return array<string, mixed>|null
     */
    public static function resolverTorneoJornada(\PDO $pdo, ?int $preferTorneoId = null, bool $persistirSesion = true): ?array
    {
        $candidato = self::resolverTorneoIdJornada($pdo, $preferTorneoId);
        if ($candidato < 1) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM `' . self::T_T . '` WHERE `torneo` = :id AND `finalizado_en` IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $candidato, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return self::filaTorneoActivoFallback($pdo);
        }

        if ($persistirSesion) {
            self::persistirTorneoJornadaSesion($candidato);
        }

        return $row;
    }

    /**
     * @return array{
     *   torneos_activos: list<array{torneo: int, nombre: string, fechator: ?string}>,
     *   torneo_activo_id: int|null,
     *   torneo_jornada_id: int|null,
     *   torneo_jornada: array{torneo: int, nombre: string}|null,
     *   campeonato_variantes: list<array{torneo: int, nombre: string, fechator: ?string, tipo: int, grupo_evento_id: int}>,
     *   permite_selector_campeonato: bool
     * }
     */
    public static function bootstrapJornada(\PDO $pdo, ?int $preferTorneoId = null): array
    {
        $activoId = self::torneoActivoId($pdo);
        $torneo = self::resolverTorneoJornada($pdo, $preferTorneoId, true);
        $tid = $torneo !== null ? (int) ($torneo['torneo'] ?? 0) : 0;
        $variantes = $activoId !== null && $activoId > 0 ? self::variantesCampeonatoActivas($pdo, $activoId) : [];

        return [
            'torneos_activos' => self::listarTorneosActivos($pdo),
            'torneo_activo_id' => $activoId,
            'torneo_jornada_id' => $tid > 0 ? $tid : null,
            'torneo_jornada' => $tid > 0 ? [
                'torneo' => $tid,
                'nombre' => trim((string) ($torneo['nombre'] ?? '')),
            ] : null,
            'campeonato_variantes' => $variantes,
            'permite_selector_campeonato' => count($variantes) >= 2,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function filaTorneoActivoFallback(\PDO $pdo): ?array
    {
        $stmt = $pdo->query(
            'SELECT * FROM `' . self::T_T . '` WHERE `finalizado_en` IS NULL ORDER BY `fechator` DESC, `torneo` DESC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function assertTorneoActivo(\PDO $pdo, int $torneoId): void
    {
        if ($torneoId < 1) {
            throw new InvalidArgumentException('Torneo no indicado.');
        }
        $st = $pdo->prepare('SELECT 1 FROM `' . self::T_T . '` WHERE `torneo` = :t AND `finalizado_en` IS NULL LIMIT 1');
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('El torneo no existe o ya está finalizado.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function usuarioDelegadoAsociacion(\PDO $pdo, int $userId, int $asocDelegado): array
    {
        $st = $pdo->prepare(
            'SELECT `id`, `cedula`, `numfvd`, `sexo`, `status`, `asociacion_id` FROM `' . self::T_U . '` WHERE `id` = :id LIMIT 1'
        );
        $st->bindValue(':id', $userId, \PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }
        $aid = $row['asociacion_id'] !== null && $row['asociacion_id'] !== '' ? (int) $row['asociacion_id'] : 0;
        if ($aid !== $asocDelegado) {
            throw new InvalidArgumentException('El atleta no pertenece a su asociación.');
        }

        return $row;
    }

    /**
     * Alta delegada: tríada de solicitud (afiliación + carnet + anualidad) en el torneo elegido.
     */
    public static function upsertNuevoAfiliado(\PDO $pdo, int $userId, int $torneoId): void
    {
        $asocDelegado = Auth::asociacionId();
        if ($asocDelegado === null || $asocDelegado < 1) {
            throw new InvalidArgumentException('Delegado sin asociación.');
        }
        self::assertTorneoActivo($pdo, $torneoId);
        TorneoMovimientoLock::assertEdicionPermitida($pdo, $torneoId);

        $u = self::usuarioDelegadoAsociacion($pdo, $userId, $asocDelegado);
        $cedula = AfiliacionAtleta::normalizarCedula((string) ($u['cedula'] ?? ''));
        if ($cedula === '') {
            throw new InvalidArgumentException('Cédula inválida en usuario.');
        }
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $stUsr = (int) ($u['status'] ?? 0);
        // Indicadores de solicitud nuevos: afiliación, carnet y anualidad (pendiente FVD).
        $af = 1;
        $ca = 1;
        $an = 1;

        $chk = $pdo->prepare('SELECT `id` FROM `' . self::T_M . '` WHERE `id_usuario` = :u AND `torneo_id` = :t LIMIT 1');
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        $mid = $chk->fetchColumn();
        if ($mid) {
            $movFin = (int) $mid;
            $upd = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET `cedula` = :c, `numfvd` = :n, `sexo` = :s, `asociacion_id` = :a,
                `estatus` = :est, `afiliacion` = :af, `anualidad` = :an, `carnet` = :ca, `traspaso` = 0
                WHERE `id` = :id AND `torneo_id` = :t2 LIMIT 1'
            );
            $upd->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $upd->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $upd->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $upd->bindValue(':a', $asocDelegado, \PDO::PARAM_INT);
            $upd->bindValue(':est', $stUsr, \PDO::PARAM_INT);
            $upd->bindValue(':af', $af, \PDO::PARAM_INT);
            $upd->bindValue(':an', $an, \PDO::PARAM_INT);
            $upd->bindValue(':ca', $ca, \PDO::PARAM_INT);
            $upd->bindValue(':id', $movFin, \PDO::PARAM_INT);
            $upd->bindValue(':t2', $torneoId, \PDO::PARAM_INT);
            $upd->execute();
            self::insertarFvdAfiliacionSiCorresponde($pdo, $torneoId, $userId, $asocDelegado, $movFin);

            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO `' . self::T_M . '` (
                `id_usuario`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `torneo_id`, `posrnk`
            ) VALUES (
                :id_usuario, :cedula, :numfvd, :sexo, :asociacion_id, :estatus,
                :afiliacion, :anualidad, :carnet, 0, 0, :torneo_id, 0
            )'
        );
        $ins->bindValue(':id_usuario', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
        $ins->bindValue(':numfvd', $numfvd, \PDO::PARAM_INT);
        $ins->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
        $ins->bindValue(':asociacion_id', $asocDelegado, \PDO::PARAM_INT);
        $ins->bindValue(':estatus', $stUsr, \PDO::PARAM_INT);
        $ins->bindValue(':afiliacion', $af, \PDO::PARAM_INT);
        $ins->bindValue(':anualidad', $an, \PDO::PARAM_INT);
        $ins->bindValue(':carnet', $ca, \PDO::PARAM_INT);
        $ins->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        $ins->execute();
        $movFin = (int) $pdo->lastInsertId();
        self::insertarFvdAfiliacionSiCorresponde($pdo, $torneoId, $userId, $asocDelegado, $movFin);
    }

    private static function insertarFvdAfiliacionSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        int $userId,
        int $asociacionOrigenId,
        int $movimientoId
    ): void {
        if ($movimientoId < 1 || !FvdSolicitudesDelegado::tablaDisponible($pdo)) {
            return;
        }
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'afiliacion', $userId, $asociacionOrigenId, null)) {
            return;
        }
        $del = Auth::userId();
        FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'afiliacion',
            $asociacionOrigenId,
            $userId,
            $del !== null && $del > 0 ? $del : null,
            null,
            FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId)
        );
    }

    private static function insertarFvdCarnetSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        int $userId,
        int $asociacionOrigenId,
        int $movimientoId
    ): void {
        if ($movimientoId < 1 || !FvdSolicitudesDelegado::tablaDisponible($pdo)) {
            return;
        }
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'carnet', $userId, $asociacionOrigenId, null)) {
            return;
        }
        $del = Auth::userId();
        FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'carnet',
            $asociacionOrigenId,
            $userId,
            $del !== null && $del > 0 ? $del : null,
            null,
            FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId)
        );
    }

    private static function insertarFvdTraspasoSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        int $userId,
        int $asociacionOrigenDelegado,
        int $asociacionDestinoId,
        int $movimientoId
    ): void {
        if ($movimientoId < 1 || !FvdSolicitudesDelegado::tablaDisponible($pdo)) {
            return;
        }
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'traspaso', $userId, $asociacionOrigenDelegado, $asociacionDestinoId)) {
            return;
        }
        $del = Auth::userId();
        FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'traspaso',
            $asociacionOrigenDelegado,
            $userId,
            $del !== null && $del > 0 ? $del : null,
            $asociacionDestinoId,
            FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId)
        );
    }

    /**
     * Solicitud de carnet: solo `carnet`; si el usuario está en estatus 9, también `anualidad`.
     *
     * @param ?int $asociacionContextoAdmin si el rol es administración general, asociación del informe (atleta debe pertenecer a ella)
     */
    public static function solicitarCarnet(\PDO $pdo, int $userId, int $torneoId, ?int $asociacionContextoAdmin = null): int
    {
        $rol = Auth::rol();
        if ($rol === 'delegado') {
            $asocOper = Auth::asociacionId();
        } elseif ($rol === 'admingral') {
            $asocOper = $asociacionContextoAdmin;
        } else {
            $asocOper = null;
        }
        if ($asocOper === null || (int) $asocOper < 1) {
            throw new InvalidArgumentException('Sin asociación de operación.');
        }
        $asocOper = (int) $asocOper;
        self::assertTorneoActivo($pdo, $torneoId);
        TorneoMovimientoLock::assertEdicionPermitida($pdo, $torneoId);

        $u = self::usuarioDelegadoAsociacion($pdo, $userId, $asocOper);
        $cedula = AfiliacionAtleta::normalizarCedula((string) ($u['cedula'] ?? ''));
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $stUsr = (int) ($u['status'] ?? 0);
        $anualidadExtra = $stUsr === self::STATUS_PENDIENTE ? 1 : 0;

        $chk = $pdo->prepare('SELECT `id`, `afiliacion`, `traspaso`, `anualidad` FROM `' . self::T_M . '` WHERE `id_usuario` = :u AND `torneo_id` = :t LIMIT 1');
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        $ex = $chk->fetch(\PDO::FETCH_ASSOC);
        if ($ex !== false) {
            $curAn = (int) ($ex['anualidad'] ?? 0);
            $newAn = $anualidadExtra > 0 ? max($curAn, 1) : $curAn;
            $setCarn = '`carnet` = 1, `cedula` = :c, `numfvd` = :n, `sexo` = :s, `anualidad` = :an';
            if (\Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $setCarn .= ', `movimiento` = 0';
            }
            $upd = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setCarn . '
                WHERE `id` = :id AND `torneo_id` = :t2 LIMIT 1'
            );
            $upd->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $upd->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $upd->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $upd->bindValue(':an', $newAn, \PDO::PARAM_INT);
            $upd->bindValue(':id', (int) $ex['id'], \PDO::PARAM_INT);
            $upd->bindValue(':t2', $torneoId, \PDO::PARAM_INT);
            $upd->execute();

            $midRet = (int) $ex['id'];
            self::insertarFvdCarnetSiCorresponde($pdo, $torneoId, $userId, $asocOper, $midRet);

            return $midRet;
        }

        $anIns = $anualidadExtra;
        $ins = $pdo->prepare(
            'INSERT INTO `' . self::T_M . '` (
                `id_usuario`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `torneo_id`, `posrnk`
            ) VALUES (
                :id_usuario, :cedula, :numfvd, :sexo, :asociacion_id, :estatus,
                0, :anualidad, 1, 0, 0, :torneo_id, 0
            )'
        );
        $ins->bindValue(':id_usuario', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
        $ins->bindValue(':numfvd', $numfvd, \PDO::PARAM_INT);
        $ins->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
        $ins->bindValue(':asociacion_id', $asocOper, \PDO::PARAM_INT);
        $ins->bindValue(':estatus', $stUsr, \PDO::PARAM_INT);
        $ins->bindValue(':anualidad', $anIns, \PDO::PARAM_INT);
        $ins->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        $ins->execute();
        $midRet = (int) $pdo->lastInsertId();
        self::insertarFvdCarnetSiCorresponde($pdo, $torneoId, $userId, $asocOper, $midRet);

        return $midRet;
    }

    /**
     * Solicitud de traspaso: `carnet` y `traspaso`; `asociacion_id` del movimiento = destino;
     * si estatus usuario 9, marca también anualidad.
     */
    public static function solicitarTraspaso(\PDO $pdo, int $userId, int $torneoId, int $asociacionDestinoId): int
    {
        $asocDelegado = Auth::asociacionId();
        if ($asocDelegado === null || $asocDelegado < 1) {
            throw new InvalidArgumentException('Delegado sin asociación.');
        }
        if ($asociacionDestinoId < 1 || $asociacionDestinoId === $asocDelegado) {
            throw new InvalidArgumentException('Asociación destino inválida.');
        }
        self::assertTorneoActivo($pdo, $torneoId);
        TorneoMovimientoLock::assertEdicionPermitida($pdo, $torneoId);

        $stDest = $pdo->prepare('SELECT `id` FROM `' . self::T_A . '` WHERE `id` = :id LIMIT 1');
        $stDest->bindValue(':id', $asociacionDestinoId, \PDO::PARAM_INT);
        $stDest->execute();
        if (!$stDest->fetchColumn()) {
            throw new InvalidArgumentException('La asociación destino no existe.');
        }

        $u = self::usuarioDelegadoAsociacion($pdo, $userId, $asocDelegado);
        $cedula = AfiliacionAtleta::normalizarCedula((string) ($u['cedula'] ?? ''));
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $stUsr = (int) ($u['status'] ?? 0);
        $anualidadExtra = $stUsr === self::STATUS_PENDIENTE ? 1 : 0;

        $chk = $pdo->prepare(
            'SELECT `id`, `anualidad`, `asociacion_id`, `carnet`, `traspaso`, `afiliacion` FROM `' . self::T_M . '` WHERE `id_usuario` = :u AND `torneo_id` = :t LIMIT 1'
        );
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        $ex = $chk->fetch(\PDO::FETCH_ASSOC);
        if ($ex !== false) {
            $prevMov = [
                'asociacion_id' => (int) ($ex['asociacion_id'] ?? 0),
                'carnet' => (int) ($ex['carnet'] ?? 0),
                'traspaso' => (int) ($ex['traspaso'] ?? 0),
                'afiliacion' => (int) ($ex['afiliacion'] ?? 0),
            ];
            $curAn = (int) ($ex['anualidad'] ?? 0);
            $newAn = $anualidadExtra > 0 ? max($curAn, 1) : $curAn;
            $setTr = '`carnet` = 1, `traspaso` = 1, `asociacion_id` = :dest,
                `cedula` = :c, `numfvd` = :n, `sexo` = :s, `afiliacion` = 0, `anualidad` = :an';
            if (\Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $setTr .= ', `movimiento` = 0';
            }
            $upd = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setTr . '
                WHERE `id` = :id AND `torneo_id` = :t2 LIMIT 1'
            );
            $upd->bindValue(':dest', $asociacionDestinoId, \PDO::PARAM_INT);
            $upd->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $upd->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $upd->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $upd->bindValue(':an', $newAn, \PDO::PARAM_INT);
            $movId = (int) $ex['id'];
            $upd->bindValue(':id', $movId, \PDO::PARAM_INT);
            $upd->bindValue(':t2', $torneoId, \PDO::PARAM_INT);
            $upd->execute();
            self::registrarHistorialSolicitudTraspaso(
                $pdo,
                $torneoId,
                $userId,
                $numfvd,
                $movId,
                $asocDelegado,
                $asociacionDestinoId,
                $prevMov
            );
            self::insertarFvdTraspasoSiCorresponde($pdo, $torneoId, $userId, $asocDelegado, $asociacionDestinoId, $movId);

            return $movId;
        }

        $anIns = $anualidadExtra;
        $ins = $pdo->prepare(
            'INSERT INTO `' . self::T_M . '` (
                `id_usuario`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `torneo_id`, `posrnk`
            ) VALUES (
                :id_usuario, :cedula, :numfvd, :sexo, :asociacion_id, :estatus,
                0, :anualidad, 1, 1, 0, :torneo_id, 0
            )'
        );
        $ins->bindValue(':id_usuario', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
        $ins->bindValue(':numfvd', $numfvd, \PDO::PARAM_INT);
        $ins->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
        $ins->bindValue(':asociacion_id', $asociacionDestinoId, \PDO::PARAM_INT);
        $ins->bindValue(':estatus', $stUsr, \PDO::PARAM_INT);
        $ins->bindValue(':anualidad', $anIns, \PDO::PARAM_INT);
        $ins->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        $ins->execute();
        $movId = (int) $pdo->lastInsertId();
        self::registrarHistorialSolicitudTraspaso(
            $pdo,
            $torneoId,
            $userId,
            $numfvd,
            $movId,
            $asocDelegado,
            $asociacionDestinoId,
            [
                'asociacion_id' => 0,
                'carnet' => 0,
                'traspaso' => 0,
                'afiliacion' => 0,
            ]
        );
        self::insertarFvdTraspasoSiCorresponde($pdo, $torneoId, $userId, $asocDelegado, $asociacionDestinoId, $movId);

        return $movId;
    }

    public static function recalcularDeudaUsuarioTorneo(\PDO $pdo, int $torneoId, int $userId): void
    {
        try {
            if (\DeudaAsociaciones::tablaDisponible($pdo)) {
                \DeudaAsociaciones::recalcularPorUsuarioTorneo($pdo, $torneoId, $userId);
            }
        } catch (Throwable $e) {
            error_log('DelegadoMovimientoTorneo::recalcularDeudaUsuarioTorneo: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function reporteAfiliadosConMovimiento(\PDO $pdo, int $asociacionId, int $torneoId): array
    {
        if ($asociacionId < 1 || $torneoId < 1) {
            return [];
        }
        $sql = 'SELECT u.`id` AS user_id, u.`cedula`, u.`nombre`, u.`numfvd`, u.`sexo`, u.`email`, u.`status` AS usuario_status,
            m.`id` AS movimiento_id, m.`afiliacion`, m.`anualidad`, m.`carnet`, m.`traspaso`, m.`inscripcion`,
            m.`created_at` AS movimiento_created_at, m.`updated_at` AS movimiento_updated_at
            FROM `' . self::T_U . '` u
            LEFT JOIN `' . self::T_M . '` m ON m.`id_usuario` = u.`id` AND m.`torneo_id` = :tid
            WHERE u.`asociacion_id` = :aid
            ORDER BY u.`nombre` ASC';
        $st = $pdo->prepare($sql);
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * Listado delegado «Afiliaciones»: solo `movimiento_torneo` con `afiliacion` = 1 en el torneo y asociación,
     * unido a `usuarios` para datos de persona. Pendiente / aceptado según Nº FVD (usuario; alineado con movimiento tras aprobación).
     *
     * @return list<array<string, mixed>>
     */
    public static function reporteAfiliacionesDesdeMovimiento(\PDO $pdo, int $asociacionId, int $torneoId): array
    {
        if ($asociacionId < 1 || $torneoId < 1) {
            return [];
        }
        $sql = 'SELECT u.`id` AS user_id, u.`cedula`, u.`nombre`, u.`numfvd`, u.`sexo`, u.`email`, u.`status` AS usuario_status,
            m.`id` AS movimiento_id, m.`afiliacion`, m.`anualidad`, m.`carnet`, m.`traspaso`, m.`inscripcion`,
            m.`created_at` AS movimiento_created_at, m.`updated_at` AS movimiento_updated_at
            FROM `' . self::T_M . '` m
            INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
            WHERE m.`torneo_id` = :tid AND m.`asociacion_id` = :aid AND m.`afiliacion` = 1
            ORDER BY (CASE WHEN COALESCE(u.`numfvd`, 0) < 1 AND COALESCE(m.`numfvd`, 0) < 1 THEN 0 ELSE 1 END) ASC,
                m.`updated_at` DESC, u.`nombre` ASC';
        $st = $pdo->prepare($sql);
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }
}
