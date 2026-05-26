<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/AdminAsociacion.php';
require_once FVD_ROOT . '/app/Modulos/Delegados/Modelos/DelegadoMovimientoTorneo.php';

use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;

header('Content-Type: application/json; charset=UTF-8');

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    http_response_code(503);
    echo json_encode([
        'logged' => false,
        'rol' => null,
        'puede_crear_torneo' => false,
        'puede_panel_admin' => false,
        'puede_afiliar_atleta' => false,
        'asociacion_id' => null,
        'asociacion_activa' => null,
        'capabilities' => null,
        'pendientes_traspaso_inscripcion' => 0,
        'supervision_pendientes' => null,
        'torneos_activos' => [],
        'torneo_activo_id' => null,
        'torneo_jornada_id' => null,
        'torneo_jornada' => null,
        'campeonato_variantes' => [],
        'permite_selector_campeonato' => false,
    ]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$logged = Auth::check();
$rol = $logged ? Auth::rol() : null;
$puedeCrear = $logged && $rol === 'admingral';
$puedePanel = $logged && AdminPolicy::puedeAccederPanel();
$asocId = $logged ? Auth::asociacionId() : null;
$capabilities = $puedePanel ? AdminPolicy::capabilities() : null;

$puedeAfiliar = $logged && is_array($capabilities) && ($capabilities['usuarios']['create'] ?? false);
$puedeFinanzasOperativasFvd = $logged && AdminPolicy::puedeVerFinanzasOperativasFvd();
$puedeEstadoCuentaAsociacion =
    $logged && $puedePanel && ($rol === 'admingral' || $rol === 'delegado');

$torneosActivos = [];
$torneoActivoId = null;
$torneoJornadaId = null;
$torneoJornada = null;
$campeonatoVariantes = [];
$permiteSelectorCampeonato = false;
if ($logged && $puedePanel) {
    $jornada = DelegadoMovimientoTorneo::bootstrapJornada($pdo);
    $torneosActivos = $jornada['torneos_activos'];
    $torneoActivoId = $jornada['torneo_activo_id'];
    $torneoJornadaId = $jornada['torneo_jornada_id'];
    $torneoJornada = $jornada['torneo_jornada'];
    $campeonatoVariantes = $jornada['campeonato_variantes'];
    $permiteSelectorCampeonato = $jornada['permite_selector_campeonato'];
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$pendTraspasoInscripcion = 0;
$supervisionPendientes = null;
if ($logged && $rol === 'admingral') {
    require_once FVD_ROOT . '/app/InscripcionTorneo.php';
    require_once FVD_ROOT . '/app/SupervisionFvd.php';
    $torAct = InscripcionTorneo::torneoActivo($pdo);
    if ($torAct !== null) {
        $pendTraspasoInscripcion = InscripcionTorneo::contarTraspasosInscripcionPendientes($pdo, (int) $torAct['torneo']);
    }
    $supervisionPendientes = SupervisionFvd::resumenPendientes($pdo);
}

$asociacionActiva = null;
if ($logged && $asocId !== null && $asocId > 0) {
    $ar = AdminAsociacion::obtener($pdo, (int) $asocId);
    if (is_array($ar)) {
        $delNom = '';
        $stDel = $pdo->prepare(
            'SELECT nombre FROM usuarios WHERE asociacion_id = :aid AND role = :rol AND status IN (1, 9) ORDER BY id ASC LIMIT 1'
        );
        $stDel->bindValue(':aid', (int) $asocId, \PDO::PARAM_INT);
        $stDel->bindValue(':rol', 'delegado', \PDO::PARAM_STR);
        $stDel->execute();
        $rd = $stDel->fetch(\PDO::FETCH_ASSOC);
        if ($rd !== false) {
            $delNom = trim((string) ($rd['nombre'] ?? ''));
        }
        $asociacionActiva = [
            'id' => (int) ($ar['id'] ?? $asocId),
            'nombre' => trim((string) ($ar['nombre'] ?? '')),
            'logo' => trim((string) ($ar['logo'] ?? '')),
            'delegado' => $delNom,
        ];
    }
}

echo json_encode([
    'logged' => $logged,
    'rol' => $rol,
    'puede_crear_torneo' => $puedeCrear,
    'puede_panel_admin' => $puedePanel,
    'puede_afiliar_atleta' => $puedeAfiliar,
    'puede_finanzas_operativas_fvd' => $puedeFinanzasOperativasFvd,
    'puede_estado_cuenta_asociacion' => $puedeEstadoCuentaAsociacion,
    'asociacion_id' => $asocId,
    'asociacion_activa' => $asociacionActiva,
    'capabilities' => $capabilities,
    'pendientes_traspaso_inscripcion' => $pendTraspasoInscripcion,
    'supervision_pendientes' => $supervisionPendientes,
    'torneos_activos' => $torneosActivos,
    'torneo_activo_id' => $torneoActivoId,
    'torneo_jornada_id' => $torneoJornadaId,
    'torneo_jornada' => $torneoJornada,
    'campeonato_variantes' => $campeonatoVariantes,
    'permite_selector_campeonato' => $permiteSelectorCampeonato,
]);
