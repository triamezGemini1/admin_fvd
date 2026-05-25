<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require $root . '/app/Autoload.php';
\Fvd\Autoload::register();
require $root . '/app/InformeFvd.php';
require $root . '/app/FinanzaFvd.php';
require $root . '/Database.php';

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "No DB\n");
    exit(1);
}

$t0 = microtime(true);
$tid = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, null);
echo 'torneo_id=' . ($tid ?? 'null') . "\n";

$t1 = microtime(true);
$filas = InformeFvd::resumenConsolidadoPorAsociacion($pdo, $tid);
echo 'resumenConsolidado: ' . count($filas) . ' filas en ' . round(microtime(true) - $t1, 3) . "s\n";

$t2 = microtime(true);
$fin = FinanzaFvd::resumenPorAsociacion($pdo, $tid);
echo 'resumenFinanza: ' . count($fin) . ' filas en ' . round(microtime(true) - $t2, 3) . "s\n";

$t3 = microtime(true);
$sel = InformeFvd::torneosConNominaParaSelector($pdo);
echo 'selector: ' . count($sel) . ' en ' . round(microtime(true) - $t3, 3) . "s\n";

echo 'total: ' . round(microtime(true) - $t0, 3) . "s\n";
