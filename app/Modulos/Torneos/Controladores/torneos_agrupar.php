<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Torneos\Modelos\AdminTorneo;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (Auth::rol() !== 'admingral') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Solo administración general puede agrupar torneos.']);
    exit;
}

try {
    if ($method === 'GET') {
        AdminPolicy::assertTorneoRead();
        $grupos = AdminTorneo::candidatosAgrupacion($pdo);
        echo json_encode(['ok' => true, 'grupos' => $grupos]);
        exit;
    }

    if ($method === 'POST') {
        AdminPolicy::assertTorneoWrite();
        $body = admin_json_body();
        $ids = $body['torneo_ids'] ?? null;
        if (!is_array($ids)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique torneo_ids (array).']);
            exit;
        }
        $r = AdminTorneo::agruparTorneosPorIds($pdo, $ids);
        echo json_encode([
            'ok' => true,
            'grupo_evento_id' => $r['grupo_evento_id'],
            'torneo_ids' => $r['torneo_ids'],
            'message' => 'Torneos asociados al campeonato (grupo ' . $r['grupo_evento_id'] . ').',
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('torneos_agrupar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
