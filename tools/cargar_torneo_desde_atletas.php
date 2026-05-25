<?php



declare(strict_types=1);



/**

 * Carga `movimiento_torneo` + solicitudes FVD desde `atletas` (equivalente a altas delegadas).

 *

 * Uso (desde la raíz del proyecto):

 *   php tools/cargar_torneo_desde_atletas.php --dry-run

 *   php tools/cargar_torneo_desde_atletas.php 6

 *   php tools/cargar_torneo_desde_atletas.php 6 --asoc=1

 *   php tools/cargar_torneo_desde_atletas.php --torneo=6 --sin-deuda
 *   php tools/cargar_torneo_desde_atletas.php 1 --force   (torneo finalizado: solo mantenimiento)

 *

 * Sin torneo_id usa el torneo activo (sin finalizar).

 */



$root = dirname(__DIR__);

chdir($root);



require_once $root . '/Database.php';

require_once $root . '/app/Autoload.php';

\Fvd\Autoload::register();

require_once $root . '/app/CargaTorneoDesdeAtletas.php';

require_once $root . '/app/InscripcionTorneo.php';

require_once $root . '/app/TorneoMovimientoLock.php';



$dryRun = in_array('--dry-run', $argv, true);

$sinDeuda = in_array('--sin-deuda', $argv, true);

$todosAtletas = in_array('--todos', $argv, true);

$force = in_array('--force', $argv, true);

$provisionarUsuarios = in_array('--provisionar-usuarios', $argv, true);



$torneoId = 0;

$asocFiltro = 0;

foreach ($argv as $arg) {

    if (preg_match('/^--torneo=(\d+)$/', $arg, $m)) {

        $torneoId = (int) $m[1];

    } elseif (preg_match('/^--asoc=(\d+)$/', $arg, $m)) {

        $asocFiltro = (int) $m[1];

    } elseif (preg_match('/^\d+$/', $arg)) {

        $torneoId = (int) $arg;

    }

}



$db = new Database();

$pdo = $db->getConnection();

if ($pdo === null) {

    fwrite(STDERR, "No hay conexión a MySQL.\n");

    exit(1);

}



if ($torneoId < 1) {
    require_once $root . '/app/InformeFvd.php';
    $torneoId = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, null) ?? 0;
    if ($torneoId < 1) {
        fwrite(STDERR, "No hay torneo con movimiento. Indique: php tools/cargar_torneo_desde_atletas.php 1\n");
        exit(1);
    }
    $tf = InformeFvd::torneoFilaPorId($pdo, $torneoId);
    echo "Torneo nómina (auto): {$torneoId} — " . ($tf['nombre'] ?? '') . "\n";
    $activo = InscripcionTorneo::torneoActivo($pdo);
    $aid = $activo !== null ? (int) ($activo['torneo'] ?? 0) : 0;
    if ($aid > 0 && $aid !== $torneoId) {
        echo "AVISO: torneo activo={$aid} (" . ($activo['nombre'] ?? '') . "); nómina legacy en torneo {$torneoId}.\n";
    }
} else {
    require_once $root . '/app/InformeFvd.php';
    $tf = InformeFvd::torneoFilaPorId($pdo, $torneoId);
    echo "Torneo indicado: {$torneoId} — " . ($tf['nombre'] ?? '') . "\n";
}



if ($dryRun) {

    echo "[DRY-RUN] No se escribirá en la base de datos.\n";

}



try {

    if ($force && !$dryRun) {
        echo "[FORCE] Se omite bloqueo por torneo finalizado / límite de nómina.\n";
    }

    $stats = CargaTorneoDesdeAtletas::ejecutar($pdo, [

        'torneo_id' => $torneoId,

        'dry_run' => $dryRun,

        'solo_asociacion_id' => $asocFiltro > 0 ? $asocFiltro : null,

        'solo_con_indicadores' => !$todosAtletas,

        'recalcular_deuda' => !$sinDeuda && !$dryRun,

        'crear_solicitudes_auditoria' => true,

        'omitir_bloqueo_torneo' => $force,

        'provisionar_usuarios_faltantes' => $provisionarUsuarios,

    ]);

} catch (Throwable $e) {

    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

    exit(1);

}



echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(0);

