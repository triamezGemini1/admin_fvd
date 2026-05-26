<?php

declare(strict_types=1);

/**
 * Audita qué tablas de fvdmasteradmin aparecen en el código admin_fvd.
 * Uso: php tools/audit_tablas_bd.php
 */

$root = dirname(__DIR__);
$tables = [];
$pdo = null;
try {
    require_once $root . '/Database.php';
    $pdo = (new Database())->getConnection();
    if ($pdo === null) {
        throw new RuntimeException('Conexión PDO nula');
    }
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
        $tables[] = (string) $row[0];
    }
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo conectar a BD: {$e->getMessage()}\n");
    exit(1);
}

$extensions = ['php', 'js', 'html', 'sql', 'md', 'json'];
$skipDirs = ['node_modules', 'vendor', '.git', 'dist', 'agent-tools', 'datosinfo'];
$skipFilePatterns = ['/fvdmasteradmin.*\.sql$/i', '/datosinfo\//i'];
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skipDirs as $sd) {
        if (strpos($path, "/{$sd}/") !== false || strpos($path, "\\{$sd}\\") !== false) {
            continue 2;
        }
    }
    foreach ($skipFilePatterns as $pat) {
        if (preg_match($pat, $path)) {
            continue 2;
        }
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $extensions, true)) {
        continue;
    }
    $files[] = $path;
}

$contents = [];
foreach ($files as $path) {
    $c = @file_get_contents($path);
    if ($c !== false) {
        $contents[$path] = $c;
    }
}

function classifyPath(string $path, string $root): string
{
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (strpos($rel, 'sql/migrations/') === 0) {
        return 'migration';
    }
    if (strpos($rel, 'tools/') === 0 || strpos($rel, 'scripts/') === 0) {
        return 'tool';
    }
    if (strpos($rel, 'app/') === 0 || strpos($rel, 'api/') === 0 || strpos($rel, 'src/') === 0) {
        return 'app';
    }
    if (preg_match('/\.html$/', $rel)) {
        return 'app';
    }

    return 'other';
}

/** @var array<string, array{app: list<string>, migration: list<string>, tool: list<string>, other: list<string>}> $usage */
$usage = [];
foreach ($tables as $table) {
    $usage[$table] = ['app' => [], 'migration' => [], 'tool' => [], 'other' => []];
    $pattern = '/\b' . preg_quote($table, '/') . '\b/i';
    foreach ($contents as $path => $body) {
        if (!preg_match($pattern, $body)) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $cat = classifyPath($path, $root);
        $usage[$table][$cat][] = $rel;
    }
}

$counts = [];
foreach ($tables as $table) {
    try {
        $st = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
        $counts[$table] = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $counts[$table] = -1;
    }
}

$sinUso = [];
$soloMigracion = [];
$enUso = [];
$legacy = [];

foreach ($tables as $table) {
    $u = $usage[$table];
    $hasApp = count($u['app']) > 0;
    $hasOther = count($u['other']) > 0;
    $hasMig = count($u['migration']) > 0;
    $hasTool = count($u['tool']) > 0;
    $any = $hasApp || $hasOther || $hasMig || $hasTool;
    $rows = $counts[$table] ?? 0;

    if (!$any) {
        $sinUso[] = ['tabla' => $table, 'rows' => $rows];
        continue;
    }
    if ($hasApp) {
        $enUso[] = ['tabla' => $table, 'rows' => $rows, 'archivos' => array_slice($u['app'], 0, 6)];
        continue;
    }
    if ($hasOther && !$hasMig && !$hasTool) {
        $legacy[] = ['tabla' => $table, 'rows' => $rows, 'archivos' => array_slice($u['other'], 0, 4), 'motivo' => 'solo_docs/dump sueltos'];
        continue;
    }
    if ($hasMig || $hasTool) {
        $soloMigracion[] = [
            'tabla' => $table,
            'rows' => $rows,
            'archivos' => array_slice(array_merge($u['migration'], $u['tool'], $u['other']), 0, 4),
        ];
        continue;
    }
    $legacy[] = ['tabla' => $table, 'rows' => $rows, 'archivos' => $u['other'], 'motivo' => 'sin app'];
}

echo "Base: fvdmasteradmin — " . count($tables) . " tablas\n";
echo "(Excluye dumps datosinfo/ y SQL completos de la búsqueda)\n";
echo str_repeat('=', 72) . "\n\n";

echo "EN USO POR admin_fvd (" . count($enUso) . ")\n";
foreach ($enUso as $row) {
    $n = $counts[$row['tabla']] ?? '?';
    echo sprintf("  %-32s rows=%-8s  %s\n", $row['tabla'], $n, implode(', ', $row['archivos']));
}

echo "\nSIN REFERENCIA EN APP/API/SRC (" . count($sinUso) . ") — candidatas fuertes a omitir\n";
foreach ($sinUso as $t) {
    echo sprintf("  %-32s rows=%s\n", $t['tabla'], $t['rows']);
}

echo "\nLEGACY / SOLO DOCS U OTROS (" . count($legacy) . ") — probablemente omitir\n";
foreach ($legacy as $row) {
    echo sprintf("  %-32s rows=%-8s  %s\n", $row['tabla'], $row['rows'], implode(', ', $row['archivos']));
}

echo "\nSOLO MIGRACIONES / TOOLS / SCRIPTS (" . count($soloMigracion) . ") — revisar antes de omitir\n";
foreach ($soloMigracion as $row) {
    $n = $counts[$row['tabla']] ?? '?';
    echo sprintf("  %-32s rows=%-8s  %s\n", $row['tabla'], $n, implode(', ', array_slice($row['archivos'], 0, 3)));
}

echo "\nJSON:\n";
echo json_encode([
    'en_uso_app' => array_column($enUso, 'tabla'),
    'sin_referencia' => array_column($sinUso, 'tabla'),
    'legacy' => array_column($legacy, 'tabla'),
    'solo_migracion_tools' => array_column($soloMigracion, 'tabla'),
    'conteos' => $counts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
