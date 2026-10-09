<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Testing\InteractsWithGrant;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\Team;
use Shipfastlabs\Grant\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use InteractsWithGrant;

    /** @var list<class-string<ServiceProvider>> */
    protected array $packageProviders = [GrantServiceProvider::class];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('grant.entities', [__DIR__.'/Fixtures']);
        $app['config']->set('grant.connection', 'testing');
        $app['config']->set('grant.permissions', Permission::class);
        $app['config']->set('grant.roles', Role::class);
        $app['config']->set('auth.providers.users.model', User::class);

        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grant-testbench-'.getmypid();
        $app->useAppPath($sandbox.DIRECTORY_SEPARATOR.'app');
        $app->useConfigPath($sandbox.DIRECTORY_SEPARATOR.'config');
        $app->useDatabasePath($sandbox.DIRECTORY_SEPARATOR.'database');
    }

    /** @param  list<class-string<ServiceProvider>>  $providers */
    protected function bootWithProviders(array $providers): void
    {
        $this->packageProviders = $providers;
        $this->refreshApplication();
        $this->defineDatabaseMigrations();
    }

    protected function defineDatabaseMigrations(): void
    {
        $entityManager = $this->em();
        $connection = $entityManager->getConnection();

        $metadata = array_values(array_filter(
            $entityManager->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $meta): bool => $meta->getName() !== RoleAssignment::class,
        ));

        new SchemaTool($entityManager)->createSchema($metadata);

        $connection->executeStatement($this->roleAssignmentsUpSql());
    }

    protected function getPackageProviders($app): array
    {
        return $this->packageProviders;
    }

    protected function em(): EntityManagerInterface
    {
        $app = $this->app;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application instance is not available.');
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $app->make(EntityManagerInterface::class);

        return $entityManager;
    }

    protected function createUser(string $name): User
    {
        $user = new User($name);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createTeam(string $name): Team
    {
        $team = new Team($name);
        $this->em()->persist($team);
        $this->em()->flush();

        return $team;
    }

    private function roleAssignmentsUpSql(): string
    {
        $sql = (string) file_get_contents(__DIR__.'/../database/dbmate/sqlite/create_role_assignments.sql.stub');
        $sql = str_replace(['{{key}}', '{{key_nullable}}'], ['INTEGER NOT NULL', 'INTEGER NULL'], $sql);

        if (preg_match('/-- migrate:up\s*(.*?)\s*-- migrate:down/s', $sql, $matches) !== 1) {
            throw new RuntimeException('The sqlite dbmate migration stub is malformed.');
        }

        return $matches[1];
    }
}
