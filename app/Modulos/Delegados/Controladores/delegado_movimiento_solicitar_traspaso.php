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

if (Auth::rol() !== 'delegado') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Solo delegado.']);
    exit;
}

AdminPolicy::assertUsuarioWrite();

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
$destId = isset($body['asociacion_destino_id']) ? (int) $body['asociacion_destino_id'] : 0;

if ($userId < 1 || $torneoId < 1 || $destId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'user_id, torneo_id y asociacion_destino_id son obligatorios.']);
    exit;
}

$aid = Auth::asociacionId();
if ($aid === null || $aid < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin asociación.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $movId = DelegadoMovimientoTorneo::solicitarTraspaso($pdo, $userId, $torneoId, $destId);
    $pdo->commit();
    DelegadoMovimientoTorneo::recalcularDeudaUsuarioTorneo($pdo, $torneoId, $userId);

    $asocO = AdminAsociacion::obtener($pdo, $aid);
    $asocD = AdminAsociacion::obtener($pdo, $destId);
    $nomO = is_array($asocO) ? trim((string) ($asocO['nombre'] ?? '')) : '';
    $nomD = is_array($asocD) ? trim((string) ($asocD['nombre'] ?? '')) : '';
    $stU = $pdo->prepare('SELECT nombre, cedula FROM usuarios WHERE id = :id LIMIT 1');
    $stU->bindValue(':id', $userId, PDO::PARAM_INT);
    $stU->execute();
    $urow = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
    $msg = sprintf(
        'Solicitud de traspaso (torneo %d): %s — cédula %s — de %s hacia %s',
        $torneoId,
        trim((string) ($urow['nombre'] ?? '')),
        trim((string) ($urow['cedula'] ?? '')),
        $nomO !== '' ? $nomO : '#' . $aid,
        $nomD !== '' ? $nomD : '#' . $destId
    );
    FvdAdminNotificaciones::crear($pdo, 'SOLICITUD_TRASPASO', $msg, null);

    echo json_encode(['ok' => true, 'movimiento_id' => $movId, 'message' => 'Solicitud de traspaso registrada.']);
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
    error_log('delegado_movimiento_solicitar_traspaso: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al registrar la solicitud.']);
}
