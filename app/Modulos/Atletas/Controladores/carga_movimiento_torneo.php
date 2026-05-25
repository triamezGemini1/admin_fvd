<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Atletas\Modelos\CargaTorneoDesdeAtletas;
use Fvd\Modulos\Informes\Modelos\InformeFvd;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;

$pdo = admin_guard_pdo();

/**
 * @return array{grupo_evento_id: int, modo_campeonato: string, meta: array<string, mixed>}|null
 */
function carga_nomina_grupo_desde_torneo(\PDO $pdo, int $torneoId, ?string $modoEsperado = null): ?array
{
    if ($torneoId < 1) {
        return null;
    }
    $meta = TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId);
    $gid = (int) ($meta['grupo_evento_id'] ?? 0);
    $modo = (string) ($meta['modo_campeonato'] ?? '');
    if ($gid < 1 || $modo === '') {
        return null;
    }
    if ($modoEsperado !== null && $modo !== $modoEsperado) {
        return null;
    }

    return ['grupo_evento_id' => $gid, 'modo_campeonato' => $modo, 'meta' => $meta];
}

/**
 * @param array<string, mixed> $opcionesBase
 * @param array<string, mixed> $body
 *
 * @return array<string, mixed>
 */
function carga_nomina_opciones_regenerar_grupo(array $opcionesBase, array $body): array
{
    return array_merge($opcionesBase, [
        'solo_con_indicadores' => true,
        'crear_solicitudes_auditoria' => isset($body['crear_solicitudes'])
            ? (bool) $body['crear_solicitudes']
            : false,
    ]);
}

try {
    AdminPolicy::assertFinanzasGestion();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $torneoId = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : 0;
    $grupoIdGet = isset($_GET['grupo_evento_id']) ? (int) $_GET['grupo_evento_id'] : 0;
    $evalCampeonatoGenero = isset($_GET['campeonato_genero']) && (string) $_GET['campeonato_genero'] === '1';
    $evalCampeonatoCategoria = isset($_GET['campeonato_categoria']) && (string) $_GET['campeonato_categoria'] === '1';

    if ($method === 'GET') {
        $estructurado = InformeFvd::torneosSelectorEstructurado($pdo);
        if ($torneoId < 1 && $grupoIdGet < 1) {
            echo json_encode([
                'ok' => true,
                'torneos_selector' => $estructurado['torneos_plano'],
                'torneos_estructurado' => $estructurado,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        @set_time_limit(300);
        if ($evalCampeonatoGenero) {
            $grupoCtx = $grupoIdGet > 0
                ? ['grupo_evento_id' => $grupoIdGet, 'modo_campeonato' => TorneoCampeonato::MODO_GENERO]
                : carga_nomina_grupo_desde_torneo($pdo, $torneoId, TorneoCampeonato::MODO_GENERO);
            if ($grupoCtx === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Indique un torneo o grupo de campeonato por género.']);
                exit;
            }
            $gid = (int) $grupoCtx['grupo_evento_id'];
            $evalCamp = CargaTorneoDesdeAtletas::evaluarCampeonatoPorGenero($pdo, $gid);
            echo json_encode([
                'ok' => true,
                'evaluacion_campeonato_genero' => $evalCamp,
                'torneos_selector' => $estructurado['torneos_plano'],
                'torneos_estructurado' => $estructurado,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($evalCampeonatoCategoria) {
            $grupoCtx = $grupoIdGet > 0
                ? ['grupo_evento_id' => $grupoIdGet, 'modo_campeonato' => TorneoCampeonato::MODO_CATEGORIA]
                : carga_nomina_grupo_desde_torneo($pdo, $torneoId, TorneoCampeonato::MODO_CATEGORIA);
            if ($grupoCtx === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Indique un torneo o grupo de campeonato por categoría.']);
                exit;
            }
            $gid = (int) $grupoCtx['grupo_evento_id'];
            $evalCamp = CargaTorneoDesdeAtletas::evaluarCampeonatoPorCategoria($pdo, $gid);
            echo json_encode([
                'ok' => true,
                'evaluacion_campeonato_categoria' => $evalCamp,
                'torneos_selector' => $estructurado['torneos_plano'],
                'torneos_estructurado' => $estructurado,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $eval = CargaTorneoDesdeAtletas::evaluarPreCarga($pdo, $torneoId);
        $grupoGen = carga_nomina_grupo_desde_torneo($pdo, $torneoId, TorneoCampeonato::MODO_GENERO);
        $grupoCat = carga_nomina_grupo_desde_torneo($pdo, $torneoId, TorneoCampeonato::MODO_CATEGORIA);
        echo json_encode([
            'ok' => true,
            'evaluacion' => $eval,
            'campeonato_genero' => $grupoGen !== null ? [
                'grupo_evento_id' => $grupoGen['grupo_evento_id'],
                'torneos_por_sexo' => TorneoCampeonato::mapTorneosIdPorSexoEnGrupo($pdo, $grupoGen['grupo_evento_id']),
            ] : null,
            'campeonato_categoria' => $grupoCat !== null ? [
                'grupo_evento_id' => $grupoCat['grupo_evento_id'],
                'torneos_por_categoria' => TorneoCampeonato::mapTorneosIdPorCategoriaEnGrupo($pdo, $grupoCat['grupo_evento_id']),
            ] : null,
            'torneos_selector' => $estructurado['torneos_plano'],
            'torneos_estructurado' => $estructurado,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            $body = [];
        }
        $accion = isset($body['accion']) ? trim((string) $body['accion']) : '';
        $tid = isset($body['torneo_id']) ? (int) $body['torneo_id'] : $torneoId;
        $grupoId = isset($body['grupo_evento_id']) ? (int) $body['grupo_evento_id'] : 0;
        $opcionesBase = [
            'omitir_bloqueo_torneo' => !empty($body['force']),
            'recalcular_deuda' => !isset($body['recalcular_deuda']) || (bool) $body['recalcular_deuda'],
            'crear_solicitudes_auditoria' => !isset($body['crear_solicitudes']) || (bool) $body['crear_solicitudes'],
            'solo_con_indicadores' => !isset($body['solo_con_indicadores']) || (bool) $body['solo_con_indicadores'],
            'provisionar_usuarios_faltantes' => !isset($body['provisionar_usuarios']) || (bool) $body['provisionar_usuarios'],
        ];

        if ($accion === 'evaluar_campeonato_genero' || $accion === 'regenerar_campeonato_genero') {
            @set_time_limit(600);
            $grupoCtx = $grupoId > 0
                ? ['grupo_evento_id' => $grupoId, 'modo_campeonato' => TorneoCampeonato::MODO_GENERO]
                : carga_nomina_grupo_desde_torneo($pdo, $tid, TorneoCampeonato::MODO_GENERO);
            if ($grupoCtx === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'No es un campeonato por género o falta grupo_evento_id.']);
                exit;
            }
            $gid = (int) $grupoCtx['grupo_evento_id'];
            if ($accion === 'evaluar_campeonato_genero') {
                $evalCamp = CargaTorneoDesdeAtletas::evaluarCampeonatoPorGenero($pdo, $gid, $opcionesBase);
                echo json_encode(['ok' => true, 'evaluacion_campeonato_genero' => $evalCamp], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($body['confirmar'])) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Debe confirmar la regeneración del campeonato (asigna M/F automáticamente y borra ambas nóminas).',
                ]);
                exit;
            }
            $precheck = CargaTorneoDesdeAtletas::precheckRegeneracionGrupo($pdo, $gid, TorneoCampeonato::MODO_GENERO);
            if ((int) ($precheck['atletas_canon_con_usuario_e_indicador'] ?? 0) < 1) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'No hay atletas con indicadores activos y usuario en portal.',
                    'precheck' => $precheck,
                ]);
                exit;
            }
            $resCamp = CargaTorneoDesdeAtletas::regenerarCampeonatoPorGenero(
                $pdo,
                $gid,
                carga_nomina_opciones_regenerar_grupo($opcionesBase, $body)
            );
            $resumen = $resCamp['resumen_regeneracion'] ?? null;
            $msg = is_array($resumen) && isset($resumen['mensaje'])
                ? (string) $resumen['mensaje']
                : 'Campeonato #' . $gid . ': nómina distribuida por género.';
            echo json_encode([
                'ok' => true,
                'message' => $msg,
                'precheck' => $precheck,
                'resultado_campeonato_genero' => $resCamp,
                'resumen_regeneracion' => $resumen,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion === 'evaluar_campeonato_categoria' || $accion === 'regenerar_campeonato_categoria') {
            @set_time_limit(600);
            $grupoCtx = $grupoId > 0
                ? ['grupo_evento_id' => $grupoId, 'modo_campeonato' => TorneoCampeonato::MODO_CATEGORIA]
                : carga_nomina_grupo_desde_torneo($pdo, $tid, TorneoCampeonato::MODO_CATEGORIA);
            if ($grupoCtx === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'No es un campeonato por categoría o falta grupo_evento_id.']);
                exit;
            }
            $gid = (int) $grupoCtx['grupo_evento_id'];
            if ($accion === 'evaluar_campeonato_categoria') {
                $evalCamp = CargaTorneoDesdeAtletas::evaluarCampeonatoPorCategoria($pdo, $gid, $opcionesBase);
                echo json_encode(['ok' => true, 'evaluacion_campeonato_categoria' => $evalCamp], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($body['confirmar'])) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Debe confirmar la regeneración del campeonato (asigna Sub 12/15/18 y borra las nóminas del grupo).',
                ]);
                exit;
            }
            $precheck = CargaTorneoDesdeAtletas::precheckRegeneracionGrupo($pdo, $gid, TorneoCampeonato::MODO_CATEGORIA);
            if ((int) ($precheck['atletas_canon_con_usuario_e_indicador'] ?? 0) < 1) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'No hay atletas con indicadores activos y usuario en portal.',
                    'precheck' => $precheck,
                ]);
                exit;
            }
            $resCamp = CargaTorneoDesdeAtletas::regenerarCampeonatoPorCategoria(
                $pdo,
                $gid,
                carga_nomina_opciones_regenerar_grupo($opcionesBase, $body)
            );
            $resumen = $resCamp['resumen_regeneracion'] ?? null;
            $msg = is_array($resumen) && isset($resumen['mensaje'])
                ? (string) $resumen['mensaje']
                : 'Campeonato #' . $gid . ': nómina distribuida por categoría.';
            echo json_encode([
                'ok' => true,
                'message' => $msg,
                'precheck' => $precheck,
                'resultado_campeonato_categoria' => $resCamp,
                'resumen_regeneracion' => $resumen,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($tid < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'torneo_id inválido.']);
            exit;
        }

        if ($accion === 'evaluar') {
            $eval = CargaTorneoDesdeAtletas::evaluarPreCarga($pdo, $tid);
            echo json_encode(['ok' => true, 'evaluacion' => $eval], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion === 'regenerar') {
            $metaProc = TorneoCampeonato::metaProcesamientoTorneo($pdo, $tid);
            if (empty($body['confirmar'])) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Debe confirmar la regeneración (elimina la nómina actual del torneo y la reconstruye desde atletas).',
                ]);
                exit;
            }
            $opcionesRegen = array_merge($opcionesBase, [
                'torneo_id' => $tid,
                'solo_con_indicadores' => true,
                'crear_solicitudes_auditoria' => isset($body['crear_solicitudes'])
                    ? (bool) $body['crear_solicitudes']
                    : false,
            ]);
            $precheck = CargaTorneoDesdeAtletas::precheckRegeneracion($pdo, $tid);
            $baseProcesables = (int) ($precheck['atletas_canon_con_usuario_e_indicador'] ?? 0);
            if ($baseProcesables < 1) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'No hay atletas con indicadores activos y usuario en portal. Revise la tabla atletas antes de regenerar.',
                    'precheck' => $precheck,
                ]);
                exit;
            }
            @set_time_limit(600);
            $stats = CargaTorneoDesdeAtletas::regenerarDesdeAtletas($pdo, $opcionesRegen);
            $resumen = $stats['resumen_regeneracion'] ?? null;
            $msg = is_array($resumen) && isset($resumen['mensaje'])
                ? (string) $resumen['mensaje']
                : 'Nómina regenerada para torneo #' . $tid . ' (' . ($metaProc['variante_etiqueta'] ?? '') . ').';
            echo json_encode([
                'ok' => true,
                'message' => $msg,
                'torneo_procesado' => $metaProc,
                'precheck' => $precheck,
                'resultado' => $stats,
                'resumen_regeneracion' => $resumen,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'accion inválida (evaluar|regenerar|evaluar_campeonato_genero|regenerar_campeonato_genero|evaluar_campeonato_categoria|regenerar_campeonato_categoria).',
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.']);
} catch (Throwable $e) {
    error_log('carga_movimiento_torneo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
