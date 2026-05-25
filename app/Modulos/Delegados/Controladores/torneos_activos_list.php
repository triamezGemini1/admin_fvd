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

$items = DelegadoMovimientoTorneo::listarTorneosActivos($pdo);

echo json_encode(['ok' => true, 'items' => $items]);
