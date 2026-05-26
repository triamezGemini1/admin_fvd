<?php

declare(strict_types=1);

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

function ins_rep_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $j
 */
function ins_rep_cedula(array $j): string
{
    return trim((string) ($j['cedula'] ?? ''));
}

/**
 * @param array<string, mixed> $j
 */
function ins_rep_carnet(array $j): string
{
    $nf = (int) ($j['numfvd'] ?? 0);

    return $nf > 0 ? (string) $nf : '—';
}

/** @deprecated Use ins_rep_carnet */
function ins_rep_carnet_numfvd(array $j): string
{
    return ins_rep_carnet($j);
}

/**
 * @param array<string, mixed> $j
 */
function ins_rep_nombre(array $j): string
{
    foreach (['nombre', 'nombre_usuario', 'usuario_nombre'] as $k) {
        $n = trim((string) ($j[$k] ?? ''));
        if ($n !== '') {
            return $n;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $j
 */
function ins_rep_telefono(array $j): string
{
    $t = trim((string) ($j['celular'] ?? ''));

    return $t !== '' ? $t : '—';
}

/**
 * Solo nombre, cédula, carnet (Nº FVD) y teléfono — sin categoría ni indicadores de movimiento.
 *
 * @param list<array<string, mixed>> $rows
 *
 * @return list<array{nombre: string, cedula: string, carnet: string, telefono: string}>
 */
function ins_rep_filas_inscritos_min(array $rows): array
{
    $out = [];
    foreach ($rows as $j) {
        $nom = ins_rep_nombre($j);
        if ($nom === '') {
            $nf = (int) ($j['numfvd'] ?? 0);
            $ced = trim((string) ($j['cedula'] ?? ''));
            if ($nf > 0) {
                $nom = 'Nº FVD ' . $nf;
            } elseif ($ced !== '') {
                $nom = $ced;
            } else {
                $nom = '—';
            }
        }
        $ced = ins_rep_cedula($j);
        $out[] = [
            'nombre' => $nom,
            'cedula' => $ced !== '' ? $ced : '—',
            'carnet' => ins_rep_carnet($j),
            'telefono' => ins_rep_telefono($j),
            '_sort' => mb_strtolower($nom, 'UTF-8'),
        ];
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['_sort'], $b['_sort']));
    foreach ($out as &$r) {
        unset($r['_sort']);
    }
    unset($r);

    return $out;
}

function ins_rep_asset_base(): string
{
    return '../';
}

function ins_rep_logo_url(): string
{
    return ins_rep_asset_base() . 'img/fvd-portal-logo.png';
}

function ins_rep_org_title(): string
{
    return 'FEDERACION VENEZOLANA DE DOMINO';
}

function ins_rep_estilos_tabla(): string
{
    return <<<'CSS'
        @page { margin: 1.2cm 1.5cm; }
        * { color: #000; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .ins-rep-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 1rem;
            padding-bottom: 0.65rem;
            border-bottom: 2px solid #1a4a8a;
        }
        .ins-rep-brand img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }
        .ins-rep-brand__text { flex: 1; min-width: 0; }
        .ins-rep-brand__org {
            margin: 0;
            font-size: 9pt;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #1a4a8a;
        }
        .ins-rep-doc-title {
            margin: 0.15rem 0 0;
            font-size: 13pt;
            font-weight: 700;
        }
        .ins-rep-doc-sub {
            margin: 0.2rem 0 0;
            font-size: 9.5pt;
            color: #444;
        }
        .ins-rep-footer {
            margin-top: 1.25rem;
            padding-top: 0.5rem;
            border-top: 1px solid #ccc;
            font-size: 8.5pt;
            color: #555;
            text-align: center;
        }
        h1 {
            font-size: 13pt;
            font-weight: 700;
            margin: 0 0 14px;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
        }
        table.data th,
        table.data td {
            padding: 5px 8px;
            text-align: left;
            font-size: 10pt;
            border-bottom: 1px solid #ccc;
        }
        table.data th {
            font-weight: 700;
            border-bottom: 2px solid #1a4a8a;
            color: #1a2d50;
        }
        table.data tbody tr:nth-child(odd) td {
            background-color: #79d4fc;
            color: #000;
            font-weight: 700;
        }
        table.data tbody tr:nth-child(even) td {
            background-color: #219762;
            color: #000;
            font-weight: 700;
        }
        table.data td.num { width: 2.5em; text-align: right; }
        .vacio { font-size: 10pt; padding: 8px 0; color: #000; font-weight: 700; }
        .no-print { margin: 0 0 1rem; }
        @media print {
            .no-print { display: none !important; }
            .ins-rep-brand { border-bottom-color: #000; }
            table.data tbody tr:nth-child(odd) td {
                background-color: #79d4fc !important;
                color: #000 !important;
                font-weight: 700 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            table.data tbody tr:nth-child(even) td {
                background-color: #219762 !important;
                color: #000 !important;
                font-weight: 700 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
CSS;
}

function ins_rep_html_institucional_header(string $docTitle, string $subtitle = ''): string
{
    $logo = ins_rep_esc(ins_rep_logo_url());
    $org = ins_rep_esc(ins_rep_org_title());
    $title = ins_rep_esc($docTitle);
    $subHtml = $subtitle !== ''
        ? '<p class="ins-rep-doc-sub">' . ins_rep_esc($subtitle) . '</p>'
        : '';

    return <<<HTML
<header class="ins-rep-brand">
    <img src="{$logo}" alt="Federación Venezolana de Dominó" width="52" height="52">
    <div class="ins-rep-brand__text">
        <p class="ins-rep-brand__org">{$org}</p>
        <h1 class="ins-rep-doc-title">{$title}</h1>
        {$subHtml}
    </div>
</header>
HTML;
}

function ins_rep_html_institucional_footer(): string
{
    $year = (int) date('Y');

    return '<footer class="ins-rep-footer">© ' . $year . ' Federación Venezolana de Dominó — Portal administrativo FVD</footer>';
}

/**
 * @param array<string, mixed> $j fila enriquecida (listarInscritos + enriquecerFilasInscritos)
 */
function ins_rep_html_celda_foto(array $j): string
{
    $url = InscripcionTorneo::rutaImagenWeb(isset($j['foto_url']) ? (string) $j['foto_url'] : (isset($j['urlimgfoto']) ? (string) $j['urlimgfoto'] : null));
    if ($url === null) {
        return '—';
    }

    return '<img class="ins-rep-thumb" src="' . ins_rep_esc($url) . '" alt="Foto" width="48" height="48">';
}

/**
 * @param array<string, mixed> $j
 */
function ins_rep_html_celda_carnet(array $j): string
{
    $img = InscripcionTorneo::rutaImagenWeb(isset($j['cedula_img_url']) ? (string) $j['cedula_img_url'] : (isset($j['urlimgcedula']) ? (string) $j['urlimgcedula'] : null));
    if ($img !== null) {
        return '<img class="ins-rep-thumb ins-rep-thumb--ced" src="' . ins_rep_esc($img) . '" alt="Cédula" width="64" height="40">';
    }
    $nf = (int) ($j['numfvd'] ?? 0);

    return $nf > 0 ? ins_rep_esc((string) $nf) : '—';
}

function ins_rep_estilos_admin_extra(): string
{
    return <<<'CSS'
        table.data--admin th { font-size: 9pt; }
        table.data--admin td { vertical-align: middle; }
        .ins-rep-thumb {
            display: block;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid rgba(0,0,0,0.15);
            background: #fff;
        }
        .ins-rep-thumb--ced { object-fit: contain; width: 72px; height: auto; max-height: 44px; }
        td.ins-rep-td-foto, td.ins-rep-td-carnet { width: 4.5rem; }
CSS;
}
