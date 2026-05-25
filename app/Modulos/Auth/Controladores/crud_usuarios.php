<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once __DIR__ . '/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminUsuario.php';

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        AdminPolicy::assertUsuarioRead();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $row = AdminUsuario::obtener($pdo, $id);
            if ($row === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'No encontrado.']);
                exit;
            }
            echo json_encode(['ok' => true, 'item' => $row]);
            exit;
        }
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $per = isset($_GET['perPage']) ? (int) $_GET['perPage'] : 50;
        $q = isset($_GET['q']) ? (string) $_GET['q'] : null;
        $st = isset($_GET['status']) ? (int) $_GET['status'] : null;
        $statusExact = ($st !== null && $st >= 0) ? $st : null;
        $r = AdminUsuario::listar($pdo, $page, $per, $q, $statusExact);
        echo json_encode(['ok' => true, 'items' => $r['items'], 'total' => $r['total']]);
        exit;
    }

    if ($method === 'POST') {
        AdminPolicy::assertUsuarioCreate();
        $body = admin_json_body();
        $newId = AdminUsuario::crear($pdo, $body);
        echo json_encode(['ok' => true, 'id' => $newId, 'message' => 'Usuario creado.']);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        AdminPolicy::assertUsuarioWrite();
        $body = admin_json_body();
        $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id.']);
            exit;
        }
        unset($body['id']);
        AdminUsuario::actualizar($pdo, $id, $body);
        echo json_encode(['ok' => true, 'message' => 'Usuario actualizado.']);
        exit;
    }

    if ($method === 'DELETE') {
        AdminPolicy::assertUsuarioDelete();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id.']);
            exit;
        }
        AdminUsuario::eliminar($pdo, $id);
        echo json_encode(['ok' => true, 'message' => 'Usuario eliminado.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('crud_usuarios: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
