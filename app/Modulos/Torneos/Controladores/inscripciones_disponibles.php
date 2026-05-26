<?php



declare(strict_types=1);



require_once __DIR__ . '/inscripciones_common.php';



use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;



$ctx = inscripciones_init_asociacion_activa();

$pdo = $ctx['pdo'];

$asoc = $ctx['asociacion_id'];



$plantillaGrupos = InscripcionTorneo::plantillaGruposDisponiblesVacios();



$torneo = inscripciones_resolver_torneo_jornada($pdo);

if ($torneo === null) {

    echo json_encode([

        'ok' => true,

        'items' => [],

        'grupos' => $plantillaGrupos,

        'asociacion_id' => $asoc,

        'torneo_id' => 0,

        'message' => 'No hay torneo activo.',

    ]);



    exit;

}

$tid = (int) $torneo['torneo'];

$tipo = InscripcionTorneo::normalizarTipoTorneo($torneo['tipo'] ?? null);

// Disponibles: afiliados de la asociación excluyendo quienes tienen inscripcion = 1 en este torneo.

$lista = InscripcionTorneo::listarDisponiblesAgrupadosPorEstatus($pdo, $tid, $asoc, $tipo);



echo json_encode([

    'ok' => true,

    'items' => $lista['items'],

    'grupos' => $lista['grupos'],

    'asociacion_id' => $asoc,

    'torneo_id' => $tid,

]);

