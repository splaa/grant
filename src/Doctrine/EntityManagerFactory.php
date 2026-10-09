<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Illuminate\Contracts\Config\Repository;
use Shipfastlabs\Grant\Internal\AssignmentMemo;
use Shipfastlabs\Grant\Listeners\ConfigureRoleAssignmentMapping;
use Shipfastlabs\Grant\Listeners\FlushGrantMemo;

final readonly class EntityManagerFactory
{
    /** @var array<string, string> */
    private const array DBAL_DRIVERS = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlsrv' => 'pdo_sqlsrv',
    ];

    public function __construct(
        private Repository $config,
        private AssignmentMemo $memo,
    ) {}

    public function make(): EntityManagerInterface
    {
        $configuration = new Configuration;
        $configuration->setMetadataDriverImpl(new AttributeDriver($this->paths()));
        $configuration->enableNativeLazyObjects(true);

        $eventManager = new EventManager;
        $eventManager->addEventSubscriber(new ConfigureRoleAssignmentMapping($this->config));
        $eventManager->addEventSubscriber(new FlushGrantMemo($this->memo));

        // @phpstan-ignore argument.type (params are validated by DBAL at runtime)
        $connection = DriverManager::getConnection($this->connectionParams($this->connection()));

        return new EntityManager($connection, $configuration, $eventManager);
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = $this->config->get('grant.entities', []);
        $paths = is_array($paths) ? $paths : [];
        $paths[] = __DIR__.'/../Entities';

        return array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && is_dir($path),
        )));
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        $name = $this->config->get('grant.connection');
        $name = is_string($name) && $name !== '' ? $name : $this->config->get('database.default', 'mysql');
        $name = is_string($name) ? $name : 'mysql';

        /** @var array<string, mixed> $connection */
        $connection = $this->config->get("database.connections.{$name}", []);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function connectionParams(array $connection): array
    {
        $driver = $connection['driver'] ?? 'mysql';
        $driver = is_string($driver) ? $driver : 'mysql';

        $params = ['driver' => self::DBAL_DRIVERS[$driver] ?? $driver];

        if ($driver === 'sqlite') {
            $database = $connection['database'] ?? ':memory:';
            $database = is_string($database) && $database !== '' ? $database : ':memory:';

            if ($database === ':memory:') {
                $params['memory'] = true;

                return $params;
            }

            $params['path'] = $database;

            return $params;
        }

        $keys = ['host' => 'host', 'port' => 'port', 'database' => 'dbname', 'username' => 'user', 'password' => 'password', 'charset' => 'charset', 'sslmode' => 'sslmode', 'unix_socket' => 'unix_socket'];

        foreach ($keys as $from => $to) {
            $value = $connection[$from] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $params[$to] = $value;
        }

        $options = $connection['options'] ?? null;

        if (is_array($options) && $options !== []) {
            $params['driverOptions'] = $options;
        }

        return $params;
    }
}
