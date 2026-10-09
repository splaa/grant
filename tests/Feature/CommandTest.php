<?php

declare(strict_types=1);

use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\Team;

use function Orchestra\Testbench\default_skeleton_path;

afterEach(function (): void {
    $migrationDirectory = base_path('db/migrations');

    @unlink(config_path('grant.php'));
    array_map(unlink(...), glob($migrationDirectory.'/*_create_role_assignments.sql') ?: []);
    @rmdir($migrationDirectory);
});

/** @return list<string> */
function grantInstalledMigrations(): array
{
    return array_values(glob(base_path('db/migrations').'/*_create_role_assignments.sql') ?: []);
}

it('lists registered permission abilities', function (): void {
    $this->artisan('grant:list')
        ->expectsTable(
            ['Permission', 'Ability'],
            [
                ['EditPosts', 'edit-posts'],
                ['DeletePosts', 'delete-posts'],
                ['ViewReports', 'view-reports'],
            ],
        )
        ->assertSuccessful();
});

it('shows a user’s roles and resolved permissions', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    $this->artisan('grant:show')
        ->expectsQuestion('Which user should be shown?', $user->getId())
        ->expectsTable([], [
            ['Roles', 'editor'],
            ['Permissions', 'edit-posts, delete-posts'],
        ])
        ->assertSuccessful();
});

it('shows global and scoped roles separately', function (): void {
    $user = $this->createUser('Taylor');
    $team = $this->createTeam('Laravel');
    $user->grant(Role::Editor);

    $this->artisan('grant:show', ['user' => (string) $user->getId(), '--on' => Team::class.':'.$team->getId()])
        ->expectsTable([], [
            ['Roles', 'editor'],
            ['Scoped roles', 'None'],
            ['Permissions', 'edit-posts, delete-posts'],
        ])
        ->assertSuccessful();
});

it('remaps or deletes stored roles that no longer match the enum', function (): void {
    $user = $this->createUser('Taylor');
    $other = $this->createUser('Other');
    $user->grant(Role::Editor);

    $this->em()->persist(new RoleAssignment((int) $user->getId(), 'legacy-admin'));
    $this->em()->persist(new RoleAssignment((int) $other->getId(), 'legacy-admin'));
    $this->em()->persist(new RoleAssignment((int) $other->getId(), 'removed'));
    $this->em()->flush();

    $this->artisan('grant:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('legacy-admin, removed')
        ->assertFailed();

    $this->artisan('grant:sync')
        ->expectsQuestion('Stored role [legacy-admin] no longer exists. What should its 2 assignments become?', 'editor')
        ->expectsQuestion('Stored role [removed] no longer exists. What should its 1 assignments become?', '__delete')
        ->expectsOutputToContain('Mapped 2 assignments of [legacy-admin] to [editor].')
        ->expectsOutputToContain('Deleted 1 assignments of [removed].')
        ->assertSuccessful();

    expect($user->roles()->all())->toBe([Role::Editor])
        ->and($other->roles()->all())->toBe([Role::Editor])
        ->and($this->em()->getRepository(RoleAssignment::class)->count([]))->toBe(2);

    $this->artisan('grant:sync')
        ->expectsOutputToContain('Every stored role matches')
        ->assertSuccessful();
});

it('installs the config, dbmate migration, and starter enums', function (): void {
    config()->set('grant.permissions', 'App\\Enums\\Permission');
    config()->set('grant.roles', 'App\\Enums\\Role');

    $migrationDirectory = base_path((string) config('grant.dbmate_path'));

    try {
        $this->artisan('grant:install')->assertSuccessful();

        $migrations = glob($migrationDirectory.'/*_create_role_assignments.sql') ?: [];

        expect(file_get_contents(app_path('Enums/Permission.php')))
            ->toContain('namespace App\\Enums;')
            ->toContain('enum Permission: string implements Ability')
            ->and(file_get_contents(app_path('Enums/Role.php')))
            ->toContain('enum Role: string implements RoleContract')
            ->toContain('self::Admin => Permission::cases(),')
            ->and(file_exists(config_path('grant.php')))->toBeTrue()
            ->and(file_exists(default_skeleton_path().'/config/grant.php'))->toBeFalse()
            ->and($migrations)->toHaveCount(1)
            ->and(file_get_contents($migrations[0]))
            ->toContain('-- migrate:up')
            ->toContain('CREATE TABLE role_assignments')
            ->toContain('user_id INTEGER NOT NULL')
            ->toContain('scopeable_id INTEGER NULL')
            ->toContain('REFERENCES users (id) ON DELETE CASCADE');
    } finally {
        @unlink(app_path('Enums/Permission.php'));
        @unlink(app_path('Enums/Role.php'));
        @unlink(config_path('grant.php'));
        array_map(unlink(...), glob($migrationDirectory.'/*_create_role_assignments.sql') ?: []);
        @rmdir($migrationDirectory);
    }
});

it('renders the migration for the grant connection driver, not the default one', function (): void {
    config()->set('database.connections.reporting', ['driver' => 'pgsql', 'database' => 'reports']);
    config()->set('grant.connection', 'reporting');

    $this->artisan('grant:install')->assertSuccessful();

    expect(grantInstalledMigrations())->toHaveCount(1)
        ->and(file_get_contents(grantInstalledMigrations()[0]))
        ->toContain('id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY')
        ->toContain('user_id BIGINT NOT NULL');
});

it('does not write a second migration when grant:install runs again', function (): void {
    $this->artisan('grant:install')->assertSuccessful();
    $first = grantInstalledMigrations();

    $this->artisan('grant:install')
        ->expectsOutputToContain('already exists; use --force to overwrite.')
        ->assertSuccessful();

    expect(grantInstalledMigrations())->toBe($first);

    $this->artisan('grant:install', ['--force' => true, '--key-type' => 'uuid'])->assertSuccessful();

    expect(grantInstalledMigrations())->toBe($first)
        ->and(file_get_contents($first[0]))->toContain('user_id VARCHAR(36) NOT NULL');
});

it('writes the chosen key type into the migration and the published config', function (): void {
    $this->artisan('grant:install', ['--key-type' => 'ulid'])->assertSuccessful();

    expect(file_get_contents(config_path('grant.php')))->toContain("'key_type' => 'ulid'")
        ->and(file_get_contents(grantInstalledMigrations()[0]))
        ->toContain('user_id VARCHAR(26) NOT NULL')
        ->toContain('scopeable_id VARCHAR(26) NULL');

    $this->artisan('grant:install', ['--force' => true, '--key-type' => 'int'])->assertSuccessful();

    expect(file_get_contents(config_path('grant.php')))->toContain("'key_type' => 'int'");
});

it('defaults the key type to the configured grant.key_type', function (): void {
    config()->set('grant.key_type', 'uuid');

    $this->artisan('grant:install')->assertSuccessful();

    expect(file_get_contents(grantInstalledMigrations()[0]))->toContain('user_id VARCHAR(36) NOT NULL');
});

it('fails on an unknown key type or an unsupported database driver', function (): void {
    $this->artisan('grant:install', ['--key-type' => 'bigint'])
        ->expectsOutputToContain('must be one of: int, uuid, ulid')
        ->assertFailed();

    config()->set('database.connections.legacy', ['driver' => 'sqlsrv']);
    config()->set('grant.connection', 'legacy');

    $this->artisan('grant:install')
        ->expectsOutputToContain('[sqlsrv] database driver')
        ->assertFailed();

    expect(grantInstalledMigrations())->toBeEmpty();
});

it('reports a missing user or a malformed scope in grant:show', function (): void {
    $this->artisan('grant:show', ['user' => '999'])
        ->expectsOutputToContain('with id [999] was found')
        ->assertFailed();

    $user = $this->createUser('Taylor');

    $this->artisan('grant:show', ['user' => (string) $user->getId(), '--on' => 'Team'])
        ->expectsOutputToContain('must use EntityClass:id')
        ->assertFailed();

    $this->artisan('grant:show', ['user' => (string) $user->getId(), '--on' => Team::class.':999'])
        ->expectsOutputToContain('No scope')
        ->assertFailed();
});
