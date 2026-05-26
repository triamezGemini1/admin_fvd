<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';

use Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones;
use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$body = admin_json_body();
$ctx = inscripciones_init_asociacion_activa();
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

require_once FVD_ROOT . '/app/AdminPolicy.php';
AdminPolicy::assertInscripcionTorneo();

$tidBody = isset($body['torneo_id']) ? (int) $body['torneo_id'] : 0;
$torneo = inscripciones_resolver_torneo_jornada($pdo, $tidBody > 0 ? $tidBody : null);
if ($torneo === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No hay torneo activo.']);
    exit;
}
$tid = (int) $torneo['torneo'];

$idInscripcion = isset($body['id_inscripcion']) ? (int) $body['id_inscripcion'] : 0;
$cedula = isset($body['cedula']) && is_string($body['cedula']) ? trim($body['cedula']) : '';
$movId = $idInscripcion > 0 ? $idInscripcion : null;
$cedulaParam = $movId === null && $cedula !== '' ? $cedula : null;

if ($movId === null && $cedulaParam === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Indique id_inscripcion o cedula.']);
    exit;
}

try {
    $res = InscripcionTorneo::retirar($pdo, $tid, $asoc, $movId, $cedulaParam);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    error_log('inscripciones_retirar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al retirar la inscripción.']);
    exit;
}

$userId = (int) ($res['id_usuario'] ?? 0);
if ($userId > 0) {
    try {
        DeudaAsociaciones::recalcularTrasInscripcion($pdo, $tid, $asoc, [$userId]);
    } catch (Throwable $eDeuda) {
        error_log('inscripciones_retirar → DeudaAsociaciones: ' . $eDeuda->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'ok' => true,
    'message' => 'Atleta retirado correctamente',
    'movimiento_id' => (int) ($res['movimiento_id'] ?? 0),
]);
