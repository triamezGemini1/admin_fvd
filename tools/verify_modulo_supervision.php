<?php

declare(strict_types=1);

/**
 * Verificación del módulo Supervisión. Ejecutar: php tools/verify_modulo_supervision.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/SupervisionFvd.php';

$checks = [
    'SupervisionFvd' => class_exists('SupervisionFvd'),
    'Fvd\\Modulos\\Supervision\\Modelos\\SupervisionFvd' => class_exists('Fvd\\Modulos\\Supervision\\Modelos\\SupervisionFvd'),
    'SupervisionFvd alias is_a' => is_a('SupervisionFvd', \Fvd\Modulos\Supervision\Modelos\SupervisionFvd::class, true),
    'resumenPendientes' => method_exists('SupervisionFvd', 'resumenPendientes'),
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
    if (($ruta['modulo'] ?? '') === 'Supervision') {
        $n++;
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 2) {
    $failed[] = "rutas count (expected 2, got {$n})";
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Supervision verificado ({$n} rutas).\n";
exit(0);
