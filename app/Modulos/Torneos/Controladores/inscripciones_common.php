<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

/**
 * @return array{pdo: PDO, asociacion_id: int}
 */
function inscripciones_init_con_asociacion(?int $asociacionIdParam): array
{
    $pdo = admin_guard_pdo();
    $rol = Auth::rol();
    if ($rol === 'delegado') {
        $id = Auth::asociacionId();
        if ($id === null || $id <= 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Delegado sin asociación asignada.']);
            exit;
        }

        return ['pdo' => $pdo, 'asociacion_id' => $id];
    }
    if ($rol === 'admingral') {
        $id = $asociacionIdParam !== null && $asociacionIdParam > 0 ? $asociacionIdParam : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique asociacion_id (administración general).']);
            exit;
        }

        return ['pdo' => $pdo, 'asociacion_id' => $id];
    }
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin permiso.']);
    exit;
}
