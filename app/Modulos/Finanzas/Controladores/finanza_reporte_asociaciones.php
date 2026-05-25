<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;
use Fvd\Modulos\Informes\Modelos\InformeFvd;

$pdo = admin_guard_pdo();

try {
    $asocId = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : 0;

    if ($asocId > 0) {
        AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($asocId);
        $lista = InformeFvd::torneosConMovimientoParaAsociacion($pdo, $asocId);
        $bloques = [];
        foreach ($lista as $meta) {
            $tid = (int) ($meta['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $bloques[] = InformeFvd::detalleAsociacionParaTorneo($pdo, $asocId, $tid, false);
        }
        $stmt = $pdo->prepare('SELECT id, nombre, logo, estatus FROM asociaciones WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $asocId, \PDO::PARAM_INT);
        $stmt->execute();
        $asoc = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($asoc === false) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }
        $estadoCuenta = InformeFvd::totalCuentaAsociacionEur($pdo, $asocId, null);
        $asociaciones = [[
            'asociacion' => $asoc,
            'informes_por_torneo' => $bloques,
            'estado_cuenta' => $estadoCuenta,
            'n_torneos' => count($bloques),
            'resumen_columnas' => array_merge(
                InformeFvd::resumenMovimientoGlobalAsociacion($pdo, $asocId),
                [
                    'deuda_eur' => round((float) ($estadoCuenta['deuda_eur'] ?? 0), 2),
                    'pagado_eur' => round((float) ($estadoCuenta['pagado_eur'] ?? 0), 2),
                    'saldo_eur' => round((float) ($estadoCuenta['saldo_eur'] ?? 0), 2),
                ]
            ),
        ]];
    } else {
        AdminPolicy::assertInformeConsolidadoGlobal();
        set_time_limit(180);
        $asociaciones = InformeFvd::reporteGlobalAsociacionesPorTorneo($pdo);
    }

    echo json_encode([
        'ok' => true,
        'vista' => 'asociaciones_por_torneo',
        'asociaciones' => $asociaciones,
        'n_asociaciones' => count($asociaciones),
        'totales_columnas' => InformeFvd::totalesColumnasReporteParticipacion($asociaciones),
        'integral' => FinanzaFvd::balanceIntegralEur($pdo),
        'tasa_eur_bs' => FinanzaFvd::obtenerTasaEurBs($pdo),
        'torneos_estructurado' => InformeFvd::torneosSelectorEstructurado($pdo),
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('finanza_reporte_asociaciones: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
