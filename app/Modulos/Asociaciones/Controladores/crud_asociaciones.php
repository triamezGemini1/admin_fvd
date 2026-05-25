<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Asociaciones\Modelos\AdminAsociacion;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        AdminPolicy::assertAsociacionRead();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $row = AdminAsociacion::obtener($pdo, $id);
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
        $est = null;
        if (array_key_exists('estatus', $_GET) && $_GET['estatus'] !== '' && $_GET['estatus'] !== null) {
            $est = (int) $_GET['estatus'];
        }
        $r = AdminAsociacion::listar($pdo, $page, $per, $est);
        echo json_encode(['ok' => true, 'items' => $r['items'], 'total' => $r['total']]);
        exit;
    }

    if ($method === 'POST') {
        AdminPolicy::assertAsociacionCreate();
        $body = admin_json_body();
        $newId = AdminAsociacion::crear($pdo, $body);
        echo json_encode(['ok' => true, 'id' => $newId, 'message' => 'Asociación creada.']);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        AdminPolicy::assertAsociacionWrite();
        $body = admin_json_body();
        $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id.']);
            exit;
        }
        unset($body['id']);
        AdminAsociacion::actualizar($pdo, $id, $body);
        echo json_encode(['ok' => true, 'message' => 'Asociación actualizada.']);
        exit;
    }

    if ($method === 'DELETE') {
        AdminPolicy::assertAsociacionDelete();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id.']);
            exit;
        }
        AdminAsociacion::eliminar($pdo, $id);
        echo json_encode(['ok' => true, 'message' => 'Asociación eliminada.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('crud_asociaciones: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
