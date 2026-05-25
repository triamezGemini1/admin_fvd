<?php

declare(strict_types=1);

namespace Fvd\Modulos\Auth\Modelos;

/**
 * CRUD de `usuarios` (portal maestro) con restricciones por rol.
 */
class AdminUsuario
{
    private const TABLE = 'usuarios';

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function listar(\PDO $pdo, int $page, int $perPage, ?string $q, ?int $statusExact = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $where = '1=1';
        $bind = [];
        if (Auth::rol() === 'delegado') {
            $aid = Auth::asociacionId();
            if ($aid === null) {
                return ['items' => [], 'total' => 0];
            }
            $where = 'u.asociacion_id = :aid';
            $bind[':aid'] = [$aid, \PDO::PARAM_INT];
        }
        if ($q !== null && trim($q) !== '') {
            $term = '%' . trim($q) . '%';
            $where .= ' AND (u.nombre LIKE :q OR u.cedula LIKE :q2 OR u.email LIKE :q3 OR u.username LIKE :q4)';
            $bind[':q'] = [$term, \PDO::PARAM_STR];
            $bind[':q2'] = [$term, \PDO::PARAM_STR];
            $bind[':q3'] = [$term, \PDO::PARAM_STR];
            $bind[':q4'] = [$term, \PDO::PARAM_STR];
        }
        if ($statusExact !== null && Auth::rol() === 'admingral') {
            $where .= ' AND u.status = :st';
            $bind[':st'] = [$statusExact, \PDO::PARAM_INT];
        }
        $sqlCount = 'SELECT COUNT(*) FROM ' . self::TABLE . ' u WHERE ' . $where;
        $stmtC = $pdo->prepare($sqlCount);
        self::applyBinds($stmtC, $bind);
        $stmtC->execute();
        $total = (int) $stmtC->fetchColumn();

        $sql = 'SELECT u.id, u.numfvd, u.cedula, u.sexo, u.nombre, u.fechnac, u.email, u.celular, u.username, u.role, u.status, u.asociacion_id, '
            . 'aso.nombre AS asociacion_nombre, '
            . 'u.posirnk, u.urlimgfoto, u.urlimgcedula, u.created_at, u.updated_at '
            . 'FROM ' . self::TABLE . ' u '
            . 'LEFT JOIN asociaciones aso ON aso.id = u.asociacion_id '
            . 'WHERE ' . $where . ' ORDER BY u.nombre ASC LIMIT :lim OFFSET :off';
        $stmt = $pdo->prepare($sql);
        self::applyBinds($stmt, $bind);
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
        $stmt = $pdo->prepare(
            'SELECT id, numfvd, cedula, sexo, nombre, fechnac, email, celular, username, role, status, asociacion_id, posirnk, urlimgfoto, urlimgcedula, created_at, updated_at FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (Auth::rol() === 'delegado' && !self::delegadoPuedeVerUsuario($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function crear(\PDO $pdo, array $data): int
    {
        $cedula = trim((string) ($data['cedula'] ?? ''));
        $nombre = trim((string) ($data['nombre'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $plain = (string) ($data['password'] ?? '');
        if ($cedula === '' || $nombre === '' || $email === '' || $username === '' || $plain === '') {
            throw new InvalidArgumentException('Cédula, nombre, email, usuario y contraseña son obligatorios.');
        }
        $role = strtolower(trim((string) ($data['role'] ?? 'usuario')));
        $asocId = isset($data['asociacion_id']) && $data['asociacion_id'] !== '' ? (int) $data['asociacion_id'] : null;

        if (Auth::rol() === 'delegado') {
            $role = 'usuario';
            $delegadoAid = Auth::asociacionId();
            if ($delegadoAid === null) {
                throw new InvalidArgumentException('Su usuario no tiene asociación asignada.');
            }
            $asocId = $delegadoAid;
        } else {
            if (!in_array($role, ['admingral', 'delegado', 'usuario'], true)) {
                $role = 'usuario';
            }
        }

        $sexo = isset($data['sexo']) ? (int) $data['sexo'] : 0;
        $numfvd = isset($data['numfvd']) ? (int) $data['numfvd'] : 0;
        if (Auth::rol() === 'delegado') {
            $numfvd = 0;
        }
        $status = isset($data['status']) ? (int) $data['status'] : Auth::STATUS_ACCESO_PORTAL;
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('No se pudo generar el hash de contraseña.');
        }

        $sql = 'INSERT INTO ' . self::TABLE . ' (
            numfvd, cedula, sexo, nombre, fechnac, email, celular, username, password_hash, role, status, asociacion_id, posirnk
        ) VALUES (
            :numfvd, :cedula, :sexo, :nombre, :fechnac, :email, :celular, :username, :password_hash, :role, :status, :asociacion_id, :posirnk
        )';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':numfvd', $numfvd, \PDO::PARAM_INT);
        $stmt->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
        $stmt->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
        $stmt->bindValue(':nombre', $nombre, \PDO::PARAM_STR);
        $fechnac = isset($data['fechnac']) ? trim((string) $data['fechnac']) : '';
        $stmt->bindValue(':fechnac', $fechnac === '' ? null : $fechnac, $fechnac === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
        $cel = isset($data['celular']) ? trim((string) $data['celular']) : '';
        $stmt->bindValue(':celular', $cel === '' ? null : $cel, $cel === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':username', $username, \PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $hash, \PDO::PARAM_STR);
        $stmt->bindValue(':role', $role, \PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, \PDO::PARAM_INT);
        $stmt->bindValue(':asociacion_id', $asocId, $asocId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $posirnk = isset($data['posirnk']) ? (int) $data['posirnk'] : 0;
        $stmt->bindValue(':posirnk', $posirnk, \PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            $sqlState = $e->errorInfo[1] ?? null;
            if ($sqlState === 1062) {
                throw new InvalidArgumentException('Cédula, email o nombre de usuario ya existen.');
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function actualizar(\PDO $pdo, int $id, array $data): void
    {
        $row = self::obtenerRaw($pdo, $id);
        if ($row === null) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }
        if (Auth::rol() === 'delegado' && !self::delegadoPuedeVerUsuario($row)) {
            throw new InvalidArgumentException('No puede editar este usuario.');
        }

        if (Auth::rol() === 'delegado') {
            unset($data['numfvd'], $data['status']);
        }

        if (Auth::rol() === 'delegado') {
            if (isset($data['role']) && strtolower(trim((string) $data['role'])) !== 'usuario') {
                throw new InvalidArgumentException('No puede asignar ese rol.');
            }
            if (isset($data['asociacion_id']) && (int) $data['asociacion_id'] !== Auth::asociacionId()) {
                throw new InvalidArgumentException('No puede reasignar la asociación.');
            }
        }

        $campos = [
            'numfvd', 'cedula', 'sexo', 'nombre', 'fechnac', 'email', 'celular', 'username',
            'role', 'status', 'asociacion_id', 'posirnk', 'urlimgfoto', 'urlimgcedula',
        ];
        $sets = [];
        $bindCols = [];
        foreach ($campos as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if (Auth::rol() === 'delegado' && in_array($col, ['role', 'asociacion_id', 'username'], true)) {
                continue;
            }
            $bindCols[] = $col;
            $sets[] = '`' . $col . '` = :' . $col;
        }
        $plain = isset($data['password']) ? (string) $data['password'] : '';
        if ($plain !== '') {
            $hash = password_hash($plain, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('No se pudo generar el hash de contraseña.');
            }
            $sets[] = 'password_hash = :password_hash';
            $data['password_hash'] = $hash;
        }

        if ($sets === []) {
            throw new InvalidArgumentException('No hay campos para actualizar.');
        }

        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($bindCols as $col) {
            self::bindUsuarioCol($stmt, ':' . $col, $col, $data[$col]);
        }
        if ($plain !== '') {
            $stmt->bindValue(':password_hash', $data['password_hash'], \PDO::PARAM_STR);
        }
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            $sqlState = $e->errorInfo[1] ?? null;
            if ($sqlState === 1062) {
                throw new InvalidArgumentException('Cédula, email o nombre de usuario duplicados.');
            }
            throw $e;
        }
    }

    /**
     * Actualización por el propio usuario (portal): datos de contacto, imágenes y contraseña.
     *
     * @param array<string, mixed> $data
     */
    public static function actualizarPerfilPropio(\PDO $pdo, int $userId, array $data): void
    {
        $sessionId = Auth::userId();
        if ($sessionId === null || $sessionId !== $userId) {
            throw new InvalidArgumentException('Sesión no válida.');
        }
        $allowed = ['nombre', 'fechnac', 'sexo', 'email', 'celular', 'username', 'urlimgfoto', 'urlimgcedula'];
        $sets = [];
        $bindCols = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $bindCols[] = $col;
            $sets[] = '`' . $col . '` = :' . $col;
        }
        $plain = isset($data['password']) ? (string) $data['password'] : '';
        if ($plain !== '') {
            if (strlen($plain) < 8) {
                throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
            }
            $hash = password_hash($plain, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('No se pudo generar el hash de contraseña.');
            }
            $sets[] = 'password_hash = :password_hash';
            $data['password_hash'] = $hash;
        }
        if ($sets === []) {
            throw new InvalidArgumentException('No hay campos para actualizar.');
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        foreach ($bindCols as $col) {
            self::bindUsuarioCol($stmt, ':' . $col, $col, $data[$col]);
        }
        if ($plain !== '') {
            $stmt->bindValue(':password_hash', $data['password_hash'], \PDO::PARAM_STR);
        }
        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            $sqlState = $e->errorInfo[1] ?? null;
            if ($sqlState === 1062) {
                throw new InvalidArgumentException('Email o nombre de usuario ya están en uso.');
            }
            throw $e;
        }
    }

    /**
     * @param 'foto'|'cedula' $campo
     */
    public static function setUrlImagenPerfilPropio(\PDO $pdo, int $userId, string $campo, string $relativePath): void
    {
        $sessionId = Auth::userId();
        if ($sessionId === null || $sessionId !== $userId) {
            throw new InvalidArgumentException('Sesión no válida.');
        }
        $col = $campo === 'foto' ? 'urlimgfoto' : ($campo === 'cedula' ? 'urlimgcedula' : '');
        if ($col === '') {
            throw new InvalidArgumentException('Campo debe ser foto o cedula.');
        }
        $stmt = $pdo->prepare('UPDATE ' . self::TABLE . ' SET `' . $col . '` = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id LIMIT 1');
        $stmt->bindValue(':p', $relativePath, \PDO::PARAM_STR);
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function eliminar(\PDO $pdo, int $id): void
    {
        if (Auth::rol() !== 'admingral') {
            throw new InvalidArgumentException('Solo administración general puede eliminar usuarios.');
        }
        if ((int) $id === Auth::userId()) {
            throw new InvalidArgumentException('No puede eliminar su propia sesión.');
        }
        $stmt = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('No se eliminó ningún registro.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function delegadoPuedeVerUsuario(array $row): bool
    {
        $aid = Auth::asociacionId();
        if ($aid === null) {
            return false;
        }
        if ($row['asociacion_id'] === null || $row['asociacion_id'] === '') {
            return false;
        }

        return (int) $row['asociacion_id'] === $aid;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerRaw(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, array{0: mixed, 1: int}> $bind
     */
    private static function applyBinds(\PDOStatement $stmt, array $bind): void
    {
        foreach ($bind as $param => $pair) {
            $stmt->bindValue($param, $pair[0], $pair[1]);
        }
    }

    /**
     * @param mixed $val
     */
    private static function bindUsuarioCol(\PDOStatement $stmt, string $param, string $col, $val): void
    {
        if (in_array($col, ['numfvd', 'sexo', 'status', 'asociacion_id', 'posirnk'], true)) {
            if ($val === null || $val === '') {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (int) $val, \PDO::PARAM_INT);
            }

            return;
        }
        if ($col === 'fechnac') {
            $s = trim((string) $val);
            $stmt->bindValue($param, $s === '' ? null : $s, $s === '' ? \PDO::PARAM_NULL : \PDO::PARAM_STR);

            return;
        }
        $s = trim((string) $val);
        $stmt->bindValue($param, $s, \PDO::PARAM_STR);
    }
}
