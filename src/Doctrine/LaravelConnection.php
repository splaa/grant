<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\PDO\Connection as PdoConnection;
use Illuminate\Database\Connection;
use PDO;

final class LaravelConnection extends AbstractConnectionMiddleware
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct(new PdoConnection($this->pdo()));
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    private function pdo(): PDO
    {
        $this->connection->reconnectIfMissingConnection();

        return $this->connection->getPdo();
    }
}
