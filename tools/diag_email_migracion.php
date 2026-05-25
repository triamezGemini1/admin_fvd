<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/Database.php';
$pdo = (new Database())->getConnection();
$r = $pdo->query(
    "SELECT u.id, u.cedula, u.numfvd, u.email FROM usuarios u
     WHERE u.email LIKE 'atleta.%@migracion.fvd.local' AND u.email = CONCAT('atleta.', 6931, '@migracion.fvd.local')"
)->fetch(PDO::FETCH_ASSOC);
$a = $pdo->query('SELECT id, cedula, numfvd FROM atletas WHERE id=6931')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['usuario' => $r, 'atleta' => $a], JSON_PRETTY_PRINT) . "\n";
