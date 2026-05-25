<?php

declare(strict_types=1);

/**
 * Verificación rápida del módulo Atletas (aliases + rutas). Ejecutar: php tools/verify_modulo_atletas.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/Atleta.php';
require $root . '/app/AfiliacionAtleta.php';
require $root . '/app/MigracionAtletasAUsuarios.php';
require $root . '/app/SyncMovimientoTorneoTridenteDesdeAtletas.php';
require $root . '/app/Torneo.php';
require $root . '/app/InscripcionTorneo.php';

$checks = [
    'Atleta' => class_exists('Atleta'),
    'AfiliacionAtleta' => class_exists('AfiliacionAtleta'),
    'MigracionAtletasAUsuarios' => class_exists('MigracionAtletasAUsuarios'),
    'SyncMovimientoTorneoTridenteDesdeAtletas' => class_exists('SyncMovimientoTorneoTridenteDesdeAtletas'),
    'Fvd\\Modulos\\Atletas\\Modelos\\Atleta' => class_exists('Fvd\\Modulos\\Atletas\\Modelos\\Atleta'),
    'Fvd\\Modulos\\Torneos\\Modelos\\InscripcionTorneo' => class_exists('Fvd\\Modulos\\Torneos\\Modelos\\InscripcionTorneo'),
    'Atleta alias is_a namespaced' => is_a('Atleta', \Fvd\Modulos\Atletas\Modelos\Atleta::class, true),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

require $root . '/rutas.php';
$rutasAtletas = 0;
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['modulo'] ?? '') === 'Atletas') {
        $rutasAtletas++;
        $handler = $ruta['handler'] ?? '';
        if (!is_file($handler)) {
            $failed[] = 'handler missing: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($rutasAtletas !== 4) {
    $failed[] = 'rutas Atletas count (expected 4, got ' . $rutasAtletas . ')';
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Atletas verificado (" . count($checks) . " checks, {$rutasAtletas} rutas).\n";
exit(0);
