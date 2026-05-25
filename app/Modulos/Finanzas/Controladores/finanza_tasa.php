<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    AdminPolicy::assertFinanzasGestion();
    if ($method === 'GET') {
        echo json_encode([
            'ok' => true,
            'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
        ]);
        exit;
    }
    if ($method === 'PATCH' || $method === 'PUT') {
        $body = admin_json_body();
        $t = isset($body['tasa_eur_bs']) ? (float) $body['tasa_eur_bs'] : 0.0;
        FinanzaFvd::actualizarTasaEurBs($pdo, $t);
        echo json_encode([
            'ok' => true,
            'message' => 'Tasa actualizada.',
            'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
        ]);
        exit;
    }
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('finanza_tasa: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
