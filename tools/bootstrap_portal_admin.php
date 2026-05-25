<?php

/**
 * Crea o repara el usuario Admin Gral del portal en `usuarios`.
 * Uso (desde la raíz del proyecto): php tools/bootstrap_portal_admin.php
 *
 * Contraseña por defecto: FvdAdmin2026! (cámbiela tras el primer acceso).
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

const ADMIN_EMAIL = 'admin@fvd.local';
const ADMIN_USERNAME = 'admin_fvd_general';
const ADMIN_CEDULA = 'ADMIN-FVD-001';
const ADMIN_PLAIN_PASSWORD = 'FvdAdmin2026!';

$db = (new Database())->getConnection();
if ($db === null) {
    fwrite(STDERR, "No hay conexión MySQL. Revise WAMP, base fvdmasteradmin y Database.php.\n");
    exit(1);
}

$hash = password_hash(ADMIN_PLAIN_PASSWORD, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "password_hash falló.\n");
    exit(1);
}

$sel = $db->prepare(
    'SELECT id FROM usuarios WHERE email = :e OR username = :u OR cedula = :c LIMIT 1'
);
$sel->execute([
    ':e' => ADMIN_EMAIL,
    ':u' => ADMIN_USERNAME,
    ':c' => ADMIN_CEDULA,
]);
$existing = $sel->fetch(PDO::FETCH_ASSOC);

if ($existing !== false) {
    $id = (int) $existing['id'];
    $upd = $db->prepare(
        'UPDATE usuarios SET
            password_hash = :ph,
            role = :role,
            status = :st,
            email = :e,
            username = :u,
            nombre = :nom,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :id LIMIT 1'
    );
    $upd->execute([
        ':ph' => $hash,
        ':role' => 'admingral',
        ':st' => 9,
        ':e' => ADMIN_EMAIL,
        ':u' => ADMIN_USERNAME,
        ':nom' => 'Administración General FVD',
        ':id' => $id,
    ]);
    echo "Usuario actualizado (id={$id}). Login: " . ADMIN_EMAIL . " o " . ADMIN_USERNAME . "\n";
    echo "Contraseña: " . ADMIN_PLAIN_PASSWORD . "\n";
    exit(0);
}

$ins = $db->prepare(
    'INSERT INTO usuarios (
        numfvd, cedula, sexo, nombre, fechnac, email, celular, username,
        password_hash, role, status, asociacion_id, posirnk
    ) VALUES (
        0, :ced, 1, :nom, :fnac, :email, NULL, :user,
        :ph, :role, :st, NULL, 0
    )'
);
$ins->execute([
    ':ced' => ADMIN_CEDULA,
    ':nom' => 'Administración General FVD',
    ':fnac' => '1990-01-01',
    ':email' => ADMIN_EMAIL,
    ':user' => ADMIN_USERNAME,
    ':ph' => $hash,
    ':role' => 'admingral',
    ':st' => 9,
]);

echo "Usuario admin creado. Login: " . ADMIN_EMAIL . " o " . ADMIN_USERNAME . "\n";
echo "Contraseña: " . ADMIN_PLAIN_PASSWORD . "\n";
