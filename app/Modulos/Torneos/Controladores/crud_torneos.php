<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/OrganizacionFvd.php';

use Fvd\Modulos\Torneos\Modelos\AdminTorneo;
use Fvd\Modulos\Torneos\Modelos\Torneo;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;

/**
 * @param array<string, mixed> $file
 * @param array<string, string> $extMap
 */
function crud_torneos_guardar_subida(array $file, string $dir, array $extMap, string $prefix): ?string
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

$pdo = admin_guard_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        AdminPolicy::assertTorneoRead();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $row = AdminTorneo::obtener($pdo, $id);
            if ($row === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'No encontrado.']);
                exit;
            }
            echo json_encode(['ok' => true, 'item' => $row]);
            exit;
        }
        if (isset($_GET['grupos_evento']) && (string) $_GET['grupos_evento'] === '1') {
            if (Auth::rol() !== 'admingral') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Solo administración general puede listar campeonatos agrupados.']);
                exit;
            }
            $grupos = AdminTorneo::listarGruposEvento($pdo);
            echo json_encode(['ok' => true, 'grupos' => $grupos]);
            exit;
        }
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $per = isset($_GET['perPage']) ? (int) $_GET['perPage'] : 50;
        $faseRaw = isset($_GET['fase']) ? trim((string) $_GET['fase']) : '';
        $fase = null;
        if ($faseRaw === 'en_proceso' || $faseRaw === 'realizados') {
            $fase = $faseRaw;
        }
        $grupoParam = isset($_GET['grupo']) ? trim((string) $_GET['grupo']) : '';
        $grupoFiltro = null;
        $soloSinGrupo = false;
        if (Auth::rol() === 'admingral' && $grupoParam !== '') {
            if ($grupoParam === 'sin') {
                $soloSinGrupo = true;
            } else {
                $g = (int) $grupoParam;
                if ($g > 0) {
                    $grupoFiltro = $g;
                }
            }
        }
        $r = AdminTorneo::listar($pdo, $page, $per, $fase, $grupoFiltro, $soloSinGrupo);
        echo json_encode(['ok' => true, 'items' => $r['items'], 'total' => $r['total']]);
        exit;
    }

    if ($method === 'POST') {
        AdminPolicy::assertTorneoCreate();
        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $isMultipart = stripos($ct, 'multipart/form-data') !== false;
        if ($isMultipart) {
            $uploadDir = FVD_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'torneos';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $uploadDir = realpath($uploadDir) ?: $uploadDir;
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    throw new RuntimeException('No se pudo crear el directorio de subidas.');
                }
            }
            $allowedInv = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $allowedAfiche = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $invPath = null;
            $afichePath = null;
            if (!empty($_FILES['invitacion']['name'])) {
                $invPath = crud_torneos_guardar_subida($_FILES['invitacion'], $uploadDir, $allowedInv, 'inv');
            }
            if (!empty($_FILES['afiche']['name'])) {
                $afichePath = crud_torneos_guardar_subida($_FILES['afiche'], $uploadDir, $allowedAfiche, 'afiche');
            }
            $organizacionId = OrganizacionFvd::idOrganizadoraActiva($pdo);
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
                'publicar_landing' => isset($_POST['publicar_landing']) ? 1 : 0,
                'pareclub' => $_POST['pareclub'] ?? 0,
                'grupo_evento_id' => isset($_POST['grupo_evento_id']) && $_POST['grupo_evento_id'] !== '' ? (int) $_POST['grupo_evento_id'] : null,
                'fecha_limite_cambios' => isset($_POST['fecha_limite_cambios']) && trim((string) $_POST['fecha_limite_cambios']) !== ''
                    ? trim((string) $_POST['fecha_limite_cambios']) : null,
                'invitaciones_despachadas' => isset($_POST['invitaciones_despachadas']) ? 1 : 0,
            ];
            $modoReg = trim((string) ($_POST['modo_registro'] ?? 'simple'));
            if (TorneoCampeonato::esModoCampeonato($modoReg)) {
                unset($datos['grupo_evento_id']);
                $r = TorneoCampeonato::crearDesdeFormulario($pdo, $datos, $modoReg, $invPath, $afichePath);
                echo json_encode([
                    'ok' => true,
                    'torneo_id' => $r['torneo_ids'][0] ?? null,
                    'torneo_ids' => $r['torneo_ids'],
                    'grupo_evento_id' => $r['grupo_evento_id'],
                    'message' => $r['message'],
                ]);
                exit;
            }
            $newId = Torneo::crear($pdo, $datos, $invPath, $afichePath);
            echo json_encode(['ok' => true, 'torneo_id' => $newId, 'message' => 'Torneo registrado correctamente.']);
            exit;
        }
        $body = admin_json_body();
        $modoReg = trim((string) ($body['modo_registro'] ?? 'simple'));
        if (TorneoCampeonato::esModoCampeonato($modoReg)) {
            unset($body['grupo_evento_id']);
            $r = TorneoCampeonato::crearDesdeFormulario($pdo, $body, $modoReg, null, null);
            echo json_encode([
                'ok' => true,
                'torneo_id' => $r['torneo_ids'][0] ?? null,
                'torneo_ids' => $r['torneo_ids'],
                'grupo_evento_id' => $r['grupo_evento_id'],
                'message' => $r['message'],
            ]);
            exit;
        }
        $newId = AdminTorneo::crearDesdePanel($pdo, $body);
        echo json_encode(['ok' => true, 'torneo_id' => $newId, 'message' => 'Torneo creado.']);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        AdminPolicy::assertTorneoWrite();
        $body = admin_json_body();
        $id = (int) ($body['torneo'] ?? $body['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique torneo (id).']);
            exit;
        }
        unset($body['torneo'], $body['id']);
        AdminTorneo::actualizar($pdo, $id, $body);
        echo json_encode(['ok' => true, 'message' => 'Torneo actualizado.']);
        exit;
    }

    if ($method === 'DELETE') {
        AdminPolicy::assertTorneoDelete();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Indique id (torneo).']);
            exit;
        }
        AdminTorneo::eliminar($pdo, $id);
        echo json_encode(['ok' => true, 'message' => 'Torneo eliminado.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('crud_torneos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
