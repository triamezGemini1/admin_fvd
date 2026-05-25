<?php

declare(strict_types=1);

namespace Fvd\Modulos\Atletas\Modelos;

use Fvd\Modulos\Torneos\Modelos\TorneoMovimientoLock;

/**
 * Alta / actualización de atleta en `usuarios` y solicitudes en `movimiento_torneo`.
 *
 * `usuarios` conserva datos de persona y asociación; el torneo y los flags de operación (afiliación,
 * anualidad, carnet, inscripción, traspaso) viven en `movimiento_torneo` para informes y supervisión.
 */
class AfiliacionAtleta
{
    /** Estatus de cuenta portal (p. ej. pendiente de pago / activación); no sustituye al Nº FVD. */
    public const STATUS_AFILIACION_PENDIENTE = 9;

    private const TABLE_U = 'usuarios';

    private const TABLE_M = 'movimiento_torneo';

    /**
     * Torneo “activo” para movimiento de afiliación: sin fecha de cierre, ordenado por fecha y id.
     */
    public static function torneoAfiliacionActivoId(\PDO $pdo): ?int
    {
        $sql = 'SELECT torneo FROM torneosact WHERE finalizado_en IS NULL ORDER BY fechator DESC, torneo DESC LIMIT 1';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $id = (int) $stmt->fetchColumn();

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>|null fila sin password_hash
     */
    public static function buscarPorCedula(\PDO $pdo, string $cedula): ?array
    {
        $cedula = self::normalizarCedula($cedula);
        if ($cedula === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, numfvd, cedula, sexo, nombre, fechnac, email, celular, username, role, status, asociacion_id, posirnk, urlimgfoto, urlimgcedula, created_at, updated_at FROM ' . self::TABLE_U . ' WHERE cedula = :c LIMIT 1'
        );
        $stmt->bindValue(':c', $cedula, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array{allowed: bool, user?: array<string, mixed>|null, message?: string}
     */
    public static function verificarAccesoConsultaCedula(\PDO $pdo, string $cedula): array
    {
        $row = self::buscarPorCedula($pdo, $cedula);
        if ($row === null) {
            return ['allowed' => true, 'user' => null];
        }
        if (Auth::rol() === 'admingral') {
            return ['allowed' => true, 'user' => $row];
        }
        $aid = Auth::asociacionId();
        if ($aid === null) {
            return ['allowed' => false, 'user' => null, 'message' => 'Sin asociación asignada.'];
        }
        $uAsoc = $row['asociacion_id'] !== null && $row['asociacion_id'] !== '' ? (int) $row['asociacion_id'] : null;
        if ($uAsoc !== null && $uAsoc !== $aid) {
            return ['allowed' => false, 'user' => null, 'message' => 'La cédula pertenece a un afiliado de otra asociación.'];
        }

        return ['allowed' => true, 'user' => $row];
    }

    /**
     * @param array<string, mixed> $post campos de $_POST saneados
     * @param array{foto: ?string, cedula_img: ?string} $rutasRelativas rutas web relativas al proyecto (p. ej. dist/assets/img/uploads/...)
     */
    public static function guardar(\PDO $pdo, array $post, array $rutasRelativas): array
    {
        AdminPolicy::assertUsuarioWrite();

        $cedula = self::normalizarCedula((string) ($post['cedula'] ?? ''));
        if ($cedula === '') {
            throw new InvalidArgumentException('La cédula es obligatoria.');
        }

        $userId = isset($post['user_id']) ? (int) $post['user_id'] : 0;
        $isUpdate = $userId > 0;

        $nombre = trim((string) ($post['nombre'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $fechnac = isset($post['fechnac']) ? trim((string) $post['fechnac']) : '';
        $fechnac = $fechnac === '' ? null : $fechnac;
        $sexo = isset($post['sexo']) ? (int) $post['sexo'] : 0;
        $celular = isset($post['celular']) ? trim((string) $post['celular']) : '';
        $celular = $celular === '' ? null : $celular;

        $esAdmin = Auth::rol() === 'admingral';
        $numfvdPost = isset($post['numfvd']) ? (int) $post['numfvd'] : 0;
        if (!$esAdmin) {
            $numfvdPost = 0;
        }

        if ($nombre === '' || $email === '') {
            throw new InvalidArgumentException('Nombre y email son obligatorios.');
        }

        $pdo->beginTransaction();
        try {
            $torneoDelegadoAfiliacion = 0;
            if ($isUpdate) {
                $row = self::obtenerUsuarioParaEdicion($pdo, $userId);
                if ($row === null) {
                    throw new InvalidArgumentException('Usuario no encontrado o sin permiso.');
                }
                if (self::normalizarCedula((string) $row['cedula']) !== $cedula) {
                    throw new InvalidArgumentException('No puede cambiar la cédula del registro.');
                }
                $numfvdFinal = $esAdmin ? $numfvdPost : (int) $row['numfvd'];
                $sql = 'UPDATE ' . self::TABLE_U . ' SET nombre = :nombre, fechnac = :fechnac, sexo = :sexo, email = :email, celular = :celular, numfvd = :numfvd';
                $params = [
                    ':nombre' => $nombre,
                    ':fechnac' => $fechnac,
                    ':sexo' => $sexo,
                    ':email' => $email,
                    ':celular' => $celular,
                    ':numfvd' => $numfvdFinal,
                    ':id' => $userId,
                ];
                if ($rutasRelativas['foto'] !== null) {
                    $sql .= ', urlimgfoto = :foto';
                    $params[':foto'] = $rutasRelativas['foto'];
                }
                if ($rutasRelativas['cedula_img'] !== null) {
                    $sql .= ', urlimgcedula = :cedimg';
                    $params[':cedimg'] = $rutasRelativas['cedula_img'];
                }
                $sql .= ' WHERE id = :id LIMIT 1';
                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($v === null && ($k === ':fechnac' || $k === ':celular')) {
                        $stmt->bindValue($k, null, \PDO::PARAM_NULL);
                    } elseif ($k === ':sexo' || $k === ':numfvd' || $k === ':id') {
                        $stmt->bindValue($k, (int) $v, \PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue($k, (string) $v, \PDO::PARAM_STR);
                    }
                }
                $stmt->execute();
                $uid = $userId;
                $numfvdAntes = (int) $row['numfvd'];
            } else {
                $existente = self::buscarPorCedula($pdo, $cedula);
                if ($existente !== null) {
                    throw new InvalidArgumentException('Ya existe un usuario con esta cédula. Use «Actualizar» tras consultar la cédula.');
                }
                $asocId = Auth::asociacionId();
                if (Auth::rol() === 'delegado') {
                    if ($asocId === null || $asocId < 1) {
                        throw new InvalidArgumentException('Delegado sin asociación asignada.');
                    }
                } else {
                    $rawAsoc = isset($post['asociacion_id']) ? trim((string) $post['asociacion_id']) : '';
                    $asocId = $rawAsoc !== '' ? (int) $rawAsoc : null;
                }
                $numfvdIns = $esAdmin ? $numfvdPost : 0;
                $plain = self::generarPasswordProvisional();
                $hash = password_hash($plain, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('No se pudo generar contraseña.');
                }
                $username = self::generarUsernameUnico($pdo, $cedula);
                $sql = 'INSERT INTO ' . self::TABLE_U . ' (
                    numfvd, cedula, sexo, nombre, fechnac, email, celular, username, password_hash, role, status, asociacion_id, posirnk, urlimgfoto, urlimgcedula
                ) VALUES (
                    :numfvd, :cedula, :sexo, :nombre, :fechnac, :email, :celular, :username, :password_hash, :role, :status, :asociacion_id, 0, :foto, :cedimg
                )';
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':numfvd', $numfvdIns, \PDO::PARAM_INT);
                $stmt->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
                $stmt->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
                $stmt->bindValue(':nombre', $nombre, \PDO::PARAM_STR);
                $stmt->bindValue(':fechnac', $fechnac, $fechnac === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
                $stmt->bindValue(':celular', $celular, $celular === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $stmt->bindValue(':username', $username, \PDO::PARAM_STR);
                $stmt->bindValue(':password_hash', $hash, \PDO::PARAM_STR);
                $stmt->bindValue(':role', 'usuario', \PDO::PARAM_STR);
                $stmt->bindValue(':status', self::STATUS_AFILIACION_PENDIENTE, \PDO::PARAM_INT);
                $stmt->bindValue(':asociacion_id', $asocId, $asocId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
                $stmt->bindValue(':foto', $rutasRelativas['foto'], $rutasRelativas['foto'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $stmt->bindValue(':cedimg', $rutasRelativas['cedula_img'], $rutasRelativas['cedula_img'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $stmt->execute();
                $uid = (int) $pdo->lastInsertId();
                $numfvdAntes = 0;
                $numfvdFinal = $numfvdIns;
            }

            $movimientoCreado = false;
            if ($esAdmin && $numfvdFinal > 0 && (!$isUpdate || $numfvdAntes === 0)) {
                $tid = self::torneoAfiliacionActivoId($pdo);
                if ($tid !== null) {
                    TorneoMovimientoLock::assertEdicionPermitida($pdo, $tid);
                    self::insertarMovimientoTridenteSiAplica($pdo, $uid, $cedula, $numfvdFinal, $sexo, $tid);
                    $movimientoCreado = true;
                }
            } elseif (!$esAdmin && !$isUpdate && $uid > 0) {
                $torneoDelegadoAfiliacion = isset($post['torneo_id']) ? (int) $post['torneo_id'] : 0;
                if ($torneoDelegadoAfiliacion > 0) {
                    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DelegadoMovimientoTorneo.php';
                    \DelegadoMovimientoTorneo::upsertNuevoAfiliado($pdo, $uid, $torneoDelegadoAfiliacion);
                    $movimientoCreado = true;
                }
            }

            $pdo->commit();

            try {
                require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DeudaAsociaciones.php';
                $tidAf = self::torneoAfiliacionActivoId($pdo);
                if ($tidAf !== null) {
                    \DeudaAsociaciones::recalcularPorUsuarioTorneo($pdo, $tidAf, $uid);
                }
                if (!$esAdmin && !$isUpdate && $torneoDelegadoAfiliacion > 0) {
                    require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'DelegadoMovimientoTorneo.php';
                    \DelegadoMovimientoTorneo::recalcularDeudaUsuarioTorneo($pdo, $torneoDelegadoAfiliacion, $uid);
                }
            } catch (Throwable $eDeuda) {
                error_log('AfiliacionAtleta::guardar → DeudaAsociaciones: ' . $eDeuda->getMessage());
            }

            return [
                'user_id' => $uid,
                'movimiento_tridente' => $movimientoCreado,
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function normalizarCedula(string $c): string
    {
        return preg_replace('/\s+/', '', trim($c)) ?? '';
    }

    private static function generarPasswordProvisional(): string
    {
        return bin2hex(random_bytes(12));
    }

    private static function generarUsernameUnico(\PDO $pdo, string $cedula): string
    {
        $base = 'afiliado_' . preg_replace('/\D/', '', $cedula);
        if ($base === 'afiliado_') {
            $base = 'afiliado_' . substr(sha1($cedula), 0, 12);
        }
        $u = $base;
        $n = 0;
        while (self::usernameExiste($pdo, $u)) {
            $n++;
            $u = $base . '_' . $n;
            if ($n > 50) {
                $u = $base . '_' . bin2hex(random_bytes(4));
                break;
            }
        }

        return $u;
    }

    private static function usernameExiste(\PDO $pdo, string $username): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM ' . self::TABLE_U . ' WHERE username = :u LIMIT 1');
        $st->bindValue(':u', $username, \PDO::PARAM_STR);
        $st->execute();

        return (bool) $st->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerUsuarioParaEdicion(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE_U . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (Auth::rol() === 'delegado' && !self::delegadoPuedeEditarFila($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function delegadoPuedeEditarFila(array $row): bool
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

    private static function insertarMovimientoTridenteSiAplica(\PDO $pdo, int $userId, string $cedula, int $numfvd, int $sexo, int $torneoId): void
    {
        $chk = $pdo->prepare('SELECT id FROM ' . self::TABLE_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1');
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        if ($chk->fetchColumn()) {
            $upd = $pdo->prepare(
                'UPDATE ' . self::TABLE_M . ' SET cedula = :c, numfvd = :n, sexo = :s, afiliacion = 1, anualidad = 1, carnet = 1, asociacion_id = :a WHERE id_usuario = :u AND torneo_id = :t LIMIT 1'
            );
            $asoc = self::resolverAsociacionIdMovimiento($pdo, $userId);
            $upd->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $upd->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $upd->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $upd->bindValue(':a', $asoc, $asoc === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $upd->bindValue(':u', $userId, \PDO::PARAM_INT);
            $upd->bindValue(':t', $torneoId, \PDO::PARAM_INT);
            $upd->execute();

            return;
        }

        $asoc = self::resolverAsociacionIdMovimiento($pdo, $userId);
        $ins = $pdo->prepare(
            'INSERT INTO ' . self::TABLE_M . ' (
                id_usuario, cedula, numfvd, sexo, asociacion_id, estatus, afiliacion, anualidad, carnet, traspaso, inscripcion, torneo_id, posrnk
            ) VALUES (
                :id_usuario, :cedula, :numfvd, :sexo, :asociacion_id, 0, 1, 1, 1, 0, 0, :torneo_id, 0
            )'
        );
        $ins->bindValue(':id_usuario', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':cedula', $cedula, \PDO::PARAM_STR);
        $ins->bindValue(':numfvd', $numfvd, \PDO::PARAM_INT);
        $ins->bindValue(':sexo', $sexo, \PDO::PARAM_INT);
        $ins->bindValue(':asociacion_id', $asoc, $asoc === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $ins->bindValue(':torneo_id', $torneoId, \PDO::PARAM_INT);
        $ins->execute();
    }

    private static function resolverAsociacionIdMovimiento(\PDO $pdo, int $userId): ?int
    {
        $st = $pdo->prepare('SELECT asociacion_id FROM ' . self::TABLE_U . ' WHERE id = :id LIMIT 1');
        $st->bindValue(':id', $userId, \PDO::PARAM_INT);
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }
}
