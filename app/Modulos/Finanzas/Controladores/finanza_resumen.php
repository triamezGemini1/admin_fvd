<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;

$pdo = admin_guard_pdo();

try {
    AdminPolicy::assertFinanzasGestion();

    $torneoIdGet = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    $grupoIdGet = isset($_GET['grupo_evento_id']) ? (int) $_GET['grupo_evento_id'] : 0;
    $soloActivo = isset($_GET['solo_activo']) && (int) $_GET['solo_activo'] === 1;
    $consolidar = isset($_GET['consolidar']) && (int) $_GET['consolidar'] === 1;
    $torneoSugerido = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, null);
    $torneoId = $torneoIdGet;
    $grupoEventoId = $grupoIdGet;
    if (!$consolidar && $soloActivo && $grupoEventoId < 1) {
        $actId = InformeFvd::torneoActivoId($pdo);
        $torneoId = $actId !== null && $actId > 0 ? $actId : 0;
    }
    // Sin torneo_id en la petición: no se asigna torneo por defecto (el usuario debe elegir en el formulario).
    if ($consolidar) {
        $resumen = [];
    } elseif ($grupoEventoId > 0) {
        $torneoId = 0;
        $resumen = FinanzaFvd::resumenPorAsociacionGrupoCampeonato($pdo, $grupoEventoId);
    } else {
        $resumen = FinanzaFvd::resumenPorAsociacion($pdo, $torneoId > 0 ? $torneoId : null);
    }

    $integral = FinanzaFvd::balanceIntegralEur($pdo);
    $tasa = FinanzaFvd::obtenerTasaEurBs($pdo);
    $tf = InformeFvd::torneoActivoFila($pdo);
    $torneoActivo = null;
    if ($tf !== null) {
        $torneoActivo = [
            'id' => (int) ($tf['torneo'] ?? 0),
            'nombre' => (string) ($tf['nombre'] ?? ''),
        ];
    }

    $torneosEstructurado = InformeFvd::torneosSelectorEstructurado($pdo);
    $torneosSelector = $torneosEstructurado['torneos_plano'];
    $torneosConNomina = InformeFvd::torneosConNominaParaSelector($pdo);

    $torneoFiltroMeta = null;
    $campeonatoEtiqueta = null;
    if ($grupoEventoId > 0) {
        $estruct = InformeFvd::torneosSelectorEstructurado($pdo);
        foreach ($estruct['campeonatos'] as $camp) {
            if ((int) ($camp['grupo_evento_id'] ?? 0) === $grupoEventoId) {
                $campeonatoEtiqueta = (string) ($camp['etiqueta'] ?? '');
                break;
            }
        }
        $torneoFiltroMeta = [
            'id' => 0,
            'grupo_evento_id' => $grupoEventoId,
            'nombre' => $campeonatoEtiqueta !== '' ? $campeonatoEtiqueta . ' (campeonato)' : 'Campeonato #' . $grupoEventoId,
            'fechator' => null,
            'finalizado_en' => null,
            'modo_campeonato' => TorneoCampeonato::detectarModoGrupo($pdo, $grupoEventoId),
        ];
    } elseif ($torneoId > 0) {
        $tfF = InformeFvd::torneoFilaPorId($pdo, $torneoId);
        if ($tfF !== null) {
            $torneoFiltroMeta = [
                'id' => (int) ($tfF['torneo'] ?? 0),
                'nombre' => (string) ($tfF['nombre'] ?? ''),
                'fechator' => $tfF['fechator'] ?? null,
                'finalizado_en' => $tfF['finalizado_en'] ?? null,
            ];
        }
    }

    $bloquesPorTorneo = null;
    if ($consolidar) {
        $bloquesPorTorneo = [];
        foreach (InformeFvd::torneosConNominaParaSelector($pdo) as $meta) {
            $tidB = (int) ($meta['torneo_id'] ?? 0);
            if ($tidB < 1) {
                continue;
            }
            $bloquesPorTorneo[] = [
                'torneo' => [
                    'torneo_id' => $tidB,
                    'nombre' => (string) ($meta['nombre'] ?? ''),
                    'fechator' => $meta['fechator'] ?? null,
                    'finalizado_en' => $meta['finalizado_en'] ?? null,
                ],
                'resumen' => FinanzaFvd::resumenPorAsociacion($pdo, $tidB),
            ];
        }
    }

    $totalesRenglones = null;
    if (!$consolidar && ($torneoId > 0 || $grupoEventoId > 0) && $resumen !== []) {
        $totalesRenglones = InformeFvd::totalesRenglonesConsolidado($resumen);
    }

    echo json_encode([
        'ok' => true,
        'resumen' => $resumen,
        'totales_renglones' => $totalesRenglones,
        'integral' => $integral,
        'tasa_eur_bs' => $tasa,
        'torneo_activo' => $torneoActivo,
        'torneo_filtro_id' => $torneoId > 0 ? $torneoId : null,
        'grupo_evento_id' => $grupoEventoId > 0 ? $grupoEventoId : null,
        'torneo_filtro' => $torneoFiltroMeta,
        'torneo_informe_sugerido' => $torneoSugerido,
        'torneo_id_solicitado' => $torneoIdGet > 0 ? $torneoIdGet : null,
        'torneos_selector' => $torneosSelector,
        'torneos_estructurado' => $torneosEstructurado,
        'torneos_con_nomina' => $torneosConNomina,
        'requiere_torneo_explicito' => true,
        'consolidar' => $consolidar,
        'bloques_por_torneo' => $bloquesPorTorneo,
    ]);
} catch (Throwable $e) {
    error_log('finanza_resumen: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
