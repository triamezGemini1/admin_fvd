<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
require_once $root . '/app/Autoload.php';
\Fvd\Autoload::register();

use Fvd\Modulos\Atletas\Modelos\SyncMovimientoTorneoTridenteDesdeAtletas;
use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoContadores;

$pdo = (new Database())->getConnection();
if (!$pdo) {
    fwrite(STDERR, "Sin conexión BD\n");
    exit(1);
}

$sqlLegacy = 'SELECT 
  SUM(`afiliacion` IN (1, 5)) AS afiliacion,
  SUM(`anualidad` IN (1, 5)) AS anualidad,
  SUM(`carnet` IN (1, 20)) AS carnet,
  SUM(`traspaso` IN (1, 6)) AS traspaso,
  SUM(`inscripcion` = 1) AS inscripcion
 FROM `atletas`';
$atletasTablaCompleta = $pdo->query($sqlLegacy)->fetch(PDO::FETCH_ASSOC);

$w = SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('');
$sql = 'SELECT 
  SUM(`afiliacion` IN (1, 5)) AS afiliacion,
  SUM(`anualidad` IN (1, 5)) AS anualidad,
  SUM(`carnet` IN (1, 20)) AS carnet,
  SUM(`traspaso` IN (1, 6)) AS traspaso,
  SUM(`inscripcion` = 1) AS inscripcion
 FROM `atletas` WHERE ' . $w;
$atletas = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

$tid = (int) ($pdo->query(
    'SELECT `torneo` FROM `torneosact` WHERE `finalizado_en` IS NULL ORDER BY `fechator` DESC, `torneo` DESC LIMIT 1'
)->fetchColumn() ?: 0);
if ($tid < 1) {
    $tid = (int) $pdo->query('SELECT COALESCE(MAX(`torneo`), 0) FROM `torneosact`')->fetchColumn();
}

$movTorneo = $pdo->query(
    'SELECT ' . MovimientoTorneoContadores::sqlSelectAgregados('m') . ' FROM `movimiento_torneo` m WHERE m.`torneo_id` = ' . $tid
)->fetch(PDO::FETCH_ASSOC);
$movAll = $pdo->query(
    'SELECT ' . MovimientoTorneoContadores::sqlSelectAgregados('m') . ' FROM `movimiento_torneo` m'
)->fetch(PDO::FETCH_ASSOC);
$bad = $pdo->query(
    'SELECT 
      SUM(`afiliacion` NOT IN (0, 1)) AS afiliacion,
      SUM(`anualidad` NOT IN (0, 1)) AS anualidad,
      SUM(`carnet` NOT IN (0, 1)) AS carnet,
      SUM(`traspaso` NOT IN (0, 1)) AS traspaso,
      SUM(`inscripcion` NOT IN (0, 1)) AS inscripcion
     FROM `movimiento_torneo` WHERE `torneo_id` = ' . $tid
)->fetch(PDO::FETCH_ASSOC);

$nMov = (int) $pdo->query('SELECT COUNT(*) FROM `movimiento_torneo` WHERE `torneo_id` = ' . $tid)->fetchColumn();
$nAtletasCanon = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT MIN(a.`id`) AS mid FROM `atletas` a
        INNER JOIN `usuarios` u ON TRIM(u.`cedula`) = TRIM(a.`cedula`)
        WHERE ' . $w . '
    ) t'
)->fetchColumn();

$movLegacy = $pdo->query(
    'SELECT 
      SUM(`afiliacion` IN (1, 5)) AS afiliacion,
      SUM(`anualidad` IN (1, 5)) AS anualidad,
      SUM(`carnet` IN (1, 20)) AS carnet,
      SUM(`traspaso` IN (1, 6)) AS traspaso,
      SUM(`inscripcion` = 1) AS inscripcion
     FROM `movimiento_torneo` WHERE `torneo_id` = ' . $tid
)->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'torneo_id' => $tid,
    'atletas_tabla_completa_legacy' => $atletasTablaCompleta,
    'atletas_con_algun_indicador_legacy' => $atletas,
    'movimiento_torneo_torneo_criterio_legacy' => $movLegacy,
    'movimiento_torneo_torneo_solo_igual_1' => $movTorneo,
    'movimiento_torneo_todos_torneos_igual_1' => $movAll,
    'movimiento_valores_distintos_de_0_1' => $bad,
    'filas_movimiento_torneo_torneo' => $nMov,
    'atletas_canon_con_usuario_criterio' => $nAtletasCanon,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
