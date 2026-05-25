<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Supervision\Modelos\SupervisionFvd;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    AdminPolicy::assertSupervisionAdmingral();

    if ($method === 'GET') {
        if (isset($_GET['resumen']) && (string) $_GET['resumen'] === '1') {
            echo json_encode([
                'ok' => true,
                'pendientes' => SupervisionFvd::resumenPendientes($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $seg = isset($_GET['segmento']) ? (string) $_GET['segmento'] : '';
        $out = SupervisionFvd::listar($pdo, $seg);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $body = admin_json_body();
        $accion = isset($body['accion']) ? (string) $body['accion'] : '';
        $mid = isset($body['movimiento_id']) ? (int) $body['movimiento_id'] : 0;

        $accionesMov = [
            'aprobar_afiliacion_movimiento',
            'rechazar_afiliacion_movimiento',
            'aprobar_carnet_movimiento',
            'rechazar_carnet_movimiento',
            'aprobar_traspaso',
            'rechazar_traspaso',
            'traspaso_revisado',
        ];
        if ($accion === 'aplicar_masivo') {
            $decisiones = $body['decisiones'] ?? null;
            if (!is_array($decisiones) || $decisiones === []) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Indique decisiones (lista no vacía).']);
                exit;
            }
            $resultado = SupervisionFvd::aplicarAccionesMasivas($pdo, $decisiones);
            $nOk = (int) ($resultado['aplicadas'] ?? 0);
            $nFail = count($resultado['fallidas'] ?? []);
            $msg = $nFail === 0
                ? "Se aplicaron {$nOk} decisión(es). Deuda recalculada por asociación."
                : "Aplicadas {$nOk}; con error {$nFail}. Revise el detalle.";
            echo json_encode(array_merge($resultado, [
                'message' => $msg,
            ]), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (in_array($accion, $accionesMov, true)) {
            if ($mid < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Indique movimiento_id.']);
                exit;
            }
            SupervisionFvd::aplicarAccionMovimiento($pdo, $accion, $mid);
            echo json_encode([
                'ok' => true,
                'message' => 'Movimiento actualizado.',
                'pendientes' => SupervisionFvd::resumenPendientes($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'accion no reconocida.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('supervision_fvd: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error interno.']);
}
