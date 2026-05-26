<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/inscripciones_common.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$ctx = inscripciones_init_asociacion_activa();
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

$cedula = trim((string) ($_GET['cedula'] ?? ''));
if ($cedula === '') {
    http_response_code(400);
    echo json_encode(['encontrado' => false, 'error' => 'Indique cédula.']);
    exit;
}

$torneo = inscripciones_resolver_torneo_jornada($pdo);
if ($torneo === null) {
    http_response_code(400);
    echo json_encode(['encontrado' => false, 'error' => 'No hay torneo activo.']);
    exit;
}

$tid = (int) $torneo['torneo'];
$result = InscripcionTorneo::buscarUsuarioInscripcionPorCedula($pdo, $cedula, $tid, $asoc);
echo json_encode($result);
