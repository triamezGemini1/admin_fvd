<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/InscripcionTorneo.php';

$pdo = admin_guard_pdo();

if (Auth::rol() !== 'delegado') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Solo delegados de asociación.']);
    exit;
}

$aid = Auth::asociacionId();
if ($aid === null || $aid < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Sin asociación asignada.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$q = isset($_GET['q']) ? (string) $_GET['q'] : '';

try {
    $tor = InscripcionTorneo::torneoActivo($pdo);
    $torneoId = $tor !== null ? (int) ($tor['torneo'] ?? 0) : 0;
    $tipoTorneo = InscripcionTorneo::normalizarTipoTorneo($tor !== null ? ($tor['tipo'] ?? 0) : 0);

    $disponibles = [];
    $solicitudes = [];
    if ($torneoId > 0) {
        $disponibles = InscripcionTorneo::listarDisponibles($pdo, $torneoId, $aid, $tipoTorneo);
        $solicitudes = InscripcionTorneo::listarSolicitudesTraspasoInscripcion($pdo, $torneoId, $aid, $q !== '' ? $q : null);
    }

    echo json_encode([
        'ok' => true,
        'torneo' => $tor,
        'asociacion_id' => $aid,
        'disponibles' => $disponibles,
        'solicitudes' => $solicitudes,
    ]);
} catch (Throwable $e) {
    error_log('delegado_traspasos_vista: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al cargar datos.']);
}
