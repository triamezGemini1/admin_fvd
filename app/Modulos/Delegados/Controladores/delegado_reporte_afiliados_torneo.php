<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;

$pdo = admin_guard_pdo();

if (Auth::rol() !== 'delegado') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Solo delegado.']);
    exit;
}

$aid = Auth::asociacionId();
if ($aid === null || $aid < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin asociación.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$tid = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
if ($tid < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Indique torneo_id.']);
    exit;
}

try {
    DelegadoMovimientoTorneo::assertTorneoActivo($pdo, $tid);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    exit;
}

$rows = DelegadoMovimientoTorneo::reporteAfiliadosConMovimiento($pdo, $aid, $tid);

echo json_encode(['ok' => true, 'torneo_id' => $tid, 'items' => $rows]);
