<?php

declare(strict_types=1);

/**
 * Diagnóstico local: inscripción rápida (ejecutar en CLI).
 * Uso: php scripts/test_inscribir_debug.php [usuario_id] [torneo_id] [asociacion_id]
 */

if (!defined('FVD_ROOT')) {
    define('FVD_ROOT', dirname(__DIR__));
}
require_once FVD_ROOT . '/app/Autoload.php';
\Fvd\Autoload::register();
require_once FVD_ROOT . '/Database.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$uid = isset($argv[1]) ? (int) $argv[1] : 0;
$tid = isset($argv[2]) ? (int) $argv[2] : 0;
$aid = isset($argv[3]) ? (int) $argv[3] : 1;

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    echo "Sin conexión BD\n";
    exit(1);
}

echo "Columnas movimiento_torneo:\n";
$cols = $pdo->query('SHOW COLUMNS FROM movimiento_torneo')->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n\n";

if ($uid < 1) {
    $row = $pdo->query(
        'SELECT u.id FROM usuarios u
         LEFT JOIN movimiento_torneo m ON m.id_usuario = u.id AND m.torneo_id = ' . max(1, $tid) . '
         WHERE u.asociacion_id = ' . $aid . ' AND u.status IN (1,9)
         AND (m.id IS NULL OR COALESCE(m.inscripcion,0) <> 1) LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $uid = $row ? (int) $row['id'] : 0;
}

if ($tid < 1) {
    $row = $pdo->query('SELECT torneo FROM torneosact ORDER BY torneo DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $tid = $row ? (int) $row['torneo'] : 0;
}

echo "Probar inscribir usuario_id=$uid torneo_id=$tid asociacion_id=$aid\n";

$stmt = $pdo->prepare('SELECT * FROM torneosact WHERE torneo = :t LIMIT 1');
$stmt->execute([':t' => $tid]);
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$torneo) {
    echo "Torneo no encontrado\n";
    exit(1);
}

$modalidad = InscripcionTorneo::modalidadDesdeTorneo($torneo);
echo "Modalidad: " . ($modalidad ?? 'null') . " clase=" . ($torneo['clase'] ?? '?') . "\n";

try {
    $res = InscripcionTorneo::inscribir($pdo, $tid, $aid, $modalidad ?? 'individual', [$uid], null);
    echo "OK: " . json_encode($res) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\nTorneos abiertos (sin finalizar):\n";
$open = $pdo->query(
    'SELECT torneo, nombre, clase, fecha_limite_cambios FROM torneosact
     WHERE finalizado_en IS NULL ORDER BY torneo DESC LIMIT 5'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($open as $t) {
    echo json_encode($t, JSON_UNESCAPED_UNICODE) . "\n";
}
