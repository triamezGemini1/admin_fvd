<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Auth\Modelos\Auth;
use Fvd\Modulos\Asociaciones\Modelos\OrganizacionFvd;
use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;
use Fvd\Modulos\Finanzas\Modelos\FinanzaGastoTorneo;
use Fvd\Modulos\Informes\Modelos\InformeFvd;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!Auth::check() || !AdminPolicy::puedeAccederPanel()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Acceso denegado.']);
        exit;
    }
    AdminPolicy::assertFinanzasOperativasFvd();

    $torneoId = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    if ($method === 'GET') {
        if ($torneoId < 1) {
            $org = OrganizacionFvd::obtenerActiva($pdo);
            echo json_encode([
                'ok' => true,
                'torneo' => null,
                'organizacion' => $org !== null ? [
                    'nombre' => (string) ($org['nombre'] ?? 'Federación Venezolana de Dominó'),
                    'logo' => (string) ($org['logo'] ?? ''),
                ] : null,
                'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
                'gastos' => [],
                'totales' => ['total_bs' => 0.0, 'total_eur' => 0.0, 'n' => 0],
                'torneos_selector' => InformeFvd::torneosConNominaParaSelector($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tf = InformeFvd::torneoFilaPorId($pdo, $torneoId);
        if ($tf === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Torneo no encontrado.']);
            exit;
        }
        $org = OrganizacionFvd::obtenerActiva($pdo);
        echo json_encode([
            'ok' => true,
            'torneo' => [
                'torneo_id' => $torneoId,
                'nombre' => (string) ($tf['nombre'] ?? ''),
                'fechator' => $tf['fechator'] ?? null,
            ],
            'organizacion' => $org !== null ? [
                'nombre' => (string) ($org['nombre'] ?? 'Federación Venezolana de Dominó'),
                'logo' => (string) ($org['logo'] ?? ''),
            ] : null,
            'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
            'gastos' => FinanzaGastoTorneo::listarPorTorneo($pdo, $torneoId),
            'totales' => FinanzaGastoTorneo::totalesPorTorneo($pdo, $torneoId),
            'torneos_selector' => InformeFvd::torneosConNominaParaSelector($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        AdminPolicy::assertFinanzasGestion();
        $body = admin_json_body();
        $tid = isset($body['torneo_id']) ? (int) $body['torneo_id'] : $torneoId;
        if ($tid < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'torneo_id requerido.']);
            exit;
        }
        unset($body['torneo_id']);
        $id = FinanzaGastoTorneo::registrar($pdo, $tid, $body);
        echo json_encode([
            'ok' => true,
            'message' => 'Gasto registrado.',
            'id' => $id,
            'totales' => FinanzaGastoTorneo::totalesPorTorneo($pdo, $tid),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('finanza_gasto_torneo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
