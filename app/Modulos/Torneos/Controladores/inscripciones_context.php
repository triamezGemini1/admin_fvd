<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;
use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;

$pdo = admin_guard_pdo();
$torneo = InscripcionTorneo::torneoActivo($pdo);
$modalidad = InscripcionTorneo::modalidadDesdeTorneo($torneo);
$tipoNorm = $torneo !== null ? InscripcionTorneo::normalizarTipoTorneo($torneo['tipo'] ?? null) : null;
$tipoLabel = $tipoNorm === null ? '—' : ($tipoNorm === 1 ? 'Masculino' : ($tipoNorm === 2 ? 'Femenino' : 'Mixto'));

$tidLock = $torneo !== null ? (int) ($torneo['torneo'] ?? 0) : null;
$movFlags = TorneoMovimientoLock::flagsJson($pdo, $tidLock > 0 ? $tidLock : null);

$asoc = null;
if (Auth::rol() === 'delegado') {
    $asoc = Auth::asociacionId();
}

$finanzas = null;
$tasaEurBs = 55.0;
$envTasa = getenv('FVD_TASA_EUR_BS');
if ($envTasa !== false && is_numeric(trim($envTasa))) {
    $tasaEurBs = max(0.01, (float) trim($envTasa));
}
if ($torneo !== null && $asoc !== null && (int) $asoc > 0) {
    $costRaw = $torneo['costotor'] ?? null;
    $costoBs = $costRaw !== null && $costRaw !== '' ? (float) $costRaw : null;
    $finanzas = InscripcionTorneo::resumenFinanzasInscripcion($pdo, (int) $torneo['torneo'], (int) $asoc, $costoBs, $tasaEurBs);
}

echo json_encode(array_merge([
    'ok' => true,
    'torneo' => $torneo,
    'modalidad' => $modalidad,
    'tipo_torneo' => $tipoNorm,
    'tipo_torneo_label' => $tipoLabel,
    'asociacion_id' => $asoc,
    'finanzas' => $finanzas,
], $movFlags));
