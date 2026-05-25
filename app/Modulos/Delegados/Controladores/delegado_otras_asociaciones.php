<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/Auth.php';

$pdo = admin_guard_pdo();

if (Auth::rol() !== 'delegado') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Solo delegados de asociación.']);
    exit;
}

$mine = Auth::asociacionId();
if ($mine === null || $mine < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Sin asociación asignada.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, nombre FROM asociaciones WHERE id != :id ORDER BY nombre ASC'
    );
    $stmt->bindValue(':id', $mine, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'items' => $rows === false ? [] : $rows,
    ]);
} catch (Throwable $e) {
    error_log('delegado_otras_asociaciones: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al listar asociaciones.']);
}
