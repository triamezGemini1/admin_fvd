<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/InscripcionTorneo.php';
require_once FVD_ROOT . '/app/TorneoMovimientoLock.php';
require_once FVD_ROOT . '/app/DeudaAsociaciones.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones;

$pdo = admin_guard_pdo();

try {
    AdminPolicy::assertFinanzasGestion();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Use POST.']);
        exit;
    }

    $body = admin_json_body();
    $torneoParam = isset($body['torneo_id']) ? (int) $body['torneo_id'] : 0;

    if ($torneoParam > 0) {
        $tid = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, $torneoParam);
        if ($tid === null || $tid !== $torneoParam) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Torneo no válido.']);
            exit;
        }
        $n = TorneoMovimientoLock::recalcularDeudaTorneo($pdo, $tid);
        $flags = TorneoMovimientoLock::flagsJson($pdo, $tid);
        echo json_encode([
            'ok' => true,
            'torneo_id' => $tid,
            'asociaciones_procesadas' => $n,
            'message' => $n > 0
                ? "Deudas actualizadas: {$n} asociación(es) en torneo_id={$tid}."
                : "Sin asociaciones con movimiento en torneo_id={$tid}.",
        ] + $flags, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $batch = DeudaAsociaciones::recalcularTodosTorneosConMovimiento($pdo);
    $tor = InscripcionTorneo::torneoActivo($pdo);
    $tidAct = $tor !== null ? (int) ($tor['torneo'] ?? 0) : 0;
    $flags = TorneoMovimientoLock::flagsJson($pdo, $tidAct > 0 ? $tidAct : null);
    $partes = [];
    foreach ($batch['torneos'] as $t) {
        $partes[] = 'torneo ' . $t['torneo_id'] . ': ' . $t['asociaciones'] . ' asoc.';
    }
    $msg = $batch['asociaciones_total'] > 0
        ? 'Deudas recalculadas en ' . count($batch['torneos']) . ' torneo(s) con nómina (' . implode('; ', $partes) . ').'
        : 'No hay torneos con filas en movimiento_torneo.';

    echo json_encode([
        'ok' => true,
        'torneos' => $batch['torneos'],
        'asociaciones_procesadas' => $batch['asociaciones_total'],
        'message' => $msg,
    ] + $flags, JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('informe_actualizar_deudas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
