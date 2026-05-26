<?php
declare(strict_types=1);
define('FVD_ROOT', dirname(__DIR__));
require FVD_ROOT . '/app/Autoload.php';
\Fvd\Autoload::register();
require FVD_ROOT . '/Database.php';

$pdo = (new Database())->getConnection();
if (!$pdo) {
    echo "Sin conexión BD\n";
    exit(1);
}

$sql = 'SELECT * FROM movimiento_torneo WHERE inscripcion = 1 AND torneo_id = 6 AND asociacion_id = 1';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo 'Filas: ' . count($rows) . "\n\n";
if ($rows === []) {
    exit(0);
}
$cols = array_keys($rows[0]);
echo implode("\t", $cols) . "\n";
foreach ($rows as $r) {
    $line = [];
    foreach ($cols as $c) {
        $line[] = (string) ($r[$c] ?? '');
    }
    echo implode("\t", $line) . "\n";
}

// Cruce con usuarios
echo "\n--- Con nombre de usuario ---\n";
$st = $pdo->prepare(
    'SELECT m.id, m.id_usuario, m.cedula, m.numfvd, m.sexo, m.inscripcion, m.torneo_id, m.asociacion_id,
            m.grupo_nombre, m.grupo_id, m.afiliacion, m.anualidad, m.carnet, m.traspaso,
            u.nombre, u.asociacion_id AS u_asoc, u.status
     FROM movimiento_torneo m
     LEFT JOIN usuarios u ON u.id = m.id_usuario
     WHERE m.inscripcion = 1 AND m.torneo_id = 6 AND m.asociacion_id = 1
     ORDER BY m.id'
);
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
    echo sprintf(
        "mov=%s user=%s cedula=%s | %s | u_asoc=%s status=%s\n",
        $x['id'],
        $x['id_usuario'],
        $x['cedula'],
        $x['nombre'] ?? '(sin usuario)',
        $x['u_asoc'] ?? '',
        $x['status'] ?? ''
    );
}
