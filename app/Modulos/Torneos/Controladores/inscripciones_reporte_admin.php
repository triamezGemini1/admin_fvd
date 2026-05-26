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
$items = InscripcionTorneo::enriquecerFilasInscritos(InscripcionTorneo::listarInscritos($pdo, $tid, $asoc));
$torneoNombre = trim((string) ($torneo['nombre'] ?? $torneo['torneo_nombre'] ?? ''));
$asocNombre = trim((string) ($ctx['asociacion']['nombre'] ?? ''));
$docSub = trim($torneoNombre . ($asocNombre !== '' ? ' · ' . $asocNombre : ''));

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FVD — Administrador de inscripciones (PDF)</title>
    <link rel="icon" href="<?= ins_rep_esc(ins_rep_asset_base() . 'img/logonvofvd.ico') ?>" type="image/x-icon">
    <style><?= ins_rep_estilos_tabla() ?><?= ins_rep_estilos_admin_extra() ?></style>
</head>
<body class="ins-rep-body">
    <p class="no-print"><button type="button" onclick="window.print()">Imprimir / PDF</button></p>
    <?= ins_rep_html_institucional_header('Administrador de inscripciones — Inscritos', $docSub) ?>
    <?php if ($items === []): ?>
        <p class="vacio">Sin inscritos en este torneo para su asociación.</p>
    <?php else: ?>
        <table class="data data--admin">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Foto</th>
                    <th>Carnet</th>
                    <th>Nombre</th>
                    <th>Cédula</th>
                    <th>Teléfono</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $j):
                    $nom = ins_rep_nombre($j);
                    if ($nom === '') {
                        $nom = trim((string) ($j['nombre_usuario'] ?? ''));
                    }
                    $ced = ins_rep_cedula($j);
                    $tel = ins_rep_telefono($j);
                    $em = trim((string) ($j['email'] ?? ''));
                    ?>
                    <tr>
                        <td class="num"><?= $i + 1 ?></td>
                        <td class="ins-rep-td-foto"><?= ins_rep_html_celda_foto($j) ?></td>
                        <td class="ins-rep-td-carnet"><?= ins_rep_html_celda_carnet($j) ?></td>
                        <td><?= ins_rep_esc($nom !== '' ? $nom : '—') ?></td>
                        <td><?= ins_rep_esc($ced !== '' ? $ced : '—') ?></td>
                        <td><?= ins_rep_esc($tel) ?></td>
                        <td><?= ins_rep_esc($em !== '' ? $em : '—') ?></td>
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
