<?php

declare(strict_types=1);

/**
 * Sesión iniciada + PDO (APIs del portal no restringidas al panel admin).
 */
function portal_auth_pdo(): \PDO
{
    header('Content-Type: application/json; charset=UTF-8');
    require_once __DIR__ . '/../../bootstrap_modulo.php';
    require_once FVD_ROOT . '/Database.php';
    require_once FVD_ROOT . '/app/Auth.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Debe iniciar sesión.']);
        exit;
    }
    $db = new Database();
    $pdo = $db->getConnection();
    if ($pdo === null) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Servicio no disponible']);
        exit;
    }

    return $pdo;
}
