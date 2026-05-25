<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
$pdo = (new Database())->getConnection();

$base = 'FROM `atletas` a WHERE 1=1';
$withU = 'FROM `atletas` a INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)';
$canonU = 'FROM `atletas` a
 INNER JOIN (SELECT MIN(`id`) AS mid FROM `atletas` GROUP BY TRIM(`cedula`)) c ON c.mid = a.`id`
 INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)';

$sel = 'SELECT
  SUM(a.`afiliacion` IN (1, 5)) AS afiliacion,
  SUM(a.`anualidad` IN (1, 5)) AS anualidad,
  SUM(a.`carnet` IN (1, 20)) AS carnet,
  SUM(a.`traspaso` IN (1, 6)) AS traspaso,
  SUM(a.`inscripcion` = 1) AS inscripcion,
  COUNT(*) AS filas';

$sinU = $pdo->query(
    $sel . ' ' . $base . ' AND NOT EXISTS (SELECT 1 FROM `usuarios` u WHERE TRIM(u.`cedula`) = TRIM(a.`cedula`))'
)->fetch(PDO::FETCH_ASSOC);
$conIndSinU = $pdo->query(
    $sel . ' ' . $base . '
     AND (a.`afiliacion` IN (1,5) OR a.`anualidad` IN (1,5) OR a.`carnet` IN (1,20) OR a.`traspaso` IN (1,6) OR a.`inscripcion`=1)
     AND NOT EXISTS (SELECT 1 FROM `usuarios` u WHERE TRIM(u.`cedula`) = TRIM(a.`cedula`))'
)->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'atletas_sin_usuario' => $sinU,
    'atletas_con_indicador_sin_usuario' => $conIndSinU,
    'canon_con_usuario' => $pdo->query($sel . ' ' . $canonU)->fetch(PDO::FETCH_ASSOC),
    'todos_con_usuario' => $pdo->query($sel . ' ' . $withU)->fetch(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT) . "\n";
