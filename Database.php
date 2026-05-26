<?php

/**
 * Conexión PDO a MySQL (base fvdmasteradmin según esquema del proyecto).
 * Credenciales: variables de entorno opcionales o valores por defecto WAMP.
 */
if (!defined('FVD_ROOT')) {
    define('FVD_ROOT', __DIR__);
}
require_once __DIR__ . '/app/Config/Env.php';
\Fvd\Config\Env::load();

class Database
{
    private string $host;
    private string $port;
    private string $dbName;
    private string $username;
    private string $password;

    public function __construct(
        ?string $host = null,
        ?string $port = null,
        ?string $dbName = null,
        ?string $username = null,
        ?string $password = null
    ) {
        $this->host = $host ?? \Fvd\Config\Env::get('FVD_DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $this->port = $port ?? \Fvd\Config\Env::get('FVD_DB_PORT', '3306') ?? '3306';
        $this->dbName = $dbName ?? \Fvd\Config\Env::get('FVD_DB_DATABASE', 'fvdmasteradmin') ?? 'fvdmasteradmin';
        $this->username = $username ?? \Fvd\Config\Env::get('FVD_DB_USERNAME', 'root') ?? 'root';
        $this->password = $password ?? \Fvd\Config\Env::get('FVD_DB_PASSWORD', '') ?? '';
    }

    public function getConnection(): ?PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->dbName
        );

        try {
            $pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);
            return $pdo;
        } catch (PDOException $e) {
            error_log('Database::getConnection — ' . $e->getMessage());
            return null;
        }
    }
}
