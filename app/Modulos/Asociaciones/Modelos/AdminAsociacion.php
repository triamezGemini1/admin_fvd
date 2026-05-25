<?php

declare(strict_types=1);

namespace Fvd\Modulos\Asociaciones\Modelos;

/**
 * CRUD de tabla `asociaciones` con alcance según rol (AdminPolicy).
 */
class AdminAsociacion
{
    private const TABLE = 'asociaciones';

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function listar(\PDO $pdo, int $page, int $perPage, ?int $estatusExact = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        if (\Auth::rol() === 'delegado') {
            $aid = \Auth::asociacionId();
            if ($aid === null || $aid < 1) {
                return ['items' => [], 'total' => 0];
            }
            $row = self::obtenerInterno($pdo, $aid);

            return ['items' => $row !== null ? [$row] : [], 'total' => $row !== null ? 1 : 0];
        }

        $where = '1=1';
        $bind = [];
        if ($estatusExact !== null) {
            $where = 'estatus = :est';
            $bind[':est'] = [$estatusExact, \PDO::PARAM_INT];
        }
        $sqlCount = 'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where;
        $stmtC = $pdo->prepare($sqlCount);
        foreach ($bind as $k => [$v, $t]) {
            $stmtC->bindValue($k, $v, $t);
        }
        $stmtC->execute();
        $total = (int) $stmtC->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY nombre ASC LIMIT :lim OFFSET :off'
        );
        foreach ($bind as $k => [$v, $t]) {
            $stmt->bindValue($k, $v, $t);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['items' => $items === false ? [] : $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obtener(\PDO $pdo, int $id): ?array
    {
        if (\Auth::rol() === 'delegado') {
            $aid = \Auth::asociacionId();
            if ($aid === null || $id !== $aid) {
                return null;
            }
        }

        return self::obtenerInterno($pdo, $id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function crear(\PDO $pdo, array $data): int
    {
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre es obligatorio.');
        }
        $sql = 'INSERT INTO ' . self::TABLE . ' (
            nombre, direccion, telefono, email, numreg, providencia, delegado,
            indica, estatus, fechreg, fechprovi, ultelECC, logo
        ) VALUES (
            :nombre, :direccion, :telefono, :email, :numreg, :providencia, :delegado,
            :indica, :estatus, :fechreg, :fechprovi, :ultelECC, :logo
        )';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':nombre', $nombre, \PDO::PARAM_STR);
        self::bindNullableStr($stmt, ':direccion', self::strOrNull($data['direccion'] ?? null));
        self::bindNullableStr($stmt, ':telefono', self::strOrNull($data['telefono'] ?? null));
        self::bindNullableStr($stmt, ':email', self::strOrNull($data['email'] ?? null));
        self::bindNullableStr($stmt, ':numreg', self::strOrNull($data['numreg'] ?? null));
        self::bindNullableStr($stmt, ':providencia', self::strOrNull($data['providencia'] ?? null));
        $delegado = trim((string) ($data['delegado'] ?? ''));
        $stmt->bindValue(':delegado', $delegado, \PDO::PARAM_STR);
        $stmt->bindValue(':indica', isset($data['indica']) ? (int) $data['indica'] : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':estatus', isset($data['estatus']) ? (int) $data['estatus'] : 1, \PDO::PARAM_INT);
        self::bindNullableStr($stmt, ':fechreg', self::strOrNull($data['fechreg'] ?? null));
        self::bindNullableStr($stmt, ':fechprovi', self::strOrNull($data['fechprovi'] ?? null));
        self::bindNullableStr($stmt, ':ultelECC', self::strOrNull($data['ultelECC'] ?? null));
        self::bindNullableStr($stmt, ':logo', self::strOrNull($data['logo'] ?? null));
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function actualizar(\PDO $pdo, int $id, array $data): void
    {
        if (\Auth::rol() === 'delegado') {
            $aid = \Auth::asociacionId();
            if ($aid === null || $id !== $aid) {
                throw new InvalidArgumentException('Solo puede editar su propia asociación.');
            }
            $data = self::filtrarCamposDelegado($data);
        }

        $camposAdmin = [
            'nombre', 'direccion', 'telefono', 'email', 'numreg', 'providencia', 'delegado',
            'indica', 'estatus', 'fechreg', 'fechprovi', 'ultelECC', 'logo',
        ];
        $sets = [];
        foreach ($camposAdmin as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $sets[] = '`' . $col . '` = :' . $col;
        }
        if ($sets === []) {
            throw new InvalidArgumentException('No hay campos para actualizar.');
        }
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($camposAdmin as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $v = $data[$col];
            if ($col === 'indica' || $col === 'estatus') {
                $stmt->bindValue(':' . $col, (int) $v, \PDO::PARAM_INT);
            } elseif (in_array($col, ['fechreg', 'fechprovi', 'ultelECC'], true)) {
                self::bindNullableStr($stmt, ':' . $col, self::strOrNull($v));
            } else {
                self::bindNullableStr($stmt, ':' . $col, self::strOrNull($v));
            }
        }
        $stmt->execute();
    }

    public static function eliminar(\PDO $pdo, int $id): void
    {
        $u = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE asociacion_id = :id');
        $u->bindValue(':id', $id, \PDO::PARAM_INT);
        $u->execute();
        if ((int) $u->fetchColumn() > 0) {
            throw new InvalidArgumentException('No se puede eliminar: hay usuarios vinculados a esta asociación.');
        }
        $stmt = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No se eliminó ningún registro.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function filtrarCamposDelegado(array $data): array
    {
        $out = [];
        foreach (\AdminPolicy::ASOCIACION_CAMPOS_DELEGADO as $c) {
            if (array_key_exists($c, $data)) {
                $out[$c] = $data[$c];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerInterno(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function bindNullableStr(\PDOStatement $stmt, string $param, ?string $v): void
    {
        if ($v === null) {
            $stmt->bindValue($param, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $v, \PDO::PARAM_STR);
        }
    }

    /**
     * @param mixed $v
     */
    private static function strOrNull($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
