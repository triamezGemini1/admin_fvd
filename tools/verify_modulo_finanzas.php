<?php

declare(strict_types=1);

/**
 * Verificación del módulo Finanzas. Ejecutar: php tools/verify_modulo_finanzas.php
 */
$root = dirname(__DIR__);
chdir($root);

require $root . '/app/Autoload.php';
\Fvd\Autoload::register();

require $root . '/app/FinanzaFvd.php';
require $root . '/app/DeudaAsociaciones.php';

$checks = [
    'FinanzaFvd' => class_exists('FinanzaFvd'),
    'DeudaAsociaciones' => class_exists('DeudaAsociaciones'),
    'Fvd\\Modulos\\Finanzas\\Modelos\\FinanzaFvd' => class_exists('Fvd\\Modulos\\Finanzas\\Modelos\\FinanzaFvd'),
    'Deuda alias is_a' => is_a('DeudaAsociaciones', \Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones::class, true),
    'PAGO_TORNEO_FINANZA_FVD' => FinanzaFvd::PAGO_TORNEO_FINANZA_FVD === 0,
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
    if (($ruta['modulo'] ?? '') === 'Finanzas') {
        $n++;
        if (!is_file($ruta['handler'] ?? '')) {
            $failed[] = 'handler: ' . ($ruta['path'] ?? '?');
        }
    }
}
if ($n !== 7) {
    $failed[] = "rutas count (expected 7, got {$n})";
}

if ($failed !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: modulo Finanzas verificado ({$n} rutas).\n";
exit(0);
