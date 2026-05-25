<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta;

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Servicio no disponible']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Debe iniciar sesión.']);
    exit;
}

AdminPolicy::assertUsuarioCreate();

$cedula = isset($_GET['cedula']) ? (string) $_GET['cedula'] : '';
$cedula = AfiliacionAtleta::normalizarCedula($cedula);

if ($cedula === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Indique la cédula.']);
    exit;
}

try {
    $chk = AfiliacionAtleta::verificarAccesoConsultaCedula($pdo, $cedula);
    if (!$chk['allowed']) {
        echo json_encode([
            'ok' => true,
            'exists' => true,
            'blocked' => true,
            'message' => $chk['message'] ?? 'No autorizado.',
            'user' => null,
        ]);
        exit;
    }
    if ($chk['user'] === null) {
        echo json_encode([
            'ok' => true,
            'exists' => false,
            'blocked' => false,
            'user' => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'exists' => true,
        'blocked' => false,
        'user' => $chk['user'],
    ]);
} catch (Throwable $e) {
    error_log('check_user: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
