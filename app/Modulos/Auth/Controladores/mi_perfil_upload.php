<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once __DIR__ . '/portal_auth_pdo.php';
require_once FVD_ROOT . '/app/AdminUsuario.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/LogoUpload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$pdo = portal_auth_pdo();
$uid = Auth::userId();
if ($uid === null || $uid < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesión inválida.']);
    exit;
}

$campo = isset($_POST['campo']) ? trim((string) $_POST['campo']) : '';
if (!in_array($campo, ['foto', 'cedula'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'campo debe ser foto o cedula.']);
    exit;
}
if (!isset($_FILES['archivo'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Adjunte archivo (archivo).']);
    exit;
}

try {
    $rel = LogoUpload::guardar($_FILES['archivo'], 'uploads/usuarios', 'usr_' . $uid . '_' . $campo);
    AdminUsuario::setUrlImagenPerfilPropio($pdo, $uid, $campo, $rel);
    $field = $campo === 'foto' ? 'urlimgfoto' : 'urlimgcedula';
    echo json_encode(['ok' => true, 'path' => $rel, 'field' => $field, 'message' => 'Imagen subida.']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('mi_perfil_upload: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
