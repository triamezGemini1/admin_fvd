<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

try {
    $ctx = inscripciones_init_asociacion_activa();
    $pdo = $ctx['pdo'];
    $asoc = $ctx['asociacion_id'];

    $preferTorneo = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : null;
    $torneo = inscripciones_resolver_torneo_jornada($pdo, $preferTorneo > 0 ? $preferTorneo : null);
    if ($torneo === null) {
        echo json_encode([
            'ok' => true,
            'items' => [],
            'estadisticas' => ['total' => 0, 'hombres' => 0, 'mujeres' => 0],
            'torneo_id' => null,
            'asociacion_id' => $asoc,
            'message' => 'No hay torneo activo.',
        ]);

        exit;
    }

    $tid = (int) $torneo['torneo'];
    $items = InscripcionTorneo::enriquecerFilasInscritos(InscripcionTorneo::listarInscritos($pdo, $tid, $asoc));
    $stats = InscripcionTorneo::estadisticasDesdeFilas($items);

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'torneo_id' => $tid,
        'asociacion_id' => $asoc,
        'estadisticas' => $stats,
        'total' => $stats['total'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[FVD][inscripciones_inscritos] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'items' => [],
        'estadisticas' => ['total' => 0, 'hombres' => 0, 'mujeres' => 0],
        'message' => 'Error al leer inscritos: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}