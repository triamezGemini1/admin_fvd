<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';

header('Content-Type: application/json; charset=UTF-8');

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Servicio no disponible']);
    exit;
}

$auth = new Auth($db);
$data = json_decode(file_get_contents('php://input') ?: '');

$login = is_object($data) ? trim((string) ($data->username ?? $data->email ?? '')) : '';
$password = is_object($data) ? (string) ($data->password ?? '') : '';

if ($login !== '' && $password !== '') {
    if ($auth->login($login, $password)) {
        echo json_encode([
            'status' => 'success',
            'rol' => $_SESSION['rol'],
            'id_asociacion' => $_SESSION['id_asociacion'],
            'numfvd' => $_SESSION['numfvd'] ?? null,
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Credenciales inválidas']);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Usuario y contraseña requeridos']);
}
