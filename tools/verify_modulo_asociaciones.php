<?php

declare(strict_types=1);

/**
 * Verificación del módulo Asociaciones. Ejecutar: php tools/verify_modulo_asociaciones.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/AdminAsociacion.php';
require $root . '/app/OrganizacionFvd.php';
require $root . '/app/LogoUpload.php';
require $root . '/app/Torneo.php';

$checks = [
    'AdminAsociacion' => class_exists('AdminAsociacion'),
    'OrganizacionFvd' => class_exists('OrganizacionFvd'),
    'LogoUpload' => class_exists('LogoUpload'),
    'Fvd\\Modulos\\Asociaciones\\Modelos\\AdminAsociacion' => class_exists('Fvd\\Modulos\\Asociaciones\\Modelos\\AdminAsociacion'),
    'OrganizacionFvd alias is_a' => is_a('OrganizacionFvd', \Fvd\Modulos\Asociaciones\Modelos\OrganizacionFvd::class, true),
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
    if (($ruta['modulo'] ?? '') === 'Asociaciones') {
        $n++;
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 5) {
    $failed[] = "rutas count (expected 5, got {$n})";
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Asociaciones verificado ({$n} rutas).\n";
exit(0);
