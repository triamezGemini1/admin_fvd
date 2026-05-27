<?php

declare(strict_types=1);

namespace Fvd\Database;

use RuntimeException;

/**
 * Error de conexión PDO sin exponer credenciales al cliente.
 */
final class ConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $connection = 'portal',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function connectionName(): string
    {
        return $this->connection;
    }
}
