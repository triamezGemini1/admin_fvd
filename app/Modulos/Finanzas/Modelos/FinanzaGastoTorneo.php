<?php

declare(strict_types=1);

namespace Fvd\Modulos\Finanzas\Modelos;

use Fvd\Modulos\Informes\Modelos\InformeFvd;

/**
 * Gastos operativos registrados para un torneo (tabla `finanza_gasto_torneo`).
 */
class FinanzaGastoTorneo
{
    private const T_GASTO = 'finanza_gasto_torneo';

    private const T_TOR = 'torneosact';

    public static function eurDesdeBs(float $montoBs, float $tasaEurBs): float
    {
        if ($montoBs <= 0) {
            throw new \InvalidArgumentException('Monto en Bs debe ser mayor a cero.');
        }
        if ($tasaEurBs <= 0) {
            throw new \InvalidArgumentException('Tasa referente inválida.');
        }

        return round($montoBs / $tasaEurBs, 2);
    }

    public static function bsDesdeEur(float $montoEur, float $tasaEurBs): float
    {
        if ($montoEur <= 0 || $tasaEurBs <= 0) {
            return 0.0;
        }

        return round($montoEur * $tasaEurBs, 2);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function registrar(\PDO $pdo, int $torneoId, array $data): int
    {
        if ($torneoId < 1 || InformeFvd::torneoFilaPorId($pdo, $torneoId) === null) {
            throw new \InvalidArgumentException('Torneo no encontrado.');
        }
        $concepto = trim((string) ($data['concepto'] ?? ''));
        if ($concepto === '') {
            throw new \InvalidArgumentException('Concepto obligatorio.');
        }
        $fecha = trim((string) ($data['fecha'] ?? ''));
        if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new \InvalidArgumentException('Fecha inválida (YYYY-MM-DD).');
        }
        $montoBs = isset($data['monto_bs']) ? (float) $data['monto_bs'] : 0.0;
        $tasa = isset($data['tasa_eur_bs']) ? (float) $data['tasa_eur_bs'] : 0.0;
        if ($tasa <= 0) {
            $tasa = FinanzaFvd::obtenerTasaEurBs($pdo);
        }
        $montoEur = isset($data['monto_eur']) && (float) $data['monto_eur'] > 0
            ? round((float) $data['monto_eur'], 2)
            : self::eurDesdeBs($montoBs, $tasa);
        $nroFactura = isset($data['nro_factura']) ? trim((string) $data['nro_factura']) : null;
        $notas = isset($data['notas']) ? trim((string) $data['notas']) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::T_GASTO . ' (torneo_id, fecha, concepto, monto_bs, tasa_eur_bs, monto_eur, nro_factura, notas)
            VALUES (:t, :f, :c, :bs, :ta, :eur, :nf, :n)'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':f', $fecha, \PDO::PARAM_STR);
        $stmt->bindValue(':c', $concepto, \PDO::PARAM_STR);
        $stmt->bindValue(':bs', round($montoBs, 2), \PDO::PARAM_STR);
        $stmt->bindValue(':ta', round($tasa, 4), \PDO::PARAM_STR);
        $stmt->bindValue(':eur', $montoEur, \PDO::PARAM_STR);
        $stmt->bindValue(':nf', $nroFactura === '' ? null : $nroFactura, $nroFactura === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':n', $notas === '' ? null : $notas, $notas === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarPorTorneo(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, torneo_id, fecha, concepto, monto_bs, tasa_eur_bs, monto_eur, nro_factura, notas, created_at
            FROM ' . self::T_GASTO . ' WHERE torneo_id = :t ORDER BY fecha DESC, id DESC'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return array{total_bs: float, total_eur: float, n: int}
     */
    public static function totalesPorTorneo(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return ['total_bs' => 0.0, 'total_eur' => 0.0, 'n' => 0];
        }
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto_bs), 0) AS tb, COALESCE(SUM(monto_eur), 0) AS te, COUNT(*) AS n
            FROM ' . self::T_GASTO . ' WHERE torneo_id = :t'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_bs' => round((float) ($r['tb'] ?? 0), 2),
            'total_eur' => round((float) ($r['te'] ?? 0), 2),
            'n' => (int) ($r['n'] ?? 0),
        ];
    }

    /**
     * Gastos agrupados por concepto con líneas y porcentajes.
     *
     * @return array{
     *   totales: array{total_bs: float, total_eur: float, n: int},
     *   conceptos: list<array<string, mixed>>
     * }
     */
    public static function consolidadoPorConcepto(\PDO $pdo, int $torneoId, float $ingresoEur): array
    {
        $tot = self::totalesPorTorneo($pdo, $torneoId);
        $gTotal = (float) ($tot['total_eur'] ?? 0);
        $lista = self::listarPorTorneo($pdo, $torneoId);
        /** @var array<string, array{concepto: string, n: int, total_bs: float, total_eur: float, lineas: list<array<string, mixed>>}> $grupos */
        $grupos = [];
        foreach ($lista as $row) {
            $c = trim((string) ($row['concepto'] ?? ''));
            if ($c === '') {
                $c = '(sin concepto)';
            }
            if (!isset($grupos[$c])) {
                $grupos[$c] = [
                    'concepto' => $c,
                    'n' => 0,
                    'total_bs' => 0.0,
                    'total_eur' => 0.0,
                    'lineas' => [],
                ];
            }
            $mEur = round((float) ($row['monto_eur'] ?? 0), 2);
            $grupos[$c]['n']++;
            $grupos[$c]['total_bs'] += (float) ($row['monto_bs'] ?? 0);
            $grupos[$c]['total_eur'] += $mEur;
            $grupos[$c]['lineas'][] = array_merge($row, [
                'pct_ingreso' => InformeFvd::pctSobreBase($mEur, $ingresoEur),
                'pct_gastos' => InformeFvd::pctSobreBase($mEur, $gTotal),
            ]);
        }
        $out = [];
        foreach ($grupos as $g) {
            $te = round($g['total_eur'], 2);
            $tb = round($g['total_bs'], 2);
            $out[] = [
                'concepto' => $g['concepto'],
                'n' => $g['n'],
                'total_bs' => $tb,
                'total_eur' => $te,
                'pct_ingreso' => InformeFvd::pctSobreBase($te, $ingresoEur),
                'pct_gastos' => InformeFvd::pctSobreBase($te, $gTotal),
                'lineas' => $g['lineas'],
            ];
        }
        usort(
            $out,
            static fn (array $a, array $b): int => ($b['total_eur'] <=> $a['total_eur']) ?: strcmp(
                (string) $a['concepto'],
                (string) $b['concepto']
            )
        );

        return ['totales' => $tot, 'conceptos' => $out];
    }
}
