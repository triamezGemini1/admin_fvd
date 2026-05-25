<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones;
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
    $pid = (int) ($body['pago_id'] ?? 0);
    $aid = (int) ($body['asociacion_id'] ?? 0);
    if ($pid < 1 || $aid < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'pago_id y asociacion_id requeridos.']);
        exit;
    }
    $st = $pdo->prepare(
        'SELECT id FROM relacion_pagos WHERE id = :id AND asociacion_id = :a AND torneo_id = :t LIMIT 1'
    );
    $st->bindValue(':id', $pid, PDO::PARAM_INT);
    $st->bindValue(':a', $aid, PDO::PARAM_INT);
    $st->bindValue(':t', FinanzaFvd::PAGO_TORNEO_FINANZA_FVD, PDO::PARAM_INT);
    $st->execute();
    if ($st->fetchColumn() === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Pago no encontrado para esta asociación.']);
        exit;
    }
    $nota = isset($body['nota']) ? trim((string) $body['nota']) : null;
    FinanzaFvd::verificarPagoManual($pdo, $pid, $nota);

    try {
        DeudaAsociaciones::recalcularFila($pdo, FinanzaFvd::PAGO_TORNEO_FINANZA_FVD, $aid);
    } catch (Throwable $e) {
        error_log('finanza_pago_verificar → DeudaAsociaciones: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Pago verificado y contabilizado contra el saldo.']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('finanza_pago_verificar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
