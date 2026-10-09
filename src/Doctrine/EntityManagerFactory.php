<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;
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

    /** @var array<string, string> */
    private const array CONNECTION_KEYS = [
        'host' => 'host',
        'port' => 'port',
        'database' => 'dbname',
        'username' => 'user',
        'password' => 'password',
        'charset' => 'charset',
        'sslmode' => 'sslmode',
        'unix_socket' => 'unix_socket',
    ];

    public function __construct(
        private Repository $config,
        private AssignmentMemo $memo,
    ) {}

    public function make(): EntityManagerInterface
    {
        $connection = $this->connection();

        $configuration = new Configuration;
        $configuration->setMetadataDriverImpl(new AttributeDriver($this->paths()));
        $configuration->enableNativeLazyObjects(true);

        if (($connection['driver'] ?? null) === 'sqlite' && ($connection['foreign_key_constraints'] ?? false) === true) {
            $configuration->setMiddlewares([new EnableForeignKeys]);
        }

        $eventManager = new EventManager;
        $eventManager->addEventSubscriber(new ConfigureRoleAssignmentMapping($this->config));
        $eventManager->addEventSubscriber(new FlushGrantMemo($this->memo));

        // @phpstan-ignore argument.type (params are validated by DBAL at runtime)
        $dbal = DriverManager::getConnection($this->connectionParams($connection), $configuration);

        return new EntityManager($dbal, $configuration, $eventManager);
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

        return new ConfigurationUrlParser()->parseConfiguration($connection);
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

        return match ($driver) {
            'sqlite' => [...$params, ...$this->sqliteParams($connection)],
            default => [...$params, ...$this->serverParams($connection)],
        };
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function sqliteParams(array $connection): array
    {
        $database = $connection['database'] ?? null;

        return match (true) {
            ! is_string($database), $database === '', $database === ':memory:' => ['memory' => true],
            default => ['path' => $database],
        };
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function serverParams(array $connection): array
    {
        $params = [];

        foreach (self::CONNECTION_KEYS as $from => $to) {
            $value = $connection[$from] ?? null;

            if ($value !== null && $value !== '') {
                $params[$to] = $value;
            }
        }

        $options = $connection['options'] ?? null;

        if (is_array($options) && $options !== []) {
            $params['driverOptions'] = $options;
        }

        return $params;
    }
}
