<?php

declare(strict_types=1);

namespace Fvd\Modulos\Atletas\Modelos;

use Fvd\Modulos\Delegados\Modelos\FvdSolicitudesDelegado;
use Fvd\Modulos\Finanzas\Modelos\DeudaAsociaciones;
use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoContadores;
use Fvd\Modulos\Informes\Modelos\InformeFvd;
use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;
use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;

/**
 * Carga masiva de `movimiento_torneo` (y solicitudes `fvd_solicitudes_delegado`) desde `atletas`,
 * en condiciones equivalentes al flujo delegado: flags reales del atleta, cola de supervisión FVD
 * y recálculo de `deuda_asociaciones`.
 *
 * No sustituye a {@see SyncMovimientoTorneoTridenteDesdeAtletas} (solo actualiza filas existentes por numfvd).
 *
 * La tabla `atletas` no tiene columna `movimiento`: el movimiento se deduce de los indicadores
 * afiliación, anualidad, carnet, traspaso e inscripción ({@see SyncMovimientoTorneoTridenteDesdeAtletas}).
 */
class CargaTorneoDesdeAtletas
{
    private const T_A = 'atletas';

    private const T_M = 'movimiento_torneo';

    private const T_U = 'usuarios';

    private const T_ASOC = 'asociaciones';

    private const NOTA_ORIGEN = 'carga_desde_atletas';

    /**
     * @param array{
     *   torneo_id?: int,
     *   dry_run?: bool,
     *   solo_asociacion_id?: int|null,
     *   solo_con_indicadores?: bool,
     *   crear_solicitudes_auditoria?: bool,
     *   recalcular_deuda?: bool,
     *   delegado_user_id?: int|null,
     *   omitir_bloqueo_torneo?: bool,
     *   eliminar_existentes_antes?: bool,
     *   provisionar_usuarios_faltantes?: bool
     * } $opciones
     *
     * @return array<string, mixed>
     */
    public static function ejecutar(\PDO $pdo, array $opciones = []): array
    {
        $dryRun = (bool) ($opciones['dry_run'] ?? false);
        $soloAsoc = isset($opciones['solo_asociacion_id']) ? (int) $opciones['solo_asociacion_id'] : 0;
        $soloInd = (bool) ($opciones['solo_con_indicadores'] ?? true);
        $crearSol = (bool) ($opciones['crear_solicitudes_auditoria'] ?? true);
        $recalcDeuda = (bool) ($opciones['recalcular_deuda'] ?? true);
        $delegadoId = isset($opciones['delegado_user_id']) ? (int) $opciones['delegado_user_id'] : null;
        if ($delegadoId !== null && $delegadoId < 1) {
            $delegadoId = null;
        }

        $torneoId = isset($opciones['torneo_id']) ? (int) $opciones['torneo_id'] : 0;
        if ($torneoId < 1) {
            throw new \InvalidArgumentException('Debe indicar torneo_id: seleccione el torneo o variante del campeonato a procesar.');
        }

        $statsProvision = null;
        if ($opciones['provisionar_usuarios_faltantes'] ?? false) {
            $statsProvision = MigracionAtletasAUsuarios::provisionarUsuariosFaltantesDesdeAtletas(
                $pdo,
                $soloInd,
                $dryRun
            );
        }

        $metaTorneo = TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId);
        $filtroAtleta = $metaTorneo['filtro_atleta'] ?? null;
        $fechatorRef = $metaTorneo['fechator'] ?? null;

        if (!$dryRun && !($opciones['omitir_bloqueo_torneo'] ?? false)) {
            TorneoMovimientoLock::assertEdicionPermitida($pdo, $torneoId);
        }

        $filas = self::filasAtletasCanonConUsuario($pdo, $soloInd, $soloAsoc);
        $stats = [
            'torneo_id' => $torneoId,
            'dry_run' => $dryRun,
            'leidos_atletas_canon' => count($filas),
            'procesados' => 0,
            'movimiento_insertados' => 0,
            'movimiento_actualizados' => 0,
            'movimiento_sin_cambio' => 0,
            'solicitudes_afiliacion' => 0,
            'solicitudes_carnet' => 0,
            'solicitudes_traspaso' => 0,
            'descartados' => [
                'sin_usuario' => 0,
                'sin_asociacion' => 0,
                'cedula_vacia' => 0,
                'no_coincide_torneo' => 0,
            ],
            'meta_torneo' => $metaTorneo,
            'deuda_asociaciones_recalculadas' => 0,
            'movimiento_eliminados' => 0,
            'renglones_volcados' => [
                'n_afiliacion' => 0,
                'n_anualidad' => 0,
                'n_carnet' => 0,
                'n_traspaso' => 0,
                'n_inscripcion' => 0,
            ],
            'fvd_solicitudes_tabla' => FvdSolicitudesDelegado::tablaDisponible($pdo),
            'provision_usuarios' => $statsProvision,
        ];

        if ($dryRun) {
            $mapExistente = self::mapMovimientoExistentePorUsuarioEnTorneo($pdo, $torneoId);
            foreach ($filas as $row) {
                if (!TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $filtroAtleta, $fechatorRef)) {
                    $stats['descartados']['no_coincide_torneo']++;

                    continue;
                }
                $prep = self::prepararFilaMovimiento($row, $torneoId);
                if ($prep === null) {
                    self::contarDescarte($stats, $row, $filtroAtleta);

                    continue;
                }
                $stats['procesados']++;
                self::acumularRenglonesVolcados($stats, $prep);
                $uid = (int) $prep['user_id'];
                $accion = self::simularAccionMovimiento($mapExistente[$uid] ?? null, $prep);
                if ($accion === 'insert') {
                    $stats['movimiento_insertados']++;
                } elseif ($accion === 'update') {
                    $stats['movimiento_actualizados']++;
                } else {
                    $stats['movimiento_sin_cambio']++;
                }
                if ($crearSol && FvdSolicitudesDelegado::tablaDisponible($pdo)) {
                    $stats['solicitudes_afiliacion'] += self::contariaSolicitudAfiliacion($pdo, $prep);
                    $stats['solicitudes_carnet'] += self::contariaSolicitudCarnet($pdo, $prep);
                    $stats['solicitudes_traspaso'] += self::contariaSolicitudTraspaso($pdo, $prep);
                }
            }

            return $stats;
        }

        $pdo->beginTransaction();
        try {
            if ($opciones['eliminar_existentes_antes'] ?? false) {
                $stats['movimiento_eliminados'] = self::eliminarMovimientoTorneoPorTorneo($pdo, $torneoId);
            }
            foreach ($filas as $row) {
                if (!TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $filtroAtleta, $fechatorRef)) {
                    $stats['descartados']['no_coincide_torneo']++;

                    continue;
                }
                $prep = self::prepararFilaMovimiento($row, $torneoId);
                if ($prep === null) {
                    self::contarDescarte($stats, $row, $filtroAtleta);

                    continue;
                }
                $stats['procesados']++;
                self::acumularRenglonesVolcados($stats, $prep);
                $movId = self::upsertMovimiento($pdo, $torneoId, $prep);
                if ($movId['accion'] === 'insert') {
                    $stats['movimiento_insertados']++;
                } elseif ($movId['accion'] === 'update') {
                    $stats['movimiento_actualizados']++;
                } else {
                    $stats['movimiento_sin_cambio']++;
                }
                if ($crearSol && FvdSolicitudesDelegado::tablaDisponible($pdo)) {
                    $stats['solicitudes_afiliacion'] += self::insertarSolicitudAfiliacionSiCorresponde(
                        $pdo,
                        $torneoId,
                        $prep,
                        $movId['id'],
                        $delegadoId
                    );
                    $stats['solicitudes_carnet'] += self::insertarSolicitudCarnetSiCorresponde(
                        $pdo,
                        $torneoId,
                        $prep,
                        $movId['id'],
                        $delegadoId
                    );
                    $stats['solicitudes_traspaso'] += self::insertarSolicitudTraspasoSiCorresponde(
                        $pdo,
                        $torneoId,
                        $prep,
                        $movId['id'],
                        $delegadoId
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($recalcDeuda && DeudaAsociaciones::tablaDisponible($pdo)) {
            $stats['deuda_asociaciones_recalculadas'] = DeudaAsociaciones::recalcularTorneoCompleto($pdo, $torneoId);
        }

        return $stats;
    }

    /**
     * Evalúa `atletas` antes de regenerar la nómina: contadores por renglón, simulación y filas existentes en el torneo.
     *
     * @param array{solo_asociacion_id?: int|null, solo_con_indicadores?: bool} $opciones
     *
     * @return array<string, mixed>
     */
    public static function evaluarPreCarga(\PDO $pdo, int $torneoId, array $opciones = []): array
    {
        if ($torneoId < 1) {
            throw new \InvalidArgumentException('torneo_id inválido.');
        }
        $soloAsoc = isset($opciones['solo_asociacion_id']) ? (int) $opciones['solo_asociacion_id'] : 0;
        $soloInd = (bool) ($opciones['solo_con_indicadores'] ?? true);

        $metaTorneo = TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId);
        $filtroAtleta = $metaTorneo['filtro_atleta'] ?? null;
        $fechatorRef = $metaTorneo['fechator'] ?? null;
        $torneoJson = [
            'torneo_id' => $metaTorneo['torneo_id'],
            'nombre' => $metaTorneo['nombre'],
            'clavetor' => $metaTorneo['clavetor'],
            'fechator' => $metaTorneo['fechator'],
            'finalizado_en' => $metaTorneo['finalizado_en'],
            'grupo_evento_id' => $metaTorneo['grupo_evento_id'],
            'es_campeonato' => $metaTorneo['es_campeonato'],
            'modo_campeonato' => $metaTorneo['modo_campeonato'],
            'variante_etiqueta' => $metaTorneo['variante_etiqueta'],
        ];

        $filas = self::filasAtletasCanonConUsuario($pdo, $soloInd, $soloAsoc);
        $mapExistente = self::mapMovimientoExistentePorUsuarioEnTorneo($pdo, $torneoId);
        $descartados = [
            'sin_usuario' => 0,
            'sin_asociacion' => 0,
            'cedula_vacia' => 0,
            'no_coincide_torneo' => 0,
        ];
        $renglonesAtletas = [
            'n_afiliacion' => 0,
            'n_anualidad' => 0,
            'n_carnet' => 0,
            'n_traspaso' => 0,
            'n_inscripcion' => 0,
        ];
        $procesables = 0;
        $simInsert = 0;
        $simUpdate = 0;
        $simSinCambio = 0;
        foreach ($filas as $row) {
            if (!TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $filtroAtleta, $fechatorRef)) {
                $descartados['no_coincide_torneo']++;

                continue;
            }
            $prep = self::prepararFilaMovimiento($row, $torneoId);
            if ($prep === null) {
                if (trim((string) ($row['cedula'] ?? '')) === '') {
                    $descartados['cedula_vacia']++;
                } elseif ((int) ($row['user_id'] ?? 0) < 1) {
                    $descartados['sin_usuario']++;
                } else {
                    $descartados['sin_asociacion']++;
                }

                continue;
            }
            $procesables++;
            $uid = (int) $prep['user_id'];
            $accionSim = self::simularAccionMovimiento($mapExistente[$uid] ?? null, $prep);
            if ($accionSim === 'insert') {
                $simInsert++;
            } elseif ($accionSim === 'update') {
                $simUpdate++;
            } else {
                $simSinCambio++;
            }
            if ((int) ($prep['afiliacion'] ?? 0) === 1) {
                $renglonesAtletas['n_afiliacion']++;
            }
            if ((int) ($prep['anualidad'] ?? 0) === 1) {
                $renglonesAtletas['n_anualidad']++;
            }
            if ((int) ($prep['carnet'] ?? 0) === 1) {
                $renglonesAtletas['n_carnet']++;
            }
            if ((int) ($prep['traspaso'] ?? 0) === 1) {
                $renglonesAtletas['n_traspaso']++;
            }
            if ((int) ($prep['inscripcion'] ?? 0) === 1) {
                $renglonesAtletas['n_inscripcion']++;
            }
        }

        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $mCalc = MovimientoTorneoContadores::montosPorTarifaYContadores($renglonesAtletas, $units);
        $montosAtletas = [
            'monto_afiliacion_eur' => round((float) ($mCalc['afiliacion'] ?? 0), 2),
            'monto_anualidad_eur' => round((float) ($mCalc['anualidad'] ?? 0), 2),
            'monto_carnet_eur' => round((float) ($mCalc['carnet'] ?? 0), 2),
            'monto_traspaso_eur' => round((float) ($mCalc['traspaso'] ?? 0), 2),
            'monto_inscripcion_eur' => round((float) ($mCalc['inscripcion'] ?? 0), 2),
        ];

        $existente = self::resumenMovimientoTorneoPorTorneo($pdo, $torneoId);

        $filaTotales = array_merge($renglonesAtletas, $montosAtletas);

        $refTabla = SyncMovimientoTorneoTridenteDesdeAtletas::conteosTablaAtletas($pdo, true);
        $atletasSinUsuario = self::conteoAtletasConIndicadoresSinUsuario($pdo, $soloInd);

        return [
            'torneo' => $torneoJson,
            'meta_procesamiento' => $metaTorneo,
            'bloqueo' => TorneoMovimientoLock::estado($pdo, $torneoId),
            'referencia_tabla_atletas' => $refTabla,
            'atletas_con_indicador_sin_usuario_portal' => $atletasSinUsuario,
            'atletas' => [
                'leidos_canon' => count($filas),
                'procesables' => $procesables,
                'descartados' => $descartados,
                'renglones' => $renglonesAtletas,
                'montos_eur' => $montosAtletas,
            ],
            'movimiento_torneo_existente' => $existente,
            'simulacion_sin_borrar' => [
                'movimiento_insertados' => $simInsert,
                'movimiento_actualizados' => $simUpdate,
                'movimiento_sin_cambio' => $simSinCambio,
            ],
            'totales_renglones' => InformeFvd::totalesRenglonesConsolidado([$filaTotales]),
            'tarifa_unitaria_eur' => $units,
        ];
    }

    /**
     * @return array{filas: int, renglones: array<string, int>}
     */
    public static function resumenMovimientoTorneoPorTorneo(\PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS filas, ' . MovimientoTorneoContadores::sqlSelectAgregados('m') . '
             FROM `' . self::T_M . '` m WHERE m.`torneo_id` = :t'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            return [
                'filas' => 0,
                'renglones' => [
                    'n_afiliacion' => 0,
                    'n_anualidad' => 0,
                    'n_carnet' => 0,
                    'n_traspaso' => 0,
                    'n_inscripcion' => 0,
                ],
            ];
        }

        return [
            'filas' => (int) ($r['filas'] ?? 0),
            'renglones' => [
                'n_afiliacion' => (int) ($r['n_afiliacion'] ?? 0),
                'n_anualidad' => (int) ($r['n_anualidad'] ?? 0),
                'n_carnet' => (int) ($r['n_carnet'] ?? 0),
                'n_traspaso' => (int) ($r['n_traspaso'] ?? 0),
                'n_inscripcion' => (int) ($r['n_inscripcion'] ?? 0),
            ],
        ];
    }

    public static function eliminarMovimientoTorneoPorTorneo(\PDO $pdo, int $torneoId): int
    {
        if ($torneoId < 1) {
            return 0;
        }
        $st = $pdo->prepare('DELETE FROM `' . self::T_M . '` WHERE `torneo_id` = :t');
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();

        return $st->rowCount();
    }

    /**
     * @param array{
     *   torneo_id: int,
     *   omitir_bloqueo_torneo?: bool,
     *   solo_asociacion_id?: int|null,
     *   solo_con_indicadores?: bool,
     *   recalcular_deuda?: bool,
     *   crear_solicitudes_auditoria?: bool
     * } $opciones
     *
     * @return array<string, mixed>
     */
    public static function regenerarDesdeAtletas(\PDO $pdo, array $opciones): array
    {
        $torneoId = (int) ($opciones['torneo_id'] ?? 0);
        if ($torneoId < 1) {
            throw new \InvalidArgumentException('torneo_id inválido.');
        }

        $recalcCuentas = (bool) ($opciones['recalcular_deuda'] ?? true);
        $tInicio = microtime(true);

        $resumen = [
            'torneo_id' => $torneoId,
            'criterio_filas_atletas' => 'Solo atletas con afiliación, anualidad, carnet, traspaso o inscripción activos (1 o legacy 5/20/6).',
            'referencia_previa' => self::precheckRegeneracion($pdo, $torneoId),
        ];

        $tFase1 = microtime(true);
        $fase1 = self::ejecutar($pdo, array_merge($opciones, [
            'torneo_id' => $torneoId,
            'dry_run' => false,
            'eliminar_existentes_antes' => true,
            'solo_con_indicadores' => true,
            'crear_solicitudes_auditoria' => (bool) ($opciones['crear_solicitudes_auditoria'] ?? false),
            'recalcular_deuda' => false,
            'provisionar_usuarios_faltantes' => (bool) ($opciones['provisionar_usuarios_faltantes'] ?? true),
        ]));
        $fase1['duracion_ms'] = (int) round((microtime(true) - $tFase1) * 1000);

        $meta = TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId);
        $gid = (int) ($meta['grupo_evento_id'] ?? 0);
        if ($gid > 0 && ($meta['modo_campeonato'] ?? '') === TorneoCampeonato::MODO_GENERO) {
            $fase1['limpieza_genero_cruzado'] = TorneoCampeonato::limpiarMovimientoGeneroCruzadoEnGrupo($pdo, $gid);
        }

        $resumen['fase_1_nomina_movimiento_torneo'] = $fase1;

        $fase2 = null;
        if ($recalcCuentas) {
            $tFase2 = microtime(true);
            $fase2 = self::recalcularCuentasTorneo($pdo, $torneoId);
            $fase2['duracion_ms'] = (int) round((microtime(true) - $tFase2) * 1000);
            $resumen['fase_2_cuentas_asociaciones'] = $fase2;
        }

        $resumen['validacion'] = self::construirValidacionRegeneracion($pdo, $torneoId, $fase1, $fase2);
        $resumen['duracion_total_ms'] = (int) round((microtime(true) - $tInicio) * 1000);
        $resumen['mensaje'] = self::mensajeResumenRegeneracion($resumen);

        return array_merge($fase1, [
            'resumen_regeneracion' => $resumen,
            'deuda_asociaciones_recalculadas' => $fase2['asociaciones_recalculadas'] ?? 0,
        ]);
    }

    /**
     * Precheck rápido (SQL): referencia en `atletas` con indicadores y nómina existente, sin simular fila a fila.
     *
     * @return array<string, mixed>
     */
    public static function precheckRegeneracion(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            throw new \InvalidArgumentException('torneo_id inválido.');
        }

        return [
            'torneo_id' => $torneoId,
            'meta_procesamiento' => TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId),
            'bloqueo' => TorneoMovimientoLock::estado($pdo, $torneoId),
            'referencia_tabla_atletas' => SyncMovimientoTorneoTridenteDesdeAtletas::conteosTablaAtletas($pdo, true),
            'atletas_con_indicador_sin_usuario_portal' => self::conteoAtletasConIndicadoresSinUsuario($pdo, true),
            'atletas_canon_con_usuario_e_indicador' => self::conteoCanonConUsuarioConIndicadores($pdo),
            'movimiento_torneo_antes' => self::resumenMovimientoTorneoPorTorneo($pdo, $torneoId),
        ];
    }

    /**
     * Fase 2: recalcula `deuda_asociaciones` solo para asociaciones con filas en el torneo (cuenta por asociación + total torneo).
     *
     * @return array<string, mixed>
     */
    public static function recalcularCuentasTorneo(\PDO $pdo, int $torneoId): array
    {
        $mov = self::resumenMovimientoTorneoPorTorneo($pdo, $torneoId);
        if (!DeudaAsociaciones::tablaDisponible($pdo)) {
            return [
                'tabla_deuda_disponible' => false,
                'asociaciones_recalculadas' => 0,
                'deuda_torneo_total_eur' => 0.0,
                'filas_movimiento_torneo' => $mov['filas'],
                'renglones_movimiento_torneo' => $mov['renglones'],
                'cuenta_general_torneo' => ['nomina_eur' => 0.0, 'nota' => 'Tabla deuda_asociaciones no disponible.'],
            ];
        }

        $n = DeudaAsociaciones::recalcularTorneoCompleto($pdo, $torneoId);
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_total_eur), 0) AS tot_eur,
                    COALESCE(SUM(monto_total), 0) AS tot_bs,
                    COUNT(*) AS filas_deuda
             FROM `deuda_asociaciones` WHERE `torneo_id` = :t'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $d = $st->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'tabla_deuda_disponible' => true,
            'asociaciones_recalculadas' => $n,
            'filas_deuda_asociaciones' => (int) ($d['filas_deuda'] ?? 0),
            'deuda_torneo_total_eur' => round((float) ($d['tot_eur'] ?? 0), 2),
            'deuda_torneo_total_bs' => round((float) ($d['tot_bs'] ?? 0), 2),
            'filas_movimiento_torneo' => $mov['filas'],
            'renglones_movimiento_torneo' => $mov['renglones'],
            'cuenta_general_torneo' => [
                'nomina_eur' => round((float) ($d['tot_eur'] ?? 0), 2),
                'nota' => 'Suma de monto_total_eur en deuda_asociaciones para este torneo (todas las asociaciones con nómina).',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $resumen
     */
    public static function mensajeResumenRegeneracion(array $resumen): string
    {
        $f1 = $resumen['fase_1_nomina_movimiento_torneo'] ?? [];
        $f2 = $resumen['fase_2_cuentas_asociaciones'] ?? null;
        $val = $resumen['validacion'] ?? [];
        $tid = (int) ($resumen['torneo_id'] ?? 0);
        $partes = [
            'Torneo #' . $tid . ':',
            (int) ($f1['movimiento_eliminados'] ?? 0) . ' filas eliminadas,',
            (int) ($f1['movimiento_insertados'] ?? 0) . ' insertadas,',
            (int) ($f1['procesados'] ?? 0) . ' atletas procesados.',
        ];
        if ($f2 !== null) {
            $partes[] = 'Cuentas: ' . (int) ($f2['asociaciones_recalculadas'] ?? 0) . ' asociación(es), total '
                . ($f2['deuda_torneo_total_eur'] ?? 0) . ' €.';
        }
        if (!empty($val['coincide_renglones_nomina_vs_movimiento'])) {
            $partes[] = 'Validación: renglones nómina coinciden con movimiento_torneo.';
        } elseif (isset($val['coincide_renglones_nomina_vs_movimiento'])) {
            $partes[] = 'Validación: revise discrepancias en renglones (ver resumen).';
        }

        return implode(' ', $partes);
    }

    /**
     * @param array<string, mixed> $fase1
     * @param array<string, mixed>|null $fase2
     *
     * @return array<string, mixed>
     */
    private static function construirValidacionRegeneracion(
        \PDO $pdo,
        int $torneoId,
        array $fase1,
        ?array $fase2
    ): array {
        $mov = self::resumenMovimientoTorneoPorTorneo($pdo, $torneoId);
        $refAtletas = SyncMovimientoTorneoTridenteDesdeAtletas::conteosTablaAtletas($pdo, true);
        $volcados = $fase1['renglones_volcados'] ?? [];
        $renMov = $mov['renglones'];
        $campos = ['n_afiliacion', 'n_anualidad', 'n_carnet', 'n_traspaso', 'n_inscripcion'];
        $comparacion = [];
        foreach ($campos as $c) {
            $vVol = (int) ($volcados[$c] ?? 0);
            $vMov = (int) ($renMov[$c] ?? 0);
            $vRef = (int) ($refAtletas[$c] ?? 0);
            $comparacion[$c] = [
                'atletas_tabla_referencia' => $vRef,
                'nomina_volcada_torneo' => $vVol,
                'movimiento_torneo' => $vMov,
                'coincide_volcado_vs_movimiento' => $vVol === $vMov,
            ];
        }

        $coincide = true;
        foreach ($campos as $c) {
            if ((int) ($volcados[$c] ?? 0) !== (int) ($renMov[$c] ?? 0)) {
                $coincide = false;
                break;
            }
        }

        return [
            'referencia_atletas_con_indicador' => $refAtletas,
            'movimiento_torneo_post_regeneracion' => $mov,
            'renglones_por_comparacion' => $comparacion,
            'coincide_renglones_nomina_vs_movimiento' => $coincide,
            'filas_movimiento_vs_procesados' => [
                'filas_movimiento_torneo' => (int) ($mov['filas'] ?? 0),
                'atletas_procesados' => (int) ($fase1['procesados'] ?? 0),
                'coincide_filas' => (int) ($mov['filas'] ?? 0) === (int) ($fase1['procesados'] ?? 0),
            ],
            'fase_2_cuentas' => $fase2,
            'nota_torneo' => 'Los conteos de «atletas_tabla_referencia» son globales (canónico con indicador). '
                . 'La nómina del torneo solo incluye atletas que aplican al filtro género/categoría del torneo.',
        ];
    }

    /**
     * Atletas canónicos con indicador activo y usuario en portal (sin filtrar por torneo).
     */
    public static function conteoCanonConUsuarioConIndicadores(\PDO $pdo): int
    {
        $wInd = SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('a');
        $cedNormA = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('a.`cedula`');
        $cedNormU = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('u.`cedula`');
        $st = $pdo->query(
            'SELECT COUNT(DISTINCT u.`id`) AS n
             FROM `' . self::T_A . '` a
             INNER JOIN (
                SELECT MIN(`id`) AS `min_id` FROM `' . self::T_A . '` GROUP BY '
                . SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('`cedula`') . '
             ) canon ON canon.`min_id` = a.`id`
             INNER JOIN `' . self::T_U . '` u ON (
                ' . $cedNormU . ' = ' . $cedNormA . '
                OR (a.`numfvd` > 0 AND u.`numfvd` = a.`numfvd`)
                OR u.`email` = CONCAT(\'atleta.\', a.`id`, \'@migracion.fvd.local\')
             )
             WHERE TRIM(a.`cedula`) <> "" AND ' . $wInd
        );
        if ($st === false) {
            return 0;
        }
        $r = $st->fetch(\PDO::FETCH_ASSOC);

        return (int) ($r['n'] ?? 0);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $prep
     */
    /**
     * @return array{n_afiliacion: int, n_anualidad: int, n_carnet: int, n_traspaso: int, n_inscripcion: int}
     */
    private static function renglonesVolcadosVacios(): array
    {
        return [
            'n_afiliacion' => 0,
            'n_anualidad' => 0,
            'n_carnet' => 0,
            'n_traspaso' => 0,
            'n_inscripcion' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $prep
     */
    private static function acumularRenglonesVolcados(array &$stats, array $prep): void
    {
        self::acumularRenglonesEnContenedor($stats, 'renglones_volcados', $prep);
    }

    /**
     * @param array<string, mixed> $contenedor
     * @param array<string, mixed> $prep
     */
    private static function acumularRenglonesEnContenedor(array &$contenedor, string $claveRenglones, array $prep): void
    {
        if (!isset($contenedor[$claveRenglones]) || !is_array($contenedor[$claveRenglones])) {
            $contenedor[$claveRenglones] = self::renglonesVolcadosVacios();
        }
        foreach (['afiliacion' => 'n_afiliacion', 'anualidad' => 'n_anualidad', 'carnet' => 'n_carnet', 'traspaso' => 'n_traspaso', 'inscripcion' => 'n_inscripcion'] as $campo => $clave) {
            if ((int) ($prep[$campo] ?? 0) === 1) {
                $contenedor[$claveRenglones][$clave]++;
            }
        }
    }

    /**
     * Fase 2 homologada: recalcula deuda solo en torneos con nómina.
     *
     * @param list<int> $torneoIds
     *
     * @return array<string, mixed>
     */
    public static function recalcularCuentasVariosTorneos(\PDO $pdo, array $torneoIds): array
    {
        $porTorneo = [];
        $totalAsoc = 0;
        $totalEur = 0.0;
        $totalBs = 0.0;
        foreach ($torneoIds as $tid) {
            $tid = (int) $tid;
            if ($tid < 1) {
                continue;
            }
            $f2 = self::recalcularCuentasTorneo($pdo, $tid);
            $porTorneo[$tid] = $f2;
            $totalAsoc += (int) ($f2['asociaciones_recalculadas'] ?? 0);
            $totalEur += (float) ($f2['deuda_torneo_total_eur'] ?? 0);
            $totalBs += (float) ($f2['deuda_torneo_total_bs'] ?? 0);
        }

        return [
            'asociaciones_recalculadas' => $totalAsoc,
            'deuda_total_eur' => round($totalEur, 2),
            'deuda_total_bs' => round($totalBs, 2),
            'por_torneo' => $porTorneo,
            'cuenta_general_campeonato' => [
                'nomina_eur' => round($totalEur, 2),
                'nota' => 'Suma de nómina (deuda_asociaciones) de todos los torneos del campeonato procesados.',
            ],
        ];
    }

    /**
     * Precheck SQL para regeneración de campeonato (género o categoría).
     *
     * @return array<string, mixed>
     */
    public static function precheckRegeneracionGrupo(\PDO $pdo, int $grupoEventoId, string $modoCampeonato): array
    {
        if ($grupoEventoId < 1) {
            throw new \InvalidArgumentException('grupo_evento_id inválido.');
        }
        $torneos = [];
        if ($modoCampeonato === TorneoCampeonato::MODO_GENERO) {
            foreach (TorneoCampeonato::mapTorneosIdPorSexoEnGrupo($pdo, $grupoEventoId) as $clave => $tid) {
                $torneos[(string) $clave] = array_merge(
                    ['clave' => $clave, 'torneo_id' => $tid],
                    self::precheckRegeneracion($pdo, $tid)
                );
            }
        } elseif ($modoCampeonato === TorneoCampeonato::MODO_CATEGORIA) {
            foreach (TorneoCampeonato::mapTorneosIdPorCategoriaEnGrupo($pdo, $grupoEventoId) as $lim => $tid) {
                $torneos['sub' . $lim] = array_merge(
                    ['clave' => 'sub' . $lim, 'categoria_limite' => $lim, 'torneo_id' => $tid],
                    self::precheckRegeneracion($pdo, $tid)
                );
            }
        }

        return [
            'grupo_evento_id' => $grupoEventoId,
            'modo_campeonato' => $modoCampeonato,
            'referencia_tabla_atletas' => SyncMovimientoTorneoTridenteDesdeAtletas::conteosTablaAtletas($pdo, true),
            'atletas_con_indicador_sin_usuario_portal' => self::conteoAtletasConIndicadoresSinUsuario($pdo, true),
            'atletas_canon_con_usuario_e_indicador' => self::conteoCanonConUsuarioConIndicadores($pdo),
            'torneos' => $torneos,
        ];
    }

    /**
     * @param array<string, mixed> $fase1 resultado de distribución (fase 1)
     *
     * @return array<string, mixed>
     */
    private static function construirValidacionRegeneracionGrupo(\PDO $pdo, array $fase1): array
    {
        $detalle = $fase1['detalle_por_torneo'] ?? [];
        $validacionTorneos = [];
        $todoOk = true;
        foreach ($detalle as $tid => $bloque) {
            $tid = (int) $tid;
            if ($tid < 1) {
                continue;
            }
            $mov = self::resumenMovimientoTorneoPorTorneo($pdo, $tid);
            $volcados = is_array($bloque['renglones'] ?? null) ? $bloque['renglones'] : self::renglonesVolcadosVacios();
            $coincide = true;
            foreach (['n_afiliacion', 'n_anualidad', 'n_carnet', 'n_traspaso', 'n_inscripcion'] as $c) {
                if ((int) ($volcados[$c] ?? 0) !== (int) ($mov['renglones'][$c] ?? 0)) {
                    $coincide = false;
                    $todoOk = false;
                }
            }
            $proc = (int) ($bloque['procesados'] ?? 0);
            $validacionTorneos[$tid] = [
                'procesados' => $proc,
                'filas_movimiento_torneo' => (int) ($mov['filas'] ?? 0),
                'coincide_filas' => $proc === (int) ($mov['filas'] ?? 0),
                'renglones_volcados' => $volcados,
                'renglones_movimiento_torneo' => $mov['renglones'],
                'coincide_renglones' => $coincide,
            ];
        }

        return [
            'referencia_atletas_con_indicador' => SyncMovimientoTorneoTridenteDesdeAtletas::conteosTablaAtletas($pdo, true),
            'renglones_volcados_campeonato' => $fase1['renglones_volcados'] ?? self::renglonesVolcadosVacios(),
            'por_torneo' => $validacionTorneos,
            'coincide_todos_los_torneos' => $todoOk,
            'nota' => 'Los conteos globales de atletas incluyen todos los indicadores; cada torneo del campeonato solo recibe atletas que aplican a su filtro.',
        ];
    }

    /**
     * @param array<string, mixed> $resumen
     */
    public static function mensajeResumenRegeneracionGrupo(array $resumen): string
    {
        $f1 = $resumen['fase_1_nomina'] ?? [];
        $f2 = $resumen['fase_2_cuentas'] ?? null;
        $gid = (int) ($resumen['grupo_evento_id'] ?? 0);
        $modo = (string) ($resumen['modo_campeonato'] ?? '');
        $partes = [
            'Campeonato #' . $gid . ' (' . $modo . '):',
            (int) ($f1['movimiento_eliminados'] ?? 0) . ' filas eliminadas,',
            (int) ($f1['movimiento_insertados'] ?? 0) . ' insertadas.',
        ];
        if ($f2 !== null) {
            $partes[] = (int) ($f2['asociaciones_recalculadas'] ?? 0) . ' asoc. recalculadas, total '
                . ($f2['deuda_total_eur'] ?? 0) . ' €.';
        }
        if (!empty($resumen['validacion']['coincide_todos_los_torneos'])) {
            $partes[] = 'Validación OK en todos los torneos del grupo.';
        }

        return implode(' ', $partes);
    }

    /**
     * Resumen ligero por torneo (sin recorrer atletas) para evaluación en UI.
     *
     * @return array<string, mixed>
     */
    public static function evaluacionTorneoResumenLigero(\PDO $pdo, int $torneoId): array
    {
        $meta = TorneoCampeonato::metaProcesamientoTorneo($pdo, $torneoId);
        $precheck = self::precheckRegeneracion($pdo, $torneoId);

        return [
            'torneo' => [
                'torneo_id' => $meta['torneo_id'],
                'nombre' => $meta['nombre'],
                'variante_etiqueta' => $meta['variante_etiqueta'],
                'grupo_evento_id' => $meta['grupo_evento_id'],
            ],
            'meta_procesamiento' => $meta,
            'referencia_tabla_atletas' => $precheck['referencia_tabla_atletas'],
            'atletas_con_indicador_sin_usuario_portal' => $precheck['atletas_con_indicador_sin_usuario_portal'],
            'atletas' => [
                'leidos_canon' => (int) ($precheck['atletas_canon_con_usuario_e_indicador'] ?? 0),
                'procesables' => null,
                'descartados' => [],
            ],
            'movimiento_torneo_existente' => $precheck['movimiento_torneo_antes'],
            'bloqueo' => $precheck['bloqueo'],
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $prep
     */
    private static function registrarProcesadoEnTorneo(array &$stats, int $torneoId, array $prep): void
    {
        if (!isset($stats['detalle_por_torneo']) || !is_array($stats['detalle_por_torneo'])) {
            $stats['detalle_por_torneo'] = [];
        }
        if (!isset($stats['detalle_por_torneo'][$torneoId])) {
            $stats['detalle_por_torneo'][$torneoId] = [
                'procesados' => 0,
                'renglones' => self::renglonesVolcadosVacios(),
            ];
        }
        $stats['detalle_por_torneo'][$torneoId]['procesados']++;
        self::acumularRenglonesEnContenedor($stats['detalle_por_torneo'][$torneoId], 'renglones', $prep);
    }

    /**
     * Evalúa distribución automática M/F para un campeonato por género.
     *
     * @param array{solo_asociacion_id?: int|null, solo_con_indicadores?: bool} $opciones
     *
     * @return array<string, mixed>
     */
    public static function evaluarCampeonatoPorGenero(\PDO $pdo, int $grupoEventoId, array $opciones = []): array
    {
        $opciones = array_merge(['solo_con_indicadores' => true], $opciones);
        $dist = self::distribuirNominaCampeonatoPorGenero($pdo, $grupoEventoId, $opciones, true);
        $map = $dist['torneos_por_sexo'] ?? [];
        $evalM = isset($map[1]) ? self::evaluacionTorneoResumenLigero($pdo, (int) $map[1]) : null;
        $evalF = isset($map[2]) ? self::evaluacionTorneoResumenLigero($pdo, (int) $map[2]) : null;
        if ($evalM !== null) {
            $evalM['atletas']['procesables'] = (int) ($dist['asignados_masculino'] ?? 0);
        }
        if ($evalF !== null) {
            $evalF['atletas']['procesables'] = (int) ($dist['asignados_femenino'] ?? 0);
        }

        return [
            'grupo_evento_id' => $grupoEventoId,
            'modo_campeonato' => TorneoCampeonato::MODO_GENERO,
            'torneos_por_sexo' => $map,
            'precheck' => self::precheckRegeneracionGrupo($pdo, $grupoEventoId, TorneoCampeonato::MODO_GENERO),
            'distribucion_automatica' => $dist,
            'masculino' => $evalM,
            'femenino' => $evalF,
        ];
    }

    /**
     * Regenera nómina del campeonato (fase 1 distribución M/F, fase 2 cuentas).
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public static function regenerarCampeonatoPorGenero(\PDO $pdo, int $grupoEventoId, array $opciones = []): array
    {
        return self::regenerarCampeonatoGrupo($pdo, $grupoEventoId, TorneoCampeonato::MODO_GENERO, $opciones);
    }

    /**
     * Evalúa distribución automática Sub 12 / 15 / 18.
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public static function evaluarCampeonatoPorCategoria(\PDO $pdo, int $grupoEventoId, array $opciones = []): array
    {
        $opciones = array_merge(['solo_con_indicadores' => true], $opciones);
        $dist = self::distribuirNominaCampeonatoPorCategoria($pdo, $grupoEventoId, $opciones, true);
        $map = $dist['torneos_por_categoria'] ?? [];
        $detalle = [];
        foreach ($map as $lim => $tid) {
            $ev = self::evaluacionTorneoResumenLigero($pdo, (int) $tid);
            $ev['atletas']['procesables'] = (int) ($dist['asignados_por_categoria'][$lim] ?? 0);
            $detalle['sub' . $lim] = $ev;
        }

        return [
            'grupo_evento_id' => $grupoEventoId,
            'modo_campeonato' => TorneoCampeonato::MODO_CATEGORIA,
            'torneos_por_categoria' => $map,
            'precheck' => self::precheckRegeneracionGrupo($pdo, $grupoEventoId, TorneoCampeonato::MODO_CATEGORIA),
            'distribucion_automatica' => $dist,
            'por_categoria' => $detalle,
        ];
    }

    /**
     * Regenera campeonato por categoría (fase 1 + fase 2 homologadas).
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public static function regenerarCampeonatoPorCategoria(\PDO $pdo, int $grupoEventoId, array $opciones = []): array
    {
        return self::regenerarCampeonatoGrupo($pdo, $grupoEventoId, TorneoCampeonato::MODO_CATEGORIA, $opciones);
    }

    /**
     * Regeneración homologada de campeonato (género o categoría).
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public static function regenerarCampeonatoGrupo(
        \PDO $pdo,
        int $grupoEventoId,
        string $modoCampeonato,
        array $opciones = []
    ): array {
        if ($grupoEventoId < 1) {
            throw new \InvalidArgumentException('grupo_evento_id inválido.');
        }
        $recalcCuentas = (bool) ($opciones['recalcular_deuda'] ?? true);
        $tInicio = microtime(true);

        $resumen = [
            'grupo_evento_id' => $grupoEventoId,
            'modo_campeonato' => $modoCampeonato,
            'criterio_filas_atletas' => 'Solo atletas con indicadores activos (1 o legacy 5/20/6).',
            'referencia_previa' => self::precheckRegeneracionGrupo($pdo, $grupoEventoId, $modoCampeonato),
        ];

        if ($opciones['provisionar_usuarios_faltantes'] ?? false) {
            $resumen['provision_usuarios'] = MigracionAtletasAUsuarios::provisionarUsuariosFaltantesDesdeAtletas(
                $pdo,
                true,
                false
            );
        }

        $opcionesFase1 = array_merge($opciones, [
            'solo_con_indicadores' => true,
            'recalcular_deuda' => false,
            'crear_solicitudes_auditoria' => (bool) ($opciones['crear_solicitudes_auditoria'] ?? false),
        ]);

        $tFase1 = microtime(true);
        if ($modoCampeonato === TorneoCampeonato::MODO_GENERO) {
            $fase1 = self::distribuirNominaCampeonatoPorGenero($pdo, $grupoEventoId, $opcionesFase1, false);
            if ($grupoEventoId > 0) {
                $fase1['limpieza_genero_cruzado'] = TorneoCampeonato::limpiarMovimientoGeneroCruzadoEnGrupo($pdo, $grupoEventoId);
            }
        } elseif ($modoCampeonato === TorneoCampeonato::MODO_CATEGORIA) {
            $fase1 = self::distribuirNominaCampeonatoPorCategoria($pdo, $grupoEventoId, $opcionesFase1, false);
        } else {
            throw new \InvalidArgumentException('Modo de campeonato no soportado para regeneración grupal.');
        }
        $fase1['duracion_ms'] = (int) round((microtime(true) - $tFase1) * 1000);
        $resumen['fase_1_nomina'] = $fase1;

        $fase2 = null;
        if ($recalcCuentas) {
            $tFase2 = microtime(true);
            $ids = [];
            if ($modoCampeonato === TorneoCampeonato::MODO_GENERO) {
                $ids = array_values(array_filter(array_map('intval', $fase1['torneos_por_sexo'] ?? [])));
            } else {
                $ids = array_values(array_filter(array_map('intval', $fase1['torneos_por_categoria'] ?? [])));
            }
            $fase2 = self::recalcularCuentasVariosTorneos($pdo, $ids);
            $fase2['duracion_ms'] = (int) round((microtime(true) - $tFase2) * 1000);
            $resumen['fase_2_cuentas'] = $fase2;
            $fase1['deuda_asociaciones_recalculadas'] = (int) ($fase2['asociaciones_recalculadas'] ?? 0);
        }

        $resumen['validacion'] = self::construirValidacionRegeneracionGrupo($pdo, $fase1);
        $resumen['duracion_total_ms'] = (int) round((microtime(true) - $tInicio) * 1000);
        $resumen['mensaje'] = self::mensajeResumenRegeneracionGrupo($resumen);

        return array_merge($fase1, [
            'resumen_regeneracion' => $resumen,
            'resultado_distribucion' => $fase1,
        ]);
    }

    /**
     * Una pasada sobre atletas: asigna `movimiento_torneo` al torneo masculino o femenino del grupo.
     *
     * @param array{
     *   omitir_bloqueo_torneo?: bool,
     *   solo_asociacion_id?: int|null,
     *   solo_con_indicadores?: bool,
     *   recalcular_deuda?: bool,
     *   crear_solicitudes_auditoria?: bool
     * } $opciones
     *
     * @return array<string, mixed>
     */
    public static function distribuirNominaCampeonatoPorGenero(
        \PDO $pdo,
        int $grupoEventoId,
        array $opciones = [],
        bool $dryRun = false
    ): array {
        if ($grupoEventoId < 1) {
            throw new \InvalidArgumentException('grupo_evento_id inválido.');
        }
        if (TorneoCampeonato::detectarModoGrupo($pdo, $grupoEventoId) !== TorneoCampeonato::MODO_GENERO) {
            throw new \InvalidArgumentException('El grupo no es un campeonato por género (Masculino + Femenino).');
        }
        $mapSexo = TorneoCampeonato::mapTorneosIdPorSexoEnGrupo($pdo, $grupoEventoId);
        $tidM = (int) ($mapSexo[1] ?? 0);
        $tidF = (int) ($mapSexo[2] ?? 0);
        if ($tidM < 1 || $tidF < 1) {
            throw new \InvalidArgumentException('El campeonato debe tener torneo masculino (tipo 1) y femenino (tipo 2).');
        }

        $soloAsoc = isset($opciones['solo_asociacion_id']) ? (int) $opciones['solo_asociacion_id'] : 0;
        $soloInd = (bool) ($opciones['solo_con_indicadores'] ?? true);
        $crearSol = (bool) ($opciones['crear_solicitudes_auditoria'] ?? false);
        $recalcDeuda = (bool) ($opciones['recalcular_deuda'] ?? true);
        $delegadoId = isset($opciones['delegado_user_id']) ? (int) $opciones['delegado_user_id'] : null;
        if ($delegadoId !== null && $delegadoId < 1) {
            $delegadoId = null;
        }

        $metaM = TorneoCampeonato::metaProcesamientoTorneo($pdo, $tidM);
        $metaF = TorneoCampeonato::metaProcesamientoTorneo($pdo, $tidF);
        $filtroM = $metaM['filtro_atleta'] ?? null;
        $filtroF = $metaF['filtro_atleta'] ?? null;
        $fechM = $metaM['fechator'] ?? null;
        $fechF = $metaF['fechator'] ?? null;

        if (!$dryRun && !($opciones['omitir_bloqueo_torneo'] ?? false)) {
            TorneoMovimientoLock::assertEdicionPermitida($pdo, $tidM);
            TorneoMovimientoLock::assertEdicionPermitida($pdo, $tidF);
        }

        $filas = self::filasAtletasCanonConUsuario($pdo, $soloInd, $soloAsoc);
        $stats = [
            'grupo_evento_id' => $grupoEventoId,
            'dry_run' => $dryRun,
            'torneos_por_sexo' => [1 => $tidM, 2 => $tidF],
            'leidos_atletas_canon' => count($filas),
            'asignados_masculino' => 0,
            'asignados_femenino' => 0,
            'sin_sexo_definido' => 0,
            'descartados' => [
                'sin_usuario' => 0,
                'sin_asociacion' => 0,
                'cedula_vacia' => 0,
                'no_aplica_filtro_torneo' => 0,
            ],
            'movimiento_insertados' => 0,
            'movimiento_actualizados' => 0,
            'movimiento_sin_cambio' => 0,
            'movimiento_eliminados' => 0,
            'deuda_asociaciones_recalculadas' => 0,
            'renglones_volcados' => self::renglonesVolcadosVacios(),
            'detalle_por_torneo' => [],
        ];

        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                $stats['movimiento_eliminados'] = self::eliminarMovimientoTorneoPorTorneo($pdo, $tidM)
                    + self::eliminarMovimientoTorneoPorTorneo($pdo, $tidF);
                self::procesarFilasDistribucionGenero(
                    $pdo,
                    $filas,
                    $tidM,
                    $tidF,
                    $filtroM,
                    $filtroF,
                    $fechM,
                    $fechF,
                    $crearSol,
                    $delegadoId,
                    $stats
                );
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            if ($recalcDeuda && DeudaAsociaciones::tablaDisponible($pdo)) {
                $stats['deuda_asociaciones_recalculadas'] = DeudaAsociaciones::recalcularTorneoCompleto($pdo, $tidM)
                    + DeudaAsociaciones::recalcularTorneoCompleto($pdo, $tidF);
            }
            $stats['limpieza_genero_cruzado'] = TorneoCampeonato::limpiarMovimientoGeneroCruzadoEnGrupo($pdo, $grupoEventoId);
        } else {
            $mapExM = self::mapMovimientoExistentePorUsuarioEnTorneo($pdo, $tidM);
            $mapExF = self::mapMovimientoExistentePorUsuarioEnTorneo($pdo, $tidF);
            self::procesarFilasDistribucionGeneroDry(
                $filas,
                $tidM,
                $tidF,
                $filtroM,
                $filtroF,
                $fechM,
                $fechF,
                $mapExM,
                $mapExF,
                $stats
            );
        }

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @param array<string, mixed> $stats
     */
    private static function procesarFilasDistribucionGenero(
        \PDO $pdo,
        array $filas,
        int $tidM,
        int $tidF,
        ?array $filtroM,
        ?array $filtroF,
        ?string $fechM,
        ?string $fechF,
        bool $crearSol,
        ?int $delegadoId,
        array &$stats
    ): void {
        foreach ($filas as $row) {
            $sexo = TorneoCampeonato::sexoAtletaDesdeFila($row);
            if ($sexo === 1) {
                $tid = $tidM;
                $filtro = $filtroM;
                $fech = $fechM;
            } elseif ($sexo === 2) {
                $tid = $tidF;
                $filtro = $filtroF;
                $fech = $fechF;
            } else {
                $stats['sin_sexo_definido']++;

                continue;
            }
            if (!TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $filtro, $fech)) {
                $stats['descartados']['no_aplica_filtro_torneo']++;

                continue;
            }
            $prep = self::prepararFilaMovimiento($row, $tid);
            if ($prep === null) {
                if (trim((string) ($row['cedula'] ?? '')) === '') {
                    $stats['descartados']['cedula_vacia']++;
                } elseif ((int) ($row['user_id'] ?? 0) < 1) {
                    $stats['descartados']['sin_usuario']++;
                } else {
                    $stats['descartados']['sin_asociacion']++;
                }

                continue;
            }
            if ($sexo === 1) {
                $stats['asignados_masculino']++;
            } else {
                $stats['asignados_femenino']++;
            }
            self::acumularRenglonesVolcados($stats, $prep);
            self::registrarProcesadoEnTorneo($stats, $tid, $prep);
            $movId = self::upsertMovimiento($pdo, $tid, $prep);
            if ($movId['accion'] === 'insert') {
                $stats['movimiento_insertados']++;
            } elseif ($movId['accion'] === 'update') {
                $stats['movimiento_actualizados']++;
            } else {
                $stats['movimiento_sin_cambio']++;
            }
            if ($crearSol && FvdSolicitudesDelegado::tablaDisponible($pdo)) {
                self::insertarSolicitudAfiliacionSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
                self::insertarSolicitudCarnetSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
                self::insertarSolicitudTraspasoSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @param array<int, array<string, mixed>> $mapExM
     * @param array<int, array<string, mixed>> $mapExF
     * @param array<string, mixed> $stats
     */
    private static function procesarFilasDistribucionGeneroDry(
        array $filas,
        int $tidM,
        int $tidF,
        ?array $filtroM,
        ?array $filtroF,
        ?string $fechM,
        ?string $fechF,
        array $mapExM,
        array $mapExF,
        array &$stats
    ): void {
        foreach ($filas as $row) {
            $sexo = TorneoCampeonato::sexoAtletaDesdeFila($row);
            if ($sexo === 1) {
                $tid = $tidM;
                $filtro = $filtroM;
                $fech = $fechM;
                $mapEx = $mapExM;
            } elseif ($sexo === 2) {
                $tid = $tidF;
                $filtro = $filtroF;
                $fech = $fechF;
                $mapEx = $mapExF;
            } else {
                $stats['sin_sexo_definido']++;

                continue;
            }
            if (!TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $filtro, $fech)) {
                $stats['descartados']['no_aplica_filtro_torneo']++;

                continue;
            }
            $prep = self::prepararFilaMovimiento($row, $tid);
            if ($prep === null) {
                if (trim((string) ($row['cedula'] ?? '')) === '') {
                    $stats['descartados']['cedula_vacia']++;
                } elseif ((int) ($row['user_id'] ?? 0) < 1) {
                    $stats['descartados']['sin_usuario']++;
                } else {
                    $stats['descartados']['sin_asociacion']++;
                }

                continue;
            }
            if ($sexo === 1) {
                $stats['asignados_masculino']++;
            } else {
                $stats['asignados_femenino']++;
            }
            self::acumularRenglonesVolcados($stats, $prep);
            self::registrarProcesadoEnTorneo($stats, $tid, $prep);
            $uid = (int) $prep['user_id'];
            $accion = self::simularAccionMovimiento($mapEx[$uid] ?? null, $prep);
            if ($accion === 'insert') {
                $stats['movimiento_insertados']++;
            } elseif ($accion === 'update') {
                $stats['movimiento_actualizados']++;
            } else {
                $stats['movimiento_sin_cambio']++;
            }
        }
    }

    /**
     * Distribuye nómina a torneos Sub 12 / 15 / 18 según categoría/edad del atleta.
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    public static function distribuirNominaCampeonatoPorCategoria(
        \PDO $pdo,
        int $grupoEventoId,
        array $opciones = [],
        bool $dryRun = false
    ): array {
        if ($grupoEventoId < 1) {
            throw new \InvalidArgumentException('grupo_evento_id inválido.');
        }
        if (TorneoCampeonato::detectarModoGrupo($pdo, $grupoEventoId) !== TorneoCampeonato::MODO_CATEGORIA) {
            throw new \InvalidArgumentException('El grupo no es un campeonato por categoría.');
        }
        $mapCat = TorneoCampeonato::mapTorneosIdPorCategoriaEnGrupo($pdo, $grupoEventoId);
        if ($mapCat === []) {
            throw new \InvalidArgumentException('El campeonato debe tener torneos Sub 12, Sub 15 y/o Sub 18.');
        }

        $soloAsoc = isset($opciones['solo_asociacion_id']) ? (int) $opciones['solo_asociacion_id'] : 0;
        $soloInd = (bool) ($opciones['solo_con_indicadores'] ?? true);
        $crearSol = (bool) ($opciones['crear_solicitudes_auditoria'] ?? false);
        $recalcDeuda = (bool) ($opciones['recalcular_deuda'] ?? true);
        $delegadoId = isset($opciones['delegado_user_id']) ? (int) $opciones['delegado_user_id'] : null;
        if ($delegadoId !== null && $delegadoId < 1) {
            $delegadoId = null;
        }

        $ctxTorneos = [];
        foreach ($mapCat as $lim => $tid) {
            $meta = TorneoCampeonato::metaProcesamientoTorneo($pdo, (int) $tid);
            $ctxTorneos[(int) $tid] = [
                'categoria_limite' => $lim,
                'filtro' => $meta['filtro_atleta'] ?? null,
                'fechator' => $meta['fechator'] ?? null,
            ];
            if (!$dryRun && !($opciones['omitir_bloqueo_torneo'] ?? false)) {
                TorneoMovimientoLock::assertEdicionPermitida($pdo, (int) $tid);
            }
        }

        $filas = self::filasAtletasCanonConUsuario($pdo, $soloInd, $soloAsoc);
        $asignadosPorCat = [];
        foreach (array_keys($mapCat) as $lim) {
            $asignadosPorCat[$lim] = 0;
        }

        $stats = [
            'grupo_evento_id' => $grupoEventoId,
            'dry_run' => $dryRun,
            'torneos_por_categoria' => $mapCat,
            'leidos_atletas_canon' => count($filas),
            'asignados_por_categoria' => $asignadosPorCat,
            'sin_categoria_aplicable' => 0,
            'ambiguo_varias_categorias' => 0,
            'descartados' => [
                'sin_usuario' => 0,
                'sin_asociacion' => 0,
                'cedula_vacia' => 0,
                'no_aplica_filtro_torneo' => 0,
            ],
            'movimiento_insertados' => 0,
            'movimiento_actualizados' => 0,
            'movimiento_sin_cambio' => 0,
            'movimiento_eliminados' => 0,
            'deuda_asociaciones_recalculadas' => 0,
            'renglones_volcados' => self::renglonesVolcadosVacios(),
            'detalle_por_torneo' => [],
        ];

        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                foreach ($mapCat as $tid) {
                    $stats['movimiento_eliminados'] += self::eliminarMovimientoTorneoPorTorneo($pdo, (int) $tid);
                }
                self::procesarFilasDistribucionCategoria(
                    $pdo,
                    $filas,
                    $ctxTorneos,
                    $mapCat,
                    $crearSol,
                    $delegadoId,
                    $stats
                );
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            if ($recalcDeuda && DeudaAsociaciones::tablaDisponible($pdo)) {
                $n = 0;
                foreach ($mapCat as $tid) {
                    $n += DeudaAsociaciones::recalcularTorneoCompleto($pdo, (int) $tid);
                }
                $stats['deuda_asociaciones_recalculadas'] = $n;
            }
        } else {
            $mapsEx = [];
            foreach ($mapCat as $tid) {
                $mapsEx[(int) $tid] = self::mapMovimientoExistentePorUsuarioEnTorneo($pdo, (int) $tid);
            }
            self::procesarFilasDistribucionCategoriaDry($filas, $ctxTorneos, $mapCat, $mapsEx, $stats);
        }

        return $stats;
    }

    /**
     * @param array<int, array{ categoria_limite: int, filtro: ?array, fechator: ?string }> $ctxTorneos
     * @param array{12?: int, 15?: int, 18?: int} $mapCat
     * @param array<string, mixed> $stats
     */
    private static function procesarFilasDistribucionCategoria(
        \PDO $pdo,
        array $filas,
        array $ctxTorneos,
        array $mapCat,
        bool $crearSol,
        ?int $delegadoId,
        array &$stats
    ): void {
        foreach ($filas as $row) {
            $candidatos = [];
            foreach ($ctxTorneos as $tid => $ctx) {
                if (TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $ctx['filtro'], $ctx['fechator'])) {
                    $candidatos[] = (int) $tid;
                }
            }
            if ($candidatos === []) {
                $stats['sin_categoria_aplicable']++;

                continue;
            }
            if (count($candidatos) > 1) {
                $stats['ambiguo_varias_categorias']++;
                $tid = $candidatos[0];
            } else {
                $tid = $candidatos[0];
            }
            $lim = (int) ($ctxTorneos[$tid]['categoria_limite'] ?? 0);
            $prep = self::prepararFilaMovimiento($row, $tid);
            if ($prep === null) {
                if (trim((string) ($row['cedula'] ?? '')) === '') {
                    $stats['descartados']['cedula_vacia']++;
                } elseif ((int) ($row['user_id'] ?? 0) < 1) {
                    $stats['descartados']['sin_usuario']++;
                } else {
                    $stats['descartados']['sin_asociacion']++;
                }

                continue;
            }
            if ($lim > 0) {
                $stats['asignados_por_categoria'][$lim] = (int) ($stats['asignados_por_categoria'][$lim] ?? 0) + 1;
            }
            self::acumularRenglonesVolcados($stats, $prep);
            self::registrarProcesadoEnTorneo($stats, $tid, $prep);
            $movId = self::upsertMovimiento($pdo, $tid, $prep);
            if ($movId['accion'] === 'insert') {
                $stats['movimiento_insertados']++;
            } elseif ($movId['accion'] === 'update') {
                $stats['movimiento_actualizados']++;
            } else {
                $stats['movimiento_sin_cambio']++;
            }
            if ($crearSol && FvdSolicitudesDelegado::tablaDisponible($pdo)) {
                self::insertarSolicitudAfiliacionSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
                self::insertarSolicitudCarnetSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
                self::insertarSolicitudTraspasoSiCorresponde($pdo, $tid, $prep, $movId['id'], $delegadoId);
            }
        }
    }

    /**
     * @param array<int, array{ categoria_limite: int, filtro: ?array, fechator: ?string }> $ctxTorneos
     * @param array<int, array<int, array<string, mixed>>> $mapsEx
     * @param array<string, mixed> $stats
     */
    private static function procesarFilasDistribucionCategoriaDry(
        array $filas,
        array $ctxTorneos,
        array $mapCat,
        array $mapsEx,
        array &$stats
    ): void {
        foreach ($filas as $row) {
            $candidatos = [];
            foreach ($ctxTorneos as $tid => $ctx) {
                if (TorneoCampeonato::atletaAplicaAFiltroTorneo($row, $ctx['filtro'], $ctx['fechator'])) {
                    $candidatos[] = (int) $tid;
                }
            }
            if ($candidatos === []) {
                $stats['sin_categoria_aplicable']++;

                continue;
            }
            if (count($candidatos) > 1) {
                $stats['ambiguo_varias_categorias']++;
            }
            $tid = $candidatos[0];
            $lim = (int) ($ctxTorneos[$tid]['categoria_limite'] ?? 0);
            $prep = self::prepararFilaMovimiento($row, $tid);
            if ($prep === null) {
                if (trim((string) ($row['cedula'] ?? '')) === '') {
                    $stats['descartados']['cedula_vacia']++;
                } elseif ((int) ($row['user_id'] ?? 0) < 1) {
                    $stats['descartados']['sin_usuario']++;
                } else {
                    $stats['descartados']['sin_asociacion']++;
                }

                continue;
            }
            if ($lim > 0) {
                $stats['asignados_por_categoria'][$lim] = (int) ($stats['asignados_por_categoria'][$lim] ?? 0) + 1;
            }
            self::acumularRenglonesVolcados($stats, $prep);
            self::registrarProcesadoEnTorneo($stats, $tid, $prep);
            $uid = (int) $prep['user_id'];
            $accion = self::simularAccionMovimiento($mapsEx[$tid][$uid] ?? null, $prep);
            if ($accion === 'insert') {
                $stats['movimiento_insertados']++;
            } elseif ($accion === 'update') {
                $stats['movimiento_actualizados']++;
            } else {
                $stats['movimiento_sin_cambio']++;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function filasAtletasCanonConUsuario(\PDO $pdo, bool $soloConIndicadores, int $soloAsociacionId): array
    {
        $wInd = '';
        if ($soloConIndicadores) {
            $wInd = ' AND ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('a');
        }
        $wAsoc = '';
        if ($soloAsociacionId > 0) {
            $wAsoc = ' AND (COALESCE(NULLIF(u.`asociacion_id`, 0), ax.`id`, 0) = :filt_asoc
                OR (a.`traspaso` IN (1, 6) AND ax.`id` = :filt_asoc2))';
        }

        $sql = 'SELECT
                a.`id` AS atleta_id,
                TRIM(a.`cedula`) AS cedula,
                a.`numfvd` AS a_numfvd,
                a.`sexo` AS a_sexo,
                a.`estatus` AS a_estatus,
                a.`afiliacion`, a.`anualidad`, a.`carnet`, a.`traspaso`, a.`inscripcion`,
                a.`categ`,
                a.`asociacion` AS atleta_asociacion_raw,
                u.`id` AS user_id,
                u.`numfvd` AS u_numfvd,
                u.`sexo` AS u_sexo,
                u.`fechnac` AS fechnac,
                u.`status` AS u_status,
                u.`asociacion_id` AS u_asociacion_id,
                ax.`id` AS asoc_from_atleta
            FROM `' . self::T_A . '` a
            INNER JOIN (
                SELECT MIN(`id`) AS `min_id` FROM `' . self::T_A . '` GROUP BY TRIM(`cedula`)
            ) canon ON canon.`min_id` = a.`id`
            INNER JOIN `' . self::T_U . '` u ON (
                ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('u.`cedula`') . ' = '
                . SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('a.`cedula`') . '
                OR (a.`numfvd` > 0 AND u.`numfvd` = a.`numfvd`)
                OR u.`email` = CONCAT(\'atleta.\', a.`id`, \'@migracion.fvd.local\')
            )
            LEFT JOIN `' . self::T_ASOC . '` ax ON ax.`id` = a.`asociacion`
            WHERE 1=1' . $wInd . $wAsoc . '
            ORDER BY u.`id` ASC';

        $st = $pdo->prepare($sql);
        if ($soloAsociacionId > 0) {
            $st->bindValue(':filt_asoc', $soloAsociacionId, \PDO::PARAM_INT);
            $st->bindValue(':filt_asoc2', $soloAsociacionId, \PDO::PARAM_INT);
        }
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || $rows === []) {
            return [];
        }

        return self::deduplicarFilasAtletasPorUsuario($rows);
    }

    /**
     * Si varios `usuarios` coinciden por cédula o numfvd, conserva el de menor `user_id`.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function deduplicarFilasAtletasPorUsuario(array $rows): array
    {
        $porUsuario = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid < 1) {
                continue;
            }
            if (!isset($porUsuario[$uid])) {
                $porUsuario[$uid] = $row;

                continue;
            }
            $prev = $porUsuario[$uid];
            $score = static function (array $r): int {
                $s = 0;
                foreach (['afiliacion', 'anualidad', 'carnet', 'traspaso', 'inscripcion'] as $c) {
                    if (SyncMovimientoTorneoTridenteDesdeAtletas::indicadorAtletaActivo($c, (int) ($r[$c] ?? 0))) {
                        $s++;
                    }
                }

                return $s;
            };
            if ($score($row) > $score($prev)) {
                $porUsuario[$uid] = $row;
            }
        }

        return array_values($porUsuario);
    }

    /**
     * Atletas canónicos con indicadores que aún no tienen fila en `usuarios`.
     *
     * @return array{filas: int, renglones: array<string, int>}
     */
    public static function conteoAtletasConIndicadoresSinUsuario(\PDO $pdo, bool $soloConIndicadores = true): array
    {
        $wInd = $soloConIndicadores ? ' AND ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlWhereAtletaConMovimiento('a') : '';
        $cedNormA = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('a.`cedula`');
        $cedNormU = SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('u.`cedula`');
        $st = $pdo->query(
            'SELECT COUNT(*) AS filas, ' . SyncMovimientoTorneoTridenteDesdeAtletas::sqlSelectConteoIndicadoresAtletas('a') . '
             FROM `' . self::T_A . '` a
             INNER JOIN (
                SELECT MIN(`id`) AS `min_id` FROM `' . self::T_A . '` GROUP BY '
                . SyncMovimientoTorneoTridenteDesdeAtletas::sqlCedulaNormalizada('`cedula`') . '
             ) canon ON canon.`min_id` = a.`id`
             WHERE TRIM(a.`cedula`) <> ""' . $wInd . '
               AND NOT EXISTS (
                 SELECT 1 FROM `' . self::T_U . '` u
                 WHERE ' . $cedNormU . ' = ' . $cedNormA . '
                    OR (a.`numfvd` > 0 AND u.`numfvd` = a.`numfvd`)
                    OR u.`email` = CONCAT(\'atleta.\', a.`id`, \'@migracion.fvd.local\')
               )'
        );
        if ($st === false) {
            return ['filas' => 0, 'renglones' => MovimientoTorneoContadores::filaVacia()];
        }
        $r = $st->fetch(\PDO::FETCH_ASSOC);

        return [
            'filas' => (int) ($r['filas'] ?? 0),
            'renglones' => $r === false ? MovimientoTorneoContadores::filaVacia() : MovimientoTorneoContadores::normalizarFilaAgregada($r),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private static function prepararFilaMovimiento(array $row, int $torneoId): ?array
    {
        $cedula = AfiliacionAtleta::normalizarCedula((string) ($row['cedula'] ?? ''));
        if ($cedula === '') {
            return null;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $asocAtleta = (int) ($row['asoc_from_atleta'] ?? 0);
        $asocUsuario = (int) ($row['u_asociacion_id'] ?? 0);

        $af = SyncMovimientoTorneoTridenteDesdeAtletas::normalizarIndicadorMovimientoTorneo(
            'afiliacion',
            (int) ($row['afiliacion'] ?? 0)
        );
        $an = SyncMovimientoTorneoTridenteDesdeAtletas::normalizarIndicadorMovimientoTorneo(
            'anualidad',
            (int) ($row['anualidad'] ?? 0)
        );
        $ca = SyncMovimientoTorneoTridenteDesdeAtletas::normalizarIndicadorMovimientoTorneo(
            'carnet',
            (int) ($row['carnet'] ?? 0)
        );
        $tr = SyncMovimientoTorneoTridenteDesdeAtletas::normalizarIndicadorMovimientoTorneo(
            'traspaso',
            (int) ($row['traspaso'] ?? 0)
        );
        $ins = SyncMovimientoTorneoTridenteDesdeAtletas::normalizarIndicadorMovimientoTorneo(
            'inscripcion',
            (int) ($row['inscripcion'] ?? 0)
        );

        $asocMov = $asocUsuario > 0 ? $asocUsuario : $asocAtleta;
        if ($tr === 1 && $asocAtleta > 0) {
            $asocMov = $asocAtleta;
        }
        if ($asocMov < 1) {
            return null;
        }

        $numfvd = (int) ($row['u_numfvd'] ?? 0);
        if ($numfvd < 1) {
            $numfvd = (int) ($row['a_numfvd'] ?? 0);
        }

        $sexo = (int) ($row['u_sexo'] ?? 0);
        if ($sexo < 1) {
            $sexo = (int) ($row['a_sexo'] ?? 0);
        }

        $estatus = (int) ($row['u_status'] ?? 0);
        if ($estatus === 0 && isset($row['a_estatus'])) {
            $estatus = (int) $row['a_estatus'];
        }

        $categ = (int) ($row['categ'] ?? 0);
        $posrnk = ($categ > 0 && $categ < 9999) ? $categ : 0;

        $asocOrigenTraspaso = $asocUsuario > 0 ? $asocUsuario : $asocAtleta;
        if ($asocOrigenTraspaso < 1) {
            $asocOrigenTraspaso = $asocMov;
        }
        $asocDestinoTraspaso = $tr && $asocAtleta > 0 && $asocAtleta !== $asocOrigenTraspaso ? $asocAtleta : null;

        return [
            'user_id' => $userId,
            'cedula' => $cedula,
            'numfvd' => $numfvd,
            'sexo' => $sexo,
            'estatus' => $estatus,
            'asociacion_id' => $asocMov,
            'afiliacion' => $af,
            'anualidad' => $an,
            'carnet' => $ca,
            'traspaso' => $tr,
            'inscripcion' => $ins,
            'posrnk' => $posrnk,
            'asoc_origen_traspaso' => $asocOrigenTraspaso,
            'asoc_destino_traspaso' => $asocDestinoTraspaso,
            'torneo_id' => $torneoId,
            '_row' => $row,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $row
     */
    /**
     * @param array<string, mixed>|null $filtroAtleta
     */
    private static function contarDescarte(array &$stats, array $row, ?array $filtroAtleta = null): void
    {
        $cedula = trim((string) ($row['cedula'] ?? ''));
        if ($cedula === '') {
            $stats['descartados']['cedula_vacia']++;

            return;
        }
        if ((int) ($row['user_id'] ?? 0) < 1) {
            $stats['descartados']['sin_usuario']++;

            return;
        }
        $stats['descartados']['sin_asociacion']++;
    }

    /**
     * Una sola consulta: filas movimiento_torneo del torneo indexadas por id_usuario.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mapMovimientoExistentePorUsuarioEnTorneo(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT `id_usuario`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `posrnk`
             FROM `' . self::T_M . '` WHERE `torneo_id` = :t'
        );
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || $rows === []) {
            return [];
        }
        $map = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['id_usuario'] ?? 0);
            if ($uid > 0) {
                $map[$uid] = $r;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed>|null $existente fila movimiento_torneo o null si no existe
     * @param array<string, mixed> $prep
     */
    private static function simularAccionMovimiento(?array $existente, array $prep): string
    {
        if ($existente === null) {
            return 'insert';
        }
        if (self::movimientoIgual($existente, $prep)) {
            return 'none';
        }

        return 'update';
    }

    /**
     * @param array<string, mixed> $prep
     *
     * @return array{id: int, accion: string}
     */
    private static function upsertMovimiento(\PDO $pdo, int $torneoId, array $prep): array
    {
        $st = $pdo->prepare(
            'SELECT `id`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `posrnk`
             FROM `' . self::T_M . '` WHERE `id_usuario` = :u AND `torneo_id` = :t LIMIT 1'
        );
        $st->bindValue(':u', $prep['user_id'], \PDO::PARAM_INT);
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();
        $ex = $st->fetch(\PDO::FETCH_ASSOC);

        if ($ex === false) {
            $ins = $pdo->prepare(
                'INSERT INTO `' . self::T_M . '` (
                    `id_usuario`, `cedula`, `numfvd`, `sexo`, `asociacion_id`, `estatus`,
                    `afiliacion`, `anualidad`, `carnet`, `traspaso`, `inscripcion`, `torneo_id`, `posrnk`
                ) VALUES (
                    :id_usuario, :cedula, :numfvd, :sexo, :asociacion_id, :estatus,
                    :afiliacion, :anualidad, :carnet, :traspaso, :inscripcion, :torneo_id, :posrnk
                )'
            );
            self::bindMovimiento($ins, $prep, $torneoId);
            $ins->execute();

            return ['id' => (int) $pdo->lastInsertId(), 'accion' => 'insert'];
        }

        $mid = (int) $ex['id'];
        if (self::movimientoIgual($ex, $prep)) {
            return ['id' => $mid, 'accion' => 'none'];
        }

        $upd = $pdo->prepare(
            'UPDATE `' . self::T_M . '` SET
                `cedula` = :cedula, `numfvd` = :numfvd, `sexo` = :sexo, `asociacion_id` = :asociacion_id,
                `estatus` = :estatus, `afiliacion` = :afiliacion, `anualidad` = :anualidad,
                `carnet` = :carnet, `traspaso` = :traspaso, `inscripcion` = :inscripcion, `posrnk` = :posrnk
             WHERE `id` = :id AND `torneo_id` = :torneo_id LIMIT 1'
        );
        $upd->bindValue(':id', $mid, \PDO::PARAM_INT);
        $upd->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        self::bindMovimiento($upd, $prep, $torneoId, false);
        $upd->execute();

        return ['id' => $mid, 'accion' => 'update'];
    }

    /**
     * @param array<string, mixed> $ex
     * @param array<string, mixed> $prep
     */
    private static function movimientoIgual(array $ex, array $prep): bool
    {
        $pairs = [
            ['cedula', 'cedula', 'str'],
            ['numfvd', 'numfvd', 'int'],
            ['sexo', 'sexo', 'int'],
            ['asociacion_id', 'asociacion_id', 'int'],
            ['estatus', 'estatus', 'int'],
            ['afiliacion', 'afiliacion', 'int'],
            ['anualidad', 'anualidad', 'int'],
            ['carnet', 'carnet', 'int'],
            ['traspaso', 'traspaso', 'int'],
            ['inscripcion', 'inscripcion', 'int'],
            ['posrnk', 'posrnk', 'int'],
        ];
        foreach ($pairs as [$ek, $pk, $tipo]) {
            if ($tipo === 'str') {
                $a = AfiliacionAtleta::normalizarCedula((string) ($ex[$ek] ?? ''));
                $b = (string) $prep[$pk];
                if ($a !== $b) {
                    return false;
                }
            } else {
                if ((int) ($ex[$ek] ?? 0) !== (int) $prep[$pk]) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function bindMovimiento(\PDOStatement $stmt, array $prep, int $torneoId, bool $includeUsuario = true): void
    {
        if ($includeUsuario) {
            $stmt->bindValue(':id_usuario', $prep['user_id'], \PDO::PARAM_INT);
            $stmt->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':cedula', $prep['cedula'], \PDO::PARAM_STR);
        $stmt->bindValue(':numfvd', $prep['numfvd'], \PDO::PARAM_INT);
        $stmt->bindValue(':sexo', $prep['sexo'], \PDO::PARAM_INT);
        $stmt->bindValue(':asociacion_id', $prep['asociacion_id'], \PDO::PARAM_INT);
        $stmt->bindValue(':estatus', $prep['estatus'], \PDO::PARAM_INT);
        $stmt->bindValue(':afiliacion', $prep['afiliacion'], \PDO::PARAM_INT);
        $stmt->bindValue(':anualidad', $prep['anualidad'], \PDO::PARAM_INT);
        $stmt->bindValue(':carnet', $prep['carnet'], \PDO::PARAM_INT);
        $stmt->bindValue(':traspaso', $prep['traspaso'], \PDO::PARAM_INT);
        $stmt->bindValue(':inscripcion', $prep['inscripcion'], \PDO::PARAM_INT);
        $stmt->bindValue(':posrnk', $prep['posrnk'], \PDO::PARAM_INT);
    }

    /**
     * Afiliación pendiente de Nº FVD (misma regla que supervisión + alta delegada).
     *
     * @param array<string, mixed> $prep
     */
    private static function insertarSolicitudAfiliacionSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        array $prep,
        int $movimientoId,
        ?int $delegadoUserId
    ): int {
        if ($movimientoId < 1 || (int) $prep['afiliacion'] !== 1) {
            return 0;
        }
        $asoc = (int) $prep['asociacion_id'];
        $uid = (int) $prep['user_id'];
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'afiliacion', $uid, $asoc, null)) {
            return 0;
        }
        $nota = self::NOTA_ORIGEN . ';' . FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId);
        $id = FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'afiliacion',
            $asoc,
            $uid,
            $delegadoUserId,
            null,
            $nota
        );

        return $id !== null ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $prep
     */
    private static function insertarSolicitudCarnetSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        array $prep,
        int $movimientoId,
        ?int $delegadoUserId
    ): int {
        if ($movimientoId < 1 || (int) $prep['carnet'] !== 1) {
            return 0;
        }
        if (!self::puedeSolicitudCarnetOTraspaso($prep)) {
            return 0;
        }
        $asoc = (int) $prep['asociacion_id'];
        $uid = (int) $prep['user_id'];
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'carnet', $uid, $asoc, null)) {
            return 0;
        }
        $nota = self::NOTA_ORIGEN . ';' . FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId);
        $id = FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'carnet',
            $asoc,
            $uid,
            $delegadoUserId,
            null,
            $nota
        );

        return $id !== null ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $prep
     */
    private static function insertarSolicitudTraspasoSiCorresponde(
        \PDO $pdo,
        int $torneoId,
        array $prep,
        int $movimientoId,
        ?int $delegadoUserId
    ): int {
        if ($movimientoId < 1 || (int) $prep['traspaso'] !== 1) {
            return 0;
        }
        if (!self::puedeSolicitudCarnetOTraspaso($prep)) {
            return 0;
        }
        $orig = (int) $prep['asoc_origen_traspaso'];
        $dest = $prep['asoc_destino_traspaso'];
        if ($dest === null || (int) $dest < 1) {
            $dest = (int) $prep['asociacion_id'];
        } else {
            $dest = (int) $dest;
        }
        if ($orig < 1 || $dest < 1 || $orig === $dest) {
            return 0;
        }
        $uid = (int) $prep['user_id'];
        if (FvdSolicitudesDelegado::tienePendiente($pdo, 'traspaso', $uid, $orig, $dest)) {
            return 0;
        }
        $nota = self::NOTA_ORIGEN . ';' . FvdSolicitudesDelegado::notaTorneoMovimiento($torneoId, $movimientoId);
        $id = FvdSolicitudesDelegado::insertarPendiente(
            $pdo,
            'traspaso',
            $orig,
            $uid,
            $delegadoUserId,
            $dest,
            $nota
        );

        return $id !== null ? 1 : 0;
    }

    /**
     * No compite con afiliación pendiente de Nº FVD (SupervisionFvd).
     *
     * @param array<string, mixed> $prep
     */
    private static function puedeSolicitudCarnetOTraspaso(array $prep): bool
    {
        $af = (int) ($prep['afiliacion'] ?? 0);
        $nf = (int) ($prep['numfvd'] ?? 0);

        return $af !== 1 || $nf > 0;
    }

    /** @param array<string, mixed> $prep */
    private static function contariaSolicitudAfiliacion(\PDO $pdo, array $prep): int
    {
        if ((int) $prep['afiliacion'] !== 1) {
            return 0;
        }
        $asoc = (int) $prep['asociacion_id'];
        $uid = (int) $prep['user_id'];

        return FvdSolicitudesDelegado::tienePendiente($pdo, 'afiliacion', $uid, $asoc, null) ? 0 : 1;
    }

    /** @param array<string, mixed> $prep */
    private static function contariaSolicitudCarnet(\PDO $pdo, array $prep): int
    {
        if ((int) $prep['carnet'] !== 1 || !self::puedeSolicitudCarnetOTraspaso($prep)) {
            return 0;
        }
        $asoc = (int) $prep['asociacion_id'];
        $uid = (int) $prep['user_id'];

        return FvdSolicitudesDelegado::tienePendiente($pdo, 'carnet', $uid, $asoc, null) ? 0 : 1;
    }

    /** @param array<string, mixed> $prep */
    private static function contariaSolicitudTraspaso(\PDO $pdo, array $prep): int
    {
        if ((int) $prep['traspaso'] !== 1 || !self::puedeSolicitudCarnetOTraspaso($prep)) {
            return 0;
        }
        $orig = (int) $prep['asoc_origen_traspaso'];
        $dest = $prep['asoc_destino_traspaso'];
        if ($dest === null || (int) $dest < 1) {
            $dest = (int) $prep['asociacion_id'];
        } else {
            $dest = (int) $dest;
        }
        if ($orig < 1 || $dest < 1 || $orig === $dest) {
            return 0;
        }
        $uid = (int) $prep['user_id'];

        return FvdSolicitudesDelegado::tienePendiente($pdo, 'traspaso', $uid, $orig, $dest) ? 0 : 1;
    }
}
