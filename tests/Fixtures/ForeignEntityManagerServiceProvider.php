<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests\Fixtures;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Shipfastlabs\Grant\Doctrine\LaravelConnectionMiddleware;

final class ForeignEntityManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('em', static function (Application $app): EntityManagerInterface {
            /** @var Connection $connection */
            $connection = $app->make('db')->connection();

            $configuration = new Configuration;
            $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
            $configuration->enableNativeLazyObjects(true);
            $configuration->setMiddlewares([new LaravelConnectionMiddleware($connection)]);

            return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite'], $configuration), $configuration);
        });

        $this->app->alias('em', EntityManagerInterface::class);
    }
}
