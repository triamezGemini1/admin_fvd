<?php
require dirname(__DIR__) . '/Database.php';
$pdo = (new Database())->getConnection();
if (!$pdo) {
    fwrite(STDERR, "no pdo\n");
    exit(1);
}
foreach ($pdo->query('SHOW COLUMNS FROM usuarios') as $c) {
    echo $c['Field'] . "\n";
}
