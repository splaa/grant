<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Shipfastlabs\Grant\Doctrine\EntityManagerFactory;
use Shipfastlabs\Grant\Internal\AssignmentMemo;
use Shipfastlabs\Grant\Tests\Fixtures\Role;

/** @return array<string, mixed> */
function grantConnectionParams(Repository $config): array
{
    return new EntityManagerFactory($config, new AssignmentMemo)->make()->getConnection()->getParams();
}

it('enforces sqlite foreign keys when the Laravel connection enables them', function (bool $enabled, int $expected): void {
    /** @var Repository $config */
    $config = $this->app?->make(Repository::class);
    $config->set('database.connections.testing.foreign_key_constraints', $enabled);

    $connection = new EntityManagerFactory($config, new AssignmentMemo)->make()->getConnection();

    expect((int) $connection->fetchOne('PRAGMA foreign_keys'))->toBe($expected);
})->with([
    'enabled' => [true, 1],
    'disabled' => [false, 0],
]);

it('cascades role assignments when the user row is deleted outside Doctrine', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    $this->em()->getConnection()->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);

    expect((int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM role_assignments'))->toBe(0);
});

it('builds connection params from the configured Laravel connection', function (): void {
    /** @var Repository $config */
    $config = $this->app?->make(Repository::class);
    $config->set('database.connections.reporting', [
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => '5433',
        'database' => 'reports',
        'username' => 'grant',
        'password' => 'secret',
        'charset' => 'utf8',
        'sslmode' => 'require',
        'prefix' => '',
        'options' => [PDO::ATTR_TIMEOUT => 5],
    ]);
    $config->set('grant.connection', 'reporting');

    expect(grantConnectionParams($config))->toMatchArray([
        'driver' => 'pdo_pgsql',
        'host' => 'db.internal',
        'port' => '5433',
        'dbname' => 'reports',
        'user' => 'grant',
        'password' => 'secret',
        'charset' => 'utf8',
        'sslmode' => 'require',
        'driverOptions' => [PDO::ATTR_TIMEOUT => 5],
    ]);
});

it('falls back to the default connection and honours a DB_URL style url', function (): void {
    /** @var Repository $config */
    $config = $this->app?->make(Repository::class);
    $config->set('database.connections.primary', [
        'driver' => 'mysql',
        'url' => 'mysql://app:p%40ss@db.example:3307/shop',
        'host' => '127.0.0.1',
        'database' => 'laravel',
    ]);
    $config->set('database.default', 'primary');
    $config->set('grant.connection');

    expect(grantConnectionParams($config))->toMatchArray([
        'driver' => 'pdo_mysql',
        'host' => 'db.example',
        'port' => 3307,
        'dbname' => 'shop',
        'user' => 'app',
        'password' => 'p@ss',
    ]);
});

it('uses an in-memory sqlite database when no file is configured', function (): void {
    /** @var Repository $config */
    $config = $this->app?->make(Repository::class);
    $config->set('database.connections.memory', ['driver' => 'sqlite', 'database' => ':memory:']);
    $config->set('grant.connection', 'memory');

    expect(grantConnectionParams($config))->toMatchArray(['driver' => 'pdo_sqlite', 'memory' => true])
        ->not->toHaveKey('path');
});
