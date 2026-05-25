<?php

declare(strict_types=1);

/**
 * Crea tablas finanza_* (migración 006). Uso desde la carpeta del proyecto:
 *   php tools/run_migration_006_finanza.php
 */

$sqlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '006_finanza_fvd.sql';

require_once dirname(__DIR__) . '/Database.php';

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "No hay conexión a MySQL. Revise Database.php / WAMP.\n");
    exit(1);
}

$sql = file_get_contents($sqlPath);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "No se pudo leer: {$sqlPath}\n");
    exit(1);
}

try {
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);
    }
    $pdo->exec($sql);
    echo "Migración 006 aplicada correctamente (finanza_parametro, finanza_cargo, finanza_pago).\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
