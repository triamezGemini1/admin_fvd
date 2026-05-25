<?php

declare(strict_types=1);

/**
 * Cola simple de avisos para administración general (sustituto de push hasta integrar Web Push).
 * Tabla: `fvd_admin_notificaciones` (ver sql/migrations/011_fvd_admin_notificaciones.sql).
 */
class FvdAdminNotificaciones
{
    private const T = 'fvd_admin_notificaciones';

    public static function tablaDisponible(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM `' . self::T . '` LIMIT 1');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function crear(PDO $pdo, string $tipo, string $mensaje, ?int $adminUserId = null): void
    {
        if (!self::tablaDisponible($pdo)) {
            error_log('FvdAdminNotificaciones: tabla ' . self::T . ' no disponible; mensaje omitido: ' . $tipo);

            return;
        }
        $tipo = trim($tipo);
        $mensaje = trim($mensaje);
        if ($tipo === '' || $mensaje === '') {
            return;
        }
        if (strlen($mensaje) > 500) {
            $mensaje = substr($mensaje, 0, 497) . '...';
        }
        $st = $pdo->prepare(
            'INSERT INTO `' . self::T . '` (`admin_user_id`, `tipo`, `mensaje`, `leido`) VALUES (:aid, :tipo, :msg, 0)'
        );
        $st->bindValue(':aid', $adminUserId, $adminUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        $st->bindValue(':msg', $mensaje, PDO::PARAM_STR);
        $st->execute();
    }
}
