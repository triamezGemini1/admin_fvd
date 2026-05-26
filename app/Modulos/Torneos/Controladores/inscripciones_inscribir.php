<?php



declare(strict_types=1);



require_once __DIR__ . '/inscripciones_common.php';



$body = admin_json_body();

$ctx = inscripciones_init_asociacion_activa();

$pdo = $ctx['pdo'];

$asoc = $ctx['asociacion_id'];



require_once FVD_ROOT . '/app/AdminPolicy.php';

AdminPolicy::assertInscripcionTorneo();



$tidBody = isset($body['torneo_id']) ? (int) $body['torneo_id'] : 0;

$torneo = inscripciones_resolver_torneo_jornada($pdo, $tidBody > 0 ? $tidBody : null);

if ($torneo === null) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'message' => 'No hay torneo activo para inscribir.']);



    exit;

}

$modalidad = InscripcionTorneo::modalidadDesdeTorneo($torneo);

if ($modalidad === null) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'message' => 'El torneo activo no tiene modalidad (clase) válida.']);



    exit;

}



$nEsperado = InscripcionTorneo::jugadoresRequeridosPorTorneo($torneo);



$ids = null;

$lineasStr = [];

if (isset($body['usuario_ids']) && is_array($body['usuario_ids'])) {

    $ids = [];

    foreach ($body['usuario_ids'] as $x) {

        $ids[] = (int) $x;

    }

    if (count($ids) !== $nEsperado) {

        http_response_code(400);

        echo json_encode([

            'ok' => false,

            'message' => 'Debe enviar exactamente ' . $nEsperado . ' atleta(s) por inscripción.',

        ]);



        exit;

    }

    if (count(array_unique($ids)) !== count($ids)) {

        http_response_code(400);

        echo json_encode(['ok' => false, 'message' => 'No repita el mismo atleta en el formulario.']);



        exit;

    }

} else {

    $lineas = $body['lineas'] ?? null;

    if (!is_array($lineas) || count($lineas) !== $nEsperado) {

        http_response_code(400);

        echo json_encode([

            'ok' => false,

            'message' => 'Complete las ' . $nEsperado . ' líneas de búsqueda del formulario.',

        ]);



        exit;

    }

    $lineasStr = [];

    foreach ($lineas as $ln) {

        $lineasStr[] = is_string($ln) ? $ln : (string) $ln;

    }

}



$grupo = isset($body['grupo_nombre']) && is_string($body['grupo_nombre']) ? trim($body['grupo_nombre']) : null;

if ($grupo === '') {

    $grupo = null;

}



$tid = (int) $torneo['torneo'];



try {

    if ($ids === null) {

        $ids = InscripcionTorneo::resolverUsuariosPorLineas($pdo, $lineasStr, $asoc);

    }

    if (count($ids) !== $nEsperado) {

        throw new InvalidArgumentException('Cantidad de atletas resuelta no coincide con la modalidad.');

    }

    $insRes = InscripcionTorneo::inscribir($pdo, $tid, $asoc, $modalidad, $ids, $grupo);

} catch (InvalidArgumentException $e) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);



    exit;

} catch (Throwable $e) {

    error_log('inscripciones_inscribir: ' . $e->getMessage());

    http_response_code(500);

    $msg = 'Error al guardar la inscripción.';

    if ($e instanceof \PDOException) {

        $msg = 'Error de base de datos al inscribir. Verifique movimiento_torneo (columnas grupo_id, cédula).';

    }

    echo json_encode(['ok' => false, 'message' => $msg]);



    exit;

}



try {

    require_once FVD_ROOT . '/app/DeudaAsociaciones.php';

    DeudaAsociaciones::recalcularTrasInscripcion($pdo, $tid, $asoc, $ids);

} catch (Throwable $e) {

    error_log('inscripciones_inscribir → DeudaAsociaciones: ' . $e->getMessage());

}



$payload = ['ok' => true, 'message' => 'Inscripción registrada.'];

if (!empty($insRes['had_traspaso'])) {

    $payload['traspaso'] = true;

    $payload['message'] .= ' Traspaso registrado (atleta de otra asociación); revise la bitácora del servidor o el panel de alertas.';

}

echo json_encode($payload);

