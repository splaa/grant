<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Listeners\ConfigureRoleAssignmentMapping;
use Shipfastlabs\Grant\Listeners\FlushGrantMemo;
use Shipfastlabs\Grant\Listeners\InjectGrantService;

final readonly class EntityManagerFactory
{
    private const string ENTITY_NAMESPACE = 'Shipfastlabs\\Grant\\Entities';

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
        private ConnectionResolverInterface $connections,
        private Container $container,
    ) {}

    public function make(): EntityManagerInterface
    {
        $connection = $this->connection();

        $configuration = new Configuration;
        $configuration->setMetadataDriverImpl(new AttributeDriver($this->paths()));
        $configuration->enableNativeLazyObjects(true);
        $configuration->setMiddlewares([new LaravelConnectionMiddleware($connection)]);

        $driver = $connection->getDriverName();

        $dbal = DriverManager::getConnection(
            ['driver' => self::DBAL_DRIVERS[$driver] ?? throw InvalidConfigurationException::connectionDriver($driver)],
            $configuration,
        );

        return $this->attach(new EntityManager($dbal, $configuration, new EventManager));
    }

    public function attach(EntityManagerInterface $entityManager): EntityManagerInterface
    {
        $eventManager = $entityManager->getEventManager();
        $attached = array_filter(
            $eventManager->getListeners(ToolEvents::postGenerateSchema),
            static fn (object $listener): bool => $listener instanceof ConfigureRoleAssignmentMapping,
        );

        if ($attached !== []) {
            return $entityManager;
        }

        $configuration = $entityManager->getConfiguration();
        $configuration->setMetadataDriverImpl($this->withGrantEntities($configuration->getMetadataDriverImpl()));

        $eventManager->addEventSubscriber(new ConfigureRoleAssignmentMapping($this->config));
        $eventManager->addEventSubscriber(new FlushGrantMemo($this->container));

        $injected = array_filter(
            $eventManager->getListeners(Events::postLoad),
            static fn (object $listener): bool => $listener instanceof InjectGrantService,
        );

        if ($injected === []) {
            $eventManager->addEventSubscriber(new InjectGrantService($this->container));
        }

        return $entityManager;
    }

    private function withGrantEntities(?MappingDriver $driver): MappingDriverChain
    {
        $chain = match (true) {
            $driver instanceof MappingDriverChain => $driver,
            default => new MappingDriverChain,
        };

        if ($driver instanceof MappingDriver && $chain !== $driver) {
            $chain->setDefaultDriver($driver);
        }

        $chain->addDriver(new AttributeDriver([__DIR__.'/../Entities']), self::ENTITY_NAMESPACE);

        return $chain;
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = $this->config->get('grant.entities', []);
        $paths = is_array($paths) ? $paths : [];

        return array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && is_dir($path),
        )));
    }

    private function connection(): Connection
    {
        $name = $this->config->get('grant.connection');
        $connection = $this->connections->connection(is_string($name) && $name !== '' ? $name : null);

        return match (true) {
            $connection instanceof Connection => $connection,
            default => throw InvalidConfigurationException::connectionDriver($connection::class),
        };
    }
}
