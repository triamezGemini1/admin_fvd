<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once __DIR__ . '/portal_auth_pdo.php';
require_once FVD_ROOT . '/app/AdminUsuario.php';
require_once FVD_ROOT . '/app/Auth.php';

$pdo = portal_auth_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid = Auth::userId();
if ($uid === null || $uid < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesión inválida.']);
    exit;
}

try {
    if ($method === 'GET') {
        $item = AdminUsuario::obtener($pdo, $uid);
        if ($item === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Usuario no encontrado.']);
            exit;
        }
        $item['asociacion_nombre'] = null;
        if (!empty($item['asociacion_id'])) {
            $s = $pdo->prepare('SELECT nombre FROM asociaciones WHERE id = :id LIMIT 1');
            $s->bindValue(':id', (int) $item['asociacion_id'], \PDO::PARAM_INT);
            $s->execute();
            $n = $s->fetchColumn();
            $item['asociacion_nombre'] = $n !== false ? (string) $n : null;
        }
        echo json_encode(['ok' => true, 'item' => $item]);
        exit;
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'JSON inválido.']);
            exit;
        }
        $patch = [];
        foreach (['nombre', 'fechnac', 'sexo', 'email', 'celular', 'username', 'urlimgfoto', 'urlimgcedula'] as $k) {
            if (array_key_exists($k, $body)) {
                $patch[$k] = $body[$k];
            }
        }
        if (array_key_exists('password', $body)) {
            $patch['password'] = (string) $body['password'];
        }
        if (isset($patch['email'])) {
            $em = trim((string) $patch['email']);
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Email no válido.');
            }
            $patch['email'] = $em;
        }
        if (isset($patch['username'])) {
            $un = trim((string) $patch['username']);
            if (strlen($un) < 3) {
                throw new InvalidArgumentException('El nombre de usuario debe tener al menos 3 caracteres.');
            }
            $patch['username'] = $un;
        }
        if (isset($patch['nombre'])) {
            $patch['nombre'] = trim((string) $patch['nombre']);
            if ($patch['nombre'] === '') {
                throw new InvalidArgumentException('El nombre no puede quedar vacío.');
            }
        }
        if (isset($patch['password']) && $patch['password'] === '') {
            unset($patch['password']);
        }
        AdminUsuario::actualizarPerfilPropio($pdo, $uid, $patch);
        $item = AdminUsuario::obtener($pdo, $uid);
        echo json_encode(['ok' => true, 'message' => 'Perfil actualizado.', 'item' => $item]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('mi_perfil: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al procesar la solicitud.']);
}
