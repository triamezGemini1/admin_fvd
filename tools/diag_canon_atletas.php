<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
require_once $root . '/app/Autoload.php';
\Fvd\Autoload::register();

use Fvd\Modulos\Atletas\Modelos\SyncMovimientoTorneoTridenteDesdeAtletas;

$pdo = (new Database())->getConnection();

$sqlMinId = 'SELECT 
  SUM(a.`afiliacion` IN (1, 5)) AS afiliacion,
  SUM(a.`anualidad` IN (1, 5)) AS anualidad,
  SUM(a.`carnet` IN (1, 20)) AS carnet,
  SUM(a.`traspaso` IN (1, 6)) AS traspaso,
  SUM(a.`inscripcion` = 1) AS inscripcion
 FROM `atletas` a
 INNER JOIN (SELECT MIN(`id`) AS mid FROM `atletas` GROUP BY TRIM(`cedula`)) c ON c.mid = a.`id`';

$sqlPickConInd = 'SELECT 
  SUM(a.`afiliacion` IN (1, 5)) AS afiliacion,
  SUM(a.`anualidad` IN (1, 5)) AS anualidad,
  SUM(a.`carnet` IN (1, 20)) AS carnet,
  SUM(a.`traspaso` IN (1, 6)) AS traspaso,
  SUM(a.`inscripcion` = 1) AS inscripcion
 FROM `atletas` a
 INNER JOIN (
   SELECT TRIM(`cedula`) AS ced,
     COALESCE(
       MIN(CASE WHEN (' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('x') . ') THEN `id` END),
       MIN(`id`)
     ) AS pick_id
   FROM `atletas` x
   GROUP BY TRIM(`cedula`)
 ) pick ON pick.pick_id = a.`id`';

$sqlAgregado = 'SELECT
  SUM(af > 0) AS afiliacion,
  SUM(an > 0) AS anualidad,
  SUM(ca > 0) AS carnet,
  SUM(tr > 0) AS traspaso,
  SUM(ins > 0) AS inscripcion
 FROM (
   SELECT TRIM(`cedula`) AS ced,
     MAX(`afiliacion` IN (1, 5)) AS af,
     MAX(`anualidad` IN (1, 5)) AS an,
     MAX(`carnet` IN (1, 20)) AS ca,
     MAX(`traspaso` IN (1, 6)) AS tr,
     MAX(`inscripcion` = 1) AS ins
   FROM `atletas`
   GROUP BY TRIM(`cedula`)
 ) t';

$dup = (int) $pdo->query(
    'SELECT COUNT(*) FROM (SELECT TRIM(`cedula`) c, COUNT(*) n FROM `atletas` GROUP BY TRIM(`cedula`) HAVING n > 1) d'
)->fetchColumn();

echo json_encode([
    'cedulas_duplicadas' => $dup,
    'tabla_completa' => $pdo->query(
        'SELECT SUM(`afiliacion` IN (1,5)) af, SUM(`anualidad` IN (1,5)) an, SUM(`carnet` IN (1,20)) ca,
                SUM(`traspaso` IN (1,6)) tr, SUM(`inscripcion`=1) ins FROM `atletas`'
    )->fetch(PDO::FETCH_ASSOC),
    'canon_MIN_id' => $pdo->query($sqlMinId)->fetch(PDO::FETCH_ASSOC),
    'canon_pick_con_indicador' => $pdo->query($sqlPickConInd)->fetch(PDO::FETCH_ASSOC),
    'canon_MAX_por_cedula' => $pdo->query($sqlAgregado)->fetch(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT) . "\n";
