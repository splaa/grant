<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Shipfastlabs\Grant\Doctrine\EntityManagerFactory;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Tests\Fixtures\Role;

function grantEntityManager(): EntityManagerInterface
{
    return new EntityManagerFactory(config(), app('db'), app())->make();
}

it('runs Doctrine on the PDO of the Laravel connection', function (): void {
    expect($this->em()->getConnection()->getNativeConnection())->toBe(DB::connection()->getPdo());

    DB::table('teams')->insert(['name' => 'Written by Laravel']);

    expect($this->em()->getConnection()->fetchOne('SELECT name FROM teams'))->toBe('Written by Laravel');
});

it('rolls role assignments back with a Laravel transaction', function (): void {
    $user = $this->createUser('Taylor');

    DB::beginTransaction();
    $user->grant(Role::Editor);

    expect(DB::transactionLevel())->toBe(1)
        ->and($user->hasRole(Role::Editor))->toBeTrue();

    DB::rollBack();

    expect($user->hasRole(Role::Editor))->toBeFalse();
});

it('commits a Doctrine transaction nested in a Laravel transaction only with the outer one', function (): void {
    $user = $this->createUser('Taylor');

    expect(fn () => DB::transaction(function () use ($user): void {
        $user->syncRoles([Role::Editor, Role::Viewer]);

        expect(DB::transactionLevel())->toBe(1);

        throw new RuntimeException('Abort');
    }))->toThrow(RuntimeException::class, 'Abort');

    expect($user->roles())->toBeEmpty()
        ->and(DB::transactionLevel())->toBe(0);

    $user->syncRoles([Role::Viewer]);

    expect(DB::transactionLevel())->toBe(0)
        ->and((int) DB::table('role_assignments')->count())->toBe(1);
});

it('creates a user and grants a role inside one Laravel transaction', function (): void {
    DB::transaction(function (): void {
        $this->createUser('Taylor')->grant(Role::Admin);
    });

    expect((int) DB::table('role_assignments')->where('role', 'admin')->count())->toBe(1);
});

it('cascades role assignments when the user row is deleted outside Doctrine', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    DB::table('users')->where('id', $user->getId())->delete();

    expect((int) DB::table('role_assignments')->count())->toBe(0);
});

it('uses the grant.connection connection, including one configured by url', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'grant-url-');

    config()->set('database.connections.reporting', [
        'driver' => 'sqlite',
        'url' => 'sqlite:///'.$database,
        'database' => 'ignored.sqlite',
    ]);
    config()->set('grant.connection', 'reporting');

    try {
        DB::connection('reporting')->statement('CREATE TABLE marker (id INTEGER)');

        $connection = grantEntityManager()->getConnection();

        expect($connection->getNativeConnection())->toBe(DB::connection('reporting')->getPdo())
            ->and($connection->createSchemaManager()->tablesExist(['marker']))->toBeTrue();
    } finally {
        DB::purge('reporting');
        @unlink((string) $database);
    }
});

it('maps Laravel drivers to DBAL drivers without connecting', function (): void {
    config()->set('database.connections.primary', ['driver' => 'pgsql', 'url' => 'pgsql://app:secret@db.example:5433/shop']);
    config()->set('grant.connection', 'primary');

    expect(grantEntityManager()->getConnection()->getParams())->toBe(['driver' => 'pdo_pgsql']);
});

it('rejects a connection driver Doctrine cannot map', function (): void {
    config()->set('database.connections.legacy', ['driver' => 'mongodb']);
    config()->set('grant.connection', 'legacy');
    DB::extend('legacy', fn (array $config, string $name): Connection => new Connection(fn (): PDO => new PDO('sqlite::memory:'), '', '', $config));

    expect(fn (): EntityManagerInterface => grantEntityManager())
        ->toThrow(InvalidConfigurationException::class, '[mongodb]');
});
