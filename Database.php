<?php

/**
 * Conexión PDO a MySQL (base fvdmasteradmin según esquema del proyecto).
 * Credenciales: variables de entorno opcionales o valores por defecto WAMP.
 */
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
        $this->host = $host ?? getenv('FVD_DB_HOST') ?: '127.0.0.1';
        $this->port = $port ?? getenv('FVD_DB_PORT') ?: '3306';
        $this->dbName = $dbName ?? getenv('FVD_DB_DATABASE') ?: 'fvdmasteradmin';
        $this->username = $username ?? getenv('FVD_DB_USERNAME') ?: 'root';
        $this->password = $password ?? (getenv('FVD_DB_PASSWORD') !== false ? getenv('FVD_DB_PASSWORD') : '');
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
