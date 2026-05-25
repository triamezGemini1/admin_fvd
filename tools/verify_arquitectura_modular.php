<?php

declare(strict_types=1);

/**
 * Reporte final de consistencia — arquitectura modular FVD.
 * Ejecutar: php tools/verify_arquitectura_modular.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

$legacyStubs = [
    'Torneo' => \Fvd\Modulos\Torneos\Modelos\Torneo::class,
    'AdminTorneo' => \Fvd\Modulos\Torneos\Modelos\AdminTorneo::class,
    'InscripcionTorneo' => \Fvd\Modulos\Torneos\Modelos\InscripcionTorneo::class,
    'TorneoMovimientoLock' => \Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock::class,
    'Atleta' => \Fvd\Modulos\Atletas\Modelos\Atleta::class,
    'AfiliacionAtleta' => \Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta::class,
    'AdminAsociacion' => \Fvd\Modulos\Asociaciones\Modelos\AdminAsociacion::class,
    'FinanzaFvd' => \Fvd\Modulos\Finanzas\Modelos\FinanzaFvd::class,
    'DeudaAsociaciones' => \Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones::class,
    'InformeFvd' => \Fvd\Modulos\Informes\Modelos\InformeFvd::class,
    'DelegadoMovimientoTorneo' => \Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo::class,
    'FvdSolicitudesDelegado' => \Fvd\Modulos\Delegados\Modelos\FvdSolicitudesDelegado::class,
    'SupervisionFvd' => \Fvd\Modulos\Supervision\Modelos\SupervisionFvd::class,
    'Auth' => \Fvd\Modulos\Auth\Modelos\Auth::class,
    'AdminPolicy' => \Fvd\Modulos\Auth\Modelos\AdminPolicy::class,
    'AdminUsuario' => \Fvd\Modulos\Auth\Modelos\AdminUsuario::class,
];

$stubFiles = [
    'Torneo', 'AdminTorneo', 'InscripcionTorneo', 'TorneoMovimientoLock',
    'Atleta', 'AfiliacionAtleta', 'AdminAsociacion', 'OrganizacionFvd', 'LogoUpload',
    'FinanzaFvd', 'DeudaAsociaciones', 'InformeFvd',
    'DelegadoMovimientoTorneo', 'DelegadoActividad', 'FvdSolicitudesDelegado',
    'SupervisionFvd', 'Auth', 'AdminPolicy', 'AdminUsuario',
];

$expectedModules = [
    'Torneos' => 9,
    'Atletas' => 4,
    'Asociaciones' => 4,
    'Finanzas' => 7,
    'Informes' => 7,
    'Delegados' => 9,
    'Supervision' => 2,
    'Auth' => 7,
];

$failed = [];
$report = [];

foreach ($stubFiles as $legacy) {
    $path = $root . '/app/' . $legacy . '.php';
    if (!is_file($path)) {
        $failed[] = "stub file missing: app/{$legacy}.php";
        continue;
    }
    require_once $path;
}

foreach ($legacyStubs as $legacy => $namespaced) {
    if (!class_exists($legacy, false)) {
        $failed[] = "legacy class not loaded: {$legacy}";
        continue;
    }
    if (!is_a($legacy, $namespaced, true)) {
        $failed[] = "alias mismatch: {$legacy} -> {$namespaced}";
        continue;
    }
    if (!class_exists($namespaced, true)) {
        $failed[] = "namespaced missing: {$namespaced}";
    }
}

$autoloadOk = class_exists('Fvd\\Modulos\\Auth\\Modelos\\Auth', true)
    && class_exists('Fvd\\Modulos\\Supervision\\Modelos\\SupervisionFvd', true);
if (!$autoloadOk) {
    $failed[] = 'PSR-4 Autoload no resuelve clases namespaced sin stub';
}

require $root . '/rutas.php';
$byModulo = [];
$totalRutas = 0;
foreach (fvd_rutas_registro() as $ruta) {
    $mod = (string) ($ruta['modulo'] ?? '?');
    $byModulo[$mod] = ($byModulo[$mod] ?? 0) + 1;
    $totalRutas++;
    if (!is_file($ruta['handler'] ?? '')) {
        $failed[] = 'handler missing: ' . ($ruta['path'] ?? '?');
    }
}

foreach ($expectedModules as $mod => $count) {
    $got = $byModulo[$mod] ?? 0;
    $report[] = sprintf('  %-14s %2d rutas%s', $mod, $got, $got === $count ? '' : " (esperado {$count})");
    if ($got !== $count) {
        $failed[] = "rutas {$mod}: expected {$count}, got {$got}";
    }
}

require_once $root . '/api/admin_api_guard.php';
if (!function_exists('admin_guard_pdo')) {
    $failed[] = 'admin_guard_pdo no disponible tras puente api/';
}

echo "=== Arquitectura modular FVD ===\n";
echo 'Autoload: Fvd\\ -> app/ (strncmp PSR-4)' . "\n";
echo "Puentes legacy: " . count($legacyStubs) . " class_alias verificados\n";
echo "Rutas registradas: {$totalRutas}\n";
echo "Por módulo:\n" . implode("\n", $report) . "\n";

if ($failed !== []) {
    echo "\nFALLOS:\n- " . implode("\n- ", $failed) . "\n";
    exit(1);
}

echo "\nOK: arquitectura modular consistente.\n";
exit(0);
