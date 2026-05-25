<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoActividad;

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

try {
    $items = DelegadoActividad::itemsRecientes($pdo, $aid, 12);
    echo json_encode([
        'ok' => true,
        'asociacion_id' => $aid,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    error_log('delegado_actividad_reciente: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al cargar actividad.']);
}
