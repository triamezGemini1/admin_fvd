<?php

declare(strict_types=1);

namespace Fvd\Modulos\Auth\Modelos;

/**
 * Autenticación contra la tabla maestra `usuarios`.
 * Roles de sesión: admingral, delegado, usuario (.cursorrules).
 *
 * Login por contraseña: operativo.
 * Login por código SMS: estructura lista (persistencia OTP + verificación); el envío vía proveedor queda pendiente.
 */
class Auth
{
    private \PDO $conn;

    private const TABLE_USUARIOS = 'usuarios';

    private const TABLE_OTP = 'auth_celular_login_otp';

    /** Estatus pendiente de aprobación (panel admin). */
    public const STATUS_PENDIENTE_APROBACION = 9;

    /** Estatus activo tras aprobación de administración general. */
    public const STATUS_ACTIVO_APROBADO = 1;

    /**
     * Valores de `usuarios.status` que permiten iniciar sesión.
     * Alineado con inscripciones y demás módulos (`status IN (1, 9)`).
     */
    public const STATUS_ACCESO_PORTAL = [1, 9];

    private const OTP_MAX_INTENTOS = 5;

    private const OTP_VALIDEZ_MINUTOS = 10;

    public function __construct(\PDO $db)
    {
        $this->conn = $db;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Inicio de sesión por username, email o cédula + contraseña.
     */
    public function login(string $identifier, string $plainPassword): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $plainPassword === '') {
            return false;
        }

        $row = $this->findUserRowByIdentifier($identifier, true);
        if ($row === null) {
            return false;
        }

        if (!password_verify($plainPassword, $row['password_hash'])) {
            return false;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            $upd = $this->conn->prepare(
                'UPDATE ' . self::TABLE_USUARIOS . ' SET password_hash = :h, updated_at = CURRENT_TIMESTAMP WHERE id = :id LIMIT 1'
            );
            $upd->bindValue(':h', $newHash, \PDO::PARAM_STR);
            $upd->bindValue(':id', (int) $row['id'], \PDO::PARAM_INT);
            $upd->execute();
            $row['password_hash'] = $newHash;
        }

        $this->abrirSesionDesdeUsuario($row);
        return true;
    }

    /**
     * Genera y guarda un OTP para el usuario; en producción debe enviarse el código al `celular` por SMS.
     * Hasta integrar el proveedor, el código queda persistido solo como hash (nadie puede leerlo desde BD).
     *
     * @return array{ok:bool, error?:string, expires_at?:string}
     */
    public function requestSmsLoginCode(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return ['ok' => false, 'error' => 'identificador_vacio'];
        }

        $row = $this->findUserRowByIdentifier($identifier, true);
        if ($row === null) {
            return ['ok' => false, 'error' => 'usuario_no_encontrado'];
        }

        $celular = trim((string) ($row['celular'] ?? ''));
        if ($celular === '') {
            return ['ok' => false, 'error' => 'sin_celular_registrado'];
        }

        $plain = (string) random_int(100000, 999999);
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $expires = (new DateTimeImmutable('+' . self::OTP_VALIDEZ_MINUTOS . ' minutes'))->format('Y-m-d H:i:s');

        $ins = $this->conn->prepare(
            'INSERT INTO ' . self::TABLE_OTP . ' (usuario_id, otp_hash, expires_at, attempts)
             VALUES (:uid, :h, :exp, 0)
             ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), expires_at = VALUES(expires_at), attempts = 0'
        );
        $ins->bindValue(':uid', (int) $row['id'], \PDO::PARAM_INT);
        $ins->bindValue(':h', $hash, \PDO::PARAM_STR);
        $ins->bindValue(':exp', $expires, \PDO::PARAM_STR);
        $ins->execute();

        $this->enviarCodigoSms($celular, $plain, $row);

        return ['ok' => true, 'expires_at' => $expires];
    }

    /**
     * Valida el código recibido por SMS y abre sesión si es correcto.
     */
    public function verifySmsLoginCode(string $identifier, string $plainCode): bool
    {
        $identifier = trim($identifier);
        $plainCode = trim($plainCode);
        if ($identifier === '' || $plainCode === '' || strlen($plainCode) < 4) {
            return false;
        }

        $row = $this->findUserRowByIdentifier($identifier, true);
        if ($row === null) {
            return false;
        }

        $uid = (int) $row['id'];
        $q = $this->conn->prepare(
            'SELECT otp_hash, expires_at, attempts FROM ' . self::TABLE_OTP . ' WHERE usuario_id = :id LIMIT 1'
        );
        $q->bindValue(':id', $uid, \PDO::PARAM_INT);
        $q->execute();
        $otp = $q->fetch(\PDO::FETCH_ASSOC);
        if ($otp === false) {
            return false;
        }

        if ((int) $otp['attempts'] >= self::OTP_MAX_INTENTOS) {
            return false;
        }

        $now = new DateTimeImmutable('now');
        $expires = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $otp['expires_at']);
        if ($expires === false || $now > $expires) {
            return false;
        }

        if (!password_verify($plainCode, $otp['otp_hash'])) {
            $bad = $this->conn->prepare(
                'UPDATE ' . self::TABLE_OTP . ' SET attempts = attempts + 1 WHERE usuario_id = :id LIMIT 1'
            );
            $bad->bindValue(':id', $uid, \PDO::PARAM_INT);
            $bad->execute();
            return false;
        }

        $del = $this->conn->prepare('DELETE FROM ' . self::TABLE_OTP . ' WHERE usuario_id = :id LIMIT 1');
        $del->bindValue(':id', $uid, \PDO::PARAM_INT);
        $del->execute();

        $this->abrirSesionDesdeUsuario($row);
        return true;
    }

    /**
     * Username sugerido cuando aún no hay uno definido (p. ej. alta automática).
     */
    public static function defaultUsernameFromNumfvd(int $numfvd): string
    {
        return 'userfvd' . $numfvd;
    }

    /**
     * Punto de extensión: integrar Twilio, SNS, proveedor local, etc.
     * Recibe el código en texto plano solo en memoria para el envío; no persistir.
     */
    protected function enviarCodigoSms(string $celularE164, string $codigoPlano, array $filaUsuario): void
    {
        // TODO: implementar envío real. Ej.: $this->smsProvider->send($celularE164, 'Su código FVD: ' . $codigoPlano);
        unset($celularE164, $codigoPlano, $filaUsuario);
    }

    private function findUserRowByIdentifier(string $identifier, bool $soloSiPuedeIniciarSesion): ?array
    {
        $sql = 'SELECT id, numfvd, cedula, sexo, nombre, fechnac, email, celular, username, password_hash, role, status, asociacion_id, posirnk, urlimgfoto, urlimgcedula
                FROM ' . self::TABLE_USUARIOS . '
                WHERE (username = :login OR email = :login OR cedula = :login)';
        if ($soloSiPuedeIniciarSesion) {
            $sql .= ' AND status IN (' . implode(',', array_map('intval', self::STATUS_ACCESO_PORTAL)) . ')';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':login', $identifier, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function abrirSesionDesdeUsuario(array $row): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['email'] = $row['email'];
        $_SESSION['cedula'] = $row['cedula'];
        $_SESSION['numfvd'] = (int) $row['numfvd'];
        $_SESSION['nombre'] = trim((string) ($row['nombre'] ?? ''));
        $_SESSION['rol'] = $this->mapDbRolToApp((string) $row['role']);
        $_SESSION['rol_db'] = $row['role'];
        $_SESSION['id_asociacion'] = $row['asociacion_id'] !== null ? (int) $row['asociacion_id'] : null;
        $_SESSION['posirnk'] = (int) $row['posirnk'];
    }

    private function mapDbRolToApp(string $dbRol): string
    {
        $r = strtolower(trim($dbRol));
        switch ($r) {
            case 'admingral':
            case 'admin_general':
                return 'admingral';
            case 'delegado':
            case 'admin_club':
            case 'aso_admin':
                return 'delegado';
            case 'usuario':
            default:
                return 'usuario';
        }
    }

    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['user_id']);
    }

    public static function userId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function rol(): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return $_SESSION['rol'] ?? null;
    }

    /**
     * Asociación provincial vinculada al usuario (delegados). Null si no aplica.
     */
    public static function asociacionId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['id_asociacion']) || $_SESSION['id_asociacion'] === null) {
            return null;
        }

        return (int) $_SESSION['id_asociacion'];
    }

    public function logout(): bool
    {
        self::logoutSession();

        return true;
    }

    /**
     * Cierra la sesión PHP (cualquier rol). No requiere instancia de Auth.
     */
    public static function logoutSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }
}
