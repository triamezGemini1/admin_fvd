<?php

declare(strict_types=1);

namespace Fvd\Modulos\Informes\Modelos;

use Fvd\Modulos\Finanzas\Modelos\FinanzaFvd;
use Fvd\Modulos\Finanzas\Modelos\FinanzaGastoTorneo;
use Fvd\Modulos\Finanzas\Modelos\MovimientoTorneoContadores;
use Fvd\Modulos\Torneos\Modelos\TorneoCampeonato;

/**
 * Informe consolidado por asociación: contadores y renglones operativos en `movimiento_torneo`;
 * montos por concepto = cantidad × tarifa de `costos` (fila más reciente). El total de cuenta
 * = nómina (`movimiento_torneo` en todos los torneos o uno) + cargos manuales (`finanza_cargo`).
 * Contadores unificados con {@see MovimientoTorneoContadores} y `DeudaAsociaciones`.
 *
 * Los afiliados y su asociación se enlazan vía `usuarios` (identidad); las cantidades y el estado
 * de solicitudes por torneo provienen de `movimiento_torneo`.
 */
class InformeFvd
{
    private const T_ASOC = 'asociaciones';

    private const T_MOV = 'movimiento_torneo';

    private const T_CARGO = 'finanza_cargo';

    private const T_TOR = 'torneosact';

    /** @var list<string> */
    public const RENGLONES = ['afiliacion', 'carnet', 'traspaso', 'anualidad', 'inscripcion'];

    public const RENGLON_TOTAL_CUENTA = 'total_cuenta';

    /**
     * Torneo en curso (no finalizado), el más reciente por fecha.
     *
     * @return array<string, mixed>|null fila de `torneosact` o null
     */
    public static function torneoActivoFila(\PDO $pdo): ?array
    {
        $stmt = $pdo->query(
            'SELECT torneo, nombre, clavetor, costotor, fechator, lugar, finalizado_en FROM ' . self::T_TOR
                . ' WHERE finalizado_en IS NULL ORDER BY fechator DESC, torneo DESC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function torneoActivoId(\PDO $pdo): ?int
    {
        $f = self::torneoActivoFila($pdo);

        return $f !== null ? (int) $f['torneo'] : null;
    }

    /**
     * Torneo con más filas en `movimiento_torneo` (p. ej. Apertura = 1 aunque el activo sea otro).
     */
    public static function torneoIdConMasMovimiento(\PDO $pdo): ?int
    {
        $st = $pdo->query(
            'SELECT torneo_id, COUNT(*) AS n FROM ' . self::T_MOV . '
             WHERE torneo_id IS NOT NULL AND torneo_id > 0
             GROUP BY torneo_id ORDER BY n DESC, torneo_id DESC LIMIT 1'
        );
        if ($st === false) {
            return null;
        }
        $tid = (int) ($st->fetchColumn() ?: 0);

        return $tid > 0 ? $tid : null;
    }

    public static function torneoTieneMovimiento(\PDO $pdo, int $torneoId): bool
    {
        if ($torneoId < 1) {
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM ' . self::T_MOV . ' WHERE torneo_id = :t LIMIT 1');
        $st->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $st->execute();

        return (bool) $st->fetchColumn();
    }

    /**
     * Torneo cuyos marcadores financieros debe mostrar el informe.
     * Si se pasa `torneoIdSolicitado` (>0), se usa tras validar.
     * Si no: torneo activo (aunque aún no tenga nómina); si no hay activo, el torneo con más movimiento.
     */
    public static function resolverTorneoIdInformeMovimiento(\PDO $pdo, ?int $torneoIdSolicitado = null): ?int
    {
        if ($torneoIdSolicitado !== null && $torneoIdSolicitado > 0) {
            if (self::torneoFilaPorId($pdo, $torneoIdSolicitado) !== null) {
                return $torneoIdSolicitado;
            }
        }
        $activo = self::torneoActivoId($pdo);
        if ($activo !== null) {
            return $activo;
        }

        return self::torneoIdConMasMovimiento($pdo);
    }

    /**
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed, filas_movimiento: int}>
     */
    public static function torneosConNominaParaSelector(\PDO $pdo): array
    {
        $st = $pdo->query(
            'SELECT m.torneo_id, COUNT(*) AS filas_movimiento, t.nombre, t.fechator, t.finalizado_en
             FROM ' . self::T_MOV . ' m
             INNER JOIN ' . self::T_TOR . ' t ON t.torneo = m.torneo_id
             WHERE m.torneo_id > 0
             GROUP BY m.torneo_id, t.nombre, t.fechator, t.finalizado_en
             ORDER BY filas_movimiento DESC, m.torneo_id DESC'
        );
        if ($st === false) {
            return [];
        }
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $tid = (int) ($r['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($r['nombre'] ?? ''),
                'fechator' => $r['fechator'] ?? null,
                'finalizado_en' => $r['finalizado_en'] ?? null,
                'filas_movimiento' => (int) ($r['filas_movimiento'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Fila de `torneosact` por id (cualquier estado de cierre).
     *
     * @return array<string, mixed>|null
     */
    public static function torneoFilaPorId(\PDO $pdo, int $torneoId): ?array
    {
        if ($torneoId < 1) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT torneo, nombre, clavetor, costotor, fechator, lugar, finalizado_en FROM '
            . self::T_TOR . ' WHERE torneo = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed>|null $tf fila de `torneosact`
     *
     * @return array<string, mixed>|null
     */
    private static function torneoJsonDesdeFila(?array $tf): ?array
    {
        if ($tf === null) {
            return null;
        }

        return [
            'id' => (int) $tf['torneo'],
            'nombre' => (string) ($tf['nombre'] ?? ''),
            'clavetor' => (string) ($tf['clavetor'] ?? ''),
            'costotor' => isset($tf['costotor']) ? round((float) $tf['costotor'], 2) : null,
            'fechator' => $tf['fechator'] ?? null,
            'lugar' => $tf['lugar'] ?? null,
            'finalizado_en' => $tf['finalizado_en'] ?? null,
        ];
    }

    /**
     * Torneos en los que la asociación tiene al menos un movimiento en `movimiento_torneo`.
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed}>
     */
    public static function torneosConMovimientoParaAsociacion(\PDO $pdo, int $asociacionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT m.torneo_id AS torneo_id, t.nombre, t.fechator, t.finalizado_en
            FROM ' . self::T_MOV . ' m
            INNER JOIN ' . self::T_TOR . ' t ON t.torneo = m.torneo_id
            WHERE m.asociacion_id = :aid AND m.torneo_id IS NOT NULL AND m.torneo_id > 0
            ORDER BY t.fechator DESC, m.torneo_id DESC'
        );
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $tid = (int) ($r['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($r['nombre'] ?? ''),
                'fechator' => $r['fechator'] ?? null,
                'finalizado_en' => $r['finalizado_en'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Lista para el selector «Otros torneos» en finanzas por asociación: torneos con nómina FVD
     * para esa asociación, más torneos ya finalizados (sin duplicar) para poder revisar el contexto.
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed, tiene_nomina: bool}>
     */
    public static function torneosParaSelectorFinanzasAsociacion(\PDO $pdo, int $asociacionId): array
    {
        $conNomina = self::torneosConMovimientoParaAsociacion($pdo, $asociacionId);
        $seen = [];
        $out = [];
        foreach ($conNomina as $r) {
            $tid = (int) ($r['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $seen[$tid] = true;
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($r['nombre'] ?? ''),
                'fechator' => $r['fechator'] ?? null,
                'finalizado_en' => $r['finalizado_en'] ?? null,
                'tiene_nomina' => true,
            ];
        }

        try {
            foreach (self::torneosFinalizadosTodos($pdo, 6000) as $r) {
                $tid = (int) ($r['torneo_id'] ?? 0);
                if ($tid < 1 || isset($seen[$tid])) {
                    continue;
                }
                $seen[$tid] = true;
                $out[] = [
                    'torneo_id' => $tid,
                    'nombre' => (string) ($r['nombre'] ?? ''),
                    'fechator' => $r['fechator'] ?? null,
                    'finalizado_en' => $r['finalizado_en'] ?? null,
                    'tiene_nomina' => false,
                ];
            }
        } catch (Throwable $e) {
            error_log('InformeFvd::torneosParaSelectorFinanzasAsociacion: ' . $e->getMessage());
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                $fa = (string) ($a['fechator'] ?? '');
                $fb = (string) ($b['fechator'] ?? '');
                $c = strcmp($fb, $fa);
                if ($c !== 0) {
                    return $c;
                }

                return ((int) ($b['torneo_id'] ?? 0)) - ((int) ($a['torneo_id'] ?? 0));
            }
        );

        return $out;
    }

    /**
     * Torneos con fecha de cierre (`finalizado_en`), más recientes primero (para selectores y vistas consolidadas).
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed}>
     */
    public static function torneosFinalizadosTodos(\PDO $pdo, int $maxRows = 5000): array
    {
        $lim = max(1, min(8000, $maxRows));
        try {
            $stmt = $pdo->query(
                'SELECT torneo AS torneo_id, nombre, fechator, finalizado_en FROM ' . self::T_TOR
                    . ' WHERE finalizado_en IS NOT NULL ORDER BY fechator DESC, torneo DESC LIMIT ' . $lim
            );
            if ($stmt === false) {
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === false) {
                return [];
            }
            $out = [];
            foreach ($rows as $r) {
                $tid = (int) ($r['torneo_id'] ?? 0);
                if ($tid < 1) {
                    continue;
                }
                $out[] = [
                    'torneo_id' => $tid,
                    'nombre' => (string) ($r['nombre'] ?? ''),
                    'fechator' => $r['fechator'] ?? null,
                    'finalizado_en' => $r['finalizado_en'] ?? null,
                ];
            }

            return $out;
        } catch (Throwable $e) {
            error_log('InformeFvd::torneosFinalizadosTodos: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Torneos que tienen al menos un movimiento en `movimiento_torneo` (cualquier asociación).
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed}>
     */
    public static function torneosConMovimientoGlobales(\PDO $pdo, int $limit = 80): array
    {
        $lim = max(1, min(120, $limit));
        $stmt = $pdo->query(
            'SELECT t.torneo AS torneo_id, t.nombre, t.fechator, t.finalizado_en
            FROM ' . self::T_TOR . ' t
            WHERE EXISTS (SELECT 1 FROM ' . self::T_MOV . ' m WHERE m.torneo_id = t.torneo)
            ORDER BY t.fechator DESC, t.torneo DESC
            LIMIT ' . $lim
        );
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $tid = (int) ($r['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($r['nombre'] ?? ''),
                'fechator' => $r['fechator'] ?? null,
                'finalizado_en' => $r['finalizado_en'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Lista para el selector del panel Finanzas «Otros torneos»: torneo en curso primero, luego todos los torneos finalizados (hasta el límite configurado).
     *
     * @return list<array{torneo_id: int, nombre: string, fechator: mixed, finalizado_en: mixed, es_torneo_activo: bool}>
     */
    public static function torneosListaSelectorPanelFinanzas(\PDO $pdo): array
    {
        $out = [];
        $seen = [];
        $act = self::torneoActivoFila($pdo);
        if ($act !== null) {
            $tid = (int) ($act['torneo'] ?? 0);
            if ($tid > 0) {
                $seen[$tid] = true;
                $out[] = [
                    'torneo_id' => $tid,
                    'nombre' => (string) ($act['nombre'] ?? ''),
                    'fechator' => $act['fechator'] ?? null,
                    'finalizado_en' => $act['finalizado_en'] ?? null,
                    'es_torneo_activo' => true,
                ];
            }
        }
        foreach (self::torneosConNominaParaSelector($pdo) as $row) {
            $tid = (int) ($row['torneo_id'] ?? 0);
            if ($tid < 1 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($row['nombre'] ?? ''),
                'fechator' => $row['fechator'] ?? null,
                'finalizado_en' => $row['finalizado_en'] ?? null,
                'filas_movimiento' => (int) ($row['filas_movimiento'] ?? 0),
                'es_torneo_activo' => false,
            ];
        }
        foreach (self::torneosFinalizadosTodos($pdo, 6000) as $row) {
            $tid = (int) ($row['torneo_id'] ?? 0);
            if ($tid < 1 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $out[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($row['nombre'] ?? ''),
                'fechator' => $row['fechator'] ?? null,
                'finalizado_en' => $row['finalizado_en'] ?? null,
                'es_torneo_activo' => false,
            ];
        }

        return $out;
    }

    /**
     * Selector de torneos para finanzas / nómina: torneos sueltos + campeonatos agrupados (2 o 3 variantes).
     *
     * @return array{
     *   torneos_plano: list<array<string, mixed>>,
     *   campeonatos: list<array<string, mixed>>,
     *   sueltos: list<array<string, mixed>>
     * }
     */
    public static function torneosSelectorEstructurado(\PDO $pdo): array
    {
        $plano = self::torneosListaSelectorPanelFinanzas($pdo);
        $porGrupo = [];
        $sueltos = [];
        foreach ($plano as $t) {
            $tid = (int) ($t['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $gid = isset($t['grupo_evento_id']) ? (int) $t['grupo_evento_id'] : 0;
            if ($gid < 1) {
                $tf = self::torneoFilaPorId($pdo, $tid);
                if ($tf !== null) {
                    $gid = (int) ($tf['grupo_evento_id'] ?? 0);
                }
            }
            $tipo = 0;
            $tf2 = self::torneoFilaPorId($pdo, $tid);
            if ($tf2 !== null) {
                $tipo = (int) ($tf2['tipo'] ?? 0);
                if ($gid < 1) {
                    $gid = (int) ($tf2['grupo_evento_id'] ?? 0);
                }
            }
            $nombre = (string) ($t['nombre'] ?? '');
            $item = array_merge($t, [
                'torneo_id' => $tid,
                'grupo_evento_id' => $gid > 0 ? $gid : null,
                'tipo_torneo' => $tipo,
            ]);
            if ($gid > 0) {
                if (!isset($porGrupo[$gid])) {
                    $porGrupo[$gid] = [];
                }
                $porGrupo[$gid][] = $item;
            } else {
                $sueltos[] = $item;
            }
        }

        $campeonatos = [];
        foreach ($porGrupo as $gid => $torneosGrupo) {
            if (count($torneosGrupo) < 2) {
                foreach ($torneosGrupo as $uno) {
                    $sueltos[] = $uno;
                }
                continue;
            }
            $modo = TorneoCampeonato::detectarModoGrupo($pdo, (int) $gid);
            $etiquetaGrupo = (string) ($torneosGrupo[0]['nombre'] ?? '');
            foreach ($torneosGrupo as $tg) {
                $nom = (string) ($tg['nombre'] ?? '');
                if (strlen($nom) < strlen($etiquetaGrupo)) {
                    $etiquetaGrupo = $nom;
                }
            }
            $baseNom = preg_replace('/\s+(MASCULINO|FEMENINO|CATEG SUB \d+).*$/iu', '', $etiquetaGrupo) ?? $etiquetaGrupo;
            $baseNom = trim($baseNom);
            if ($baseNom === '') {
                $baseNom = 'Campeonato #' . $gid;
            }
            $variantes = [];
            foreach ($torneosGrupo as $tg) {
                $tid = (int) ($tg['torneo_id'] ?? 0);
                $tipo = (int) ($tg['tipo_torneo'] ?? 0);
                $nom = (string) ($tg['nombre'] ?? '');
                $variantes[] = array_merge($tg, [
                    'variante_etiqueta' => TorneoCampeonato::etiquetaVarianteTorneo($nom, $tipo, $modo),
                ]);
            }
            usort($variantes, static function (array $a, array $b): int {
                $oa = self::ordenVarianteCampeonato((string) ($a['variante_etiqueta'] ?? ''));
                $ob = self::ordenVarianteCampeonato((string) ($b['variante_etiqueta'] ?? ''));

                return $oa <=> $ob;
            });
            $campeonatos[] = [
                'grupo_evento_id' => (int) $gid,
                'etiqueta' => $baseNom,
                'modo_campeonato' => $modo,
                'n_torneos' => count($variantes),
                'torneos' => $variantes,
            ];
        }

        usort($campeonatos, static fn (array $a, array $b): int => strcmp((string) ($b['etiqueta'] ?? ''), (string) ($a['etiqueta'] ?? '')));

        return [
            'torneos_plano' => $plano,
            'campeonatos' => $campeonatos,
            'sueltos' => $sueltos,
        ];
    }

    /**
     * Agrupa bloques de {@see detalleAsociacionParaTorneo} en campeonatos (cuenta consolidada) y torneos sueltos.
     *
     * @param list<array<string, mixed>> $bloques
     *
     * @return list<array{
     *   tipo: string,
     *   grupo_evento_id?: int,
     *   etiqueta: string,
     *   modo_campeonato?: string|null,
     *   n_torneos: int,
     *   totales_consolidados: array<string, mixed>,
     *   renglones_consolidados: list<array<string, mixed>>,
     *   torneos: list<array<string, mixed>>
     * }>
     */
    public static function agruparBloquesInformeAsociacionPorCampeonato(\PDO $pdo, array $bloques): array
    {
        $estruct = self::torneosSelectorEstructurado($pdo);
        $torneoAGrupo = [];
        foreach ($estruct['campeonatos'] as $camp) {
            $gid = (int) ($camp['grupo_evento_id'] ?? 0);
            foreach ($camp['torneos'] as $t) {
                $tid = (int) ($t['torneo_id'] ?? 0);
                if ($tid > 0 && $gid > 0) {
                    $torneoAGrupo[$tid] = $camp;
                }
            }
        }

        $porGrupo = [];
        $sueltos = [];
        foreach ($bloques as $bloque) {
            $tor = $bloque['torneo_activo'] ?? null;
            $tid = is_array($tor) && isset($tor['id']) ? (int) $tor['id'] : 0;
            if ($tid < 1) {
                $sueltos[] = $bloque;

                continue;
            }
            if (!isset($torneoAGrupo[$tid])) {
                $sueltos[] = $bloque;

                continue;
            }
            $gid = (int) ($torneoAGrupo[$tid]['grupo_evento_id'] ?? 0);
            if ($gid < 1) {
                $sueltos[] = $bloque;

                continue;
            }
            if (!isset($porGrupo[$gid])) {
                $porGrupo[$gid] = [
                    'meta' => $torneoAGrupo[$tid],
                    'bloques' => [],
                ];
            }
            $porGrupo[$gid]['bloques'][] = $bloque;
        }

        $grupos = [];
        foreach ($porGrupo as $gid => $pack) {
            $meta = $pack['meta'];
            $subs = $pack['bloques'];
            $grupos[] = [
                'tipo' => 'campeonato',
                'grupo_evento_id' => (int) $gid,
                'etiqueta' => (string) ($meta['etiqueta'] ?? 'Campeonato #' . $gid),
                'modo_campeonato' => $meta['modo_campeonato'] ?? null,
                'n_torneos' => count($subs),
                'totales_consolidados' => self::totalesConsolidadosDesdeBloquesInforme($subs),
                'renglones_consolidados' => self::renglonesConsolidadosDesdeBloquesInforme($subs),
                'torneos' => $subs,
            ];
        }
        usort($grupos, static fn (array $a, array $b): int => strcmp((string) ($b['etiqueta'] ?? ''), (string) ($a['etiqueta'] ?? '')));

        foreach ($sueltos as $bloque) {
            $tor = $bloque['torneo_activo'] ?? null;
            $nom = is_array($tor) && isset($tor['nombre']) ? (string) $tor['nombre'] : 'Torneo';
            $grupos[] = [
                'tipo' => 'torneo_suelto',
                'etiqueta' => $nom,
                'n_torneos' => 1,
                'totales_consolidados' => $bloque['totales'] ?? [],
                'renglones_consolidados' => $bloque['renglones'] ?? [],
                'torneos' => [$bloque],
            ];
        }

        return $grupos;
    }

    /**
     * @param list<array<string, mixed>> $bloques
     *
     * @return list<array<string, mixed>>
     */
    public static function renglonesConsolidadosDesdeBloquesInforme(array $bloques): array
    {
        $cant = array_fill_keys(self::RENGLONES, 0);
        $montos = array_fill_keys(self::RENGLONES, 0.0);
        $units = null;
        foreach ($bloques as $bloque) {
            foreach ($bloque['renglones'] ?? [] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $cod = (string) ($r['codigo'] ?? '');
                if ($cod === self::RENGLON_TOTAL_CUENTA || !in_array($cod, self::RENGLONES, true)) {
                    continue;
                }
                $cant[$cod] += (int) ($r['cantidad'] ?? 0);
                $montos[$cod] += (float) ($r['monto_eur'] ?? 0);
                if ($units === null && isset($r['costo_tarifa_eur'])) {
                    $units = (float) $r['costo_tarifa_eur'];
                }
            }
        }
        $etiquetas = [
            'afiliacion' => 'Afiliados',
            'carnet' => 'Carnets',
            'traspaso' => 'Traspasos',
            'anualidad' => 'Anualidad',
            'inscripcion' => 'Inscripciones',
        ];
        $out = [];
        foreach (self::RENGLONES as $cod) {
            $out[] = [
                'codigo' => $cod,
                'etiqueta' => $etiquetas[$cod] ?? $cod,
                'cantidad' => $cant[$cod],
                'monto_eur' => round($montos[$cod], 2),
                'costo_tarifa_eur' => $units,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $bloques
     *
     * @return array{monto_nomina_eur: float, monto_total_eur: float}
     */
    public static function totalesConsolidadosDesdeBloquesInforme(array $bloques): array
    {
        $sum = 0.0;
        foreach (self::renglonesConsolidadosDesdeBloquesInforme($bloques) as $r) {
            $sum += (float) ($r['monto_eur'] ?? 0);
        }
        $sum = round($sum, 2);

        return [
            'monto_nomina_eur' => $sum,
            'monto_total_eur' => $sum,
        ];
    }

    private static function ordenVarianteCampeonato(string $etiqueta): int
    {
        if (str_contains($etiqueta, 'Sub 12')) {
            return 1;
        }
        if (str_contains($etiqueta, 'Masculino')) {
            return 2;
        }
        if (str_contains($etiqueta, 'Sub 15')) {
            return 3;
        }
        if (str_contains($etiqueta, 'Femenino')) {
            return 4;
        }
        if (str_contains($etiqueta, 'Sub 18')) {
            return 5;
        }

        return 99;
    }

    /**
     * Clasifica texto libre de `finanza_cargo.concepto` en un renglón del informe.
     */
    public static function bucketConcepto(string $concepto): string
    {
        $c = function_exists('mb_strtolower')
            ? mb_strtolower(trim($concepto), 'UTF-8')
            : strtolower(trim($concepto));
        if ($c === '') {
            return 'otros';
        }
        if (str_contains($c, 'traspaso')) {
            return 'traspaso';
        }
        if (str_contains($c, 'inscripc')) {
            return 'inscripcion';
        }
        if (str_contains($c, 'carnet')) {
            return 'carnet';
        }
        if (str_contains($c, 'anual')) {
            return 'anualidad';
        }
        if (str_contains($c, 'afil')) {
            return 'afiliacion';
        }

        return 'otros';
    }

    /**
     * Suma de todos los cargos manuales por asociación (`finanza_cargo`).
     *
     * @return array<string, float> clave asociacion_id string
     */
    public static function sumaCargosManualesPorAsociacion(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                'SELECT asociacion_id, COALESCE(SUM(monto_eur), 0) AS s
                FROM ' . self::T_CARGO . '
                WHERE asociacion_id IS NOT NULL AND asociacion_id > 0
                GROUP BY asociacion_id'
            );
            if ($stmt === false) {
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === false) {
                return [];
            }
            $out = [];
            foreach ($rows as $r) {
                $aid = (int) ($r['asociacion_id'] ?? 0);
                if ($aid < 1) {
                    continue;
                }
                $out[(string) $aid] = round((float) ($r['s'] ?? 0), 2);
            }

            return $out;
        } catch (Throwable $e) {
            error_log('InformeFvd::sumaCargosManualesPorAsociacion: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Deuda informada por asociación: nómina (torneo indicado o todos) + cargos manuales.
     *
     * @param int|null $torneoIdNomina null = todos los torneos con movimiento
     *
     * @return array<string, float>
     */
    /**
     * Deuda informada (nómina en torneos indicados + cargos manuales globales).
     *
     * @param list<int> $torneoIds vacío = todos los torneos en nómina
     *
     * @return array<string, float>
     */
    public static function deudaInformadaPorAsociacionTorneos(\PDO $pdo, array $torneoIds): array
    {
        $ids = [];
        foreach ($torneoIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $ids = array_keys($ids);
        $counts = $ids === []
            ? MovimientoTorneoContadores::contadoresPorAsociacion($pdo, null)
            : MovimientoTorneoContadores::contadoresPorAsociacionTorneos($pdo, $ids);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $cargos = self::sumaCargosManualesPorAsociacion($pdo);
        $keys = array_unique(array_merge(array_keys($counts), array_keys($cargos)));
        $out = [];
        foreach ($keys as $sk) {
            $c = $counts[$sk] ?? MovimientoTorneoContadores::filaVacia();
            $m = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $nomina = MovimientoTorneoContadores::totalNominaDesdeMontos($m);
            $out[$sk] = round($nomina + ($cargos[$sk] ?? 0.0), 2);
        }

        return $out;
    }

    public static function deudaInformadaPorAsociacion(\PDO $pdo, ?int $torneoIdNomina = null): array
    {
        if ($torneoIdNomina !== null && $torneoIdNomina > 0) {
            return self::deudaInformadaPorAsociacionTorneos($pdo, [$torneoIdNomina]);
        }
        $counts = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, null);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $cargos = self::sumaCargosManualesPorAsociacion($pdo);
        $keys = array_unique(array_merge(array_keys($counts), array_keys($cargos)));
        $out = [];
        foreach ($keys as $sk) {
            $c = $counts[$sk] ?? MovimientoTorneoContadores::filaVacia();
            $m = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $nomina = MovimientoTorneoContadores::totalNominaDesdeMontos($m);
            $out[$sk] = round($nomina + ($cargos[$sk] ?? 0.0), 2);
        }

        return $out;
    }

    /**
     * Total cuenta de una asociación (todos los formularios / torneos + cargos manuales).
     *
     * @param int|null $torneoIdNomina null = nómina en todos los torneos; si se indica, solo ese torneo en la parte nómina
     *
     * @return array{
     *   deuda_eur: float,
     *   nomina_eur: float,
     *   cargos_manuales_eur: float,
     *   pagado_eur: float,
     *   saldo_eur: float
     * }
     */
    public static function totalCuentaAsociacionEur(\PDO $pdo, int $asociacionId, ?int $torneoIdNomina = null): array
    {
        if ($asociacionId < 1) {
            return [
                'deuda_eur' => 0.0,
                'nomina_eur' => 0.0,
                'cargos_manuales_eur' => 0.0,
                'pagado_eur' => 0.0,
                'saldo_eur' => 0.0,
            ];
        }
        $sk = (string) $asociacionId;
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        if ($torneoIdNomina !== null && $torneoIdNomina > 0) {
            $c = MovimientoTorneoContadores::contadoresTorneoAsociacion($pdo, $torneoIdNomina, $asociacionId);
        } else {
            $counts = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, null);
            $c = $counts[$sk] ?? MovimientoTorneoContadores::filaVacia();
        }
        $m = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
        $nomina = MovimientoTorneoContadores::totalNominaDesdeMontos($m);
        $cargosMap = self::sumaCargosManualesPorAsociacion($pdo);
        $cargos = $cargosMap[$sk] ?? 0.0;
        $deuda = round($nomina + $cargos, 2);
        $pag = FinanzaFvd::pagadoVerificadoEur($pdo, $asociacionId);

        return [
            'deuda_eur' => $deuda,
            'nomina_eur' => $nomina,
            'cargos_manuales_eur' => $cargos,
            'pagado_eur' => $pag,
            'saldo_eur' => round($deuda - $pag, 2),
        ];
    }

    /**
     * Suma de cargos clasificados como «otros» por asociación.
     *
     * @return array<string, float> clave asociacion_id string
     */
    private static function montosOtrosPorAsociacion(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                'SELECT asociacion_id, concepto, monto_eur FROM ' . self::T_CARGO
            );
            if ($stmt === false) {
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === false) {
                return [];
            }
            $out = [];
            foreach ($rows as $r) {
                if (self::bucketConcepto((string) ($r['concepto'] ?? '')) !== 'otros') {
                    continue;
                }
                $aid = (int) ($r['asociacion_id'] ?? 0);
                if ($aid < 1) {
                    continue;
                }
                $key = (string) $aid;
                if (!isset($out[$key])) {
                    $out[$key] = 0.0;
                }
                $out[$key] = round($out[$key] + round((float) ($r['monto_eur'] ?? 0), 2), 2);
            }

            return $out;
        } catch (Throwable $e) {
            error_log('InformeFvd::montosOtrosPorAsociacion: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param int|null $torneoIdInforme si null, {@see resolverTorneoIdInformeMovimiento()}
     */
    public static function resumenConsolidadoPorAsociacion(\PDO $pdo, ?int $torneoIdInforme = null): array
    {
        $tid = self::resolverTorneoIdInformeMovimiento($pdo, $torneoIdInforme);
        $counts = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, $tid);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $stmt = $pdo->query('SELECT id, nombre, estatus, logo FROM ' . self::T_ASOC . ' ORDER BY nombre ASC');
        if ($stmt === false) {
            return [];
        }
        $asocs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($asocs === false) {
            return [];
        }

        $out = [];
        foreach ($asocs as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $sk = (string) $id;
            $c = $counts[$sk] ?? MovimientoTorneoContadores::filaVacia();
            $mCalc = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $m = [
                'afiliacion' => $mCalc['afiliacion'],
                'carnet' => $mCalc['carnet'],
                'traspaso' => $mCalc['traspaso'],
                'anualidad' => $mCalc['anualidad'],
                'inscripcion' => $mCalc['inscripcion'],
            ];
            $montoNomina = round(
                $m['afiliacion'] + $m['carnet'] + $m['traspaso'] + $m['anualidad'] + $m['inscripcion'],
                2
            );
            $out[] = [
                'id' => $id,
                'nombre' => $a['nombre'] ?? '',
                'estatus' => $a['estatus'] ?? 0,
                'logo' => $a['logo'] ?? null,
                'torneo_informe_id' => $tid,
                'n_afiliacion' => $c['n_afiliacion'],
                'n_carnet' => $c['n_carnet'],
                'n_traspaso' => $c['n_traspaso'],
                'n_anualidad' => $c['n_anualidad'],
                'n_inscripcion' => $c['n_inscripcion'],
                'monto_afiliacion_eur' => $m['afiliacion'],
                'monto_carnet_eur' => $m['carnet'],
                'monto_traspaso_eur' => $m['traspaso'],
                'monto_anualidad_eur' => $m['anualidad'],
                'monto_inscripcion_eur' => $m['inscripcion'],
                'monto_nomina_eur' => $montoNomina,
                'monto_total_eur' => $montoNomina,
            ];
        }

        return $out;
    }

    /**
     * Suma cantidades y montos € por renglón (afiliación, anualidad, carnet, traspaso, inscripción).
     *
     * @param list<array<string, mixed>> $filas
     *
     * @return array<string, int|float>
     */
    public static function totalesRenglonesConsolidado(array $filas): array
    {
        $t = [
            'n_afiliacion' => 0,
            'n_anualidad' => 0,
            'n_carnet' => 0,
            'n_traspaso' => 0,
            'n_inscripcion' => 0,
            'monto_afiliacion_eur' => 0.0,
            'monto_anualidad_eur' => 0.0,
            'monto_carnet_eur' => 0.0,
            'monto_traspaso_eur' => 0.0,
            'monto_inscripcion_eur' => 0.0,
        ];
        foreach ($filas as $row) {
            $t['n_afiliacion'] += (int) ($row['n_afiliacion'] ?? 0);
            $t['n_anualidad'] += (int) ($row['n_anualidad'] ?? 0);
            $t['n_carnet'] += (int) ($row['n_carnet'] ?? 0);
            $t['n_traspaso'] += (int) ($row['n_traspaso'] ?? 0);
            $t['n_inscripcion'] += (int) ($row['n_inscripcion'] ?? 0);
            $t['monto_afiliacion_eur'] = round($t['monto_afiliacion_eur'] + (float) ($row['monto_afiliacion_eur'] ?? 0), 2);
            $t['monto_anualidad_eur'] = round($t['monto_anualidad_eur'] + (float) ($row['monto_anualidad_eur'] ?? 0), 2);
            $t['monto_carnet_eur'] = round($t['monto_carnet_eur'] + (float) ($row['monto_carnet_eur'] ?? 0), 2);
            $t['monto_traspaso_eur'] = round($t['monto_traspaso_eur'] + (float) ($row['monto_traspaso_eur'] ?? 0), 2);
            $t['monto_inscripcion_eur'] = round($t['monto_inscripcion_eur'] + (float) ($row['monto_inscripcion_eur'] ?? 0), 2);
        }

        return $t;
    }

    /**
     * Metadatos de renglón financiero (orden de presentación).
     *
     * @return list<array{codigo: string, etiqueta: string, n_key: string, tarifa_key: string}>
     */
    public static function metaRenglonesFinanzas(): array
    {
        return [
            ['codigo' => 'afiliacion', 'etiqueta' => 'Afiliación', 'n_key' => 'n_afiliacion', 'tarifa_key' => 'afiliacion'],
            ['codigo' => 'anualidad', 'etiqueta' => 'Anualidad', 'n_key' => 'n_anualidad', 'tarifa_key' => 'anualidad'],
            ['codigo' => 'carnet', 'etiqueta' => 'Carnet', 'n_key' => 'n_carnet', 'tarifa_key' => 'carnet'],
            ['codigo' => 'traspaso', 'etiqueta' => 'Traspaso', 'n_key' => 'n_traspaso', 'tarifa_key' => 'traspaso'],
            ['codigo' => 'inscripcion', 'etiqueta' => 'Inscripción', 'n_key' => 'n_inscripcion', 'tarifa_key' => 'inscripcion'],
        ];
    }

    /**
     * @param array{n_afiliacion: int, n_carnet: int, n_traspaso: int, n_anualidad: int, n_inscripcion: int} $c
     * @param array{afiliacion: float, carnet: float, traspaso: float, anualidad: float, inscripcion: float} $units
     *
     * @return list<array{codigo: string, etiqueta: string, cantidad: int, tarifa_eur: float, monto_eur: float}>
     */
    public static function renglonesDesdeContadores(array $c, array $units): array
    {
        $montos = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
        $out = [];
        foreach (self::metaRenglonesFinanzas() as $m) {
            $qty = (int) ($c[$m['n_key']] ?? 0);
            $tar = round((float) ($units[$m['tarifa_key']] ?? 0), 2);
            $cod = $m['codigo'];
            $out[] = [
                'codigo' => $cod,
                'etiqueta' => $m['etiqueta'],
                'cantidad' => $qty,
                'tarifa_eur' => $tar,
                'monto_eur' => round((float) ($montos[$cod] ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Resumen FVD por periodo: totales por evento (renglón) y desglose por asociación.
     *
     * @return array<string, mixed>
     */
    public static function resumenFinanzasPeriodo(
        \PDO $pdo,
        ?int $torneoId = null,
        ?int $grupoEventoId = null,
        ?int $soloAsociacionId = null
    ): array {
        $torneoIds = null;
        $modo = 'periodo_completo';
        $etiqueta = 'Periodo completo — todos los torneos';
        $torneoFiltro = null;
        $grupoMeta = null;
        $asocFiltro = null;
        if ($soloAsociacionId !== null && $soloAsociacionId > 0) {
            $stAs = $pdo->prepare('SELECT id, nombre FROM ' . self::T_ASOC . ' WHERE id = :id LIMIT 1');
            $stAs->bindValue(':id', $soloAsociacionId, \PDO::PARAM_INT);
            $stAs->execute();
            $ar = $stAs->fetch(\PDO::FETCH_ASSOC);
            if ($ar !== false) {
                $asocFiltro = [
                    'id' => (int) ($ar['id'] ?? 0),
                    'nombre' => (string) ($ar['nombre'] ?? ''),
                ];
            }
        }

        if ($grupoEventoId !== null && $grupoEventoId > 0) {
            $modo = 'campeonato';
            $torneoIds = [];
            foreach (TorneoCampeonato::torneosDeGrupo($pdo, $grupoEventoId) as $tr) {
                $tid = (int) ($tr['torneo'] ?? 0);
                if ($tid > 0) {
                    $torneoIds[] = $tid;
                }
            }
            $estruct = self::torneosSelectorEstructurado($pdo);
            foreach ($estruct['campeonatos'] as $camp) {
                if ((int) ($camp['grupo_evento_id'] ?? 0) === $grupoEventoId) {
                    $etiqueta = (string) ($camp['etiqueta'] ?? 'Campeonato #' . $grupoEventoId);
                    break;
                }
            }
            if ($etiqueta === 'Periodo completo — todos los torneos') {
                $etiqueta = 'Campeonato #' . $grupoEventoId;
            }
            $grupoMeta = ['grupo_evento_id' => $grupoEventoId, 'etiqueta' => $etiqueta, 'n_torneos' => count($torneoIds)];
        } elseif ($torneoId !== null && $torneoId > 0) {
            $modo = 'torneo';
            $tf = self::torneoFilaPorId($pdo, $torneoId);
            $torneoFiltro = self::torneoJsonDesdeFila($tf);
            $etiqueta = $torneoFiltro !== null
                ? (string) ($torneoFiltro['nombre'] ?? 'Torneo #' . $torneoId)
                : 'Torneo #' . $torneoId;
        }

        $aidScope = $soloAsociacionId !== null && $soloAsociacionId > 0 ? $soloAsociacionId : null;
        $countsGlobal = MovimientoTorneoContadores::contadoresGlobales(
            $pdo,
            $modo === 'torneo' ? $torneoId : null,
            $torneoIds,
            $aidScope
        );
        if ($modo === 'campeonato' && $torneoIds !== null && $torneoIds !== []) {
            $countsPorAsoc = MovimientoTorneoContadores::contadoresPorAsociacionTorneos($pdo, $torneoIds);
        } elseif ($modo === 'torneo' && $torneoId > 0) {
            $countsPorAsoc = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, $torneoId);
        } else {
            $countsPorAsoc = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, null);
        }
        if ($aidScope > 0 && $asocFiltro !== null) {
            $etiqueta = $modo === 'periodo_completo'
                ? 'Periodo completo — ' . $asocFiltro['nombre']
                : $etiqueta . ' · ' . $asocFiltro['nombre'];
        }

        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $renglones = self::renglonesDesdeContadores($countsGlobal, $units);
        $totalNomina = MovimientoTorneoContadores::totalNominaDesdeMontos(
            MovimientoTorneoContadores::montosPorTarifaYContadores($countsGlobal, $units)
        );
        $totalCantidad = 0;
        foreach ($renglones as $r) {
            $totalCantidad += (int) ($r['cantidad'] ?? 0);
        }

        if ($aidScope > 0) {
            $stmt = $pdo->prepare('SELECT id, nombre, estatus, logo FROM ' . self::T_ASOC . ' WHERE id = :id LIMIT 1');
            $stmt->bindValue(':id', $aidScope, \PDO::PARAM_INT);
            $stmt->execute();
            $asocs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $stmt = $pdo->query('SELECT id, nombre, estatus, logo FROM ' . self::T_ASOC . ' ORDER BY nombre ASC');
            $asocs = $stmt === false ? [] : ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        }
        $asociaciones = [];
        foreach ($asocs as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if ($aidScope > 0 && $id !== $aidScope) {
                continue;
            }
            $c = $countsPorAsoc[(string) $id] ?? MovimientoTorneoContadores::filaVacia();
            $rengAsoc = self::renglonesDesdeContadores($c, $units);
            $mNom = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $totAsoc = MovimientoTorneoContadores::totalNominaDesdeMontos($mNom);
            $qtyAsoc = 0;
            foreach ($rengAsoc as $rr) {
                $qtyAsoc += (int) ($rr['cantidad'] ?? 0);
            }
            if ($qtyAsoc < 1 && $totAsoc <= 0.0) {
                continue;
            }
            $asociaciones[] = [
                'id' => $id,
                'nombre' => (string) ($a['nombre'] ?? ''),
                'estatus' => $a['estatus'] ?? 0,
                'logo' => $a['logo'] ?? null,
                'renglones' => $rengAsoc,
                'total_cantidad' => $qtyAsoc,
                'total_nomina_eur' => $totAsoc,
            ];
        }

        return [
            'periodo' => [
                'modo' => $modo,
                'etiqueta' => $etiqueta,
                'torneo_id' => $modo === 'torneo' ? $torneoId : null,
                'grupo_evento_id' => $modo === 'campeonato' ? $grupoEventoId : null,
                'torneo' => $torneoFiltro,
                'campeonato' => $grupoMeta,
                'asociacion_id' => $aidScope,
                'asociacion' => $asocFiltro,
            ],
            'tarifas_eur' => $units,
            'renglones' => $renglones,
            'total_cantidad' => $totalCantidad,
            'total_nomina_eur' => $totalNomina,
            'asociaciones' => $asociaciones,
        ];
    }

    /**
     * Una fila por torneo con cantidades y montos por evento (periodo / campeonato / asociación).
     *
     * @return array{
     *   filas_torneos: list<array<string, mixed>>,
     *   totales: array{renglones: list<array<string, mixed>>, total_cantidad: int, total_nomina_eur: float},
     *   tarifas_eur: array<string, float>,
     *   periodo: array<string, mixed>
     * }
     */
    public static function resumenFinanzasFilasPorTorneo(
        \PDO $pdo,
        ?int $grupoEventoId = null,
        ?int $soloAsociacionId = null
    ): array {
        $torneos = self::torneosConNominaParaSelector($pdo);
        if ($soloAsociacionId !== null && $soloAsociacionId > 0) {
            $torneos = self::torneosConMovimientoParaAsociacion($pdo, $soloAsociacionId);
        }
        if ($grupoEventoId !== null && $grupoEventoId > 0) {
            $idsGrupo = [];
            foreach (TorneoCampeonato::torneosDeGrupo($pdo, $grupoEventoId) as $tr) {
                $tid = (int) ($tr['torneo'] ?? 0);
                if ($tid > 0) {
                    $idsGrupo[$tid] = true;
                }
            }
            $torneos = array_values(array_filter(
                $torneos,
                static fn (array $t): bool => isset($idsGrupo[(int) ($t['torneo_id'] ?? 0)])
            ));
        }

        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $filasTorneos = [];
        $sumCounts = MovimientoTorneoContadores::filaVacia();

        foreach ($torneos as $meta) {
            $tid = (int) ($meta['torneo_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $c = MovimientoTorneoContadores::contadoresGlobales($pdo, $tid, null, $soloAsociacionId);
            foreach (array_keys($sumCounts) as $k) {
                $sumCounts[$k] += (int) ($c[$k] ?? 0);
            }
            $renglones = self::renglonesDesdeContadores($c, $units);
            $totM = MovimientoTorneoContadores::totalNominaDesdeMontos(
                MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units)
            );
            $totQ = 0;
            foreach ($renglones as $rr) {
                $totQ += (int) ($rr['cantidad'] ?? 0);
            }
            if ($totQ < 1 && $totM <= 0.0) {
                continue;
            }
            $filasTorneos[] = [
                'torneo_id' => $tid,
                'nombre' => (string) ($meta['nombre'] ?? ''),
                'fechator' => $meta['fechator'] ?? null,
                'finalizado_en' => $meta['finalizado_en'] ?? null,
                'renglones' => $renglones,
                'total_cantidad' => $totQ,
                'total_nomina_eur' => $totM,
            ];
        }

        $renglonesTot = self::renglonesDesdeContadores($sumCounts, $units);
        $totalNomina = MovimientoTorneoContadores::totalNominaDesdeMontos(
            MovimientoTorneoContadores::montosPorTarifaYContadores($sumCounts, $units)
        );
        $totalCantidad = 0;
        foreach ($renglonesTot as $r) {
            $totalCantidad += (int) ($r['cantidad'] ?? 0);
        }

        $etiqueta = 'Periodo completo — todos los torneos';
        $grupoMeta = null;
        if ($grupoEventoId !== null && $grupoEventoId > 0) {
            $estruct = self::torneosSelectorEstructurado($pdo);
            foreach ($estruct['campeonatos'] as $camp) {
                if ((int) ($camp['grupo_evento_id'] ?? 0) === $grupoEventoId) {
                    $etiqueta = (string) ($camp['etiqueta'] ?? 'Campeonato #' . $grupoEventoId);
                    break;
                }
            }
            if ($etiqueta === 'Periodo completo — todos los torneos') {
                $etiqueta = 'Campeonato #' . $grupoEventoId;
            }
            $grupoMeta = ['grupo_evento_id' => $grupoEventoId, 'etiqueta' => $etiqueta];
        }
        if ($soloAsociacionId !== null && $soloAsociacionId > 0) {
            $st = $pdo->prepare('SELECT nombre FROM ' . self::T_ASOC . ' WHERE id = :id LIMIT 1');
            $st->bindValue(':id', $soloAsociacionId, \PDO::PARAM_INT);
            $st->execute();
            $nom = (string) ($st->fetchColumn() ?: '');
            if ($nom !== '') {
                $etiqueta .= ' · ' . $nom;
            }
        }

        return [
            'periodo' => [
                'modo' => $grupoEventoId > 0 ? 'campeonato' : 'periodo_completo',
                'etiqueta' => $etiqueta,
                'grupo_evento_id' => $grupoEventoId > 0 ? $grupoEventoId : null,
                'campeonato' => $grupoMeta,
                'asociacion_id' => $soloAsociacionId > 0 ? $soloAsociacionId : null,
            ],
            'tarifas_eur' => $units,
            'filas_torneos' => $filasTorneos,
            'totales' => [
                'renglones' => $renglonesTot,
                'total_cantidad' => $totalCantidad,
                'total_nomina_eur' => $totalNomina,
            ],
        ];
    }

    /** Porcentaje sobre base; null si base ≤ 0. */
    public static function pctSobreBase(float $parte, float $base): ?float
    {
        if ($base <= 0.0) {
            return null;
        }

        return round(100 * $parte / $base, 1);
    }

    /**
     * @param list<array<string, mixed>> $renglones
     *
     * @return list<array<string, mixed>>
     */
    public static function renglonesConPctIngreso(array $renglones, float $ingresoEur): array
    {
        return array_map(
            static function (array $r) use ($ingresoEur): array {
                $m = (float) ($r['monto_eur'] ?? 0);
                $r['pct_ingreso'] = self::pctSobreBase($m, $ingresoEur);

                return $r;
            },
            $renglones
        );
    }

    /**
     * @param array{total_bs?: float, total_eur?: float, n?: int} $gastosTotales
     *
     * @return array{
     *   ingresos_eur: float,
     *   gastos_eur: float,
     *   gastos_bs: float,
     *   resultado_eur: float,
     *   pct_gastos_ingreso: ?float,
     *   pct_resultado_ingreso: ?float,
     *   gastos_registros: int
     * }
     */
    public static function cajaResultadoTorneo(float $ingresoEur, array $gastosTotales): array
    {
        $gEur = round((float) ($gastosTotales['total_eur'] ?? 0), 2);
        $ing = round($ingresoEur, 2);
        $res = round($ing - $gEur, 2);

        return [
            'ingresos_eur' => $ing,
            'gastos_eur' => $gEur,
            'gastos_bs' => round((float) ($gastosTotales['total_bs'] ?? 0), 2),
            'resultado_eur' => $res,
            'pct_gastos_ingreso' => self::pctSobreBase($gEur, $ing),
            'pct_resultado_ingreso' => self::pctSobreBase($res, $ing),
            'gastos_registros' => (int) ($gastosTotales['n'] ?? 0),
        ];
    }

    /**
     * Listado por torneo: ingresos (nómina), gastos operativos y resultado con %.
     *
     * @return array<string, mixed>
     */
    public static function resultadoFinancieroFilasPorTorneo(
        \PDO $pdo,
        ?int $grupoEventoId = null,
        ?int $soloAsociacionId = null
    ): array {
        $base = self::resumenFinanzasFilasPorTorneo($pdo, $grupoEventoId, $soloAsociacionId);
        $filas = [];
        $sumGastosEur = 0.0;
        $sumGastosBs = 0.0;
        $sumGastosN = 0;
        foreach ($base['filas_torneos'] as $row) {
            $tid = (int) ($row['torneo_id'] ?? 0);
            $gTot = FinanzaGastoTorneo::totalesPorTorneo($pdo, $tid);
            $ing = (float) ($row['total_nomina_eur'] ?? 0);
            $caja = self::cajaResultadoTorneo($ing, $gTot);
            $sumGastosEur += $caja['gastos_eur'];
            $sumGastosBs += $caja['gastos_bs'];
            $sumGastosN += $caja['gastos_registros'];
            $filas[] = array_merge($row, $caja, [
                'renglones' => self::renglonesConPctIngreso($row['renglones'] ?? [], $ing),
            ]);
        }
        $totIng = (float) ($base['totales']['total_nomina_eur'] ?? 0);
        $cajaTot = self::cajaResultadoTorneo($totIng, [
            'total_eur' => $sumGastosEur,
            'total_bs' => $sumGastosBs,
            'n' => $sumGastosN,
        ]);
        $base['filas_torneos'] = $filas;
        $base['totales'] = array_merge($base['totales'], $cajaTot, [
            'renglones' => self::renglonesConPctIngreso($base['totales']['renglones'] ?? [], $totIng),
        ]);

        return $base;
    }

    /**
     * Desglose de un torneo: ingresos por concepto, gastos por concepto y líneas.
     *
     * @return array<string, mixed>
     */
    public static function resultadoFinancieroDetalleTorneo(
        \PDO $pdo,
        int $torneoId,
        ?int $soloAsociacionId = null
    ): array {
        $resumen = self::resumenFinanzasPeriodo($pdo, $torneoId, null, $soloAsociacionId);
        $ing = (float) ($resumen['total_nomina_eur'] ?? 0);
        $gCons = FinanzaGastoTorneo::consolidadoPorConcepto($pdo, $torneoId, $ing);
        $caja = self::cajaResultadoTorneo($ing, $gCons['totales']);

        return [
            'periodo' => $resumen['periodo'],
            'tarifas_eur' => $resumen['tarifas_eur'],
            'ingresos' => [
                'renglones' => self::renglonesConPctIngreso($resumen['renglones'] ?? [], $ing),
                'total_nomina_eur' => $ing,
                'total_cantidad' => (int) ($resumen['total_cantidad'] ?? 0),
            ],
            'gastos' => $gCons,
            'resultado' => $caja,
            'asociaciones' => $resumen['asociaciones'] ?? [],
        ];
    }

    /**
     * Añade contadores y montos por renglón a filas de resumen financiero (mismo torneo).
     *
     * @param list<array<string, mixed>> $filas
     */
    /**
     * @param list<int> $torneoIds
     */
    public static function adjuntarEstadisticasRenglonAResumenTorneos(\PDO $pdo, array &$filas, array $torneoIds): void
    {
        $ids = [];
        foreach ($torneoIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return;
        }
        if (count($ids) === 1) {
            self::adjuntarEstadisticasRenglonAResumen($pdo, $filas, $ids[0]);

            return;
        }
        $counts = MovimientoTorneoContadores::contadoresPorAsociacionTorneos($pdo, $ids);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        foreach ($filas as &$r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $c = $counts[(string) $id] ?? MovimientoTorneoContadores::filaVacia();
            $mCalc = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $r['n_afiliacion'] = (int) ($c['n_afiliacion'] ?? 0);
            $r['n_anualidad'] = (int) ($c['n_anualidad'] ?? 0);
            $r['n_carnet'] = (int) ($c['n_carnet'] ?? 0);
            $r['n_traspaso'] = (int) ($c['n_traspaso'] ?? 0);
            $r['n_inscripcion'] = (int) ($c['n_inscripcion'] ?? 0);
            $r['monto_afiliacion_eur'] = round((float) ($mCalc['afiliacion'] ?? 0), 2);
            $r['monto_anualidad_eur'] = round((float) ($mCalc['anualidad'] ?? 0), 2);
            $r['monto_carnet_eur'] = round((float) ($mCalc['carnet'] ?? 0), 2);
            $r['monto_traspaso_eur'] = round((float) ($mCalc['traspaso'] ?? 0), 2);
            $r['monto_inscripcion_eur'] = round((float) ($mCalc['inscripcion'] ?? 0), 2);
        }
        unset($r);
    }

    public static function adjuntarEstadisticasRenglonAResumen(\PDO $pdo, array &$filas, int $torneoId): void
    {
        if ($torneoId < 1) {
            return;
        }
        $counts = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, $torneoId);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        foreach ($filas as &$r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $c = $counts[(string) $id] ?? MovimientoTorneoContadores::filaVacia();
            $mCalc = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
            $r['n_afiliacion'] = (int) ($c['n_afiliacion'] ?? 0);
            $r['n_anualidad'] = (int) ($c['n_anualidad'] ?? 0);
            $r['n_carnet'] = (int) ($c['n_carnet'] ?? 0);
            $r['n_traspaso'] = (int) ($c['n_traspaso'] ?? 0);
            $r['n_inscripcion'] = (int) ($c['n_inscripcion'] ?? 0);
            $r['monto_afiliacion_eur'] = round((float) ($mCalc['afiliacion'] ?? 0), 2);
            $r['monto_anualidad_eur'] = round((float) ($mCalc['anualidad'] ?? 0), 2);
            $r['monto_carnet_eur'] = round((float) ($mCalc['carnet'] ?? 0), 2);
            $r['monto_traspaso_eur'] = round((float) ($mCalc['traspaso'] ?? 0), 2);
            $r['monto_inscripcion_eur'] = round((float) ($mCalc['inscripcion'] ?? 0), 2);
        }
        unset($r);
    }

    /**
     * Detalle por asociación (renglones + totales; sin listado de movimientos).
     * Equivale a {@see detalleAsociacionParaTorneo} con torneo activo y fila de total de cuenta.
     *
     * @return array<string, mixed>
     */
    public static function detalleAsociacion(\PDO $pdo, int $asociacionId, ?int $torneoIdInforme = null): array
    {
        $tid = self::resolverTorneoIdInformeMovimiento($pdo, $torneoIdInforme);

        return self::detalleAsociacionParaTorneo($pdo, $asociacionId, $tid, true);
    }

    /**
     * Detalle por asociación para un torneo concreto.
     *
     * @param int|null $torneoId id de `torneosact`; null = {@see resolverTorneoIdInformeMovimiento()}
     * @param bool       $incluirTotalCuenta si true, añade renglón «Total cuenta asociación» (deuda/pagado/saldo); en listas multi-torneo solo una vez.
     *
     * @return array<string, mixed>
     */
    public static function detalleAsociacionParaTorneo(\PDO $pdo, int $asociacionId, ?int $torneoId, bool $incluirTotalCuenta): array
    {
        $stmt = $pdo->prepare('SELECT id, nombre, estatus, logo FROM ' . self::T_ASOC . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $asoc = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($asoc === false) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }

        $tf = null;
        if ($torneoId !== null && $torneoId > 0) {
            $tf = self::torneoFilaPorId($pdo, $torneoId);
            if ($tf === null) {
                throw new InvalidArgumentException('Torneo no encontrado.');
            }
            $tid = (int) $torneoId;
        } else {
            $tid = self::resolverTorneoIdInformeMovimiento($pdo, null);
            $tf = $tid !== null ? self::torneoFilaPorId($pdo, $tid) : null;
        }
        $torneoActivoJson = self::torneoJsonDesdeFila($tf);

        $counts = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, $tid);
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $sk = (string) $asociacionId;
        $c = $counts[$sk] ?? MovimientoTorneoContadores::filaVacia();
        $mCalc = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
        $m = [
            'afiliacion' => $mCalc['afiliacion'],
            'carnet' => $mCalc['carnet'],
            'traspaso' => $mCalc['traspaso'],
            'anualidad' => $mCalc['anualidad'],
            'inscripcion' => $mCalc['inscripcion'],
        ];

        $renglones = [
            ['codigo' => 'afiliacion', 'etiqueta' => 'Afiliados', 'cantidad' => $c['n_afiliacion'], 'monto_eur' => $m['afiliacion'], 'costo_tarifa_eur' => round($units['afiliacion'], 2)],
            ['codigo' => 'carnet', 'etiqueta' => 'Carnets', 'cantidad' => $c['n_carnet'], 'monto_eur' => $m['carnet'], 'costo_tarifa_eur' => round($units['carnet'], 2)],
            ['codigo' => 'traspaso', 'etiqueta' => 'Traspasos', 'cantidad' => $c['n_traspaso'], 'monto_eur' => $m['traspaso'], 'costo_tarifa_eur' => round($units['traspaso'], 2)],
            ['codigo' => 'anualidad', 'etiqueta' => 'Anualidad', 'cantidad' => $c['n_anualidad'], 'monto_eur' => $m['anualidad'], 'costo_tarifa_eur' => round($units['anualidad'], 2)],
            ['codigo' => 'inscripcion', 'etiqueta' => 'Inscripciones', 'cantidad' => $c['n_inscripcion'], 'monto_eur' => $m['inscripcion'], 'costo_tarifa_eur' => round($units['inscripcion'], 2)],
        ];
        $montoNomina = round(
            $m['afiliacion'] + $m['carnet'] + $m['traspaso'] + $m['anualidad'] + $m['inscripcion'],
            2
        );

        $estadoCuenta = null;
        if ($incluirTotalCuenta) {
            $estadoCuenta = self::totalCuentaAsociacionEur($pdo, $asociacionId, null);
            $renglones[] = [
                'codigo' => self::RENGLON_TOTAL_CUENTA,
                'etiqueta' => 'Total cuenta asociación',
                'cantidad' => null,
                'monto_eur' => $estadoCuenta['deuda_eur'],
                'pagado_eur' => $estadoCuenta['pagado_eur'],
                'saldo_eur' => $estadoCuenta['saldo_eur'],
                'nomina_eur' => $estadoCuenta['nomina_eur'],
                'cargos_manuales_eur' => $estadoCuenta['cargos_manuales_eur'],
                'costo_tarifa_eur' => null,
            ];
        }

        return [
            'asociacion' => $asoc,
            'torneo_activo_id' => $tid,
            'torneo_activo' => $torneoActivoJson,
            'renglones' => $renglones,
            'totales' => [
                'monto_nomina_eur' => $montoNomina,
                'monto_total_eur' => $montoNomina,
                'estado_cuenta' => $estadoCuenta,
            ],
        ];
    }

    /**
     * Listado de movimientos y cargos para un renglón concreto.
     *
     * @return array{renglon: string, movimientos: list<array<string, mixed>>, cargos: list<array<string, mixed>>}
     */
    public static function detalleRenglon(\PDO $pdo, int $asociacionId, string $renglon, ?int $torneoIdInforme = null): array
    {
        $renglon = trim($renglon);
        if (!in_array($renglon, self::RENGLONES, true)) {
            throw new InvalidArgumentException('Renglón no válido.');
        }

        $colMap = [
            'afiliacion' => 'afiliacion',
            'carnet' => 'carnet',
            'traspaso' => 'traspaso',
            'anualidad' => 'anualidad',
            'inscripcion' => 'inscripcion',
        ];
        $col = $colMap[$renglon];
        $tid = self::resolverTorneoIdInformeMovimiento($pdo, $torneoIdInforme);

        $sql = 'SELECT m.id, m.torneo_id, m.cedula, m.numfvd, m.sexo, m.' . $col . ' AS flag_renglon,
                u.nombre AS nombre_usuario
            FROM ' . self::T_MOV . ' m
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.asociacion_id = :a AND m.' . $col . ' = 1';
        if ($tid !== null && $tid > 0) {
            $sql .= ' AND m.torneo_id = :tid';
        }
        $sql .= ' ORDER BY m.numfvd ASC, m.cedula ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        if ($tid !== null && $tid > 0) {
            $stmt->bindValue(':tid', $tid, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $movs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_CARGO . ' WHERE asociacion_id = :a ORDER BY fecha_emision DESC, id DESC');
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $allCargos = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $cargos = [];
        foreach ($allCargos as $cg) {
            if (self::bucketConcepto((string) ($cg['concepto'] ?? '')) === $renglon) {
                $cargos[] = $cg;
            }
        }

        return [
            'renglon' => $renglon,
            'movimientos' => $movs,
            'cargos' => $cargos,
        ];
    }

    /**
     * Totales de movimiento de una asociación en todos los torneos (columnas del reporte participación).
     *
     * @return array<string, mixed>
     */
    public static function resumenMovimientoGlobalAsociacion(\PDO $pdo, int $asociacionId): array
    {
        if ($asociacionId < 1) {
            return [];
        }
        $countsAll = MovimientoTorneoContadores::contadoresPorAsociacion($pdo, null);
        $sk = (string) $asociacionId;
        $c = $countsAll[$sk] ?? MovimientoTorneoContadores::filaVacia();
        $units = MovimientoTorneoContadores::tarifaCostosMasReciente($pdo);
        $m = MovimientoTorneoContadores::montosPorTarifaYContadores($c, $units);
        $nomina = MovimientoTorneoContadores::totalNominaDesdeMontos($m);

        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM ' . self::T_MOV . ' WHERE asociacion_id = :a');
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $nMov = (int) ($stmt->fetchColumn() ?: 0);

        return [
            'n_afiliacion' => $c['n_afiliacion'],
            'n_anualidad' => $c['n_anualidad'],
            'n_carnet' => $c['n_carnet'],
            'n_traspaso' => $c['n_traspaso'],
            'n_inscripcion' => $c['n_inscripcion'],
            'monto_afiliacion_eur' => $m['afiliacion'],
            'monto_anualidad_eur' => $m['anualidad'],
            'monto_carnet_eur' => $m['carnet'],
            'monto_traspaso_eur' => $m['traspaso'],
            'monto_inscripcion_eur' => $m['inscripcion'],
            'monto_participacion_eur' => $m['inscripcion'],
            'monto_nomina_total_eur' => $nomina,
            'n_movimiento_torneo' => $nMov,
        ];
    }

    /**
     * Suma resúmenes de columna para pie de tabla del reporte participación.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public static function totalesColumnasReporteParticipacion(array $items): array
    {
        $tot = [
            'n_afiliacion' => 0,
            'n_anualidad' => 0,
            'n_carnet' => 0,
            'n_traspaso' => 0,
            'n_inscripcion' => 0,
            'monto_afiliacion_eur' => 0.0,
            'monto_anualidad_eur' => 0.0,
            'monto_carnet_eur' => 0.0,
            'monto_traspaso_eur' => 0.0,
            'monto_inscripcion_eur' => 0.0,
            'monto_participacion_eur' => 0.0,
            'monto_nomina_total_eur' => 0.0,
            'n_movimiento_torneo' => 0,
            'deuda_eur' => 0.0,
            'pagado_eur' => 0.0,
            'saldo_eur' => 0.0,
        ];
        foreach ($items as $item) {
            $r = $item['resumen_columnas'] ?? [];
            if (!is_array($r)) {
                continue;
            }
            $tot['n_afiliacion'] += (int) ($r['n_afiliacion'] ?? 0);
            $tot['n_anualidad'] += (int) ($r['n_anualidad'] ?? 0);
            $tot['n_carnet'] += (int) ($r['n_carnet'] ?? 0);
            $tot['n_traspaso'] += (int) ($r['n_traspaso'] ?? 0);
            $tot['n_inscripcion'] += (int) ($r['n_inscripcion'] ?? 0);
            $tot['monto_afiliacion_eur'] += (float) ($r['monto_afiliacion_eur'] ?? 0);
            $tot['monto_anualidad_eur'] += (float) ($r['monto_anualidad_eur'] ?? 0);
            $tot['monto_carnet_eur'] += (float) ($r['monto_carnet_eur'] ?? 0);
            $tot['monto_traspaso_eur'] += (float) ($r['monto_traspaso_eur'] ?? 0);
            $tot['monto_inscripcion_eur'] += (float) ($r['monto_inscripcion_eur'] ?? 0);
            $tot['monto_participacion_eur'] += (float) ($r['monto_participacion_eur'] ?? 0);
            $tot['monto_nomina_total_eur'] += (float) ($r['monto_nomina_total_eur'] ?? 0);
            $tot['n_movimiento_torneo'] += (int) ($r['n_movimiento_torneo'] ?? 0);
            $ec = $item['estado_cuenta'] ?? [];
            if (is_array($ec)) {
                $tot['deuda_eur'] += (float) ($ec['deuda_eur'] ?? 0);
                $tot['pagado_eur'] += (float) ($ec['pagado_eur'] ?? 0);
                $tot['saldo_eur'] += (float) ($ec['saldo_eur'] ?? 0);
            }
        }
        foreach (['monto_afiliacion_eur', 'monto_anualidad_eur', 'monto_carnet_eur', 'monto_traspaso_eur', 'monto_inscripcion_eur', 'monto_participacion_eur', 'monto_nomina_total_eur', 'deuda_eur', 'pagado_eur', 'saldo_eur'] as $k) {
            $tot[$k] = round((float) $tot[$k], 2);
        }

        return $tot;
    }

    /**
     * Reporte administración general: cada asociación con movimiento y, por cada torneo,
     * el resumen de renglones (afiliación, anualidad, carnet, traspaso, inscripción).
     *
     * @return list<array{asociacion: array<string, mixed>, informes_por_torneo: list<array<string, mixed>>, estado_cuenta: array<string, mixed>, n_torneos: int, resumen_columnas: array<string, mixed>}>
     */
    public static function reporteGlobalAsociacionesPorTorneo(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT DISTINCT a.id, a.nombre, a.logo, a.estatus
            FROM ' . self::T_ASOC . ' a
            INNER JOIN ' . self::T_MOV . ' m ON m.asociacion_id = a.id
            WHERE m.asociacion_id IS NOT NULL AND m.asociacion_id > 0
            ORDER BY a.nombre ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $asoc) {
            $aid = (int) ($asoc['id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $lista = self::torneosConMovimientoParaAsociacion($pdo, $aid);
            if ($lista === []) {
                continue;
            }
            $bloques = [];
            foreach ($lista as $meta) {
                $tid = (int) ($meta['torneo_id'] ?? 0);
                if ($tid < 1) {
                    continue;
                }
                $bloques[] = self::detalleAsociacionParaTorneo($pdo, $aid, $tid, false);
            }
            if ($bloques === []) {
                continue;
            }
            $estadoCuenta = self::totalCuentaAsociacionEur($pdo, $aid, null);
            $out[] = [
                'asociacion' => [
                    'id' => $aid,
                    'nombre' => (string) ($asoc['nombre'] ?? ''),
                    'logo' => $asoc['logo'] ?? null,
                    'estatus' => $asoc['estatus'] ?? null,
                ],
                'informes_por_torneo' => $bloques,
                'estado_cuenta' => $estadoCuenta,
                'n_torneos' => count($bloques),
                'resumen_columnas' => array_merge(
                    self::resumenMovimientoGlobalAsociacion($pdo, $aid),
                    [
                        'deuda_eur' => round((float) ($estadoCuenta['deuda_eur'] ?? 0), 2),
                        'pagado_eur' => round((float) ($estadoCuenta['pagado_eur'] ?? 0), 2),
                        'saldo_eur' => round((float) ($estadoCuenta['saldo_eur'] ?? 0), 2),
                    ]
                ),
            ];
        }

        return $out;
    }
}
