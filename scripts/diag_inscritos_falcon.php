<?php

declare(strict_types=1);

define('FVD_ROOT', dirname(__DIR__));
require FVD_ROOT . '/app/Autoload.php';
\Fvd\Autoload::register();
require FVD_ROOT . '/Database.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$pdo = (new Database())->getConnection();
if ($pdo === null) {
    echo "No DB\n";
    exit(1);
}

echo "Columnas asociaciones:\n";
$cols = $pdo->query('SHOW COLUMNS FROM asociaciones')->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n\n";

$tid = (int) ($argv[1] ?? 0);
$asoc = (int) ($argv[2] ?? 1);
$tids = [];
if ($tid > 0) {
    $tids[] = $tid;
} else {
    $q = $pdo->query('SELECT torneo FROM torneosact WHERE finalizado_en IS NULL ORDER BY torneo ASC');
    if ($q) {
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $tids[] = (int) $t;
        }
    }
    if ($tids === []) {
        $tids = [9, 8, 7];
    }
}
$hasEntidad = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'entidad'")->fetch();
echo 'usuarios.entidad: ' . ($hasEntidad ? 'si' : 'no') . "\n\n";
foreach ($tids as $tid) {
    echo "=== Torneo $tid ===\n";
    $r = $pdo->prepare(
        'SELECT m.id, m.id_usuario, m.asociacion_id, m.inscripcion, u.asociacion_id AS u_asoc,
                ' . ($hasEntidad ? 'u.entidad,' : '') . ' u.status, u.nombre
         FROM movimiento_torneo m
         LEFT JOIN usuarios u ON u.id = m.id_usuario
         WHERE m.torneo_id = :t AND CAST(m.inscripcion AS UNSIGNED) = 1'
    );
    $r->execute([':t' => $tid]);
    $rows = $r->fetchAll(PDO::FETCH_ASSOC);
    echo 'Raw inscripcion=1: ' . count($rows) . "\n";
    $r2 = $pdo->prepare('SELECT inscripcion, COUNT(*) c FROM movimiento_torneo WHERE torneo_id=:t GROUP BY inscripcion');
    $r2->execute([':t' => $tid]);
    echo "Por valor inscripcion:\n";
    foreach ($r2->fetchAll(PDO::FETCH_ASSOC) as $g) {
        echo "  inscripcion={$g['inscripcion']} count={$g['c']}\n";
    }
    $r3 = $pdo->prepare(
        'SELECT COUNT(*) FROM movimiento_torneo m
         INNER JOIN usuarios u ON u.id=m.id_usuario
         WHERE m.torneo_id=:t AND CAST(m.inscripcion AS UNSIGNED)=1
         AND (m.asociacion_id=:a OR u.asociacion_id=:a2)'
    );
    $r3->execute([':t' => $tid, ':a' => $asoc, ':a2' => $asoc]);
    echo 'Falcon scope CAST inscripcion=1: ' . $r3->fetchColumn() . "\n";
    foreach ($rows as $x) {
        $ent = $hasEntidad ? (' ent=' . ($x['entidad'] ?? '')) : '';
        echo "  mov={$x['id']} user={$x['id_usuario']} m_asoc={$x['asociacion_id']} u_asoc={$x['u_asoc']}{$ent} st={$x['status']} {$x['nombre']}\n";
    }
    $all = $pdo->prepare('SELECT COUNT(*) FROM movimiento_torneo WHERE torneo_id=:t');
    $all->execute([':t' => $tid]);
    echo 'Total filas movimiento (cualquier inscripcion): ' . $all->fetchColumn() . "\n";
    $lista = InscripcionTorneo::listarInscritos($pdo, $tid, $asoc);
    echo "listarInscritos(asoc=$asoc): " . count($lista) . "\n";
}
