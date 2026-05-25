<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
$pdo = (new Database())->getConnection();

$dup = $pdo->query(
    'SELECT COUNT(*) FROM (
      SELECT TRIM(`cedula`) c FROM `usuarios` WHERE TRIM(`cedula`) <> "" GROUP BY TRIM(`cedula`) HAVING COUNT(*) > 1
    ) t'
)->fetchColumn();

$filasCargaSql = (int) $pdo->query(
    'SELECT COUNT(*) FROM `atletas` a
     INNER JOIN (SELECT MIN(`id`) mid FROM `atletas` GROUP BY TRIM(`cedula`)) c ON c.mid = a.`id`
     INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)
     WHERE (a.`afiliacion` IN (1,5) OR a.`anualidad` IN (1,5) OR a.`carnet` IN (1,20) OR a.`traspaso` IN (1,6) OR a.`inscripcion`=1)'
)->fetchColumn();

$usuariosUnicos = (int) $pdo->query(
    'SELECT COUNT(DISTINCT u.`id`) FROM `atletas` a
     INNER JOIN (SELECT MIN(`id`) mid FROM `atletas` GROUP BY TRIM(`cedula`)) c ON c.mid = a.`id`
     INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)
     WHERE (a.`afiliacion` IN (1,5) OR a.`anualidad` IN (1,5) OR a.`carnet` IN (1,20) OR a.`traspaso` IN (1,6) OR a.`inscripcion`=1)'
)->fetchColumn();

echo json_encode([
    'cedulas_con_varios_usuarios' => (int) $dup,
    'filas_sql_carga_con_indicadores' => $filasCargaSql,
    'usuarios_distintos_misma_query' => $usuariosUnicos,
], JSON_PRETTY_PRINT) . "\n";
