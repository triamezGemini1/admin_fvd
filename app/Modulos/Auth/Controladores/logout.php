<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/app/Auth.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (Auth::check()) {
    Auth::logoutSession();
}

echo json_encode(['ok' => true, 'message' => 'Sesión cerrada.']);
