<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Illuminate\Database\Connection;

final readonly class LaravelConnectionMiddleware implements Middleware
{
    public function __construct(private Connection $connection) {}

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->connection) extends AbstractDriverMiddleware
        {
            public function __construct(Driver $driver, private readonly Connection $connection)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): LaravelConnection
            {
                return new LaravelConnection($this->connection);
            }
        };
    }
}
