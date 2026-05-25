<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

$db = (new Database())->getConnection();
if ($db === null) {
    fwrite(STDERR, "Sin conexión a MySQL (revise Database.php / WAMP).\n");
    exit(1);
}

foreach (['admin@fvd.local', 'admin_fvd_general', 'ADMIN-FVD-001'] as $login) {
    $stmt = $db->prepare(
        'SELECT id, email, username, role, status, cedula,
                LENGTH(password_hash) AS hash_len
         FROM usuarios
         WHERE username = :l OR email = :l OR cedula = :l
         LIMIT 3'
    );
    $stmt->execute([':l' => $login]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- Búsqueda: {$login} ---\n";
    if ($rows === []) {
        echo "(sin filas)\n";
        continue;
    }
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        $st = (int) $r['status'];
        if ($st !== 9) {
            echo ">>> PROBLEMA: status={$st} pero Auth::STATUS_ACCESO_PORTAL exige status=9.\n";
        }
        $role = strtolower((string) $r['role']);
        if ($role !== 'admingral') {
            echo ">>> AVISO: role='{$r['role']}' (se espera admingral para panel admin).\n";
        }
    }
}
