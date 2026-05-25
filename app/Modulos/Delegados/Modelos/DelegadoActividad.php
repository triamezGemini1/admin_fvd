<?php

declare(strict_types=1);

namespace Fvd\Modulos\Delegados\Modelos;

/**
 * Actividad reciente en el panel del delegado (ámbito de una asociación).
 */
class DelegadoActividad
{
    /**
     * @return list<array{titulo: string, fecha: ?string, href: string}>
     */
    public static function itemsRecientes(\PDO $pdo, int $asociacionId, int $limit = 12): array
    {
        if ($asociacionId < 1) {
            return [];
        }
        $limMov = max(1, min(20, $limit + 8));
        $sql = 'SELECT m.id, m.updated_at, m.traspaso, m.inscripcion, m.afiliacion, m.carnet, m.anualidad,
                m.numfvd, m.cedula, COALESCE(u.nombre, \'\') AS nombre_u
            FROM movimiento_torneo m
            LEFT JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.asociacion_id = :a
            ORDER BY m.updated_at DESC, m.id DESC
            LIMIT ' . $limMov;
        $st = $pdo->prepare($sql);
        $st->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $st->execute();
        $movRows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($movRows === false) {
            $movRows = [];
        }

        $items = [];
        foreach ($movRows as $r) {
            $items[] = self::mapMovimiento($r);
        }

        $st2 = $pdo->prepare(
            'SELECT nombre, updated_at FROM usuarios WHERE asociacion_id = :a AND status = 9
             ORDER BY updated_at DESC, id DESC LIMIT 6'
        );
        $st2->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $st2->execute();
        $pend = $st2->fetchAll(\PDO::FETCH_ASSOC);
        if ($pend !== false) {
            foreach ($pend as $u) {
                $nom = trim((string) ($u['nombre'] ?? ''));
                $items[] = [
                    'titulo' => 'Cuenta portal pendiente (FVD): ' . ($nom !== '' ? $nom : 'usuario'),
                    'fecha' => isset($u['updated_at']) ? (string) $u['updated_at'] : null,
                    'href' => 'panel.html#del-afiliaciones',
                ];
            }
        }

        usort(
            $items,
            static function (array $x, array $y): int {
                $ta = $x['fecha'] ?? '';
                $tb = $y['fecha'] ?? '';

                return strcmp((string) $tb, (string) $ta);
            }
        );

        return array_slice($items, 0, max(1, min(24, $limit)));
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{titulo: string, fecha: ?string, href: string}
     */
    private static function mapMovimiento(array $r): array
    {
        $nombre = trim((string) ($r['nombre_u'] ?? ''));
        $ced = trim((string) ($r['cedula'] ?? ''));
        $etq = $nombre !== '' ? $nombre : ($ced !== '' ? $ced : 'Integrante');
        $ins = (int) ($r['inscripcion'] ?? 0) === 1;
        $tr = (int) ($r['traspaso'] ?? 0) === 1;
        $af = (int) ($r['afiliacion'] ?? 0) === 1;
        $nfM = (int) ($r['numfvd'] ?? 0);
        $car = (int) ($r['carnet'] ?? 0) === 1;
        $anu = (int) ($r['anualidad'] ?? 0) === 1;

        $href = 'panel.html#del-afiliaciones';
        if ($ins && $tr) {
            $titulo = 'Solicitud de traspaso (inscripción): ' . $etq;
            $href = 'panel.html#del-traspasos';
        } elseif ($ins) {
            $titulo = 'Inscripción en nómina: ' . $etq;
            $href = 'inscripciones.html';
        } elseif ($af && $nfM < 1) {
            $titulo = 'Alta / afiliación pendiente de Nº FVD: ' . $etq;
            $href = 'panel.html#del-afiliaciones';
        } elseif ($af) {
            $titulo = 'Afiliación registrada (Nº FVD en nómina): ' . $etq;
            $href = 'panel.html#del-afiliaciones';
        } elseif ($car) {
            $titulo = 'Carnet en nómina: ' . $etq;
            $href = 'panel.html#del-afiliaciones';
        } elseif ($anu) {
            $titulo = 'Anualidad en nómina: ' . $etq;
            $href = 'panel.html#del-afiliaciones';
        } else {
            $titulo = 'Actualización de nómina: ' . $etq;
        }

        $fecha = isset($r['updated_at']) ? (string) $r['updated_at'] : null;

        return ['titulo' => $titulo, 'fecha' => $fecha, 'href' => $href];
    }
}
