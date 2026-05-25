<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/Database.php';
$pdo = (new Database())->getConnection();

$nf = $pdo->query('SELECT `numfvd` FROM `atletas` WHERE `afiliacion` IN (1,5) LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
$ph = implode(',', array_fill(0, count($nf), '?'));
$inU = 0;
if ($nf !== []) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `usuarios` WHERE `numfvd` IN ($ph)");
    foreach ($nf as $i => $v) {
        $st->bindValue($i + 1, (int) $v, PDO::PARAM_INT);
    }
    $st->execute();
    $inU = (int) $st->fetchColumn();
}

$maxU = (int) $pdo->query('SELECT COALESCE(MAX(`numfvd`),0) FROM `usuarios`')->fetchColumn();
$maxA = (int) $pdo->query('SELECT COALESCE(MAX(`numfvd`),0) FROM `atletas` WHERE `numfvd`>0')->fetchColumn();

echo json_encode([
    'ejemplo_numfvd_atletas_afiliados' => $nf,
    'esos_3_en_usuarios' => $inU,
    'max_numfvd_usuarios' => $maxU,
    'max_numfvd_atletas' => $maxA,
    'inscripcion_match_cedula' => (int) $pdo->query(
        'SELECT COUNT(DISTINCT a.id) FROM atletas a
         INNER JOIN usuarios u ON TRIM(u.cedula)=TRIM(a.cedula)
         WHERE a.inscripcion=1'
    )->fetchColumn(),
], JSON_PRETTY_PRINT) . "\n";
