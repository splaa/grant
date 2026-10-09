<?php

declare(strict_types=1);

it('ships a dbmate migration for every supported driver', function (): void {
    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $sql = (string) file_get_contents(__DIR__.'/../../database/dbmate/'.$driver.'/create_role_assignments.sql.stub');

        expect($sql)->toContain('-- migrate:up')
            ->toContain('-- migrate:down')
            ->toContain('{{key}}')
            ->toContain('{{key_nullable}}')
            ->toContain('CREATE TABLE role_assignments')
            ->toContain('role_assignments_user_role_scope_unique')
            ->toContain('UNIQUE (user_id, role, scopeable_type, scopeable_id)')
            ->toContain('FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE')
            ->toContain('DROP TABLE IF EXISTS role_assignments');
    }
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
