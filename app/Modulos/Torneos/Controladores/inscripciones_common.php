<?php



declare(strict_types=1);



require_once __DIR__ . '/../../bootstrap_modulo.php';

require_once FVD_ROOT . '/Database.php';

require_once FVD_ROOT . '/app/Auth.php';

require_once FVD_ROOT . '/app/AdminPolicy.php';



use Fvd\Modulos\Asociaciones\Modelos\AdminAsociacion;
use Fvd\Modulos\Delegados\Modelos\DelegadoMovimientoTorneo;
use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;



/**

 * Guard JSON del módulo inscripciones sin cerrar sesión antes de leer Auth::asociacionId().

 */

function inscripciones_guard_pdo(): \PDO

{

    header('Content-Type: application/json; charset=UTF-8');



    if (session_status() === PHP_SESSION_NONE) {

        session_start();

    }



    if (!Auth::check()) {

        http_response_code(401);

        echo json_encode(['ok' => false, 'message' => 'Debe iniciar sesión.']);

        exit;

    }

    if (!AdminPolicy::puedeAccederPanel()) {

        http_response_code(403);

        echo json_encode(['ok' => false, 'message' => 'Sin acceso al panel de administración.']);

        exit;

    }



    $db = new Database();

    $pdo = $db->getConnection();

    if ($pdo === null) {

        http_response_code(503);

        echo json_encode(['ok' => false, 'message' => 'Servicio no disponible']);

        exit;

    }



    return $pdo;

}



/**

 * @return array{id: int, nombre: string, logo: string, delegado: string}|null

 */

function inscripciones_resolver_asociacion(\PDO $pdo, ?int $asocId = null): ?array

{

    if ($asocId === null) {

        $asocId = Auth::asociacionId();

    }

    if ($asocId === null || $asocId < 1) {

        return null;

    }



    $fila = AdminAsociacion::obtener($pdo, $asocId);

    $nombre = '';

    $logo = '';

    $delegado = '';

    $numreg = '';
    if (is_array($fila)) {

        $nombre = trim((string) ($fila['nombre'] ?? ''));

        $logo = trim((string) ($fila['logo'] ?? ''));

        $numreg = trim((string) ($fila['numreg'] ?? ''));

    }

    $delegado = inscripciones_nombre_delegado($pdo, $asocId, is_array($fila) ? ($fila['delegado'] ?? '') : '');



    return [

        'id' => $asocId,

        'codigo' => $asocId,

        'numreg' => $numreg,

        'nombre' => $nombre !== '' ? $nombre : 'Asociación #' . $asocId,

        'logo' => $logo,

        'delegado' => $delegado,

    ];

}

/**
 * Nombre del delegado desde usuarios con asociacion_id = entidad activa.
 */
function inscripciones_nombre_delegado(\PDO $pdo, int $asocId, $rawDelegadoCampo): string
{
    $stmt = $pdo->prepare(
        'SELECT nombre FROM usuarios
         WHERE asociacion_id = :aid AND role = :rol AND status IN (1, 9)
         ORDER BY id ASC LIMIT 1'
    );
    $stmt->bindValue(':aid', $asocId, \PDO::PARAM_INT);
    $stmt->bindValue(':rol', 'delegado', \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row !== false) {
        $n = trim((string) ($row['nombre'] ?? ''));
        if ($n !== '') {
            return $n;
        }
    }

    $raw = trim((string) $rawDelegadoCampo);
    if ($raw === '' || $raw === '0' || $raw === '0.0') {
        return '';
    }
    if (preg_match('/^\d+$/', $raw) === 1) {
        $st = $pdo->prepare('SELECT nombre FROM usuarios WHERE cedula = :c OR numfvd = :n LIMIT 1');
        $st->bindValue(':c', $raw, \PDO::PARAM_STR);
        $st->bindValue(':n', (int) $raw, \PDO::PARAM_INT);
        $st->execute();
        $u = $st->fetch(\PDO::FETCH_ASSOC);
        if ($u !== false) {
            $n = trim((string) ($u['nombre'] ?? ''));
            if ($n !== '') {
                return $n;
            }
        }
    }

    return $raw;
}

/**
 * Torneo de la barra de jornada (activo para inscripciones).
 *
 * @return array<string, mixed>|null
 */
function inscripciones_resolver_torneo_jornada(\PDO $pdo, ?int $preferTorneoId = null): ?array
{
    $candidato = $preferTorneoId ?? 0;
    if ($candidato < 1 && isset($_GET['torneo_id'])) {
        $candidato = (int) $_GET['torneo_id'];
    }

    return DelegadoMovimientoTorneo::resolverTorneoJornada($pdo, $candidato > 0 ? $candidato : null, true);
}



/**

 * Asociación activa del usuario en sesión (delegado / operador con asociación asignada).

 *

 * @return array{pdo: PDO, asociacion_id: int, asociacion: array{id: int, nombre: string, logo: string, delegado: string}}

 */

function inscripciones_init_asociacion_activa(): array

{

    $pdo = inscripciones_guard_pdo();

    $asociacion = inscripciones_resolver_asociacion($pdo);

    if ($asociacion === null) {

        http_response_code(403);

        echo json_encode([

            'ok' => false,

            'message' => 'Debe ingresar con una asociación asignada para gestionar inscripciones.',

        ]);

        exit;

    }



    return [

        'pdo' => $pdo,

        'asociacion_id' => $asociacion['id'],

        'asociacion' => $asociacion,

    ];

}

