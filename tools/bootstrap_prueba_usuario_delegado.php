<?php

/**
 * Crea o actualiza:
 * 1) Un usuario portal de prueba (rol `usuario`).
 * 2) Un usuario delegado de prueba por cada fila de `asociaciones` (rol `delegado`, asociacion_id = id).
 *
 * Uso (desde la raíz del proyecto):
 *   php tools/bootstrap_prueba_usuario_delegado.php
 *
 * Contraseña común: FvdPrueba2026!
 * Patrón de login delegado por asociación N:
 *   usuario: del_test_asoc_N   —   email: del_test_asoc_N@fvd.local
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

const PRUEBA_PASSWORD = 'FvdPrueba2026!';

const U_EMAIL = 'usr_prueba@fvd.local';
const U_USERNAME = 'user_fvd_prueba';
const U_CEDULA = 'FVD-USER-TEST-001';

$db = (new Database())->getConnection();
if ($db === null) {
    fwrite(STDERR, "No hay conexión MySQL. Revise WAMP, base fvdmasteradmin y Database.php.\n");
    exit(1);
}

$asocs = $db->query('SELECT id, nombre FROM asociaciones ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
if ($asocs === false || count($asocs) < 1) {
    fwrite(STDERR, "No hay filas en `asociaciones`. Cree al menos una asociación.\n");
    exit(1);
}

$hash = password_hash(PRUEBA_PASSWORD, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "password_hash falló.\n");
    exit(1);
}

function upsertUsuario(
    PDO $db,
    string $hash,
    string $email,
    string $username,
    string $cedula,
    string $role,
    ?int $asociacionId,
    int $numfvd,
    string $nombre
): void {
    $sel = $db->prepare(
        'SELECT id FROM usuarios WHERE email = :e OR username = :u OR cedula = :c LIMIT 1'
    );
    $sel->execute([':e' => $email, ':u' => $username, ':c' => $cedula]);
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
                cedula = :c,
                nombre = :nom,
                asociacion_id = :aid,
                numfvd = :nf,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id LIMIT 1'
        );
        $upd->execute([
            ':ph' => $hash,
            ':role' => $role,
            ':st' => 9,
            ':e' => $email,
            ':u' => $username,
            ':c' => $cedula,
            ':nom' => $nombre,
            ':aid' => $asociacionId,
            ':nf' => $numfvd,
            ':id' => $id,
        ]);
        echo "Actualizado id={$id} ({$role}) asoc=" . ($asociacionId ?? 'null') . " — {$email} / {$username}\n";

        return;
    }

    $ins = $db->prepare(
        'INSERT INTO usuarios (
            numfvd, cedula, sexo, nombre, fechnac, email, celular, username,
            password_hash, role, status, asociacion_id, posirnk
        ) VALUES (
            :nf, :ced, 1, :nom, :fnac, :email, NULL, :user,
            :ph, :role, :st, :aid, 0
        )'
    );
    $ins->execute([
        ':nf' => $numfvd,
        ':ced' => $cedula,
        ':nom' => $nombre,
        ':fnac' => '1995-06-15',
        ':email' => $email,
        ':user' => $username,
        ':ph' => $hash,
        ':role' => $role,
        ':st' => 9,
        ':aid' => $asociacionId,
    ]);
    echo "Creado ({$role}) asoc=" . ($asociacionId ?? 'null') . " — {$email} / {$username}\n";
}

upsertUsuario(
    $db,
    $hash,
    U_EMAIL,
    U_USERNAME,
    U_CEDULA,
    'usuario',
    null,
    0,
    'Usuario prueba FVD'
);

foreach ($asocs as $row) {
    $aid = (int) ($row['id'] ?? 0);
    if ($aid < 1) {
        continue;
    }
    $slug = 'del_test_asoc_' . $aid;
    $email = $slug . '@fvd.local';
    $cedula = 'FVD-DELTST-' . str_pad((string) $aid, 5, '0', STR_PAD_LEFT);
    $nomAs = trim((string) ($row['nombre'] ?? ''));
    $nom = 'Delegado prueba — ' . ($nomAs !== '' ? $nomAs : ('asoc #' . $aid));
    upsertUsuario($db, $hash, $email, $slug, $cedula, 'delegado', $aid, 0, $nom);
}

echo "\nContraseña (todos los usuarios anteriores): " . PRUEBA_PASSWORD . "\n";
echo 'Delegados: un usuario por asociación — login = del_test_asoc_<ID> o email del_test_asoc_<ID>@fvd.local' . "\n";
echo "Ejemplo asociación 3: del_test_asoc_3 / del_test_asoc_3@fvd.local\n\n";
echo "Admin general (otro script): php tools/bootstrap_portal_admin.php\n";
echo "  Login: admin@fvd.local — Contraseña: FvdAdmin2026!\n";
