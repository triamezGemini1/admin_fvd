<?php
require dirname(__DIR__) . '/Database.php';
$pdo = (new Database())->getConnection();
if (!$pdo) {
    exit(1);
}
echo "=== asociaciones (muestra) ===\n";
foreach ($pdo->query('SELECT id, nombre, delegado FROM asociaciones ORDER BY id LIMIT 15') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "=== usuarios por asociacion_id (top) ===\n";
foreach ($pdo->query('SELECT asociacion_id, COUNT(*) c FROM usuarios WHERE status IN (1,9) AND asociacion_id IS NOT NULL GROUP BY asociacion_id ORDER BY c DESC LIMIT 10') as $r) {
    echo json_encode($r) . "\n";
}
$hasEnt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'entidad'")->fetch();
echo 'columna entidad: ' . ($hasEnt ? 'SI' : 'NO') . "\n";
$st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE asociacion_id = :a AND status IN (1,9)');
$st->execute([':a' => 1]);
echo 'usuarios FALCON (asociacion_id=1): ' . $st->fetchColumn() . "\n";
$st2 = $pdo->query('SELECT torneo, nombre FROM torneosact WHERE finalizado_en IS NULL ORDER BY fechator DESC LIMIT 3');
echo "torneos activos:\n";
foreach ($st2 as $t) {
    echo json_encode($t) . "\n";
}
