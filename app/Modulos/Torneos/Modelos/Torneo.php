<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

/**
 * Alta de torneos en `torneosact` (parámetros técnicos, costos, multimedia).
 * `torneosact.organizacion_id` apunta a `organizacion_fvd` (FVD rectora), no a asociaciones.
 * Sin lógica de "primer torneo del año" / apertura anual masiva (apertura_anual = 0).
 */
class Torneo
{
    public const DEFAULT_TIEMPO = 35;

    public const DEFAULT_PUNTOS = 200;

    public const DEFAULT_RONDAS = 9;

    /** 1 = ranking activo */
    public const DEFAULT_RANKING = 1;

    /** 0 = pendiente de activación */
    public const DEFAULT_ESTATUS = 0;

    /**
     * @param array{
     *   nombre: string,
     *   lugar?: string|null,
     *   fechator?: string|null,
     *   tipo?: int|null,
     *   clase?: int|null,
     *   tiempo?: int|null,
     *   puntos?: int|null,
     *   rondas?: int|null,
     *   ranking?: int|null,
     *   estatus?: int|null,
     *   costotor?: float|null,
     *   organizacion_id: int,
     *   publicar_landing?: int|null,
     *   pareclub?: int|null
     * } $datos
     */
    public static function crear(\PDO $pdo, array $datos, ?string $rutaInvitacion, ?string $rutaAfiche): int
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del torneo es obligatorio.');
        }

        $tiempo = self::intOrDefault($datos['tiempo'] ?? null, self::DEFAULT_TIEMPO);
        $puntos = self::intOrDefault($datos['puntos'] ?? null, self::DEFAULT_PUNTOS);
        $rondas = self::intOrDefault($datos['rondas'] ?? null, self::DEFAULT_RONDAS);
        $ranking = self::intOrDefault($datos['ranking'] ?? null, self::DEFAULT_RANKING);
        $estatus = self::intOrDefault($datos['estatus'] ?? null, self::DEFAULT_ESTATUS);

        $lugar = isset($datos['lugar']) ? trim((string) $datos['lugar']) : null;
        $lugar = $lugar === '' ? null : $lugar;

        $fechator = isset($datos['fechator']) ? trim((string) $datos['fechator']) : null;
        $fechator = $fechator === '' ? null : $fechator;

        $tipo = isset($datos['tipo']) && $datos['tipo'] !== '' ? (int) $datos['tipo'] : null;
        if ($tipo === 0) {
            $tipo = null;
        }
        $clase = isset($datos['clase']) && $datos['clase'] !== '' ? (int) $datos['clase'] : null;
        if ($clase === 0) {
            $clase = null;
        }

        $costotor = isset($datos['costotor']) && $datos['costotor'] !== '' ? (float) $datos['costotor'] : null;
        $organizacionId = isset($datos['organizacion_id']) && $datos['organizacion_id'] !== ''
            ? (int) $datos['organizacion_id'] : 0;
        if ($organizacionId < 1) {
            throw new InvalidArgumentException('Falta la organización rectora (organizacion_fvd). Ejecute la migración 003.');
        }

        $publicar = array_key_exists('publicar_landing', $datos)
            ? (int) (bool) $datos['publicar_landing'] : 1;
        $pareclub = self::intOrDefault($datos['pareclub'] ?? null, 0);

        $grupoEventoId = isset($datos['grupo_evento_id']) && $datos['grupo_evento_id'] !== '' ? (int) $datos['grupo_evento_id'] : null;
        if ($grupoEventoId !== null && $grupoEventoId < 1) {
            $grupoEventoId = null;
        }

        $fechaLimiteCambios = isset($datos['fecha_limite_cambios']) ? trim((string) $datos['fecha_limite_cambios']) : null;
        $fechaLimiteCambios = $fechaLimiteCambios === '' ? null : $fechaLimiteCambios;

        $invDesp = array_key_exists('invitaciones_despachadas', $datos)
            ? (int) (bool) $datos['invitaciones_despachadas'] : 0;

        $clavetor = self::generarClaveTorneo($pdo);

        $sql = 'INSERT INTO torneosact (
            grupo_evento_id, apertura_anual, finalizado_en, organizacion_id, clavetor,
            nombre, lugar, fechator, tipo, clase, tiempo, puntos, rondas, estatus, costotor,
            ranking, pareclub, invitacion, afiche, publicar_landing,
            invitaciones_despachadas, fecha_limite_cambios
        ) VALUES (
            :grupo_evento_id, 0, NULL, :organizacion_id, :clavetor,
            :nombre, :lugar, :fechator, :tipo, :clase, :tiempo, :puntos, :rondas, :estatus, :costotor,
            :ranking, :pareclub, :invitacion, :afiche, :publicar_landing,
            :invitaciones_despachadas, :fecha_limite_cambios
        )';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':grupo_evento_id', $grupoEventoId, $grupoEventoId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':organizacion_id', $organizacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':clavetor', $clavetor, \PDO::PARAM_STR);
        $stmt->bindValue(':nombre', $nombre, \PDO::PARAM_STR);
        $stmt->bindValue(':lugar', $lugar, $lugar === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':fechator', $fechator, $fechator === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':tipo', $tipo, $tipo === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':clase', $clase, $clase === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':tiempo', $tiempo, \PDO::PARAM_INT);
        $stmt->bindValue(':puntos', $puntos, \PDO::PARAM_INT);
        $stmt->bindValue(':rondas', $rondas, \PDO::PARAM_INT);
        $stmt->bindValue(':estatus', $estatus, \PDO::PARAM_INT);
        $stmt->bindValue(':costotor', $costotor === null ? null : (string) $costotor, $costotor === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':ranking', $ranking, \PDO::PARAM_INT);
        $stmt->bindValue(':pareclub', $pareclub, \PDO::PARAM_INT);
        $stmt->bindValue(':invitacion', $rutaInvitacion, $rutaInvitacion === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':afiche', $rutaAfiche, $rutaAfiche === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':publicar_landing', $publicar, \PDO::PARAM_INT);
        $stmt->bindValue(':invitaciones_despachadas', $invDesp, \PDO::PARAM_INT);
        $stmt->bindValue(':fecha_limite_cambios', $fechaLimiteCambios, $fechaLimiteCambios === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    private static function intOrDefault($value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function generarClaveTorneo(\PDO $pdo): string
    {
        $year = date('Y');
        $stmt = $pdo->query('SELECT COALESCE(MAX(torneo), 0) + 1 AS n FROM torneosact');
        $n = (int) $stmt->fetchColumn();

        return $year . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
