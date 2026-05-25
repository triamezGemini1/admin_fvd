<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';
require_once dirname(__DIR__) . '/app/AdminPolicy.php';

$asocParam = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : null;
$ctx = inscripciones_init_con_asociacion($asocParam !== null && $asocParam > 0 ? $asocParam : null);
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

AdminPolicy::assertUsuarioRead();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (mb_strlen($q, 'UTF-8') < 3) {
    echo json_encode(['ok' => true, 'items' => []]);

    exit;
}

$t = InscripcionTorneo::torneoActivo($pdo);
$tid = $t !== null ? (int) $t['torneo'] : 0;
$filtroAsoc = Auth::rol() === 'delegado' ? null : $asoc;
$items = $tid > 0
    ? InscripcionTorneo::buscarUsuariosParaAutocomplete($pdo, $q, $filtroAsoc, 15, $tid)
    : [];

echo json_encode(['ok' => true, 'items' => $items]);
