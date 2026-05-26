<?php

declare(strict_types=1);

$roots = [
    dirname(__DIR__) . '/app',
    dirname(__DIR__) . '/api',
];
$tables = [];
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $c = file_get_contents($file->getPathname());
        if (preg_match_all('/(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z][a-z0-9_]*)`?/i', $c, $m)) {
            foreach ($m[1] as $t) {
                $tables[strtolower($t)] = true;
            }
        }
        if (preg_match_all("/(?:const T(?:ABLE)?_\\w+|private const T_\\w+) = '([a-z][a-z0-9_]*)'/", $c, $m2)) {
            foreach ($m2[1] as $t) {
                $tables[strtolower($t)] = true;
            }
        }
    }
}
ksort($tables);
require dirname(__DIR__) . '/Database.php';
$pdo = (new Database())->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "Sin conexión BD\n");
    exit(1);
}
$all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$usadas = array_keys($tables);
$noUsadas = array_values(array_diff($all, $usadas));
echo "TABLAS CON SQL EN app/ + api/ (" . count($usadas) . ")\n";
echo implode(', ', $usadas) . "\n\n";
echo "TABLAS EN BD SIN SQL EN APP/API (" . count($noUsadas) . ")\n";
foreach ($noUsadas as $t) {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
    echo sprintf("  %-32s rows=%d\n", $t, $n);
}
