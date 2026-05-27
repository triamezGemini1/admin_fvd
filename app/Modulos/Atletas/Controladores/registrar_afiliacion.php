<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Database\ConnectionException;
use Fvd\Database\ConnectionManager;
use Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta;
use Fvd\Servicios\AffiliationService;
use InvalidArgumentException;
use RuntimeException;
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

AdminPolicy::assertUsuarioWrite();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$allowedImg = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

try {
    $uploadRoot = AffiliationService::resolverDirectorioUploads(FVD_ROOT);

    $cedula = AfiliacionAtleta::normalizarCedula((string) ($_POST['cedula'] ?? ''));
    if ($cedula === '') {
        throw new InvalidArgumentException('La cédula es obligatoria.');
    }

    $rutaFoto = null;
    $rutaCed = null;
    if (!empty($_FILES['foto_atleta']['name'])) {
        $rutaFoto = AffiliationService::guardarImagenUpload(
            $_FILES['foto_atleta'],
            $uploadRoot,
            $allowedImg,
            $cedula,
            'foto'
        );
    }
    if (!empty($_FILES['imagen_cedula']['name'])) {
        $rutaCed = AffiliationService::guardarImagenUpload(
            $_FILES['imagen_cedula'],
            $uploadRoot,
            $allowedImg,
            $cedula,
            'cedula'
        );
    }

    $res = AffiliationService::registrarAfiliacion($_POST, ['foto' => $rutaFoto, 'cedula_img' => $rutaCed]);

    echo json_encode([
        'ok' => true,
        'message' => 'Registro guardado correctamente.',
        'user_id' => $res['user_id'],
        'movimiento_tridente' => $res['movimiento_tridente'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (ConnectionException $e) {
    error_log('registrar_afiliacion: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'code' => strtoupper($e->connectionName()) . '_UNAVAILABLE',
        'message' => AffiliationService::mensajeErrorConexion($e),
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('registrar_afiliacion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error interno al registrar la afiliación.']);
}
