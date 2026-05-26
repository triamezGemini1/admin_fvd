<?php



declare(strict_types=1);



require_once __DIR__ . '/inscripciones_common.php';



use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;
use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;
use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;



$pdo = inscripciones_guard_pdo();

$asociacion = inscripciones_resolver_asociacion($pdo);

$asocId = $asociacion !== null ? $asociacion['id'] : null;



$preferTorneo = isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : null;
$torneo = inscripciones_resolver_torneo_jornada($pdo, $preferTorneo > 0 ? $preferTorneo : null);
$torneoActivoId = DelegadoMovimientoTorneo::torneoActivoId($pdo);
$campeonatoVariantes = $torneoActivoId !== null && $torneoActivoId > 0
    ? DelegadoMovimientoTorneo::variantesCampeonatoActivas($pdo, $torneoActivoId)
    : [];

$modalidad = InscripcionTorneo::modalidadDesdeTorneo($torneo);

$tipoNorm = $torneo !== null ? InscripcionTorneo::normalizarTipoTorneo($torneo['tipo'] ?? null) : null;

$tipoLabel = $tipoNorm === null ? '—' : ($tipoNorm === 1 ? 'Masculino' : ($tipoNorm === 2 ? 'Femenino' : 'Mixto'));



$tidLock = $torneo !== null ? (int) ($torneo['torneo'] ?? 0) : null;

$movFlags = TorneoMovimientoLock::flagsJsonInscripciones($pdo, $tidLock > 0 ? $tidLock : null);
$jugadoresRequeridos = $torneo !== null ? InscripcionTorneo::jugadoresRequeridosPorTorneo($torneo) : 0;



$msg = null;

if ($asociacion === null) {

    $msg = 'No hay asociación vinculada a su usuario en esta sesión.';

} elseif ($torneo === null) {

    $msg = 'No hay torneo activo abierto.';

}



echo json_encode(array_merge([

    'ok' => $asociacion !== null,

    'torneo' => $torneo,

    'torneo_id' => $torneo !== null ? (int) $torneo['torneo'] : null,

    'modalidad' => $modalidad,

    'jugadores_requeridos' => $jugadoresRequeridos,

    'pareclub' => $torneo !== null ? (int) ($torneo['pareclub'] ?? 0) : 0,

    'tipo_torneo' => $tipoNorm,

    'tipo_torneo_label' => $tipoLabel,

    'asociacion_id' => $asocId,

    'asociacion' => $asociacion,

    'message' => $msg,

    'torneo_activo_id' => $torneoActivoId,

    'campeonato_variantes' => $campeonatoVariantes,

    'permite_selector_campeonato' => count($campeonatoVariantes) >= 2,

], $movFlags));

