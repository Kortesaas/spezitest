<?php

declare(strict_types=1);

namespace Spezitest\Database;

use PDO;
use PDOException;

final readonly class ConnectionFactory
{
    public function __construct(private DatabaseConfiguration $configuration)
    {
    }

    public function create(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->configuration->host(),
            $this->configuration->port(),
            $this->configuration->databaseName(),
            $this->configuration->charset(),
        );

        try {
            return new PDO(
                $dsn,
                $this->configuration->user(),
                $this->configuration->password(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException(
                'Database connection failed. Verify the DB_* configuration and server availability.',
                0,
                $exception,
            );
        }
    }
}
