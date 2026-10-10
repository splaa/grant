<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

/** @return array{EntityManagerInterface, SchemaTool, list<ClassMetadata<object>>} */
function schemaToolOn(string $driver): array
{
    $url = match ($driver) {
        'sqlite' => 'sqlite:///'.tempnam(sys_get_temp_dir(), 'grant-schema-'),
        default => getenv('GRANT_TEST_'.strtoupper($driver).'_URL'),
    };

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped('Set GRANT_TEST_'.strtoupper($driver).'_URL to run this test.');
    }

    test()->refreshWithConfig([
        'database.connections.schema' => ['driver' => $driver, 'url' => $url, 'foreign_key_constraints' => true],
        'grant.connection' => 'schema',
    ]);

    /** @var EntityManagerInterface $entityManager */
    $entityManager = app(GrantServiceProvider::ENTITY_MANAGER);
    $schemaTool = new SchemaTool($entityManager);
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

    $schemaTool->dropSchema($metadata);

    return [$entityManager, $schemaTool, $metadata];
}

function runDbmateUp(Connection $connection, string $driver): void
{
    $sql = (string) file_get_contents(__DIR__.'/../../database/dbmate/'.$driver.'/create_role_assignments.sql.stub');
    $sql = str_replace(['{{key}}', '{{key_nullable}}'], ['INTEGER NOT NULL', 'INTEGER NULL'], $sql);

    if (preg_match('/-- migrate:up\s*(.*?)\s*-- migrate:down/s', $sql, $matches) !== 1) {
        throw new RuntimeException('The dbmate migration stub is malformed.');
    }

    foreach (array_filter(array_map(trim(...), explode(';', $matches[1]))) as $statement) {
        $connection->executeStatement($statement);
    }
}

it('ships a dbmate migration for every supported driver', function (): void {
    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $sql = (string) file_get_contents(__DIR__.'/../../database/dbmate/'.$driver.'/create_role_assignments.sql.stub');

        expect($sql)->toContain('-- migrate:up')
            ->toContain('-- migrate:down')
            ->toContain('{{key}}')
            ->toContain('{{key_nullable}}')
            ->toContain('CREATE TABLE role_assignments')
            ->toContain('role_assignments_user_role_scope_unique')
            ->toContain('(user_id, role, scopeable_type, scopeable_id)')
            ->toContain('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE')
            ->toContain('role_assignments_user_role_global_unique')
            ->toContain('DROP TABLE IF EXISTS role_assignments');
    }
});

it('rejects a duplicate global role but allows the same role on different scopes', function (): void {
    $user = $this->createUser('Taylor');
    $connection = $this->em()->getConnection();
    $insert = 'INSERT INTO role_assignments (user_id, role, scopeable_type, scopeable_id) VALUES (?, ?, ?, ?)';

    $connection->executeStatement($insert, [$user->getId(), 'editor', null, null]);
    $connection->executeStatement($insert, [$user->getId(), 'editor', 'team', 1]);
    $connection->executeStatement($insert, [$user->getId(), 'editor', 'team', 2]);

    expect(fn (): int|string => $connection->executeStatement($insert, [$user->getId(), 'editor', null, null]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn (): int|string => $connection->executeStatement($insert, [$user->getId(), 'editor', 'team', 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rolls the sqlite migration back cleanly', function (): void {
    $connection = $this->em()->getConnection();
    $schemaManager = $connection->createSchemaManager();

    expect($schemaManager->tablesExist(['role_assignments']))->toBeTrue();

    $sql = (string) file_get_contents(__DIR__.'/../../database/dbmate/sqlite/create_role_assignments.sql.stub');

    if (preg_match('/-- migrate:down\s*(.*)$/s', $sql, $matches) !== 1) {
        throw new RuntimeException('The sqlite dbmate migration stub has no down section.');
    }

    $connection->executeStatement((string) $matches[1]);

    expect($schemaManager->tablesExist(['role_assignments']))->toBeFalse();
});

it('generates a drift-free role_assignments schema with doctrine schema tooling', function (string $driver): void {
    [$entityManager, $schemaTool, $metadata] = schemaToolOn($driver);

    $schemaTool->createSchema($metadata);

    $user = new User('Taylor');
    $entityManager->persist($user);
    $entityManager->flush();

    $user->grant(Role::Editor);
    $user->grant(Role::Editor, $this->createTeam('Laravel'));

    $connection = $entityManager->getConnection();
    $duplicateGlobal = fn (): int|string => $connection->insert('role_assignments', ['user_id' => $user->getId(), 'role' => Role::Editor->value]);

    expect($schemaTool->getUpdateSchemaSql($metadata))->toBe([]);

    match ($driver) {
        'sqlite' => null,
        default => expect($duplicateGlobal)->toThrow(UniqueConstraintViolationException::class),
    };

    $entityManager->remove($user);
    $entityManager->flush();

    expect($connection->fetchOne('SELECT COUNT(*) FROM role_assignments'))->toEqual(0);

    $schemaTool->dropSchema($metadata);
})->with(['sqlite', 'pgsql', 'mysql']);

it('matches the dbmate migration with doctrine schema tooling', function (string $driver): void {
    [$entityManager, $schemaTool, $metadata] = schemaToolOn($driver);

    $schemaTool->createSchema(array_values(array_filter(
        $metadata,
        static fn (ClassMetadata $meta): bool => $meta->getName() !== RoleAssignment::class,
    )));
    runDbmateUp($entityManager->getConnection(), $driver);

    expect($schemaTool->getUpdateSchemaSql($metadata))->toBe([]);

    $schemaTool->dropSchema($metadata);
})->with(['pgsql', 'mysql']);
