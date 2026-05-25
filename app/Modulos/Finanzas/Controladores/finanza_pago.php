<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;

$pdo = admin_guard_pdo();

try {
    AdminPolicy::assertFinanzasGestion();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
        exit;
    }
    $body = admin_json_body();
    $aid = (int) ($body['asociacion_id'] ?? 0);
    if ($aid < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'asociacion_id requerido.']);
        exit;
    }
    $res = FinanzaFvd::registrarPago($pdo, $aid, $body);
    echo json_encode(['ok' => true, 'message' => 'Pago registrado.', 'pago' => $res]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('finanza_pago: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
