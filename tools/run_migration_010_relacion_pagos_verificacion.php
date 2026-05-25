<?php

declare(strict_types=1);

/**
 * Aplica sql/migrations/010_relacion_pagos_verificacion.sql
 * Uso desde la raíz del proyecto:
 *   php tools/run_migration_010_relacion_pagos_verificacion.php
 */

$sqlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '010_relacion_pagos_verificacion.sql';

require_once dirname(__DIR__) . '/Database.php';

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "No hay conexión a MySQL.\n");
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
    echo "Migración 010 aplicada (verificado, verificado_en, conciliacion_json en relacion_pagos).\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
