<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/FvdAdminNotificaciones.php';
require_once FVD_ROOT . '/app/AdminAsociacion.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;

$pdo = admin_guard_pdo();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Debe iniciar sesión.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
$torneoId = isset($body['torneo_id']) ? (int) $body['torneo_id'] : 0;
$asocId = isset($body['asociacion_id']) ? (int) $body['asociacion_id'] : 0;

if ($userId < 1 || $torneoId < 1 || $asocId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'user_id, torneo_id y asociacion_id son obligatorios.']);
    exit;
}

AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($asocId);

if (Auth::rol() === 'delegado') {
    AdminPolicy::assertUsuarioWrite();
}

try {
    $pdo->beginTransaction();
    $ctxAdmin = Auth::rol() === 'admingral' ? $asocId : null;
    $movId = DelegadoMovimientoTorneo::solicitarCarnet($pdo, $userId, $torneoId, $ctxAdmin);
    $pdo->commit();
    DelegadoMovimientoTorneo::recalcularDeudaUsuarioTorneo($pdo, $torneoId, $userId);

    $asoc = AdminAsociacion::obtener($pdo, $asocId);
    $nomAsoc = is_array($asoc) ? trim((string) ($asoc['nombre'] ?? '')) : '';
    $stU = $pdo->prepare('SELECT nombre, cedula FROM usuarios WHERE id = :id LIMIT 1');
    $stU->bindValue(':id', $userId, PDO::PARAM_INT);
    $stU->execute();
    $urow = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
    $actor = Auth::rol() === 'admingral' ? 'Admin. gral.' : 'Delegado';
    $msg = sprintf(
        '%s — Solicitud de carnet (informe, torneo %d): %s — cédula %s — asociación %s',
        $actor,
        $torneoId,
        trim((string) ($urow['nombre'] ?? '')),
        trim((string) ($urow['cedula'] ?? '')),
        $nomAsoc !== '' ? $nomAsoc : '#' . $asocId
    );
    FvdAdminNotificaciones::crear($pdo, 'SOLICITUD_CARNET_INFORME', $msg, null);

    echo json_encode(['ok' => true, 'movimiento_id' => $movId, 'message' => 'Solicitud de carnet registrada en movimiento_torneo.']);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('informe_movimiento_solicitar_carnet: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al registrar la solicitud.']);
}
