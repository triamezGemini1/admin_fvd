<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Database\ConnectionManager;
use Fvd\Servicios\AffiliationService;
use InvalidArgumentException;
use Throwable;

if (!ConnectionManager::tryGetPortalConnection()) {
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

$cedulaRaw = isset($_GET['cedula']) ? trim((string) $_GET['cedula']) : '';
$nacionalidad = isset($_GET['nacionalidad']) ? strtoupper(trim((string) $_GET['nacionalidad'])) : null;
if ($nacionalidad === '') {
    $nacionalidad = null;
}

try {
    $payload = AffiliationService::validarIdentidadParaAfiliacion($cedulaRaw, $nacionalidad);
    echo json_encode($payload);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('check_user: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al validar la identidad.']);
}
