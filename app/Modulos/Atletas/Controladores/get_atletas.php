<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/Database.php';
require_once FVD_ROOT . '/app/Auth.php';
require_once FVD_ROOT . '/app/AdminPolicy.php';

use Fvd\Modulos\Atletas\Modelos\Atleta;

$database = new Database();
$db = $database->getConnection();

if ($db === null) {
    http_response_code(503);
    echo json_encode(['message' => 'Servicio no disponible']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$asocQ = isset($_GET['asociacion_id']) ? (int) $_GET['asociacion_id'] : 0;
$statQ = null;
if (array_key_exists('status', $_GET) && $_GET['status'] !== '' && $_GET['status'] !== null) {
    $statQ = (int) $_GET['status'];
}

$useFilter = ($asocQ > 0 || $statQ !== null);

if ($useFilter) {
    if (!Auth::check() || !AdminPolicy::puedeAccederPanel()) {
        http_response_code(403);
        echo json_encode(['message' => 'Debe iniciar sesión en el panel para filtrar atletas.']);
        exit;
    }
    if (Auth::rol() === 'delegado') {
        $my = Auth::asociacionId();
        if ($my !== null && $my > 0) {
            $asocQ = $my;
        }
    }
}

try {
    $atleta = new Atleta($db);
    $asocParam = $useFilter && $asocQ > 0 ? $asocQ : null;
    $stmt = $useFilter ? $atleta->listarActivos($asocParam, $statQ) : $atleta->listarActivos(null, null);
    $atletas_arr = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $st = $row['usuario_status'];
        $atletas_arr[] = [
            'id' => (int) $row['id'],
            'cedula' => $row['cedula'],
            'nombre' => $row['nombre'],
            'numfvd' => (int) $row['numfvd'],
            'sexo' => (int) $row['sexo'],
            'asociacion_nombre' => (string) ($row['asociacion_nombre'] ?? ''),
            'foto_url' => (string) ($row['foto_url'] ?? ''),
            'usuario_status' => $st === null || $st === '' ? null : (int) $st,
        ];
    }

    if (count($atletas_arr) > 0) {
        echo json_encode($atletas_arr);
    } else {
        echo json_encode(['message' => 'No hay atletas.']);
    }
} catch (Throwable $e) {
    error_log('get_atletas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'Error al consultar atletas.']);
}
