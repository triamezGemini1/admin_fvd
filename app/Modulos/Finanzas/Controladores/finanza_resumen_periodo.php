<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Auth\Modelos\Auth;
use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;
use Fvd\Modulos\Informes\Modelos\InformeFvd;

$pdo = admin_guard_pdo();

try {
    if (!Auth::check() || !AdminPolicy::puedeAccederPanel()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Acceso denegado.']);
        exit;
    }
    AdminPolicy::assertFinanzasOperativasFvd();

    $asocScope = 0;
    $rol = Auth::rol();
    $req = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : 0;
    if ($req > 0) {
        AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($req);
        $asocScope = $req;
    }

    $torneoId = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    $grupoId = isset($_GET['grupo_evento_id']) ? (int) $_GET['grupo_evento_id'] : 0;
    $soloAsoc = $asocScope > 0 ? $asocScope : null;

    $integral = null;
    $estadoAsoc = null;
    if ($asocScope > 0) {
        $estadoAsoc = FinanzaFvd::estadoAsociacion($pdo, $asocScope);
    } else {
        $integral = FinanzaFvd::balanceIntegralEur($pdo);
    }

    $payload = [
        'ok' => true,
        'rol' => $rol,
        'asociacion_id' => $asocScope > 0 ? $asocScope : null,
        'vista_asociacion' => $asocScope > 0,
        'integral' => $integral,
        'estado_asociacion' => $estadoAsoc,
        'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
        'torneos_estructurado' => $asocScope > 0 ? null : InformeFvd::torneosSelectorEstructurado($pdo),
    ];

    if ($torneoId > 0) {
        $tf = InformeFvd::torneoFilaPorId($pdo, $torneoId);
        if ($tf === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Torneo no encontrado.']);
            exit;
        }
        $det = InformeFvd::resumenFinanzasPeriodo($pdo, $torneoId, null, $soloAsoc);
        $payload['vista'] = 'detalle_torneo';
        $payload['torneo'] = [
            'torneo_id' => $torneoId,
            'nombre' => (string) ($tf['nombre'] ?? ''),
            'fechator' => $tf['fechator'] ?? null,
            'finalizado_en' => $tf['finalizado_en'] ?? null,
        ];
        $payload['resumen'] = $det;
    } else {
        $gid = $grupoId > 0 ? $grupoId : null;
        $listado = InformeFvd::resumenFinanzasFilasPorTorneo($pdo, $gid, $soloAsoc);
        $payload['vista'] = 'listado_torneos';
        $payload['listado'] = $listado;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('finanza_resumen_periodo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
