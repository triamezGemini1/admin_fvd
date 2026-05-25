<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/FinanzaFvd.php';
require_once FVD_ROOT . '/app/TorneoMovimientoLock.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;
use Fvd\Modulos\Informes\Modelos\InformeFvd;

$pdo = admin_guard_pdo();

try {
    $asocId = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : 0;
    $renglon = isset($_GET['renglon']) ? trim((string) $_GET['renglon']) : '';

    if ($asocId < 1) {
        AdminPolicy::assertInformeConsolidadoGlobal();
        $torneoInforme = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
        $tidInf = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, $torneoInforme > 0 ? $torneoInforme : null);
        $filas = InformeFvd::resumenConsolidadoPorAsociacion($pdo, $tidInf);
        $fin = FinanzaFvd::resumenPorAsociacion($pdo, $tidInf);
        $byId = [];
        foreach ($fin as $f) {
            $byId[(int) ($f['id'] ?? 0)] = $f;
        }
        foreach ($filas as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $x = $byId[$id] ?? null;
            $row['deuda_eur'] = $x !== null ? round((float) ($x['deuda_eur'] ?? 0), 2) : 0.0;
            $row['pagado_eur'] = $x !== null ? round((float) ($x['pagado_eur'] ?? 0), 2) : 0.0;
            $row['saldo_eur'] = $x !== null ? round((float) ($x['saldo_eur'] ?? 0), 2) : 0.0;
        }
        unset($row);

        $tf = InformeFvd::torneoActivoFila($pdo);
        $torneoActivoJson = null;
        if ($tf !== null) {
            $torneoActivoJson = [
                'id' => (int) $tf['torneo'],
                'nombre' => (string) ($tf['nombre'] ?? ''),
                'clavetor' => (string) ($tf['clavetor'] ?? ''),
                'costotor' => isset($tf['costotor']) ? round((float) $tf['costotor'], 2) : null,
                'fechator' => $tf['fechator'] ?? null,
                'lugar' => $tf['lugar'] ?? null,
                'finalizado_en' => $tf['finalizado_en'] ?? null,
            ];
        }
        $tidLock = $torneoActivoJson !== null ? (int) $torneoActivoJson['id'] : null;
        $movFlags = TorneoMovimientoLock::flagsJson($pdo, $tidLock);
        $tfInf = $tidInf !== null ? InformeFvd::torneoFilaPorId($pdo, $tidInf) : null;
        $torneoInformeJson = $tfInf !== null ? [
            'id' => (int) $tfInf['torneo'],
            'nombre' => (string) ($tfInf['nombre'] ?? ''),
            'clavetor' => (string) ($tfInf['clavetor'] ?? ''),
            'fechator' => $tfInf['fechator'] ?? null,
            'finalizado_en' => $tfInf['finalizado_en'] ?? null,
        ] : null;

        echo json_encode(array_merge([
            'ok' => true,
            'torneo_activo_id' => $torneoActivoJson !== null ? $torneoActivoJson['id'] : null,
            'torneo_activo' => $torneoActivoJson,
            'torneo_informe_id' => $tidInf,
            'torneo_informe' => $torneoInformeJson,
            'torneos_nomina_selector' => InformeFvd::torneosConNominaParaSelector($pdo),
            'torneos_estructurado' => InformeFvd::torneosSelectorEstructurado($pdo),
            'filas' => $filas,
            'totales_renglones' => InformeFvd::totalesRenglonesConsolidado($filas),
            'integral' => FinanzaFvd::balanceIntegralEur($pdo),
            'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
        ], $movFlags));
        exit;
    }

    AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($asocId);

    $torneoInforme = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    $tidInf = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, $torneoInforme > 0 ? $torneoInforme : null);

    if ($renglon === '') {
        $det = InformeFvd::detalleAsociacion($pdo, $asocId, $tidInf);
        $movFlags = TorneoMovimientoLock::flagsJson($pdo, isset($det['torneo_activo_id']) ? (int) $det['torneo_activo_id'] : null);
        echo json_encode(['ok' => true, 'data' => array_merge($det, $movFlags)]);
        exit;
    }

    $det = InformeFvd::detalleRenglon($pdo, $asocId, $renglon, $tidInf);
    $asoc = InformeFvd::detalleAsociacion($pdo, $asocId, $tidInf);
    $movFlags = TorneoMovimientoLock::flagsJson($pdo, isset($asoc['torneo_activo_id']) ? (int) $asoc['torneo_activo_id'] : null);

    $payload = array_merge([
        'ok' => true,
        'asociacion' => $asoc['asociacion'],
        'torneo_activo_id' => $asoc['torneo_activo_id'],
        'torneo_activo' => $asoc['torneo_activo'],
        'renglon' => $det['renglon'],
        'movimientos' => $det['movimientos'],
        'cargos' => $det['cargos'],
    ], $movFlags);

    if ($renglon === 'carnet') {
        $tid = isset($asoc['torneo_activo_id']) ? (int) $asoc['torneo_activo_id'] : 0;
        if ($tid > 0) {
            $payload['afiliados_carnet_informe'] = DelegadoMovimientoTorneo::reporteAfiliadosConMovimiento($pdo, $asocId, $tid);
        } else {
            $payload['afiliados_carnet_informe'] = [];
        }
    }

    echo json_encode($payload);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('informe_consolidado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al cargar el informe.']);
}
