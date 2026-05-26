<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;

$pdo = admin_guard_pdo();

if (!Auth::check() || !AdminPolicy::puedeAccederPanel()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$jornada = DelegadoMovimientoTorneo::bootstrapJornada($pdo);

$prefer = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : null;
if ($prefer > 0) {
    $jornada = DelegadoMovimientoTorneo::bootstrapJornada($pdo, $prefer);
}

echo json_encode([
    'ok' => true,
    'items' => $jornada['torneos_activos'],
    'torneo_activo_id' => $jornada['torneo_activo_id'],
    'torneo_jornada_id' => $jornada['torneo_jornada_id'],
    'torneo_jornada' => $jornada['torneo_jornada'],
    'campeonato_variantes' => $jornada['campeonato_variantes'],
    'permite_selector_campeonato' => $jornada['permite_selector_campeonato'],
]);
