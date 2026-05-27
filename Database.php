<?php

/**
 * Conexión PDO a MySQL (base fvdmasteradmin según esquema del proyecto).
 * Credenciales: archivo .env en la raíz del proyecto.
 */
if (!defined('FVD_ROOT')) {
    define('FVD_ROOT', __DIR__);
}
require_once __DIR__ . '/app/Autoload.php';
\Fvd\Autoload::register();
require_once __DIR__ . '/app/Config/Env.php';
require_once __DIR__ . '/app/Config/App.php';
\Fvd\Config\Env::load();

class Database
{
    private string $host;
    private string $port;
    private string $dbName;
    private string $username;
    private string $password;
    private string $socket;

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
        $this->socket = trim(\Fvd\Config\Env::get('FVD_DB_SOCKET', '') ?? '');
    }

    public function getConnection(): ?PDO
    {
        return \Fvd\Config\DatabaseFactory::connect();
    }
}
