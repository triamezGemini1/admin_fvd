<?php

declare(strict_types=1);

namespace Fvd\Modulos\Finanzas\Modelos;

use Fvd\Modulos\Informes\Modelos\InformeFvd;

/**
 * Finanzas FVD por asociación: cargos en EUR, pagos con tasa BCV (Bs/EUR) y recibos.
 */
class FinanzaFvd
{
    private const T_PARAM = 'finanza_parametro';
    private const T_CARGO = 'finanza_cargo';
    /** Pagos: tabla maestra `relacion_pagos` (tasa, moneda, tipo, montos Bs + equivalente en divisas). */
    private const T_PAGO = 'relacion_pagos';
    private const T_ASOC = 'asociaciones';

    /** Pagos contables FVD (cargos `finanza_cargo`): sin torneo concreto; desglose en informes por asociación. */
    public const PAGO_TORNEO_FINANZA_FVD = 0;

    public static function obtenerTasaEurBs(\PDO $pdo): float
    {
        $stmt = $pdo->query('SELECT tasa_eur_bs FROM ' . self::T_PARAM . ' WHERE id = 1 LIMIT 1');
        if ($stmt === false) {
            return 48.0;
        }
        $v = $stmt->fetchColumn();

        return $v === false ? 48.0 : max(0.0001, (float) $v);
    }

    public static function actualizarTasaEurBs(\PDO $pdo, float $tasa): void
    {
        if ($tasa <= 0 || $tasa > 999999.9999) {
            throw new InvalidArgumentException('Tasa EUR→Bs inválida.');
        }
        $stmt = $pdo->prepare(
            'UPDATE ' . self::T_PARAM . ' SET tasa_eur_bs = :t WHERE id = 1'
        );
        $stmt->bindValue(':t', round($tasa, 4), \PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Resumen financiero por asociación (deuda/pagado/saldo global + conteo de nómina en un torneo).
     *
     * @param int|null $torneoMovimientoId si se indica, `n_movimiento_torneo` cuenta filas de `movimiento_torneo` para ese torneo; si null, usa el torneo activo (sin finalizar).
     *
     * @return list<array<string, mixed>>
     */
    public static function resumenPorAsociacion(\PDO $pdo, ?int $torneoMovimientoId = null): array
    {
        $tidEstadisticas = null;
        if ($torneoMovimientoId !== null && $torneoMovimientoId > 0) {
            $tid = (int) $torneoMovimientoId;
            $tidEstadisticas = $tid;
            $subMov = '(SELECT COUNT(*) FROM movimiento_torneo m WHERE m.asociacion_id = a.id AND m.torneo_id = ' . $tid . ')';
        } else {
            $resolved = InformeFvd::resolverTorneoIdInformeMovimiento($pdo, null);
            if ($resolved !== null && $resolved > 0) {
                $tid = (int) $resolved;
                $tidEstadisticas = $tid;
                $subMov = '(SELECT COUNT(*) FROM movimiento_torneo m WHERE m.asociacion_id = a.id AND m.torneo_id = ' . $tid . ')';
            } else {
                $subMov = '(SELECT COUNT(*) FROM movimiento_torneo m WHERE m.asociacion_id = a.id AND m.torneo_id = (
                    SELECT t.torneo FROM torneosact t WHERE t.finalizado_en IS NULL
                    ORDER BY t.fechator DESC, t.torneo DESC LIMIT 1
                ))';
            }
        }
        $sql = 'SELECT a.id, a.nombre, a.estatus, a.logo,
            (SELECT COALESCE(SUM(p.monto_dolares), 0) FROM ' . self::T_PAGO . ' p
                WHERE p.asociacion_id = a.id AND p.torneo_id = ' . (int) self::PAGO_TORNEO_FINANZA_FVD . '
                AND COALESCE(p.verificado, 0) = 1) AS pagado_eur,
            ' . $subMov . ' AS n_movimiento_torneo
            FROM ' . self::T_ASOC . ' a
            ORDER BY a.nombre ASC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $deudas = InformeFvd::deudaInformadaPorAsociacion($pdo, $torneoMovimientoId);
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['id'] ?? 0);
            $sk = (string) $aid;
            $deu = $deudas[$sk] ?? 0.0;
            $pag = round((float) ($r['pagado_eur'] ?? 0), 2);
            $r['deuda_eur'] = $deu;
            $r['pagado_eur'] = $pag;
            $r['saldo_eur'] = round($deu - $pag, 2);
            $r['n_movimiento_torneo'] = (int) ($r['n_movimiento_torneo'] ?? 0);
            $out[] = $r;
        }

        if ($tidEstadisticas !== null && $tidEstadisticas > 0) {
            InformeFvd::adjuntarEstadisticasRenglonAResumen($pdo, $out, $tidEstadisticas);
        }

        return $out;
    }

    /**
     * Resumen por asociación agregando varios torneos (campeonato).
     *
     * @param list<int> $torneoIds
     *
     * @return list<array<string, mixed>>
     */
    public static function resumenPorAsociacionTorneos(\PDO $pdo, array $torneoIds): array
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
            return self::resumenPorAsociacion($pdo, null);
        }
        if (count($ids) === 1) {
            return self::resumenPorAsociacion($pdo, $ids[0]);
        }
        $ph = implode(',', array_map('intval', $ids));
        $subMov = '(SELECT COUNT(*) FROM movimiento_torneo m WHERE m.asociacion_id = a.id AND m.torneo_id IN (' . $ph . '))';
        $sql = 'SELECT a.id, a.nombre, a.estatus, a.logo,
            (SELECT COALESCE(SUM(p.monto_dolares), 0) FROM ' . self::T_PAGO . ' p
                WHERE p.asociacion_id = a.id AND p.torneo_id = ' . (int) self::PAGO_TORNEO_FINANZA_FVD . '
                AND COALESCE(p.verificado, 0) = 1) AS pagado_eur,
            ' . $subMov . ' AS n_movimiento_torneo
            FROM ' . self::T_ASOC . ' a
            ORDER BY a.nombre ASC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        $deudas = InformeFvd::deudaInformadaPorAsociacionTorneos($pdo, $ids);
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['id'] ?? 0);
            $sk = (string) $aid;
            $deu = $deudas[$sk] ?? 0.0;
            $pag = round((float) ($r['pagado_eur'] ?? 0), 2);
            $r['deuda_eur'] = $deu;
            $r['pagado_eur'] = $pag;
            $r['saldo_eur'] = round($deu - $pag, 2);
            $r['n_movimiento_torneo'] = (int) ($r['n_movimiento_torneo'] ?? 0);
            $out[] = $r;
        }
        InformeFvd::adjuntarEstadisticasRenglonAResumenTorneos($pdo, $out, $ids);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resumenPorAsociacionGrupoCampeonato(\PDO $pdo, int $grupoEventoId): array
    {
        if ($grupoEventoId < 1) {
            return [];
        }
        $ids = [];
        foreach (\Fvd\Modulos\Torneos\Modelos\TorneoCampeonato::torneosDeGrupo($pdo, $grupoEventoId) as $tr) {
            $tid = (int) ($tr['torneo'] ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }

        return self::resumenPorAsociacionTorneos($pdo, $ids);
    }

    /**
     * @return array{deuda_eur: float, pagado_eur: float, saldo_eur: float}
     */
    public static function balanceIntegralEur(\PDO $pdo): array
    {
        $deudas = InformeFvd::deudaInformadaPorAsociacion($pdo, null);
        $deu = round(array_sum($deudas), 2);
        $p = $pdo->query(
            'SELECT COALESCE(SUM(monto_dolares), 0) FROM ' . self::T_PAGO
            . ' WHERE torneo_id = ' . (int) self::PAGO_TORNEO_FINANZA_FVD
            . ' AND COALESCE(verificado, 0) = 1'
        );
        $pag = $p === false ? 0.0 : round((float) $p->fetchColumn(), 2);

        return [
            'deuda_eur' => $deu,
            'pagado_eur' => $pag,
            'saldo_eur' => round($deu - $pag, 2),
        ];
    }

    public static function pagadoVerificadoEur(\PDO $pdo, int $asociacionId): float
    {
        if ($asociacionId < 1) {
            return 0.0;
        }
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_dolares), 0) FROM ' . self::T_PAGO
            . ' WHERE asociacion_id = :a AND torneo_id = :t AND COALESCE(verificado, 0) = 1'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt->execute();

        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obtenerAsociacion(\PDO $pdo, int $asociacionId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, nombre, estatus FROM ' . self::T_ASOC . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Estado financiero consolidado + desglose por concepto (cargos agrupados).
     *
     * @return array{
     *   asociacion: array<string, mixed>,
     *   totales: array{deuda_eur: float, pagado_eur: float, saldo_eur: float},
     *   por_concepto: list<array{concepto: string, monto_eur: float}>,
     *   cargos: list<array<string, mixed>>,
     *   pagos: list<array<string, mixed>>
     * }
     */
    public static function estadoAsociacion(\PDO $pdo, int $asociacionId): array
    {
        $asoc = self::obtenerAsociacion($pdo, $asociacionId);
        if ($asoc === null) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }

        $stmt = $pdo->prepare(
            'SELECT concepto, SUM(monto_eur) AS monto_eur FROM ' . self::T_CARGO
                . ' WHERE asociacion_id = :a GROUP BY concepto ORDER BY concepto ASC'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $porConcepto = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($porConcepto as &$pc) {
            $pc['monto_eur'] = round((float) ($pc['monto_eur'] ?? 0), 2);
        }
        unset($pc);

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::T_CARGO . ' WHERE asociacion_id = :a ORDER BY fecha_emision DESC, id DESC'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $cargos = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::T_PAGO . ' WHERE asociacion_id = :a AND torneo_id = :t ORDER BY fecha DESC, id DESC'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt->execute();
        $pagosRaw = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $pagos = [];
        foreach ($pagosRaw as $pr) {
            $pagos[] = self::mapPagoRelacionFila($pr);
        }

        $cuenta = InformeFvd::totalCuentaAsociacionEur($pdo, $asociacionId, null);
        $deu = $cuenta['deuda_eur'];
        $pag = $cuenta['pagado_eur'];

        $stmt2 = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_dolares), 0) AS s_pend FROM ' . self::T_PAGO
            . ' WHERE asociacion_id = :a AND torneo_id = :t AND COALESCE(verificado, 0) = 0'
        );
        $stmt2->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt2->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt2->execute();
        $pagPend = round((float) ($stmt2->fetchColumn() ?: 0), 2);

        return [
            'asociacion' => $asoc,
            'totales' => [
                'deuda_eur' => $deu,
                'nomina_eur' => $cuenta['nomina_eur'],
                'cargos_manuales_eur' => $cuenta['cargos_manuales_eur'],
                'pagado_eur' => $pag,
                'pagado_pendiente_verificacion_eur' => $pagPend,
                'saldo_eur' => $cuenta['saldo_eur'],
            ],
            'por_concepto' => $porConcepto,
            'cargos' => $cargos,
            'pagos' => $pagos,
        ];
    }

    /**
     * @param array{concepto: string, monto_eur: float, fecha_emision: string, referencia?: string|null, notas?: string|null} $data
     */
    public static function registrarCargo(\PDO $pdo, int $asociacionId, array $data): int
    {
        if (self::obtenerAsociacion($pdo, $asociacionId) === null) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }
        $concepto = trim((string) ($data['concepto'] ?? ''));
        if ($concepto === '') {
            throw new InvalidArgumentException('Concepto obligatorio.');
        }
        $monto = isset($data['monto_eur']) ? (float) $data['monto_eur'] : 0.0;
        if ($monto <= 0) {
            throw new InvalidArgumentException('Monto EUR debe ser mayor a cero.');
        }
        $fecha = trim((string) ($data['fecha_emision'] ?? ''));
        if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new InvalidArgumentException('Fecha emisión inválida (YYYY-MM-DD).');
        }
        $ref = isset($data['referencia']) ? trim((string) $data['referencia']) : null;
        $notas = isset($data['notas']) ? trim((string) $data['notas']) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::T_CARGO . ' (asociacion_id, concepto, monto_eur, referencia, fecha_emision, notas)
            VALUES (:a, :c, :m, :r, :f, :n)'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':c', $concepto, \PDO::PARAM_STR);
        $stmt->bindValue(':m', round($monto, 2), \PDO::PARAM_STR);
        $stmt->bindValue(':r', $ref === '' ? null : $ref, $ref === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':f', $fecha, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $notas === '' ? null : $notas, $notas === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    /**
     * Expone columnas `relacion_pagos` con los alias que usa el panel (antes `finanza_pago`).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function mapPagoRelacionFila(array $p): array
    {
        $p['fecha_pago'] = (string) ($p['fecha'] ?? '');
        $p['monto_eur'] = round((float) ($p['monto_dolares'] ?? 0), 2);
        $p['monto_bs'] = round((float) ($p['monto_total'] ?? 0), 2);
        $p['tasa_eur_bs'] = round((float) ($p['tasa_cambio'] ?? 0), 2);
        $p['observacion'] = $p['observaciones'] ?? null;
        $p['verificado'] = (int) ($p['verificado'] ?? 0);
        $p['verificado_en'] = $p['verificado_en'] ?? null;
        $p['conciliacion_json'] = $p['conciliacion_json'] ?? null;

        return $p;
    }

    /**
     * Intenta conciliación automática (reglas mínimas + texto tipo entidad bancaria).
     * Si no aprueba, deja verificado = 0 y guarda JSON con estado pendiente.
     *
     * @return array{verificado: bool, detalle: string}
     */
    public static function aplicarConciliacionAutomatica(\PDO $pdo, int $pagoId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_PAGO . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['verificado' => false, 'detalle' => 'Pago no encontrado.'];
        }

        $ref = trim((string) ($row['referencia'] ?? ''));
        $banco = trim((string) ($row['banco'] ?? ''));
        $tipo = (string) ($row['tipo_pago'] ?? '');
        $eur = round((float) ($row['monto_dolares'] ?? 0), 2);
        $base = [
            'motor' => 'fvd_conciliacion_v1',
            'instante' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'referencia_declarada' => $ref,
            'banco_declarado' => $banco,
            'tipo_pago' => $tipo,
            'monto_eur_operacion' => $eur,
        ];

        $aprobado = false;
        if ($eur > 0 && strlen($ref) >= 6 && strlen($banco) >= 3) {
            if ($tipo === 'transferencia' && preg_match('/^[0-9A-Za-z\-]{6,}$/', $ref) === 1) {
                $aprobado = true;
            }
            if ($tipo === 'pago_movil' && preg_match('/^[0-9]{6,}$/', $ref) === 1) {
                $aprobado = true;
            }
        }

        if ($aprobado) {
            $base['resultado'] = 'conciliado_automatico';
            $base['mensaje_entidad'] = 'Validación automática: referencia y datos bancarios mínimos consistentes (sustituir por respuesta real de la entidad cuando exista API).';
            $json = json_encode($base, JSON_UNESCAPED_UNICODE);
            $upd = $pdo->prepare(
                'UPDATE ' . self::T_PAGO . ' SET verificado = 1, verificado_en = NOW(), conciliacion_json = :j WHERE id = :id LIMIT 1'
            );
            $upd->bindValue(':j', $json, \PDO::PARAM_STR);
            $upd->bindValue(':id', $pagoId, \PDO::PARAM_INT);
            $upd->execute();

            return ['verificado' => true, 'detalle' => 'Conciliación automática aprobada.'];
        }

        $base['resultado'] = 'pendiente_revision';
        $base['mensaje_entidad'] = 'No supera las reglas automáticas de conciliación; requiere verificación manual.';
        $json = json_encode($base, JSON_UNESCAPED_UNICODE);
        $upd = $pdo->prepare(
            'UPDATE ' . self::T_PAGO . ' SET verificado = 0, verificado_en = NULL, conciliacion_json = :j WHERE id = :id LIMIT 1'
        );
        $upd->bindValue(':j', $json, \PDO::PARAM_STR);
        $upd->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $upd->execute();

        return ['verificado' => false, 'detalle' => 'Pendiente de verificación; no contabilizado contra el saldo.'];
    }

    /**
     * Verificación manual (administración): marca el pago como contabilizado.
     */
    public static function verificarPagoManual(\PDO $pdo, int $pagoId, ?string $nota = null): void
    {
        $chk = $pdo->prepare('SELECT id FROM ' . self::T_PAGO . ' WHERE id = :id LIMIT 1');
        $chk->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $chk->execute();
        if ($chk->fetchColumn() === false) {
            throw new InvalidArgumentException('Pago no encontrado.');
        }
        $meta = [
            'motor' => 'verificacion_manual',
            'instante' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'resultado' => 'verificado_manual',
            'nota' => $nota !== null && trim($nota) !== '' ? trim($nota) : null,
        ];
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $upd = $pdo->prepare(
            'UPDATE ' . self::T_PAGO . ' SET verificado = 1, verificado_en = NOW(),
            conciliacion_json = :j WHERE id = :id LIMIT 1'
        );
        $upd->bindValue(':j', $json, \PDO::PARAM_STR);
        $upd->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $upd->execute();
    }

    /**
     * Datos mínimos para el recibo de una operación (solo torneo FVD contable).
     *
     * @return array<string, mixed>
     */
    public static function datosReciboOperacion(\PDO $pdo, int $asociacionId, int $pagoId): array
    {
        if ($asociacionId < 1 || $pagoId < 1) {
            throw new InvalidArgumentException('Parámetros inválidos.');
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::T_PAGO . ' WHERE id = :id AND asociacion_id = :a AND torneo_id = :t LIMIT 1'
        );
        $stmt->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt->execute();
        $raw = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($raw === false) {
            throw new InvalidArgumentException('Operación de pago no encontrada para esta asociación.');
        }
        $op = self::mapPagoRelacionFila($raw);

        $asoc = self::obtenerAsociacion($pdo, $asociacionId);
        if ($asoc === null) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }

        $montoOp = round((float) ($op['monto_eur'] ?? 0), 2);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_eur), 0) FROM ' . self::T_CARGO . ' WHERE asociacion_id = :a'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $deuda = round((float) ($stmt->fetchColumn() ?: 0), 2);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_dolares), 0) FROM ' . self::T_PAGO
            . ' WHERE asociacion_id = :a AND torneo_id = :t AND COALESCE(verificado, 0) = 1'
        );
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt->execute();
        $pagadoVerif = round((float) ($stmt->fetchColumn() ?: 0), 2);

        $saldoPendiente = round($deuda - $pagadoVerif, 2);

        $conc = null;
        if (!empty($op['conciliacion_json']) && is_string($op['conciliacion_json'])) {
            $dec = json_decode($op['conciliacion_json'], true);
            $conc = is_array($dec) ? $dec : null;
        }

        $verif = (int) ($op['verificado'] ?? 0) === 1;
        $motor = is_array($conc) ? (string) ($conc['motor'] ?? '') : '';
        $concAuto = $motor !== '' && str_starts_with($motor, 'fvd_conciliacion');
        $concManual = $motor === 'verificacion_manual';

        return [
            'asociacion' => [
                'id' => (int) ($asoc['id'] ?? $asociacionId),
                'nombre' => (string) ($asoc['nombre'] ?? ''),
            ],
            'operacion_id' => (int) ($op['id'] ?? $pagoId),
            'deuda_total_eur' => $deuda,
            'monto_abonado_eur' => $montoOp,
            /** Importe en EUR declarado en la operación frente al saldo deudor (mismo que el abono de esta fila). */
            'monto_eur_segun_saldo_deudor' => $montoOp,
            'saldo_pendiente_eur' => $saldoPendiente,
            'fecha' => (string) ($op['fecha_pago'] ?? ''),
            'tipo_pago' => (string) ($op['tipo_pago'] ?? ''),
            'tasa_vcb_bs_por_eur' => round((float) ($op['tasa_eur_bs'] ?? 0), 4),
            'equivalencia_bs' => round((float) ($op['monto_bs'] ?? 0), 2),
            'banco' => (string) ($op['banco'] ?? ''),
            'referencia' => (string) ($op['referencia'] ?? ''),
            'verificado' => $verif,
            'verificado_en' => $op['verificado_en'] ?? null,
            'conciliacion' => $conc,
            'conciliacion_automatica' => $verif && $concAuto && !$concManual,
            'conciliacion_manual_admin' => $verif && $concManual,
        ];
    }

    /**
     * Ajusta la tasa Bs/EUR de una operación ya registrada y recalcula el monto en bolívares.
     */
    public static function actualizarTasaReciboOperacion(\PDO $pdo, int $asociacionId, int $pagoId, float $tasa): array
    {
        if ($asociacionId < 1 || $pagoId < 1) {
            throw new InvalidArgumentException('Parámetros inválidos.');
        }
        if ($tasa <= 0 || $tasa > 999999.9999) {
            throw new InvalidArgumentException('Tasa inválida.');
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::T_PAGO . ' WHERE id = :id AND asociacion_id = :a AND torneo_id = :t LIMIT 1'
        );
        $stmt->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', self::PAGO_TORNEO_FINANZA_FVD, \PDO::PARAM_INT);
        $stmt->execute();
        $raw = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($raw === false) {
            throw new InvalidArgumentException('Operación de pago no encontrada para esta asociación.');
        }
        $eur = round((float) ($raw['monto_dolares'] ?? 0), 2);
        $tasaBd = round($tasa, 4);
        $montoBs = round($eur * $tasaBd, 2);
        $upd = $pdo->prepare(
            'UPDATE ' . self::T_PAGO . ' SET tasa_cambio = :t, monto_total = :bs WHERE id = :id LIMIT 1'
        );
        $upd->bindValue(':t', $tasaBd, \PDO::PARAM_STR);
        $upd->bindValue(':bs', $montoBs, \PDO::PARAM_STR);
        $upd->bindValue(':id', $pagoId, \PDO::PARAM_INT);
        $upd->execute();

        return self::datosReciboOperacion($pdo, $asociacionId, $pagoId);
    }

    /**
     * @param array{monto_eur: float, referencia: string, banco: string, fecha_pago: string, observacion?: string|null, tipo_pago?: string|null, tasa_eur_bs?: float|numeric-string|null} $data
     * @return array<string, mixed>
     */
    public static function registrarPago(\PDO $pdo, int $asociacionId, array $data): array
    {
        if (self::obtenerAsociacion($pdo, $asociacionId) === null) {
            throw new InvalidArgumentException('Asociación no encontrada.');
        }
        $montoEur = isset($data['monto_eur']) ? (float) $data['monto_eur'] : 0.0;
        if ($montoEur <= 0) {
            throw new InvalidArgumentException('Monto EUR debe ser mayor a cero.');
        }
        $tasa = self::obtenerTasaEurBs($pdo);
        if (isset($data['tasa_eur_bs'])) {
            $tasaOv = (float) $data['tasa_eur_bs'];
            if ($tasaOv > 0 && $tasaOv <= 999999.9999) {
                $tasa = $tasaOv;
            }
        }
        $tasaBd = round($tasa, 4);
        if ($tasaBd <= 0) {
            $tasaBd = 1.0;
        }
        $montoBs = round($montoEur * $tasaBd, 2);
        $ref = trim((string) ($data['referencia'] ?? ''));
        $banco = trim((string) ($data['banco'] ?? ''));
        if ($ref === '' || $banco === '') {
            throw new InvalidArgumentException('Referencia y banco son obligatorios.');
        }
        $fecha = trim((string) ($data['fecha_pago'] ?? ''));
        if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new InvalidArgumentException('Fecha de pago inválida (YYYY-MM-DD).');
        }
        $obs = isset($data['observacion']) ? trim((string) $data['observacion']) : null;
        $tipo = trim((string) ($data['tipo_pago'] ?? 'transferencia'));
        $tiposOk = ['efectivo', 'transferencia', 'pago_movil'];
        if (!in_array($tipo, $tiposOk, true)) {
            $tipo = 'transferencia';
        }

        $torneoId = self::PAGO_TORNEO_FINANZA_FVD;

        $pdo->beginTransaction();
        try {
            $seqStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(secuencia), 0) + 1 FROM ' . self::T_PAGO
                . ' WHERE torneo_id = :t AND asociacion_id = :a FOR UPDATE'
            );
            $seqStmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
            $seqStmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
            $seqStmt->execute();
            $secuencia = (int) $seqStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::T_PAGO . ' (
                    torneo_id, asociacion_id, secuencia, fecha, tasa_cambio, tipo_pago, moneda,
                    monto_total, monto_dolares, referencia, banco, observaciones
                ) VALUES (
                    :tor, :a, :seq, :f, :tasa, :tipo, \'Bs\',
                    :bs, :eur, :r, :bk, :o
                )'
            );
            $stmt->bindValue(':tor', $torneoId, \PDO::PARAM_INT);
            $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
            $stmt->bindValue(':seq', $secuencia, \PDO::PARAM_INT);
            $stmt->bindValue(':f', $fecha, \PDO::PARAM_STR);
            $stmt->bindValue(':tasa', $tasaBd, \PDO::PARAM_STR);
            $stmt->bindValue(':tipo', $tipo, \PDO::PARAM_STR);
            $stmt->bindValue(':bs', $montoBs, \PDO::PARAM_STR);
            $stmt->bindValue(':eur', round($montoEur, 2), \PDO::PARAM_STR);
            $stmt->bindValue(':r', $ref, \PDO::PARAM_STR);
            $stmt->bindValue(':bk', $banco, \PDO::PARAM_STR);
            $stmt->bindValue(':o', $obs === '' ? null : $obs, $obs === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $stmt->execute();
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $concRes = ['verificado' => false, 'detalle' => 'Sin conciliación automática.'];
        try {
            $concRes = self::aplicarConciliacionAutomatica($pdo, $id);
        } catch (Throwable $e) {
            error_log('FinanzaFvd::aplicarConciliacionAutomatica: ' . $e->getMessage());
            $concRes = ['verificado' => false, 'detalle' => 'No se pudo conciliar (¿migración 010 aplicada?).'];
        }

        try {
            DeudaAsociaciones::recalcularFila($pdo, $torneoId, $asociacionId);
        } catch (Throwable $e) {
            error_log('FinanzaFvd registrarPago → DeudaAsociaciones: ' . $e->getMessage());
        }

        return [
            'id' => $id,
            'torneo_id' => $torneoId,
            'secuencia' => $secuencia,
            'tipo_pago' => $tipo,
            'moneda' => 'Bs',
            'monto_eur' => round($montoEur, 2),
            'monto_bs' => $montoBs,
            'tasa_eur_bs' => round($tasa, 4),
            'verificado' => (bool) ($concRes['verificado'] ?? false),
            'conciliacion_detalle' => (string) ($concRes['detalle'] ?? ''),
        ];
    }
}
