<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
require_once $root . '/app/Autoload.php';
\Fvd\Autoload::register();
require_once $root . '/app/AfiliacionAtleta.php';

$pdo = (new Database())->getConnection();

$st = $pdo->query(
    'SELECT a.`id`, TRIM(a.`cedula`) AS ced, a.`numfvd`, a.`afiliacion`, a.`carnet`, a.`inscripcion`
     FROM `atletas` a
     WHERE a.`afiliacion` IN (1, 5)
     LIMIT 5'
);
$sample = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

$withNumfvd = (int) $pdo->query(
    'SELECT COUNT(*) FROM `atletas` a
     INNER JOIN `usuarios` u ON u.`numfvd` > 0 AND a.`numfvd` > 0 AND u.`numfvd` = a.`numfvd`
     WHERE a.`afiliacion` IN (1, 5)'
)->fetchColumn();

$withTrim = (int) $pdo->query(
    'SELECT COUNT(*) FROM `atletas` a
     INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)
     WHERE a.`afiliacion` IN (1, 5)'
)->fetchColumn();

$usuariosPorCed = $pdo->query(
    'SELECT TRIM(`cedula`) c, COUNT(*) n FROM `usuarios` WHERE TRIM(`cedula`) <> "" GROUP BY TRIM(`cedula`) HAVING n > 1 LIMIT 3'
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'muestra_afiliados' => $sample,
    'afiliados_con_usuario_por_trim_cedula' => $withTrim,
    'afiliados_con_usuario_por_numfvd' => $withNumfvd,
    'cedulas_usuario_duplicadas_ejemplo' => $usuariosPorCed,
    'total_afiliacion_atletas' => (int) $pdo->query('SELECT COUNT(*) FROM `atletas` WHERE `afiliacion` IN (1,5)')->fetchColumn(),
], JSON_PRETTY_PRINT) . "\n";
