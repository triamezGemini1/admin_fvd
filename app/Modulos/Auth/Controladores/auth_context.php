<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';
require_once FVD_ROOT . '/app/AdminAsociacion.php';

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
if ($logged && $rol === 'delegado' && $asocId !== null && $asocId > 0) {
    $ar = AdminAsociacion::obtener($pdo, (int) $asocId);
    if (is_array($ar)) {
        $asociacionActiva = [
            'id' => (int) ($ar['id'] ?? $asocId),
            'nombre' => trim((string) ($ar['nombre'] ?? '')),
            'logo' => trim((string) ($ar['logo'] ?? '')),
            'delegado' => trim((string) ($ar['delegado'] ?? '')),
        ];
    }
}

echo json_encode([
    'logged' => $logged,
    'rol' => $rol,
    'puede_crear_torneo' => $puedeCrear,
    'puede_panel_admin' => $puedePanel,
    'puede_afiliar_atleta' => $puedeAfiliar,
    'asociacion_id' => $asocId,
    'asociacion_activa' => $asociacionActiva,
    'capabilities' => $capabilities,
    'pendientes_traspaso_inscripcion' => $pendTraspasoInscripcion,
    'supervision_pendientes' => $supervisionPendientes,
]);
