<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;

$pdo = admin_guard_pdo();

try {
    AdminPolicy::assertFinanzasGestion();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Use POST.']);
        exit;
    }

    $body = admin_json_body();
    $asocId = (int) ($body['asociacion_id'] ?? $body['asoc'] ?? 0);
    $pagoId = (int) ($body['pago_id'] ?? 0);
    $tasa = isset($body['tasa_vcb_bs_por_eur']) ? (float) $body['tasa_vcb_bs_por_eur'] : 0.0;
    if ($asocId < 1 || $pagoId < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Indique asociacion_id (o asoc) y pago_id.']);
        exit;
    }

    $rec = FinanzaFvd::actualizarTasaReciboOperacion($pdo, $asocId, $pagoId, $tasa);
    echo json_encode(['ok' => true, 'recibo' => $rec], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('informe_recibo_actualizar_tasa: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
