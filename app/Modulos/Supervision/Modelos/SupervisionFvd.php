<?php

declare(strict_types=1);

namespace Fvd\Modulos\Supervision\Modelos;

use Fvd\Modulos\Auth\Modelos\Auth;
use Fvd\Modulos\Delegados\Modelos\FvdSolicitudesDelegado;
use Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones;
use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoCampo;
use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;
use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Supervisión FVD (admin. gral.): solicitudes en `movimiento_torneo` del torneo activo.
 *
 * Flujo de datos (operaciones / preparación de torneo):
 * - Los reportes y contadores operativos se basan en `movimiento_torneo` (contexto de torneo).
 * - Las acciones del panel delegado deben reflejarse en `movimiento_torneo` (inscripción, carnet, traspaso, alta nueva).
 * - Alta nueva de afiliado: el delegado deja `usuarios.numfvd = 0` y en movimiento `afiliacion`, `anualidad` y `carnet` en 1
 *   como solicitud; al aprobar afiliación, administración asigna `numfvd` en `usuarios` y **solo** `numfvd` en `movimiento_torneo`
 *   (el indicador `afiliacion` sigue en 1; la cola de supervisión usa `afiliacion` = 1 y `numfvd` &lt; 1).
 * - Los datos de identidad / asociación del afiliado siguen viniendo de `usuarios` (quién pertenece a qué asociación);
 *   el estado de solicitudes y torneo es `movimiento_torneo`; la referencia de solicitudes delegadas se registra en `fvd_solicitudes_delegado`.
 *
 * Prioridad de clasificación (una fila solo cuenta en un segmento):
 * 1) Afiliación — `afiliacion` = 1 y aún sin Nº FVD en movimiento (`numfvd` &lt; 1).
 * 2) Traspaso — `traspaso` = 1 y no bloqueado por afiliación pendiente de Nº FVD.
 * 3) Carnet — `carnet` = 1 y no bloqueado por afiliación pendiente de Nº FVD ni traspaso.
 *
 * Listados:
 * - Todas: solicitudes visibles según prioridad anterior.
 * - Afiliaciones: `afiliacion` = 1 y `numfvd` &lt; 1 en `movimiento_torneo`.
 * - Traspasos / carnets: mismas reglas que arriba (no compiten con afiliación pendiente de Nº FVD).
 *
 * Finanzas: los montos usan {@see MovimientoTorneoContadores} (`SUM(campo = 1)`). Al aprobar carnet o traspaso
 * los indicadores siguen en 1; `movimiento` = 9 ({@see MovimientoTorneoCampo::APROBADO_SUPERVISION}) marca la
 * aprobación administrativa y saca la fila de la cola de supervisión sin bajar contadores.
 */
class SupervisionFvd
{
    private const T_U = 'usuarios';

    private const T_M = 'movimiento_torneo';

    private const T_A = 'asociaciones';

    private static function marcarFvdSolicitudResuelta(
        \PDO $pdo,
        string $tipo,
        string $estado,
        int $atletaUserId,
        int $asociacionOrigenUsuario,
        ?int $asociacionDestinoTraspaso
    ): void {
        if (!FvdSolicitudesDelegado::tablaDisponible($pdo)) {
            return;
        }
        $admin = Auth::userId();
        if ($admin === null || $admin < 1 || $atletaUserId < 1 || $asociacionOrigenUsuario < 1) {
            return;
        }
        FvdSolicitudesDelegado::marcarUltimaPendiente(
            $pdo,
            $tipo,
            $estado,
            $atletaUserId,
            $asociacionOrigenUsuario,
            $asociacionDestinoTraspaso,
            $admin
        );
    }

    /** Afiliación nueva: sigue en cola hasta que `movimiento_torneo.numfvd` reciba el Nº FVD (usuario y movimiento). */
    private const SQL_AFILI_PEND = 'm.`afiliacion` = 1 AND (m.`numfvd` IS NULL OR m.`numfvd` < 1)';

    /** Ya tiene Nº FVD en movimiento o no es alta de afiliación pendiente. */
    private const SQL_AFILI_NO_BLOQUEA = '(m.`afiliacion` <> 1 OR COALESCE(m.`numfvd`, 0) > 0)';

    private static function sqlColaCarnetPendiente(\PDO $pdo): string
    {
        $base = 'm.`carnet` = 1 AND ' . self::SQL_AFILI_NO_BLOQUEA . ' AND m.`traspaso` <> 1';
        if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
            return $base . ' AND ' . MovimientoTorneoCampo::sqlSupervisionPendiente('m');
        }

        return $base;
    }

    private static function sqlColaTraspasoPendiente(\PDO $pdo): string
    {
        $base = 'm.`traspaso` = 1 AND ' . self::SQL_AFILI_NO_BLOQUEA;
        if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
            return $base . ' AND ' . MovimientoTorneoCampo::sqlSupervisionPendiente('m');
        }

        return $base;
    }

    /**
     * @return array{afiliacion: int, carnet: int, traspaso: int, todas: int}
     */
    public static function resumenPendientes(\PDO $pdo): array
    {
        $tid = self::torneoActivoId($pdo);
        if ($tid < 1) {
            return ['afiliacion' => 0, 'carnet' => 0, 'traspaso' => 0, 'todas' => 0];
        }

        $sqlCa = self::sqlColaCarnetPendiente($pdo);
        $sqlTr = self::sqlColaTraspasoPendiente($pdo);

        $st = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN ' . self::SQL_AFILI_PEND . ' THEN 1 ELSE 0 END) AS n_af,
                SUM(CASE WHEN ' . $sqlTr . ' THEN 1 ELSE 0 END) AS n_tr,
                SUM(CASE WHEN ' . $sqlCa . ' THEN 1 ELSE 0 END) AS n_ca
            FROM `' . self::T_M . '` m
            WHERE m.`torneo_id` = :tid'
        );
        $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
        $st->execute();
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $nAf = (int) ($r['n_af'] ?? 0);
        $nTr = (int) ($r['n_tr'] ?? 0);
        $nCa = (int) ($r['n_ca'] ?? 0);

        return [
            'afiliacion' => $nAf,
            'carnet' => $nCa,
            'traspaso' => $nTr,
            'todas' => $nAf + $nTr + $nCa,
        ];
    }

    private static function torneoActivoId(\PDO $pdo): int
    {
        $tor = InscripcionTorneo::torneoActivo($pdo);

        return $tor !== null ? (int) ($tor['torneo'] ?? 0) : 0;
    }

    /**
     * @return array{ok: bool, message?: string, torneo_id?: int|null, items: list<array<string, mixed>>}
     */
    public static function listar(\PDO $pdo, string $segmento): array
    {
        $seg = strtolower(trim($segmento));
        if (!in_array($seg, ['afiliacion', 'carnet', 'traspaso', 'todas'], true)) {
            return ['ok' => false, 'message' => 'segmento inválido', 'items' => []];
        }

        $tid = self::torneoActivoId($pdo);
        if ($tid < 1) {
            return [
                'ok' => true,
                'torneo_id' => null,
                'message' => 'No hay torneo activo; no hay movimientos para supervisar.',
                'items' => [],
            ];
        }

        $whereExtra = '1=0';
        if ($seg === 'todas') {
            $whereExtra = '((' . self::SQL_AFILI_PEND . ') OR (' . self::sqlColaCarnetPendiente($pdo) . ') OR ('
                . self::sqlColaTraspasoPendiente($pdo) . '))';
        } elseif ($seg === 'afiliacion') {
            $whereExtra = self::SQL_AFILI_PEND;
        } elseif ($seg === 'carnet') {
            $whereExtra = self::sqlColaCarnetPendiente($pdo);
        } elseif ($seg === 'traspaso') {
            $whereExtra = self::sqlColaTraspasoPendiente($pdo);
        }

        $items = self::fetchMovimientosSegmento($pdo, $tid, $whereExtra);

        return ['ok' => true, 'torneo_id' => $tid, 'items' => self::sortItemsSupervision($items)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchMovimientosSegmento(\PDO $pdo, int $torneoId, string $whereExtra): array
    {
        $colMov = MovimientoTorneoCampo::columnaMovimientoDisponible($pdo) ? ', m.`movimiento`' : '';
        $sql = 'SELECT m.`id` AS movimiento_id, m.`torneo_id`, m.`id_usuario`, m.`cedula`, m.`numfvd`,
                m.`afiliacion`, m.`anualidad`, m.`carnet`, m.`traspaso`, m.`inscripcion`' . $colMov . ',
                m.`asociacion_id`, axh.`nombre` AS asociacion_hacia_nombre,
                ao.`nombre` AS asociacion_desde_nombre,
                u.`nombre` AS nombre_usuario
            FROM `' . self::T_M . '` m
            INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
            LEFT JOIN `' . self::T_A . '` axh ON axh.`id` = m.`asociacion_id`
            LEFT JOIN `' . self::T_A . '` ao ON ao.`id` = u.`asociacion_id`
            WHERE m.`torneo_id` = :tid AND ' . $whereExtra . '
            ORDER BY m.`id` DESC';
        $st = $pdo->prepare($sql);
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }

        $items = [];
        foreach ($rows as $r) {
            $afil = (int) ($r['afiliacion'] ?? 0);
            $carn = (int) ($r['carnet'] ?? 0);
            $tras = (int) ($r['traspaso'] ?? 0);
            $nfM = (int) ($r['numfvd'] ?? 0);
            $eje = '';
            $tipoSolicitud = 'Movimiento';
            if ($afil === 1 && $nfM < 1) {
                $eje = 'afiliacion';
                $tipoSolicitud = 'Afiliación';
            } elseif ($tras === 1) {
                $eje = 'traspaso';
                $tipoSolicitud = 'Traspaso';
            } elseif ($carn === 1) {
                $eje = 'carnet';
                $tipoSolicitud = 'Carnet';
            }
            $desde = trim((string) ($r['asociacion_desde_nombre'] ?? ''));
            $hacia = trim((string) ($r['asociacion_hacia_nombre'] ?? ''));

            $items[] = [
                'tipo' => 'movimiento_supervision',
                'tipo_solicitud' => $tipoSolicitud,
                'eje_supervision' => $eje,
                'movimiento_id' => (int) $r['movimiento_id'],
                'torneo_id' => (int) $r['torneo_id'],
                'id_usuario' => (int) $r['id_usuario'],
                'cedula' => (string) $r['cedula'],
                'nombre' => (string) ($r['nombre_usuario'] ?? ''),
                'numfvd' => (int) $r['numfvd'],
                'afiliacion' => $afil,
                'anualidad' => (int) ($r['anualidad'] ?? 0),
                'carnet' => $carn,
                'traspaso' => $tras,
                'inscripcion' => (int) ($r['inscripcion'] ?? 0),
                'movimiento_estado' => isset($r['movimiento']) ? (int) $r['movimiento'] : 0,
                'asociacion_id' => $r['asociacion_id'] !== null ? (int) $r['asociacion_id'] : null,
                'asociacion_nombre' => $hacia !== '' ? $hacia : '—',
                'asociacion_desde_nombre' => $desde,
                'asociacion_hacia_nombre' => $hacia,
                '_sort' => (int) $r['movimiento_id'],
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function sortItemsSupervision(array $items): array
    {
        usort($items, static function (array $x, array $y): int {
            $kx = (int) ($x['_sort'] ?? 0);
            $ky = (int) ($y['_sort'] ?? 0);

            return $ky <=> $kx;
        });
        foreach ($items as &$row) {
            unset($row['_sort']);
        }
        unset($row);

        return $items;
    }

    /**
     * @param 'aprobar_afiliacion_movimiento'|'rechazar_afiliacion_movimiento'|'aprobar_carnet_movimiento'|'rechazar_carnet_movimiento'|'aprobar_traspaso'|'rechazar_traspaso'|'traspaso_revisado'
     */
    /**
     * Aplica varias decisiones en secuencia, valida el estado en BD y recalcula deuda por asociación al final.
     *
     * @param list<array{movimiento_id: int, accion: string}> $decisiones
     *
     * @return array{
     *   ok: bool,
     *   torneo_id: int,
     *   aplicadas: int,
     *   fallidas: list<array{movimiento_id: int, accion?: string, message: string}>,
     *   detalle_aplicadas: list<array{movimiento_id: int, accion: string}>,
     *   pendientes: array{afiliacion: int, carnet: int, traspaso: int, todas: int},
     *   deuda_asociaciones_recalculadas: int
     * }
     */
    public static function aplicarAccionesMasivas(\PDO $pdo, array $decisiones): array
    {
        $tid = self::torneoActivoId($pdo);
        if ($tid < 1) {
            throw new InvalidArgumentException('No hay torneo activo.');
        }
        TorneoMovimientoLock::assertEdicionPermitida($pdo, $tid);

        $accionesPermitidas = [
            'aprobar_afiliacion_movimiento',
            'rechazar_afiliacion_movimiento',
            'aprobar_carnet_movimiento',
            'rechazar_carnet_movimiento',
            'aprobar_traspaso',
            'rechazar_traspaso',
            'traspaso_revisado',
        ];

        $aplicadas = [];
        $fallidas = [];
        $asocsRecalc = [];

        foreach ($decisiones as $idx => $raw) {
            if (!is_array($raw)) {
                $fallidas[] = ['movimiento_id' => 0, 'message' => 'Fila ' . ($idx + 1) . ': formato inválido.'];

                continue;
            }
            $accion = trim((string) ($raw['accion'] ?? ''));
            if ($accion === 'traspaso_revisado') {
                $accion = 'aprobar_traspaso';
            }
            $mid = (int) ($raw['movimiento_id'] ?? 0);
            if ($mid < 1) {
                $fallidas[] = ['movimiento_id' => 0, 'message' => 'Fila ' . ($idx + 1) . ': movimiento_id inválido.'];

                continue;
            }
            if (!in_array($accion, $accionesPermitidas, true)) {
                $fallidas[] = ['movimiento_id' => $mid, 'accion' => $accion, 'message' => 'Acción no reconocida.'];

                continue;
            }

            try {
                foreach (self::asociacionesParaRecalculoDeuda($pdo, $mid, $accion, $tid) as $aid) {
                    if ($aid > 0) {
                        $asocsRecalc[$aid] = true;
                    }
                }
                self::aplicarAccionMovimiento($pdo, $accion, $mid, false);
                if (!self::validarAccionAplicada($pdo, $accion, $mid, $tid)) {
                    throw new InvalidArgumentException(
                        'El movimiento no quedó en el estado esperado (ya estaba resuelto o no cumple la condición).'
                    );
                }
                $aidPost = self::asociacionIdMovimiento($pdo, $mid);
                if ($aidPost > 0) {
                    $asocsRecalc[$aidPost] = true;
                }
                $aplicadas[] = ['movimiento_id' => $mid, 'accion' => $accion];
            } catch (Throwable $e) {
                $fallidas[] = [
                    'movimiento_id' => $mid,
                    'accion' => $accion,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $nRecalc = 0;
        if (DeudaAsociaciones::tablaDisponible($pdo)) {
            foreach (array_keys($asocsRecalc) as $aid) {
                try {
                    DeudaAsociaciones::recalcularFila($pdo, $tid, (int) $aid);
                    $nRecalc++;
                } catch (Throwable $e) {
                    error_log('SupervisionFvd::aplicarAccionesMasivas recalc asoc ' . $aid . ': ' . $e->getMessage());
                }
            }
        }

        return [
            'ok' => $fallidas === [],
            'torneo_id' => $tid,
            'aplicadas' => count($aplicadas),
            'fallidas' => $fallidas,
            'detalle_aplicadas' => $aplicadas,
            'pendientes' => self::resumenPendientes($pdo),
            'deuda_asociaciones_recalculadas' => $nRecalc,
        ];
    }

    public static function aplicarAccionMovimiento(
        \PDO $pdo,
        string $accion,
        int $movimientoId,
        bool $recalcularDeuda = true
    ): void {
        $tor = InscripcionTorneo::torneoActivo($pdo);
        if ($tor === null) {
            throw new InvalidArgumentException('No hay torneo activo.');
        }
        $tid = (int) ($tor['torneo'] ?? 0);
        if ($tid < 1) {
            throw new InvalidArgumentException('Torneo activo inválido.');
        }

        TorneoMovimientoLock::assertEdicionPermitida($pdo, $tid);

        if ($accion === 'traspaso_revisado') {
            $accion = 'aprobar_traspaso';
        }

        if ($accion === 'aprobar_afiliacion_movimiento') {
            $pdo->beginTransaction();
            try {
                $stFind = $pdo->prepare(
                    'SELECT m.`id_usuario`, u.`numfvd` AS u_numfvd, u.`asociacion_id` AS u_aid
                    FROM `' . self::T_M . '` m
                    INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                    WHERE m.`id` = :id AND m.`torneo_id` = :tid AND m.`afiliacion` = 1 LIMIT 1'
                );
                $stFind->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
                $stFind->bindValue(':tid', $tid, \PDO::PARAM_INT);
                $stFind->execute();
                $pair = $stFind->fetch(\PDO::FETCH_ASSOC);
                if ($pair === false) {
                    throw new InvalidArgumentException('No se encontró la solicitud de afiliación en este torneo.');
                }
                $uid = (int) ($pair['id_usuario'] ?? 0);
                if ($uid < 1) {
                    throw new InvalidArgumentException('Movimiento sin usuario válido.');
                }
                $numfvdUsuario = (int) ($pair['u_numfvd'] ?? 0);
                $numfvdFinal = $numfvdUsuario;
                if ($numfvdUsuario < 1) {
                    $stMax = $pdo->query('SELECT COALESCE(MAX(`numfvd`), 0) FROM `' . self::T_U . '`');
                    if ($stMax === false) {
                        throw new RuntimeException('No se pudo calcular el siguiente Nº FVD.');
                    }
                    $mx = (int) $stMax->fetchColumn();
                    $numfvdFinal = max(1, $mx + 1);
                    $upU = $pdo->prepare(
                        'UPDATE `' . self::T_U . '` SET `numfvd` = :n WHERE `id` = :uid AND `numfvd` = 0 LIMIT 1'
                    );
                    $upU->bindValue(':n', $numfvdFinal, \PDO::PARAM_INT);
                    $upU->bindValue(':uid', $uid, \PDO::PARAM_INT);
                    $upU->execute();
                    if ($upU->rowCount() < 1) {
                        $stR = $pdo->prepare('SELECT `numfvd` FROM `' . self::T_U . '` WHERE `id` = :uid LIMIT 1');
                        $stR->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $stR->execute();
                        $numfvdFinal = (int) ($stR->fetchColumn() ?: 0);
                        if ($numfvdFinal < 1) {
                            throw new InvalidArgumentException('No se pudo asignar el Nº FVD al atleta.');
                        }
                    }
                }
                $st = $pdo->prepare(
                    'UPDATE `' . self::T_M . '` SET `numfvd` = :nf WHERE `id` = :id AND `torneo_id` = :tid AND `afiliacion` = 1 LIMIT 1'
                );
                $st->bindValue(':nf', $numfvdFinal, \PDO::PARAM_INT);
                $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
                $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
                $st->execute();
                if ($st->rowCount() < 1) {
                    throw new InvalidArgumentException('No se actualizó la afiliación del movimiento.');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            $uAid = isset($pair['u_aid']) && $pair['u_aid'] !== null && $pair['u_aid'] !== '' ? (int) $pair['u_aid'] : 0;
            self::marcarFvdSolicitudResuelta($pdo, 'afiliacion', 'aprobada', $uid, $uAid, null);

            return;
        }
        if ($accion === 'rechazar_afiliacion_movimiento') {
            $stPre = $pdo->prepare(
                'SELECT m.`id_usuario`, u.`asociacion_id` AS u_aid FROM `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                WHERE m.`id` = :id AND m.`torneo_id` = :tid AND m.`afiliacion` = 1 LIMIT 1'
            );
            $stPre->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $stPre->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $stPre->execute();
            $preA = $stPre->fetch(\PDO::FETCH_ASSOC) ?: [];
            $uidA = (int) ($preA['id_usuario'] ?? 0);
            $uAidA = isset($preA['u_aid']) && $preA['u_aid'] !== null && $preA['u_aid'] !== '' ? (int) $preA['u_aid'] : 0;

            $setRechAfil = '`afiliacion` = 0, `anualidad` = 0, `carnet` = 0';
            if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $setRechAfil .= ', ' . MovimientoTorneoCampo::sqlSetMovimientoCero();
            }
            $st = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setRechAfil . '
                 WHERE `id` = :id AND `torneo_id` = :tid AND `afiliacion` = 1 LIMIT 1'
            );
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $st->execute();
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se rechazó la afiliación del movimiento.');
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            if ($uidA > 0 && $uAidA > 0) {
                self::marcarFvdSolicitudResuelta($pdo, 'afiliacion', 'rechazada', $uidA, $uAidA, null);
            }

            return;
        }
        if ($accion === 'aprobar_carnet_movimiento') {
            $stSnap = $pdo->prepare(
                'SELECT m.`id_usuario`, u.`asociacion_id` AS u_aid FROM `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                WHERE m.`id` = :id AND m.`torneo_id` = :tid LIMIT 1'
            );
            $stSnap->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $stSnap->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $stSnap->execute();
            $snap = $stSnap->fetch(\PDO::FETCH_ASSOC) ?: [];
            $uidC = (int) ($snap['id_usuario'] ?? 0);
            $uAidC = isset($snap['u_aid']) && $snap['u_aid'] !== null && $snap['u_aid'] !== '' ? (int) $snap['u_aid'] : 0;

            $wCarn = ' AND `carnet` = 1 AND (`afiliacion` <> 1 OR COALESCE(`numfvd`, 0) > 0) AND `traspaso` <> 1';
            if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $wCarn .= ' AND ' . MovimientoTorneoCampo::sqlSupervisionPendienteSinAlias();
                $setApr = MovimientoTorneoCampo::sqlSetAprobadoSupervision();
            } else {
                $setApr = '`carnet` = 0';
            }
            $st = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setApr . ' WHERE `id` = :id AND `torneo_id` = :tid' . $wCarn . ' LIMIT 1'
            );
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $st->execute();
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se actualizó el carnet del movimiento.');
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            if ($uidC > 0 && $uAidC > 0) {
                self::marcarFvdSolicitudResuelta($pdo, 'carnet', 'aprobada', $uidC, $uAidC, null);
            }

            return;
        }
        if ($accion === 'rechazar_carnet_movimiento') {
            $stSnap = $pdo->prepare(
                'SELECT m.`id_usuario`, u.`asociacion_id` AS u_aid FROM `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                WHERE m.`id` = :id AND m.`torneo_id` = :tid LIMIT 1'
            );
            $stSnap->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $stSnap->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $stSnap->execute();
            $snapR = $stSnap->fetch(\PDO::FETCH_ASSOC) ?: [];
            $uidCr = (int) ($snapR['id_usuario'] ?? 0);
            $uAidCr = isset($snapR['u_aid']) && $snapR['u_aid'] !== null && $snapR['u_aid'] !== '' ? (int) $snapR['u_aid'] : 0;

            $setRechCa = '`carnet` = 0';
            if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $setRechCa .= ', ' . MovimientoTorneoCampo::sqlSetMovimientoCero();
            }
            $st = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setRechCa . '
                 WHERE `id` = :id AND `torneo_id` = :tid AND `carnet` = 1 AND (`afiliacion` <> 1 OR COALESCE(`numfvd`, 0) > 0) AND `traspaso` <> 1 LIMIT 1'
            );
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $st->execute();
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se rechazó el carnet del movimiento.');
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            if ($uidCr > 0 && $uAidCr > 0) {
                self::marcarFvdSolicitudResuelta($pdo, 'carnet', 'rechazada', $uidCr, $uAidCr, null);
            }

            return;
        }
        if ($accion === 'aprobar_traspaso') {
            $stSnap = $pdo->prepare(
                'SELECT m.`id_usuario`, u.`asociacion_id` AS u_aid, m.`asociacion_id` AS m_dest
                FROM `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                WHERE m.`id` = :id AND m.`torneo_id` = :tid AND m.`traspaso` = 1 LIMIT 1'
            );
            $stSnap->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $stSnap->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $stSnap->execute();
            $snapT = $stSnap->fetch(\PDO::FETCH_ASSOC) ?: [];
            $uidT = (int) ($snapT['id_usuario'] ?? 0);
            $uAidT = isset($snapT['u_aid']) && $snapT['u_aid'] !== null && $snapT['u_aid'] !== '' ? (int) $snapT['u_aid'] : 0;
            $mDest = isset($snapT['m_dest']) && $snapT['m_dest'] !== null && $snapT['m_dest'] !== '' ? (int) $snapT['m_dest'] : 0;

            $wTr = ' AND `traspaso` = 1 AND (`afiliacion` <> 1 OR COALESCE(`numfvd`, 0) > 0)';
            if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $wTr .= ' AND ' . MovimientoTorneoCampo::sqlSupervisionPendienteSinAlias();
                $setAprTr = MovimientoTorneoCampo::sqlSetAprobadoSupervision();
            } else {
                $setAprTr = '`traspaso` = 0';
            }
            $st = $pdo->prepare(
                'UPDATE `' . self::T_M . '` SET ' . $setAprTr . ' WHERE `id` = :id AND `torneo_id` = :tid' . $wTr . ' LIMIT 1'
            );
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $st->execute();
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se aprobó el traspaso.');
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            if ($uidT > 0 && $uAidT > 0 && $mDest > 0) {
                self::marcarFvdSolicitudResuelta($pdo, 'traspaso', 'aprobada', $uidT, $uAidT, $mDest);
            }

            return;
        }
        if ($accion === 'rechazar_traspaso') {
            $stPre = $pdo->prepare(
                'SELECT m.`id_usuario`, m.`asociacion_id` AS mov_asoc, u.`asociacion_id` AS usr_asoc
                FROM `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                WHERE m.`id` = :id AND m.`torneo_id` = :tid2 LIMIT 1'
            );
            $stPre->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $stPre->bindValue(':tid2', $tid, \PDO::PARAM_INT);
            $stPre->execute();
            $pair = $stPre->fetch(\PDO::FETCH_ASSOC) ?: [];
            $asocMov = isset($pair['mov_asoc']) && $pair['mov_asoc'] !== null && $pair['mov_asoc'] !== '' ? (int) $pair['mov_asoc'] : 0;
            $asocUsr = isset($pair['usr_asoc']) && $pair['usr_asoc'] !== null && $pair['usr_asoc'] !== '' ? (int) $pair['usr_asoc'] : 0;
            $uidTr = (int) ($pair['id_usuario'] ?? 0);

            $setRechTr = 'm.`inscripcion` = 0, m.`traspaso` = 0, m.`asociacion_id` = u.`asociacion_id`';
            if (MovimientoTorneoCampo::columnaMovimientoDisponible($pdo)) {
                $setRechTr .= ', m.`movimiento` = 0';
            }
            $sql = 'UPDATE `' . self::T_M . '` m
                INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
                SET ' . $setRechTr . '
                WHERE m.`id` = :id AND m.`torneo_id` = :tid AND m.`traspaso` = 1 AND (m.`afiliacion` <> 1 OR COALESCE(m.`numfvd`, 0) > 0)
                LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->bindValue(':tid', $tid, \PDO::PARAM_INT);
            $st->execute();
            if ($st->rowCount() < 1) {
                throw new InvalidArgumentException('No se rechazó el traspaso.');
            }
            self::sincronizarDeudaSiActivo($pdo, $movimientoId, $tid, $recalcularDeuda);
            if ($recalcularDeuda && DeudaAsociaciones::tablaDisponible($pdo) && $asocMov > 0 && $asocMov !== $asocUsr) {
                try {
                    DeudaAsociaciones::recalcularFila($pdo, $tid, $asocMov);
                } catch (Throwable $e) {
                    error_log('SupervisionFvd rechazar_traspaso deuda (asoc. anterior): ' . $e->getMessage());
                }
            }
            if ($uidTr > 0 && $asocUsr > 0 && $asocMov > 0) {
                self::marcarFvdSolicitudResuelta($pdo, 'traspaso', 'rechazada', $uidTr, $asocUsr, $asocMov);
            }

            return;
        }

        throw new InvalidArgumentException('accion inválida.');
    }

    /**
     * @return list<int>
     */
    private static function asociacionesParaRecalculoDeuda(\PDO $pdo, int $movimientoId, string $accion, int $torneoId): array
    {
        $out = [];
        $aid = self::asociacionIdMovimiento($pdo, $movimientoId);
        if ($aid > 0) {
            $out[] = $aid;
        }
        if ($accion !== 'rechazar_traspaso') {
            return $out;
        }
        $st = $pdo->prepare(
            'SELECT m.`asociacion_id` AS mov_asoc, u.`asociacion_id` AS usr_asoc
             FROM `' . self::T_M . '` m
             INNER JOIN `' . self::T_U . '` u ON u.`id` = m.`id_usuario`
             WHERE m.`id` = :id AND m.`torneo_id` = :tid LIMIT 1'
        );
        $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return $out;
        }
        $asocMov = isset($row['mov_asoc']) && $row['mov_asoc'] !== null && $row['mov_asoc'] !== '' ? (int) $row['mov_asoc'] : 0;
        $asocUsr = isset($row['usr_asoc']) && $row['usr_asoc'] !== null && $row['usr_asoc'] !== '' ? (int) $row['usr_asoc'] : 0;
        if ($asocMov > 0 && $asocMov !== $asocUsr) {
            $out[] = $asocMov;
        }
        if ($asocUsr > 0) {
            $out[] = $asocUsr;
        }

        return array_values(array_unique($out));
    }

    private static function asociacionIdMovimiento(\PDO $pdo, int $movimientoId): int
    {
        $st = $pdo->prepare('SELECT `asociacion_id` FROM `' . self::T_M . '` WHERE `id` = :id LIMIT 1');
        $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row['asociacion_id'] === null || $row['asociacion_id'] === '') {
            return 0;
        }

        return (int) $row['asociacion_id'];
    }

    private static function validarAccionAplicada(\PDO $pdo, string $accion, int $movimientoId, int $torneoId): bool
    {
        $selMov = MovimientoTorneoCampo::columnaMovimientoDisponible($pdo) ? ', `movimiento`' : '';
        $st = $pdo->prepare(
            'SELECT `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `numfvd`' . $selMov . '
             FROM `' . self::T_M . '` WHERE `id` = :id AND `torneo_id` = :tid LIMIT 1'
        );
        $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
        $st->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            return false;
        }
        $afil = (int) ($r['afiliacion'] ?? 0);
        $anu = (int) ($r['anualidad'] ?? 0);
        $ca = (int) ($r['carnet'] ?? 0);
        $tr = (int) ($r['traspaso'] ?? 0);
        $nf = (int) ($r['numfvd'] ?? 0);

        $mov = (int) ($r['movimiento'] ?? 0);
        $usaMov = MovimientoTorneoCampo::columnaMovimientoDisponible($pdo);

        switch ($accion) {
            case 'aprobar_afiliacion_movimiento':
                return $afil === 1 && $nf > 0;
            case 'rechazar_afiliacion_movimiento':
                return $afil === 0 && $anu === 0 && $ca === 0;
            case 'aprobar_carnet_movimiento':
                return $usaMov ? ($ca === 1 && $mov === MovimientoTorneoCampo::APROBADO_SUPERVISION) : $ca === 0;
            case 'rechazar_carnet_movimiento':
                return $ca === 0 && (!$usaMov || $mov === 0);
            case 'aprobar_traspaso':
                return $usaMov ? ($tr === 1 && $mov === MovimientoTorneoCampo::APROBADO_SUPERVISION) : $tr === 0;
            case 'rechazar_traspaso':
                return $tr === 0 && (int) ($r['inscripcion'] ?? 0) === 0;
            default:
                return false;
        }
    }

    private static function sincronizarDeudaSiActivo(
        \PDO $pdo,
        int $movimientoId,
        int $torneoId,
        bool $recalcularDeuda
    ): void {
        if ($recalcularDeuda) {
            self::sincronizarDeudaTrasMovimiento($pdo, $movimientoId, $torneoId);
        }
    }

    private static function sincronizarDeudaTrasMovimiento(\PDO $pdo, int $movimientoId, int $torneoId): void
    {
        try {
            if (!DeudaAsociaciones::tablaDisponible($pdo)) {
                return;
            }
            $st = $pdo->prepare('SELECT asociacion_id FROM `' . self::T_M . '` WHERE `id` = :id LIMIT 1');
            $st->bindValue(':id', $movimientoId, \PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return;
            }
            $aid = $row['asociacion_id'] !== null && $row['asociacion_id'] !== '' ? (int) $row['asociacion_id'] : 0;
            if ($aid < 1) {
                return;
            }
            DeudaAsociaciones::recalcularFila($pdo, $torneoId, $aid);
        } catch (Throwable $e) {
            error_log('SupervisionFvd::sincronizarDeudaTrasMovimiento: ' . $e->getMessage());
        }
    }
}
