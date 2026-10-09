<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Shipfastlabs\Grant\Doctrine\EntityManagerFactory;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Facades\Grant;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Tests\Fixtures\ForeignEntityManagerServiceProvider;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

it('exposes its entity manager as EntityManagerInterface when nothing else is bound', function (): void {
    expect($this->app?->make(EntityManagerInterface::class))
        ->toBe($this->app?->make(GrantServiceProvider::ENTITY_MANAGER));
});

it('leaves an application EntityManagerInterface alone in either provider order', function (array $providers): void {
    $this->bootWithProviders($providers);

    $foreign = $this->app?->make('em');
    $grantEntityManager = $this->app?->make(GrantServiceProvider::ENTITY_MANAGER);

    expect($this->app?->make(EntityManagerInterface::class))->toBe($foreign)
        ->and($grantEntityManager)->not->toBe($foreign);

    $user = new User('Taylor');
    $grantEntityManager->persist($user);
    $grantEntityManager->flush();

    $user->grant(Role::Editor);

    $loadedByForeign = $foreign->find(User::class, $user->getId());

    expect(Gate::forUser($user)->allows(Permission::EditPosts))->toBeTrue()
        ->and($loadedByForeign)->not->toBe($user)
        ->and($loadedByForeign?->hasRole(Role::Editor))->toBeTrue()
        ->and($foreign->getEventManager()->getListeners(Events::postLoad))->toHaveCount(1)
        ->and($grantEntityManager->getEventManager()->getListeners(Events::postLoad))->toHaveCount(1)
        ->and($foreign->getUnitOfWork()->getIdentityMap())->not->toHaveKey(RoleAssignment::class);
})->with([
    'foreign provider first' => [[ForeignEntityManagerServiceProvider::class, GrantServiceProvider::class]],
    'grant provider first' => [[GrantServiceProvider::class, ForeignEntityManagerServiceProvider::class]],
]);

it('does not build the entity manager while the application boots', function (): void {
    $this->refreshApplication();

    expect($this->app?->resolved(GrantServiceProvider::ENTITY_MANAGER))->toBeFalse()
        ->and(Gate::has(Permission::EditPosts->value))->toBeTrue();
});

it('flushes the memo of the current scope from a long-lived entity manager', function (): void {
    $app = $this->app;

    /** @var Repository $config */
    $config = $app?->make(Repository::class);
    $entityManager = new EntityManagerFactory($config, $app?->make('db'), $app)->make();
    $app?->instance(GrantServiceProvider::ENTITY_MANAGER, $entityManager);

    $user = new User('Taylor');
    $entityManager->persist($user);
    $entityManager->flush();

    $user->grant(Role::Viewer);

    $app?->forgetScopedInstances();
    $app?->instance(GrantServiceProvider::ENTITY_MANAGER, $entityManager);

    expect(Grant::roles($user)->all())->toBe([Role::Viewer]);

    $entityManager->persist(new RoleAssignment((int) $user->getId(), Role::Editor->value));
    $entityManager->flush();

    expect(Grant::roles($user)->all())->toEqualCanonicalizing([Role::Viewer, Role::Editor]);
});
