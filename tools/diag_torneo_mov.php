<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
require_once $root . '/app/Autoload.php';
\Fvd\Autoload::register();

use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoContadores;

$pdo = (new Database())->getConnection();
if (!$pdo) {
    exit(1);
}

$rows = $pdo->query(
    'SELECT m.`torneo_id` AS tid, COUNT(*) AS filas, ' . MovimientoTorneoContadores::sqlSelectAgregados('m') . '
     FROM `movimiento_torneo` m GROUP BY m.`torneo_id` ORDER BY filas DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$bad = $pdo->query(
    'SELECT 
      SUM(`afiliacion` NOT IN (0, 1)) AS afiliacion,
      SUM(`anualidad` NOT IN (0, 1)) AS anualidad,
      SUM(`carnet` NOT IN (0, 1)) AS carnet,
      SUM(`traspaso` NOT IN (0, 1)) AS traspaso,
      SUM(`inscripcion` NOT IN (0, 1)) AS inscripcion
     FROM `movimiento_torneo`'
)->fetch(PDO::FETCH_ASSOC);

$activos = $pdo->query(
    'SELECT `torneo`, `fechator`, `lugar` FROM `torneosact` WHERE `finalizado_en` IS NULL ORDER BY `fechator` DESC'
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['torneos_activos' => $activos, 'por_torneo' => $rows, 'valores_no_0_1' => $bad], JSON_PRETTY_PRINT) . "\n";
