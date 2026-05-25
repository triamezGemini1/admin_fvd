<?php

declare(strict_types=1);

/**
 * Copia filas de `finanza_pago` a `relacion_pagos` con torneo_id = 0 (finanzas FVD).
 * Idempotente: marca cada fila en observaciones con [migrado finanza_pago#ID].
 *
 * Uso (desde la raíz del proyecto):
 *   php tools/migrate_finanza_pago_a_relacion_pagos.php
 */

require_once dirname(__DIR__) . '/Database.php';

$db = new Database();
$pdo = $db->getConnection();
if ($pdo === null) {
    fwrite(STDERR, "No hay conexión a MySQL.\n");
    exit(1);
}

$tblFp = $pdo->query("SHOW TABLES LIKE 'finanza_pago'")->fetch(PDO::FETCH_NUM);
$tblRp = $pdo->query("SHOW TABLES LIKE 'relacion_pagos'")->fetch(PDO::FETCH_NUM);
if ($tblFp === false) {
    echo "No existe finanza_pago; nada que migrar.\n";
    exit(0);
}
if ($tblRp === false) {
    fwrite(STDERR, "No existe relacion_pagos. Cree la tabla en el esquema maestro antes de migrar.\n");
    exit(1);
}

$rows = $pdo->query('SELECT * FROM finanza_pago ORDER BY asociacion_id ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
if ($rows === false || $rows === []) {
    echo "finanza_pago está vacío.\n";
    exit(0);
}

$migrated = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $legacyId = (int) ($row['id'] ?? 0);
        $marker = '[migrado finanza_pago#' . $legacyId . ']';
        $chk = $pdo->prepare('SELECT id FROM relacion_pagos WHERE observaciones LIKE :m LIMIT 1');
        $chk->bindValue(':m', $marker . '%', PDO::PARAM_STR);
        $chk->execute();
        if ($chk->fetch(PDO::FETCH_ASSOC) !== false) {
            $skipped++;
            continue;
        }

        $asoc = (int) ($row['asociacion_id'] ?? 0);
        if ($asoc < 1) {
            $skipped++;
            continue;
        }

        $seqStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(secuencia), 0) + 1 FROM relacion_pagos WHERE torneo_id = 0 AND asociacion_id = :a FOR UPDATE'
        );
        $seqStmt->bindValue(':a', $asoc, PDO::PARAM_INT);
        $seqStmt->execute();
        $seq = (int) $seqStmt->fetchColumn();

        $obsOrig = trim((string) ($row['observacion'] ?? ''));
        $obs = $obsOrig !== '' ? ($marker . ' ' . $obsOrig) : $marker;

        $ins = $pdo->prepare(
            'INSERT INTO relacion_pagos (
                torneo_id, asociacion_id, secuencia, fecha, tasa_cambio, tipo_pago, moneda,
                monto_total, monto_dolares, referencia, banco, observaciones
            ) VALUES (
                0, :a, :s, :f, :tasa, \'transferencia\', \'Bs\',
                :bs, :eur, :ref, :banco, :obs
            )'
        );
        $ins->bindValue(':a', $asoc, PDO::PARAM_INT);
        $ins->bindValue(':s', $seq, PDO::PARAM_INT);
        $ins->bindValue(':f', (string) ($row['fecha_pago'] ?? ''), PDO::PARAM_STR);
        $tasa = round((float) ($row['tasa_eur_bs'] ?? 0), 2);
        if ($tasa <= 0) {
            $tasa = 1.0;
        }
        $ins->bindValue(':tasa', $tasa, PDO::PARAM_STR);
        $ins->bindValue(':bs', round((float) ($row['monto_bs'] ?? 0), 2), PDO::PARAM_STR);
        $ins->bindValue(':eur', round((float) ($row['monto_eur'] ?? 0), 2), PDO::PARAM_STR);
        $ins->bindValue(':ref', (string) ($row['referencia'] ?? ''), PDO::PARAM_STR);
        $ins->bindValue(':banco', (string) ($row['banco'] ?? ''), PDO::PARAM_STR);
        $ins->bindValue(':obs', $obs, PDO::PARAM_STR);
        $ins->execute();
        $migrated++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Migración finanza_pago → relacion_pagos (torneo_id=0): {$migrated} insertadas, {$skipped} omitidas (ya existían o inválidas).\n";

if ($migrated > 0) {
    require_once dirname(__DIR__) . '/app/DeudaAsociaciones.php';
    if (DeudaAsociaciones::tablaDisponible($pdo)) {
        $asocsU = [];
        foreach ($rows as $row) {
            $a = (int) ($row['asociacion_id'] ?? 0);
            if ($a > 0) {
                $asocsU[$a] = true;
            }
        }
        foreach (array_keys($asocsU) as $a) {
            DeudaAsociaciones::recalcularFila($pdo, 0, $a);
        }
        echo 'deuda_asociaciones (torneo 0) actualizada para ' . count($asocsU) . " asociación(es) con pagos migrados.\n";
    }
}
