<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/InformeFvd.php';

use Fvd\Modulos\Auth\Modelos\Auth;
use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;

$pdo = admin_guard_pdo();

try {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Indique id de asociación.']);
        exit;
    }
    AdminPolicy::assertLecturaInformeFinanzaPorAsociacion($id);
    $tidParam = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    $grupoParam = isset($_GET['grupo_evento_id']) ? (int) $_GET['grupo_evento_id'] : 0;
    $consolidarCampeonato = !isset($_GET['consolidar_campeonato']) || (int) $_GET['consolidar_campeonato'] !== 0;

    $estado = FinanzaFvd::estadoAsociacion($pdo, $id);
    $estado['tasa_eur_bs'] = FinanzaFvd::obtenerTasaEurBs($pdo);
    $estado['puede_gestionar_cargos'] = Auth::check() && Auth::rol() === 'admingral';

    $aIdAct = InformeFvd::torneoActivoId($pdo);
    $lista = InformeFvd::torneosConMovimientoParaAsociacion($pdo, $id);
    if ($grupoParam > 0) {
        $idsGrupo = [];
        foreach (\Fvd\Modulos\Torneos\Modelos\TorneoCampeonato::torneosDeGrupo($pdo, $grupoParam) as $tr) {
            $tgid = (int) ($tr['torneo'] ?? 0);
            if ($tgid > 0) {
                $idsGrupo[$tgid] = true;
            }
        }
        $lista = array_values(array_filter($lista, static function (array $meta) use ($idsGrupo): bool {
            $tid = (int) ($meta['torneo_id'] ?? 0);

            return $tid > 0 && isset($idsGrupo[$tid]);
        }));
        $consolidarCampeonato = true;
    }
    $bloques = [];
    if ($tidParam > 0 && $grupoParam < 1) {
        $bloques[] = InformeFvd::detalleAsociacionParaTorneo($pdo, $id, $tidParam, true);
    } elseif (count($lista) < 1) {
        $bloques[] = InformeFvd::detalleAsociacionParaTorneo($pdo, $id, null, true);
    } else {
        $hayActivoEnLista = false;
        if ($aIdAct !== null) {
            foreach ($lista as $meta) {
                if ((int) ($meta['torneo_id'] ?? 0) === $aIdAct) {
                    $hayActivoEnLista = true;
                    break;
                }
            }
        }
        foreach ($lista as $idx => $meta) {
            $tid = (int) ($meta['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $inclTotalCuenta = ($aIdAct !== null && $tid === $aIdAct) || (!$hayActivoEnLista && $idx === 0);
            $bloques[] = InformeFvd::detalleAsociacionParaTorneo($pdo, $id, $tid, $inclTotalCuenta);
        }
    }
    $estado['torneos_con_nomina_asoc'] = $lista;
    $estado['informes_por_torneo'] = $bloques;
    $gruposInforme = $consolidarCampeonato
        ? InformeFvd::agruparBloquesInformeAsociacionPorCampeonato($pdo, $bloques)
        : [];
    $estado['grupos_informe'] = $gruposInforme;
    $estado['consolidar_campeonato'] = $consolidarCampeonato;
    $estado['grupo_evento_id'] = $grupoParam > 0 ? $grupoParam : null;
    $estado['torneos_con_movimiento'] = $lista;
    $estado['torneos_para_selector'] = InformeFvd::torneosParaSelectorFinanzasAsociacion($pdo, $id);
    $estado['torneos_estructurado'] = InformeFvd::torneosSelectorEstructurado($pdo);

    $vistaId = $grupoParam > 0 ? 0 : ($tidParam > 0 ? $tidParam : ($aIdAct ?? InformeFvd::resolverTorneoIdInformeMovimiento($pdo, null)));
    $vistaNombre = '';
    if ($grupoParam > 0) {
        foreach ($gruposInforme as $g) {
            if ((int) ($g['grupo_evento_id'] ?? 0) === $grupoParam) {
                $vistaNombre = (string) ($g['etiqueta'] ?? '') . ' (campeonato)';
                break;
            }
        }
        if ($vistaNombre === '') {
            $vistaNombre = 'Campeonato #' . $grupoParam;
        }
    } elseif ($vistaId !== null && (int) $vistaId > 0) {
        $tfV = InformeFvd::torneoFilaPorId($pdo, (int) $vistaId);
        if ($tfV !== null) {
            $vistaNombre = (string) ($tfV['nombre'] ?? '');
        }
    }
    $estado['vista_torneo_id'] = $vistaId;
    $estado['vista_torneo_nombre'] = $vistaNombre;

    echo json_encode(['ok' => true, 'data' => $estado]);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('finanza_asociacion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
