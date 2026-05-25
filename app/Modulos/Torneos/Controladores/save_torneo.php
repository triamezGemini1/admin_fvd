<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/OrganizacionFvd.php';

use Fvd\Modulos\Torneos\Modelos\Torneo;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;

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

if (Auth::rol() !== 'admingral') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Solo la administración general de la FVD puede registrar torneos.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$uploadDir = FVD_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'torneos';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No se pudo crear el directorio de subidas.']);
        exit;
    }
}

$allowedInv = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$allowedAfiche = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

function guardarSubida(array $file, string $dir, array $extMap, string $prefix): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir el archivo.');
    }
    $name = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !isset($extMap[$ext])) {
        throw new RuntimeException('Tipo de archivo no permitido.');
    }
    $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
    if ($safeBase === '' || $safeBase === '_') {
        $safeBase = 'archivo';
    }
    $destName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $dir . DIRECTORY_SEPARATOR . $destName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    return 'uploads/torneos/' . $destName;
}

try {
    $organizacionId = OrganizacionFvd::idOrganizadoraActiva($pdo);

    $invPath = null;
    $afichePath = null;

    if (!empty($_FILES['invitacion']['name'])) {
        $invPath = guardarSubida($_FILES['invitacion'], $uploadDir, $allowedInv, 'inv');
    }
    if (!empty($_FILES['afiche']['name'])) {
        $afichePath = guardarSubida($_FILES['afiche'], $uploadDir, $allowedAfiche, 'afiche');
    }

    $datos = [
        'nombre' => $_POST['nombre'] ?? '',
        'lugar' => $_POST['lugar'] ?? null,
        'fechator' => $_POST['fechator'] ?? null,
        'tipo' => $_POST['tipo'] ?? null,
        'clase' => $_POST['clase'] ?? null,
        'tiempo' => $_POST['tiempo'] ?? null,
        'puntos' => $_POST['puntos'] ?? null,
        'rondas' => $_POST['rondas'] ?? null,
        'ranking' => $_POST['ranking'] ?? null,
        'estatus' => $_POST['estatus'] ?? null,
        'costotor' => $_POST['costotor'] ?? null,
        'organizacion_id' => $organizacionId,
        'publicar_landing' => isset($_POST['publicar_landing']) ? (int) $_POST['publicar_landing'] : 1,
        'pareclub' => $_POST['pareclub'] ?? 0,
    ];

    $modoReg = trim((string) ($_POST['modo_registro'] ?? 'simple'));
    if (TorneoCampeonato::esModoCampeonato($modoReg)) {
        $r = TorneoCampeonato::crearDesdeFormulario($pdo, $datos, $modoReg, $invPath, $afichePath);
        echo json_encode([
            'ok' => true,
            'message' => $r['message'],
            'torneo_id' => $r['torneo_ids'][0] ?? null,
            'torneo_ids' => $r['torneo_ids'],
            'grupo_evento_id' => $r['grupo_evento_id'],
        ]);
        exit;
    }

    $id = Torneo::crear($pdo, $datos, $invPath, $afichePath);

    echo json_encode([
        'ok' => true,
        'message' => 'Torneo registrado correctamente.',
        'torneo_id' => $id,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('save_torneo: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
