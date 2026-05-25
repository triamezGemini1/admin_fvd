<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;

$pdo = admin_guard_pdo();

try {
    $asocId = (int) ($_GET['asociacion_id'] ?? $_GET['asoc'] ?? 0);
    $pagoId = (int) ($_GET['pago_id'] ?? 0);
    if ($asocId < 1 || $pagoId < 1) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Indique asociacion_id (o asoc) y pago_id (operación en relacion_pagos, torneo contable FVD).',
        ]);
        exit;
    }

    AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($asocId);

    $rec = FinanzaFvd::datosReciboOperacion($pdo, $asocId, $pagoId);
    echo json_encode(['ok' => true, 'recibo' => $rec], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('informe_recibo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
