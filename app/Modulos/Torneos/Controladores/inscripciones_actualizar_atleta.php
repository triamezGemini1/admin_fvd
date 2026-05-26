<?php

declare(strict_types=1);

require_once __DIR__ . '/inscripciones_common.php';

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

$body = admin_json_body();
$ctx = inscripciones_init_asociacion_activa();
$pdo = $ctx['pdo'];
$asoc = $ctx['asociacion_id'];

$usuarioId = isset($body['usuario_id']) ? (int) $body['usuario_id'] : 0;
$celular = array_key_exists('celular', $body) && is_string($body['celular']) ? $body['celular'] : null;
$email = array_key_exists('email', $body) && is_string($body['email']) ? $body['email'] : null;

if ($usuarioId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Indique usuario_id.']);

    exit;
}

if ($celular === null && $email === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Indique celular y/o email.']);

    exit;
}

try {
    InscripcionTorneo::actualizarDatosContactoUsuario($pdo, $usuarioId, $asoc, $celular, $email);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);

    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error al guardar el teléfono.']);

    exit;
}

echo json_encode(['ok' => true, 'message' => 'Datos del atleta actualizados.']);
