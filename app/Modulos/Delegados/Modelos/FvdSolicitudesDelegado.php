<?php

declare(strict_types=1);

namespace Fvd\Modulos\Delegados\Modelos;

/**
 * `fvd_solicitudes_delegado`: registro de referencia de solicitudes delegadas (traspaso, carnet, afiliación).
 * Se inserta en pendiente al registrar el movimiento; supervisión FVD marca aprobada / rechazada.
 */
class FvdSolicitudesDelegado
{
    private const T = 'fvd_solicitudes_delegado';

    public static function tablaDisponible(\PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE '" . self::T . "'");
            if ($st === false) {
                return false;
            }

            return $st->fetch(\PDO::FETCH_NUM) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param 'traspaso'|'carnet'|'afiliacion' $tipo
     */
    public static function insertarPendiente(
        \PDO $pdo,
        string $tipo,
        int $asociacionOrigenId,
        int $atletaUserId,
        ?int $delegadoUserId,
        ?int $asociacionDestinoId,
        ?string $nota
    ): ?int {
        if (!self::tablaDisponible($pdo) || $asociacionOrigenId < 1 || $atletaUserId < 1) {
            return null;
        }
        if (!in_array($tipo, ['traspaso', 'carnet', 'afiliacion'], true)) {
            return null;
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO `' . self::T . '` (`tipo`, `asociacion_id`, `atleta_id`, `delegado_id`, `asociacion_destino_id`, `nota`, `estado`)
                VALUES (:tipo, :asoc, :atl, :del, :dest, :nota, :estado)'
            );
            $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
            $st->bindValue(':asoc', $asociacionOrigenId, \PDO::PARAM_INT);
            $st->bindValue(':atl', $atletaUserId, \PDO::PARAM_INT);
            $st->bindValue(':del', $delegadoUserId, $delegadoUserId !== null && $delegadoUserId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
            $st->bindValue(':dest', $asociacionDestinoId, $asociacionDestinoId !== null && $asociacionDestinoId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
            $st->bindValue(':nota', $nota, $nota === null || $nota === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $st->bindValue(':estado', 'pendiente', \PDO::PARAM_STR);
            $st->execute();

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('FvdSolicitudesDelegado::insertarPendiente: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Marca la solicitud pendiente más reciente que coincida con tipo / atleta / asociación origen (y destino en traspaso).
     *
     * @param 'aprobada'|'rechazada' $estado
     * @param 'traspaso'|'carnet'|'afiliacion' $tipo
     */
    public static function marcarUltimaPendiente(
        \PDO $pdo,
        string $tipo,
        string $estado,
        int $atletaUserId,
        int $asociacionOrigenId,
        ?int $asociacionDestinoId,
        int $resueltoPorUserId
    ): int {
        if (!self::tablaDisponible($pdo) || $atletaUserId < 1 || $asociacionOrigenId < 1) {
            return 0;
        }
        if (!in_array($tipo, ['traspaso', 'carnet', 'afiliacion'], true) || !in_array($estado, ['aprobada', 'rechazada'], true)) {
            return 0;
        }
        try {
            if ($tipo === 'traspaso' && $asociacionDestinoId !== null && $asociacionDestinoId > 0) {
                $st = $pdo->prepare(
                    'UPDATE `' . self::T . '` SET `estado` = :est, `resuelto_en` = NOW(), `resuelto_por_user_id` = :adm
                    WHERE `id` = (
                        SELECT `id` FROM (
                            SELECT `id` FROM `' . self::T . '`
                            WHERE `tipo` = :tipo AND `estado` = :pend AND `atleta_id` = :atl AND `asociacion_id` = :asoc
                              AND `asociacion_destino_id` <=> :dest
                            ORDER BY `id` DESC LIMIT 1
                        ) AS sub
                    )'
                );
                $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
                $st->bindValue(':pend', 'pendiente', \PDO::PARAM_STR);
                $st->bindValue(':atl', $atletaUserId, \PDO::PARAM_INT);
                $st->bindValue(':asoc', $asociacionOrigenId, \PDO::PARAM_INT);
                $st->bindValue(':dest', $asociacionDestinoId, \PDO::PARAM_INT);
            } else {
                $st = $pdo->prepare(
                    'UPDATE `' . self::T . '` SET `estado` = :est, `resuelto_en` = NOW(), `resuelto_por_user_id` = :adm
                    WHERE `id` = (
                        SELECT `id` FROM (
                            SELECT `id` FROM `' . self::T . '`
                            WHERE `tipo` = :tipo AND `estado` = :pend AND `atleta_id` = :atl AND `asociacion_id` = :asoc
                              AND (`asociacion_destino_id` IS NULL OR `asociacion_destino_id` = 0)
                            ORDER BY `id` DESC LIMIT 1
                        ) AS sub
                    )'
                );
                $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
                $st->bindValue(':pend', 'pendiente', \PDO::PARAM_STR);
                $st->bindValue(':atl', $atletaUserId, \PDO::PARAM_INT);
                $st->bindValue(':asoc', $asociacionOrigenId, \PDO::PARAM_INT);
            }
            $st->bindValue(':est', $estado, \PDO::PARAM_STR);
            $st->bindValue(':adm', $resueltoPorUserId, \PDO::PARAM_INT);
            $st->execute();

            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('FvdSolicitudesDelegado::marcarUltimaPendiente: ' . $e->getMessage());

            return 0;
        }
    }

    public static function notaTorneoMovimiento(int $torneoId, int $movimientoId): string
    {
        return 'torneo_id=' . $torneoId . ';movimiento_id=' . $movimientoId;
    }

    /**
     * @param 'traspaso'|'carnet'|'afiliacion' $tipo
     */
    public static function tienePendiente(
        \PDO $pdo,
        string $tipo,
        int $atletaUserId,
        int $asociacionOrigenId,
        ?int $asociacionDestinoId
    ): bool {
        if (!self::tablaDisponible($pdo) || $atletaUserId < 1 || $asociacionOrigenId < 1) {
            return false;
        }
        if (!in_array($tipo, ['traspaso', 'carnet', 'afiliacion'], true)) {
            return false;
        }
        try {
            if ($tipo === 'traspaso' && $asociacionDestinoId !== null && $asociacionDestinoId > 0) {
                $st = $pdo->prepare(
                    'SELECT 1 FROM `' . self::T . '` WHERE `tipo` = :tipo AND `estado` = :pend
                     AND `atleta_id` = :atl AND `asociacion_id` = :asoc AND `asociacion_destino_id` <=> :dest LIMIT 1'
                );
                $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
                $st->bindValue(':pend', 'pendiente', \PDO::PARAM_STR);
                $st->bindValue(':atl', $atletaUserId, \PDO::PARAM_INT);
                $st->bindValue(':asoc', $asociacionOrigenId, \PDO::PARAM_INT);
                $st->bindValue(':dest', $asociacionDestinoId, \PDO::PARAM_INT);
            } else {
                $st = $pdo->prepare(
                    'SELECT 1 FROM `' . self::T . '` WHERE `tipo` = :tipo AND `estado` = :pend
                     AND `atleta_id` = :atl AND `asociacion_id` = :asoc
                     AND (`asociacion_destino_id` IS NULL OR `asociacion_destino_id` = 0) LIMIT 1'
                );
                $st->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
                $st->bindValue(':pend', 'pendiente', \PDO::PARAM_STR);
                $st->bindValue(':atl', $atletaUserId, \PDO::PARAM_INT);
                $st->bindValue(':asoc', $asociacionOrigenId, \PDO::PARAM_INT);
            }
            $st->execute();

            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('FvdSolicitudesDelegado::tienePendiente: ' . $e->getMessage());

            return false;
        }
    }
}
