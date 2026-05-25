<?php

declare(strict_types=1);

/**
 * Verificación del módulo Informes. Ejecutar: php tools/verify_modulo_informes.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/InformeFvd.php';

$checks = [
    'InformeFvd' => class_exists('InformeFvd'),
    'Fvd\\Modulos\\Informes\\Modelos\\InformeFvd' => class_exists('Fvd\\Modulos\\Informes\\Modelos\\InformeFvd'),
    'InformeFvd alias is_a' => is_a('InformeFvd', \Fvd\Modulos\Informes\Modelos\InformeFvd::class, true),
    'RENGLONES' => in_array('afiliacion', InformeFvd::RENGLONES, true),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

require $root . '/rutas.php';
$n = 0;
$paths = [];
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['modulo'] ?? '') === 'Informes') {
        $n++;
        $paths[] = $ruta['path'] ?? '';
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 7) {
    $failed[] = "rutas count (expected 7, got {$n})";
}

$finInforme = 0;
foreach (fvd_rutas_registro() as $ruta) {
    if (($ruta['modulo'] ?? '') === 'Finanzas' && strncmp((string) ($ruta['path'] ?? ''), 'api/informe_', 12) === 0) {
        $finInforme++;
    }
}
if ($finInforme > 0) {
    $failed[] = 'informe_* APIs still registered under Finanzas';
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Informes verificado ({$n} rutas).\n";
exit(0);
