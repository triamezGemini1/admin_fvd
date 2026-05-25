<?php

declare(strict_types=1);

namespace Fvd\Modulos\Asociaciones\Modelos;

/**
 * Entidad rectora FVD (tabla `organizacion_fvd`).
 * Los torneos oficiales se asocian a esta organización, no a asociaciones.
 */
class OrganizacionFvd
{
    private const TABLE = 'organizacion_fvd';

    /**
     * Id de la organización rectora activa (estatus = 1), menor id en caso de varias filas.
     *
     * @throws RuntimeException si no hay fila activa
     */
    public static function idOrganizadoraActiva(\PDO $pdo): int
    {
        $sql = 'SELECT id FROM ' . self::TABLE . ' WHERE estatus = 1 ORDER BY id ASC LIMIT 1';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('No se pudo consultar la organización FVD.');
        }
        $id = (int) $stmt->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException('No hay organización rectora activa. Ejecute sql/migrations/003_organizacion_fvd.sql.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obtenerActiva(\PDO $pdo): ?array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE estatus = 1 ORDER BY id ASC LIMIT 1';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listar(\PDO $pdo): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obtenerPorId(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public static function actualizar(\PDO $pdo, int $id, array $data): void
    {
        $allowed = [
            'nombre', 'direccion', 'telefono', 'email', 'numreg', 'providencia',
            'responsable_principal', 'indica', 'estatus', 'fechreg', 'fechprovi', 'ultelECC', 'logo',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $sets[] = '`' . $col . '` = :' . $col;
            $v = $data[$col];
            $params[':' . $col] = $v;
        }
        if ($sets === []) {
            throw new InvalidArgumentException('No hay campos para actualizar.');
        }
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $val) {
            if ($k === ':id') {
                $stmt->bindValue($k, (int) $val, \PDO::PARAM_INT);
                continue;
            }
            $col = ltrim($k, ':');
            if ($val === null) {
                $stmt->bindValue($k, null, \PDO::PARAM_NULL);
            } elseif (in_array($col, ['indica', 'estatus'], true) || is_int($val)) {
                $stmt->bindValue($k, (int) $val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($k, (string) $val, \PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No se actualizó ningún registro.');
        }
    }
}
