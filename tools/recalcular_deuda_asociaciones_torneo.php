<?php

declare(strict_types=1);

/**
 * Recalcula `deuda_asociaciones` para todas las asociaciones con movimiento en un torneo.
 *
 * Uso (desde la raíz del proyecto):
 *   php tools/recalcular_deuda_asociaciones_torneo.php 6
 */

require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/app/DeudaAsociaciones.php';

$tid = isset($argv[1]) ? (int) $argv[1] : 0;
if ($tid < 0) {
    fwrite(STDERR, "Indique torneo_id numérico (>= 0). Ej: php tools/recalcular_deuda_asociaciones_torneo.php 6\n");
    exit(1);
}

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "No hay conexión a MySQL.\n");
    exit(1);
}

if (!DeudaAsociaciones::tablaDisponible($pdo)) {
    fwrite(STDERR, "La tabla deuda_asociaciones no existe.\n");
    exit(1);
}

$n = DeudaAsociaciones::recalcularTorneoCompleto($pdo, $tid);
echo "Deuda recalculada: {$n} asociación(es) en torneo_id={$tid}.\n";
