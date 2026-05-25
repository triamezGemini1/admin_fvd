<?php

declare(strict_types=1);

namespace Fvd\Modulos\Atletas\Modelos;

/**
 * Proceso de conversión atletas → usuarios + movimiento_torneo.
 *
 * La carga masiva de datos se hace con el SQL:
 *   sql/migrations/002_migrate_atletas_a_usuarios_y_movimiento_torneo.sql
 *
 * Sincronizar tridente en `movimiento_torneo` desde `atletas` (solo `numfvd`):
 *   Rutina auditable: {@see SyncMovimientoTorneoTridenteDesdeAtletas::ejecutar()}
 *   SQL masivo (referencia): sql/migrations/007_sync_movimiento_torneo_tridente_desde_atletas.sql
 *   {@see self::syncTridenteMovimientoTorneoDesdeAtletas()} o
 *   {@see self::estadisticasSyncTridenteMovimientoTorneoDesdeAtletas()} (solo lectura).
 *
 * Carga completa de torneo (movimiento + solicitudes FVD + deuda), equivalente a altas delegadas:
 *   {@see CargaTorneoDesdeAtletas::ejecutar()} — CLI: `php tools/cargar_torneo_desde_atletas.php`
 *
 * Esta clase ofrece validaciones previas y constantes de operación desde PHP.
 */
class MigracionAtletasAUsuarios
{
    /** Contraseña inicial asignada en el script SQL 002 (bcrypt embebido). */
    public const PASSWORD_INICIAL_MIGRACION = 'MigracionFVD2026!';

    /** Hash bcrypt de {@see PASSWORD_INICIAL_MIGRACION} (mismo valor que sql/migrations/002). */
    private const PASSWORD_HASH_MIGRACION = '$2y$10$B6phliqgjw4gkwcmt1fV4u6b6Ptpid.iCS1Jgik/Gk/YUK.qpVvyC';

    /**
     * Detecta cédulas repetidas en atletas (impiden cumplir UNIQUE en usuarios.cedula).
     * El script 002 migra solo el atleta con menor `id` por cada cédula; el resto queda pendiente.
     *
     * @return list<array{cedula:string,cnt:int}>
     */
    public static function cedulasDuplicadasEnAtletas(\PDO $pdo): array
    {
        $sql = 'SELECT TRIM(cedula) AS cedula, COUNT(*) AS cnt
                FROM atletas
                GROUP BY TRIM(cedula)
                HAVING cnt > 1
                ORDER BY cnt DESC';

        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{numfvd:int,cnt:int}>
     */
    public static function numfvdDuplicadosEnAtletas(\PDO $pdo): array
    {
        return SyncMovimientoTorneoTridenteDesdeAtletas::numfvdDuplicadosEnAtletas($pdo);
    }

    /**
     * Resumen de conteos para comparar tras ejecutar el SQL 002.
     *
     * @return array{atletas:int,usuarios_migracion:int,movimiento_torneo:int,usuarios_total:int}
     */
    public static function resumenPostMigracion(\PDO $pdo): array
    {
        $atletas = (int) $pdo->query('SELECT COUNT(*) FROM atletas')->fetchColumn();
        $usuariosMigracion = (int) $pdo->query(
            "SELECT COUNT(*) FROM usuarios WHERE email LIKE '%@migracion.fvd.local'"
        )->fetchColumn();
        $mov = (int) $pdo->query('SELECT COUNT(*) FROM movimiento_torneo')->fetchColumn();
        $usuariosTotal = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

        return [
            'atletas' => $atletas,
            'usuarios_migracion' => $usuariosMigracion,
            'movimiento_torneo' => $mov,
            'usuarios_total' => $usuariosTotal,
        ];
    }

    /**
     * Indica si el entorno parece listo para ejecutar el script 002 (sin ejecutarlo).
     */
    public static function puedeMigrar(\PDO $pdo): array
    {
        $dupCed = self::cedulasDuplicadasEnAtletas($pdo);
        $dupNum = self::numfvdDuplicadosEnAtletas($pdo);

        return [
            'ok' => count($dupCed) === 0,
            'cedulas_duplicadas' => $dupCed,
            'numfvd_duplicados' => $dupNum,
            'mensaje' => count($dupCed) > 0
                ? 'Hay cédulas duplicadas: el SQL 002 solo crea usuario para MIN(id) por cédula. Corrige atletas si necesitas 1:1 distinto.'
                : 'Sin cédulas duplicadas; revisar numfvd duplicados si afectan negocio.',
        ];
    }

    /**
     * Estadística (dry-run de la rutina): leídos, seleccionados, pendientes de escritura, etc.
     *
     * @return array<string, mixed>
     */
    public static function estadisticasSyncTridenteMovimientoTorneoDesdeAtletas(\PDO $pdo): array
    {
        $det = SyncMovimientoTorneoTridenteDesdeAtletas::ejecutar($pdo, true, true);

        return [
            'atletas_filas_criterio' => $det['atletas_filas_criterio'],
            'atletas_cedulas_distintas_criterio' => $det['atletas_cedulas_distintas_criterio'],
            'numfvd_coincidentes_distintos' => $det['seleccionados_distintos_numfvd'],
            'filas_movimiento_torneo_coincidentes' => $det['seleccionados'],
            'detalle_rutina' => $det,
        ];
    }

    /**
     * Ejecuta la rutina de sync y devuelve estadísticas completas + claves resumidas.
     *
     * @return array<string, mixed>
     */
    /**
     * Crea filas en `usuarios` para atletas canónicos con indicadores que aún no tienen cuenta portal.
     * Necesario para que {@see CargaTorneoDesdeAtletas} pueda volcar flags a `movimiento_torneo`.
     *
     * @return array{dry_run: bool, insertados: int, omitidos_ya_existian: int}
     */
    /**
     * Corrige cuentas creadas con email atleta.{id}@migracion cuya cédula/numfvd no coincide con el atleta canónico.
     *
     * @return array{dry_run: bool, actualizados: int}
     */
    public static function repararUsuariosMigracionDesdeAtletas(\PDO $pdo, bool $dryRun = false): array
    {
        $cedNormA = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('a.`cedula`');
        $cedNormU = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('u.`cedula`');
        $sql = 'SELECT u.`id` AS user_id, a.`id` AS atleta_id, TRIM(a.`cedula`) AS cedula, a.`numfvd`, a.`sexo`,
                       TRIM(a.`nombre`) AS nombre, a.`fechnac`, ax.`id` AS asociacion_id
                FROM `usuarios` u
                INNER JOIN `atletas` a ON u.`email` = CONCAT(\'atleta.\', a.`id`, \'@migracion.fvd.local\')
                LEFT JOIN `asociaciones` ax ON ax.`id` = a.`asociacion`
                WHERE ' . $cedNormU . ' <> ' . $cedNormA . '
                   OR (a.`numfvd` > 0 AND (u.`numfvd` IS NULL OR u.`numfvd` <> a.`numfvd`))';
        $st = $pdo->query($sql);
        $filas = $st === false ? [] : $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($dryRun || $filas === []) {
            return ['dry_run' => $dryRun, 'actualizados' => 0, 'pendientes' => count($filas)];
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE `usuarios` SET `cedula` = :cedula, `numfvd` = :numfvd, `sexo` = :sexo, `nombre` = :nombre,
                    `fechnac` = :fechnac, `asociacion_id` = :asociacion_id WHERE `id` = :id'
            );
            foreach ($filas as $r) {
                $sexo = (int) ($r['sexo'] ?? 0);
                if ($sexo !== 1 && $sexo !== 2) {
                    $sexo = 0;
                }
                $stmt->execute([
                    ':cedula' => (string) ($r['cedula'] ?? ''),
                    ':numfvd' => (int) ($r['numfvd'] ?? 0),
                    ':sexo' => $sexo,
                    ':nombre' => (string) ($r['nombre'] ?? ''),
                    ':fechnac' => $r['fechnac'] ?? null,
                    ':asociacion_id' => (($asoc = (int) ($r['asociacion_id'] ?? 0)) > 0) ? $asoc : null,
                    ':id' => (int) ($r['user_id'] ?? 0),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['dry_run' => false, 'actualizados' => count($filas), 'pendientes' => count($filas)];
    }

    public static function provisionarUsuariosFaltantesDesdeAtletas(
        \PDO $pdo,
        bool $soloConIndicadores = true,
        bool $dryRun = false
    ): array {
        if (!$dryRun) {
            self::repararUsuariosMigracionDesdeAtletas($pdo, false);
        }

        $wInd = $soloConIndicadores ? ' AND ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('a') : '';
        $cedNormA = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('a.`cedula`');
        $cedNormU = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('u.`cedula`');

        $sqlSel = 'SELECT a.`id`, a.`numfvd`, TRIM(a.`cedula`) AS cedula, a.`sexo`, TRIM(a.`nombre`) AS nombre,
                a.`fechnac`, a.`celular`, a.`categ`, a.`foto`, a.`cedula_img`, ax.`id` AS asociacion_id
            FROM `atletas` a
            INNER JOIN (
                SELECT MIN(`id`) AS `min_id` FROM `atletas` GROUP BY ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('`cedula`') . '
            ) canon ON canon.`min_id` = a.`id`
            LEFT JOIN `asociaciones` ax ON ax.`id` = a.`asociacion`
            WHERE TRIM(a.`cedula`) <> ""' . $wInd . '
              AND NOT EXISTS (
                SELECT 1 FROM `usuarios` u
                WHERE ' . $cedNormU . ' = ' . $cedNormA . '
                   OR (a.`numfvd` > 0 AND u.`numfvd` = a.`numfvd`)
                   OR u.`email` = CONCAT(\'atleta.\', a.`id`, \'@migracion.fvd.local\')
              )';

        $stSel = $pdo->query($sqlSel);
        $filas = $stSel === false ? [] : $stSel->fetchAll(\PDO::FETCH_ASSOC);
        $n = count($filas);
        if ($dryRun || $n === 0) {
            return ['dry_run' => $dryRun, 'insertados' => 0, 'pendientes' => $n];
        }

        $insertados = 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO `usuarios` (
                    `numfvd`, `cedula`, `sexo`, `nombre`, `fechnac`, `email`, `celular`, `username`,
                    `password_hash`, `role`, `status`, `asociacion_id`, `posirnk`, `urlimgfoto`, `urlimgcedula`
                ) VALUES (
                    :numfvd, :cedula, :sexo, :nombre, :fechnac, :email, :celular, :username,
                    :password_hash, :role, :status, :asociacion_id, :posirnk, :urlimgfoto, :urlimgcedula
                )'
            );
            foreach ($filas as $a) {
                $atletaId = (int) ($a['id'] ?? 0);
                $numfvd = (int) ($a['numfvd'] ?? 0);
                $cedula = trim((string) ($a['cedula'] ?? ''));
                $username = substr('userfvd' . $numfvd . '_' . $atletaId, 0, 60);
                $categ = (int) ($a['categ'] ?? 0);
                $posirnk = ($categ > 0 && $categ < 9999) ? $categ : 0;
                $sexo = (int) ($a['sexo'] ?? 0);
                if ($sexo !== 1 && $sexo !== 2) {
                    $sexo = 0;
                }
                $stmt->execute([
                    ':numfvd' => $numfvd,
                    ':cedula' => $cedula,
                    ':sexo' => $sexo,
                    ':nombre' => (string) ($a['nombre'] ?? 'Atleta FVD'),
                    ':fechnac' => $a['fechnac'] ?? null,
                    ':email' => 'atleta.' . $atletaId . '@migracion.fvd.local',
                    ':celular' => ($c = trim((string) ($a['celular'] ?? ''))) !== '' ? substr($c, 0, 20) : null,
                    ':username' => $username,
                    ':password_hash' => self::PASSWORD_HASH_MIGRACION,
                    ':role' => 'usuario',
                    ':status' => 9,
                    ':asociacion_id' => ($asoc = (int) ($a['asociacion_id'] ?? 0)) > 0 ? $asoc : null,
                    ':posirnk' => $posirnk,
                    ':urlimgfoto' => ($f = trim((string) ($a['foto'] ?? ''))) !== '' ? $f : null,
                    ':urlimgcedula' => ($ci = trim((string) ($a['cedula_img'] ?? ''))) !== '' ? $ci : null,
                ]);
                $insertados++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['dry_run' => false, 'insertados' => $insertados, 'pendientes' => $n];
    }

    public static function syncTridenteMovimientoTorneoDesdeAtletas(\PDO $pdo): array
    {
        $det = SyncMovimientoTorneoTridenteDesdeAtletas::ejecutar($pdo, true, false);

        return [
            'atletas_filas_criterio' => $det['atletas_filas_criterio'],
            'atletas_cedulas_distintas_criterio' => $det['atletas_cedulas_distintas_criterio'],
            'numfvd_coincidentes_distintos' => $det['seleccionados_distintos_numfvd'],
            'filas_movimiento_torneo_coincidentes' => $det['seleccionados'],
            'filas_actualizadas' => $det['actualizados'],
            'detalle_rutina' => $det,
        ];
    }
}
