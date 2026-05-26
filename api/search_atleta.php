<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';
require_once dirname(__DIR__) . '/app/AdminPolicy.php';

$ctx = inscripciones_init_asociacion_activa();
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

AdminPolicy::assertUsuarioRead();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (mb_strlen($q, 'UTF-8') < 3) {
    echo json_encode(['ok' => true, 'items' => []]);

    exit;
}

$t = inscripciones_resolver_torneo_jornada($pdo);
$tid = $t !== null ? (int) $t['torneo'] : 0;
$items = $tid > 0
    ? InscripcionTorneo::buscarUsuariosParaAutocomplete($pdo, $q, $asoc, 15, $tid)
    : [];

echo json_encode(['ok' => true, 'items' => $items]);
