<?php

declare(strict_types=1);

/**
 * Verificación del módulo Delegados. Ejecutar: php tools/verify_modulo_delegados.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/DelegadoMovimientoTorneo.php';
require $root . '/app/DelegadoActividad.php';
require $root . '/app/FvdSolicitudesDelegado.php';

$checks = [
    'DelegadoMovimientoTorneo' => class_exists('DelegadoMovimientoTorneo'),
    'DelegadoActividad' => class_exists('DelegadoActividad'),
    'FvdSolicitudesDelegado' => class_exists('FvdSolicitudesDelegado'),
    'Fvd\\Modulos\\Delegados\\Modelos\\DelegadoMovimientoTorneo' => class_exists('Fvd\\Modulos\\Delegados\\Modelos\\DelegadoMovimientoTorneo'),
    'DelegadoMovimientoTorneo alias is_a' => is_a('DelegadoMovimientoTorneo', \Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo::class, true),
    'FvdSolicitudesDelegado alias is_a' => is_a('FvdSolicitudesDelegado', \Fvd\Modulos\Delegados\Modelos\FvdSolicitudesDelegado::class, true),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

require $root . '/rutas.php';
$n = 0;
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['modulo'] ?? '') === 'Delegados') {
        $n++;
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 9) {
    $failed[] = "rutas count (expected 9, got {$n})";
}

$asocDelegado = 0;
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['path'] ?? '') === 'api/delegado_otras_asociaciones.php' && ($ruta['modulo'] ?? '') === 'Asociaciones') {
        $asocDelegado++;
    }
}
if ($asocDelegado > 0) {
    $failed[] = 'delegado_otras_asociaciones still registered under Asociaciones';
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Delegados verificado ({$n} rutas).\n";
exit(0);
