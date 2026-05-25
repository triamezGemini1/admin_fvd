<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
require_once $root . '/app/Autoload.php';
\Fvd\Autoload::register();
require_once $root . '/app/AfiliacionAtleta.php';

$pdo = (new Database())->getConnection();
$st = $pdo->query('SELECT TRIM(`cedula`) c FROM `atletas` WHERE `afiliacion` IN (1,5) LIMIT 10');
$ceds = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
$found = 0;
foreach ($ceds as $raw) {
    $n = AfiliacionAtleta::normalizarCedula((string) $raw);
    $st2 = $pdo->prepare('SELECT id, cedula, numfvd FROM `usuarios` WHERE REPLACE(REPLACE(TRIM(`cedula`),"-","")," ","") = :c OR `cedula` LIKE :l LIMIT 1');
    $st2->execute([':c' => $n, ':l' => '%' . $n]);
    if ($st2->fetch()) {
        $found++;
    }
}
echo json_encode(['probadas' => count($ceds), 'encontradas_norm' => $found], JSON_PRETTY_PRINT) . "\n";
