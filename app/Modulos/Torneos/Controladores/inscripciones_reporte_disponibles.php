<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';
require_once __DIR__ . '/inscripciones_reporte_helpers.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$ctx = inscripciones_init_asociacion_activa();
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

$torneo = inscripciones_resolver_torneo_jornada($pdo, isset($_GET['torneo_id']) ? (int) $_GET['torneo_id'] : null);
if ($torneo === null) {
    http_response_code(400);
    echo '<p style="color:#000;font-family:Arial,sans-serif">No hay torneo activo.</p>';
    exit;
}

$tid = (int) $torneo['torneo'];
$tipo = InscripcionTorneo::normalizarTipoTorneo($torneo['tipo'] ?? null);
$lista = InscripcionTorneo::listarDisponiblesAgrupadosPorEstatus($pdo, $tid, $asoc, $tipo);
$items = $lista['items'];
$torneoNombre = trim((string) ($torneo['nombre'] ?? $torneo['torneo_nombre'] ?? ''));
$docSub = $torneoNombre !== '' ? 'Torneo: ' . $torneoNombre : '';

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FVD — Atletas disponibles</title>
    <link rel="icon" href="<?= ins_rep_esc(ins_rep_asset_base() . 'img/logonvofvd.ico') ?>" type="image/x-icon">
    <style><?= ins_rep_estilos_tabla() ?></style>
</head>
<body class="ins-rep-body">
    <p class="no-print"><button type="button" onclick="window.print()">Imprimir / PDF</button></p>
    <?= ins_rep_html_institucional_header('Atletas disponibles para inscripción', $docSub) ?>
    <?php if ($items === []): ?>
        <p class="vacio">No hay atletas disponibles.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Cédula</th>
                    <th>Carnet / Nº FVD</th>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $j):
                    $cedRep = ins_rep_cedula($j);
                    ?>
                    <tr>
                        <td class="num"><?= $i + 1 ?></td>
                        <td><?= ins_rep_esc($cedRep !== '' ? $cedRep : '—') ?></td>
                        <td><?= ins_rep_esc(ins_rep_carnet_numfvd($j)) ?></td>
                        <td><?= ins_rep_esc(ins_rep_nombre($j)) ?></td>
                        <td><?= ins_rep_esc(ins_rep_telefono($j)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?= ins_rep_html_institucional_footer() ?>
    <script>
        if (window.location.search.indexOf('auto_print=1') !== -1) {
            window.onload = function () { window.print(); };
        }
    </script>
</body>
</html>
