<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';

use Fvd\Modulos\Asociaciones\Modelos\OrganizacionFvd;

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        AdminPolicy::assertOrganizacionRead();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $row = OrganizacionFvd::obtenerPorId($pdo, $id);
            if ($row === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'No encontrado.']);
                exit;
            }
            echo json_encode(['ok' => true, 'item' => $row]);
            exit;
        }
        $items = OrganizacionFvd::listar($pdo);
        echo json_encode(['ok' => true, 'items' => $items, 'total' => count($items)]);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        AdminPolicy::assertOrganizacionWrite();
        $body = admin_json_body();
        $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id de organización.']);
            exit;
        }
        unset($body['id']);
        OrganizacionFvd::actualizar($pdo, $id, $body);
        echo json_encode(['ok' => true, 'message' => 'Organización actualizada.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('crud_organizacion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
