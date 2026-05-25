<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap_modulo.php';
require_once FVD_ROOT . '/api/admin_api_guard.php';
require_once FVD_ROOT . '/app/AdminUsuario.php';
require_once FVD_ROOT . '/app/AdminTorneo.php';

use Fvd\Modulos\Asociaciones\Modelos\AdminAsociacion;
use Fvd\Modulos\Asociaciones\Modelos\LogoUpload;
use Fvd\Modulos\Asociaciones\Modelos\OrganizacionFvd;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

$pdo = admin_guard_pdo();

try {
    $entidad = isset($_POST['entidad']) ? trim((string) $_POST['entidad']) : '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Indique id válido.']);
        exit;
    }

    if ($entidad === 'organizacion') {
        AdminPolicy::assertOrganizacionWrite();
        if (!isset($_FILES['logo'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Adjunte el archivo en el campo logo.']);
            exit;
        }
        if (OrganizacionFvd::obtenerPorId($pdo, $id) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Organización no encontrada.']);
            exit;
        }
        $rel = LogoUpload::guardar($_FILES['logo'], 'uploads/organizacion', 'org_' . $id);
        echo json_encode(['ok' => true, 'path' => $rel, 'field' => 'logo', 'message' => 'Archivo subido.']);
        exit;
    }

    if ($entidad === 'asociacion') {
        AdminPolicy::assertAsociacionWrite();
        if (!isset($_FILES['logo'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Adjunte el archivo en el campo logo.']);
            exit;
        }
        if (AdminAsociacion::obtener($pdo, $id) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Asociación no encontrada.']);
            exit;
        }
        $rel = LogoUpload::guardar($_FILES['logo'], 'uploads/asociaciones', 'aso_' . $id);
        echo json_encode(['ok' => true, 'path' => $rel, 'field' => 'logo', 'message' => 'Archivo subido.']);
        exit;
    }

    if ($entidad === 'usuario') {
        AdminPolicy::assertUsuarioWrite();
        $campo = isset($_POST['campo']) ? trim((string) $_POST['campo']) : '';
        if (!in_array($campo, ['foto', 'cedula'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'campo debe ser foto o cedula.']);
            exit;
        }
        if (!isset($_FILES['archivo'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Adjunte archivo (archivo).']);
            exit;
        }
        if (AdminUsuario::obtener($pdo, $id) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Usuario no encontrado.']);
            exit;
        }
        $rel = LogoUpload::guardar($_FILES['archivo'], 'uploads/usuarios', 'usr_' . $id . '_' . $campo);
        $field = $campo === 'foto' ? 'urlimgfoto' : 'urlimgcedula';
        echo json_encode(['ok' => true, 'path' => $rel, 'field' => $field, 'message' => 'Imagen subida.']);
        exit;
    }

    if ($entidad === 'torneo') {
        AdminPolicy::assertTorneoWrite();
        $campo = isset($_POST['campo']) ? trim((string) $_POST['campo']) : '';
        if (!in_array($campo, ['afiche', 'invitacion'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'campo debe ser afiche o invitacion.']);
            exit;
        }
        if (!isset($_FILES['archivo'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Adjunte archivo (archivo).']);
            exit;
        }
        if (AdminTorneo::obtener($pdo, $id) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Torneo no encontrado.']);
            exit;
        }
        if ($campo === 'afiche') {
            $rel = LogoUpload::guardar($_FILES['archivo'], 'uploads/torneos', 'tor_' . $id . '_afiche');
        } else {
            $rel = LogoUpload::guardarInvitacionTorneo($_FILES['archivo'], 'uploads/torneos', 'tor_' . $id . '_inv');
        }
        echo json_encode(['ok' => true, 'path' => $rel, 'field' => $campo, 'message' => 'Archivo subido.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Entidad no válida (organizacion|asociacion|usuario|torneo).']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('upload_panel_asset: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
