<?php

declare(strict_types=1);

/**
 * Guardias JSON + sesión + PDO para APIs del panel CRUD.
 */
function admin_guard_pdo(): \PDO
{
    header('Content-Type: application/json; charset=UTF-8');
    require_once __DIR__ . '/../../bootstrap_modulo.php';
    require_once FVD_ROOT . '/Database.php';
    require_once FVD_ROOT . '/app/Auth.php';
    require_once FVD_ROOT . '/app/AdminPolicy.php';

    $db = new Database();
    $pdo = $db->getConnection();
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
    if (!AdminPolicy::puedeAccederPanel()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Sin acceso al panel de administración.']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $pdo;
}

/**
 * @return array<string, mixed>
 */
function admin_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);

    return is_array($j) ? $j : [];
}
