<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';

$asocParam = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : null;
$ctx = inscripciones_init_con_asociacion($asocParam > 0 ? $asocParam : null);
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

$torneo = InscripcionTorneo::torneoActivo($pdo);
if ($torneo === null) {
    echo json_encode(['ok' => true, 'items' => [], 'message' => 'No hay torneo activo.']);

    exit;
}
$tid = (int) $torneo['torneo'];
$items = InscripcionTorneo::listarInscritos($pdo, $tid, $asoc);

echo json_encode(['ok' => true, 'items' => $items, 'torneo_id' => $tid]);
