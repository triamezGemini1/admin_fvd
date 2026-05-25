<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta;

$database = new Database();
$pdo = $database->getConnection();

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

AdminPolicy::assertUsuarioWrite();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$uploadRoot = FVD_ROOT . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadRoot)) {
    @mkdir($uploadRoot, 0755, true);
}
$uploadRoot = realpath($uploadRoot) ?: $uploadRoot;
if ($uploadRoot === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Ruta de subidas inválida.']);
    exit;
}
if (!is_dir($uploadRoot)) {
    if (!@mkdir($uploadRoot, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No se pudo crear la carpeta de subidas.']);
        exit;
    }
}

$allowedImg = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

/**
 * @param array<string, mixed> $file
 */
function afiliacion_guardar_imagen(array $file, string $dir, array $extMap, string $cedulaBase, string $suffix): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir la imagen.');
    }
    $name = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !isset($extMap[$ext])) {
        throw new RuntimeException('Formato de imagen no permitido (JPG, PNG, WebP).');
    }
    $safeCed = preg_replace('/\W/', '_', $cedulaBase);
    if ($safeCed === '') {
        $safeCed = 'ced';
    }
    $destName = $safeCed . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $dir . DIRECTORY_SEPARATOR . $destName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    return 'dist/assets/img/uploads/' . $destName;
}

try {
    $cedula = AfiliacionAtleta::normalizarCedula((string) ($_POST['cedula'] ?? ''));
    if ($cedula === '') {
        throw new InvalidArgumentException('La cédula es obligatoria.');
    }

    $rutaFoto = null;
    $rutaCed = null;
    if (!empty($_FILES['foto_atleta']['name'])) {
        $rutaFoto = afiliacion_guardar_imagen($_FILES['foto_atleta'], $uploadRoot, $allowedImg, $cedula, 'foto');
    }
    if (!empty($_FILES['imagen_cedula']['name'])) {
        $rutaCed = afiliacion_guardar_imagen($_FILES['imagen_cedula'], $uploadRoot, $allowedImg, $cedula, 'cedula');
    }

    $post = $_POST;
    $res = AfiliacionAtleta::guardar($pdo, $post, ['foto' => $rutaFoto, 'cedula_img' => $rutaCed]);

    echo json_encode([
        'ok' => true,
        'message' => 'Registro guardado correctamente.',
        'user_id' => $res['user_id'],
        'movimiento_tridente' => $res['movimiento_tridente'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('save_afiliacion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
