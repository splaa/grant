<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use LaravelDoctrine\ORM\Auth\DoctrineUserProvider as LaravelDoctrineUserProvider;
use LaravelDoctrine\ORM\DoctrineServiceProvider;
use Shipfastlabs\Grant\Auth\DoctrineUserProvider;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Tests\Fixtures\ForeignEntityManagerServiceProvider;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

function registryManager(): EntityManagerInterface
{
    /** @var ManagerRegistry $registry */
    $registry = app(ManagerRegistry::class);

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $registry->getManager();

    return $entityManager;
}

beforeEach(function (): void {
    $this->database = tempnam(sys_get_temp_dir(), 'grant-ld-').'.sqlite';
});

afterEach(function (): void {
    @unlink($this->database);
});

it('shares the laravel-doctrine entity manager in either provider order', function (array $providers): void {
    $this->bootWithProviders($providers, $this->laravelDoctrineConfig($this->database));

    $entityManager = registryManager();
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    expect(app(GrantServiceProvider::ENTITY_MANAGER))->toBe($entityManager)
        ->and(app(EntityManagerInterface::class))->toBe($entityManager)
        ->and($entityManager->getMetadataFactory()->isTransient(RoleAssignment::class))->toBeFalse()
        ->and($entityManager->getRepository(RoleAssignment::class)->count([]))->toBe(1)
        ->and(Gate::forUser($user)->allows(Permission::EditPosts))->toBeTrue();
})->with([
    'laravel-doctrine first' => [[DoctrineServiceProvider::class, GrantServiceProvider::class]],
    'grant first' => [[GrantServiceProvider::class, DoctrineServiceProvider::class]],
]);

it('rolls role changes back with laravel-doctrine transactions', function (): void {
    $this->bootWithProviders([DoctrineServiceProvider::class, GrantServiceProvider::class], $this->laravelDoctrineConfig($this->database));

    $entityManager = registryManager();
    $user = $this->createUser('Taylor');

    expect($user->hasRole(Role::Editor))->toBeFalse();

    expect(fn (): mixed => $entityManager->wrapInTransaction(function () use ($user): never {
        $user->grant(Role::Editor);

        expect($user->hasRole(Role::Editor))->toBeTrue();

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class, 'Rolled back.');

    $entityManager->clear();

    expect($entityManager->getConnection()->fetchOne('select count(*) from role_assignments'))->toBe(0)
        ->and($user->hasRole(Role::Editor))->toBeFalse();
});

it('keeps working after laravel-doctrine resets the manager', function (): void {
    $this->bootWithProviders([DoctrineServiceProvider::class, GrantServiceProvider::class], $this->laravelDoctrineConfig($this->database));

    $user = $this->createUser('Taylor');
    $previous = registryManager();

    /** @var ManagerRegistry $registry */
    $registry = app(ManagerRegistry::class);
    $registry->resetManager();

    $entityManager = registryManager();
    $user = $entityManager->find(User::class, $user->getId());
    $user?->grant(Role::Viewer);

    expect($entityManager)->not->toBe($previous)
        ->and(app(GrantServiceProvider::ENTITY_MANAGER))->toBe($entityManager)
        ->and($user?->hasRole(Role::Viewer))->toBeTrue();
});

it('leaves the doctrine auth driver to laravel-doctrine', function (): void {
    $this->bootWithProviders([DoctrineServiceProvider::class, GrantServiceProvider::class], $this->laravelDoctrineConfig($this->database));

    expect(Auth::createUserProvider('users'))->toBeInstanceOf(LaravelDoctrineUserProvider::class);
});

it('lets laravel-doctrine schema commands manage role_assignments without drift', function (): void {
    $this->refreshWithConfig($this->laravelDoctrineConfig($this->database), [DoctrineServiceProvider::class, GrantServiceProvider::class]);

    $this->artisan('doctrine:schema:create')->assertSuccessful();

    expect(app()->resolved(GrantServiceProvider::ENTITY_MANAGER))->toBeFalse();

    $this->artisan('doctrine:schema:validate')->assertSuccessful();

    $entityManager = registryManager();
    $user = new User('Taylor');
    $entityManager->persist($user);
    $entityManager->flush();

    $user->grant(Role::Editor);

    expect($user->hasRole(Role::Editor))->toBeTrue();

    $this->artisan('doctrine:schema:validate')->assertSuccessful();
});

it('shares an entity manager bound under a container key', function (): void {
    $this->bootWithProviders([ForeignEntityManagerServiceProvider::class, GrantServiceProvider::class], [
        'grant.entity_manager' => EntityManagerInterface::class,
        'auth.providers.users.driver' => 'doctrine',
    ]);

    $foreign = app('em');
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    expect(app(GrantServiceProvider::ENTITY_MANAGER))->toBe($foreign)
        ->and($foreign->getMetadataFactory()->isTransient(RoleAssignment::class))->toBeFalse()
        ->and($user->hasRole(Role::Editor))->toBeTrue()
        ->and(Auth::createUserProvider('users'))->toBeInstanceOf(DoctrineUserProvider::class);
});

it('rejects an entity manager source that cannot be resolved', function (): void {
    $this->refreshWithConfig(['grant.entity_manager' => 'missing']);

    app(GrantServiceProvider::ENTITY_MANAGER);
})->throws(InvalidConfigurationException::class, 'missing');
