<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Exceptions\UnsavedEntityException;
use Shipfastlabs\Grant\Facades\Grant;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Post;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\Team;
use Shipfastlabs\Grant\Tests\Fixtures\User;

it('assigns, revokes, and synchronizes global roles', function (): void {
    $user = $this->createUser('Taylor');

    $user->grant(Role::Editor)->grant(Role::Viewer);

    expect($user->hasRole(Role::Editor))->toBeTrue()
        ->and($user->roles()->all())->toEqualCanonicalizing([Role::Editor, Role::Viewer])
        ->and($user->permissions()->all())->toEqualCanonicalizing(Permission::cases());

    $user->revoke(Role::Viewer);
    $user->syncRoles([Role::Admin]);

    expect($user->roles()->all())->toBe([Role::Admin]);
});

it('exposes assignments and removes them when the user is deleted', function (): void {
    $user = $this->createUser('Taylor');
    $other = $this->createUser('Other');
    $team = $this->createTeam('Laravel');

    $user->grant(Role::Editor)->grant(Role::Viewer, on: $team);
    $other->grant(Role::Editor);

    $assignments = $user->roleAssignments();
    $scoped = collect($assignments)->firstWhere(static fn (RoleAssignment $row): bool => $row->getScopeableType() === Team::class);

    expect($assignments)->toHaveCount(2)
        ->each->toBeInstanceOf(RoleAssignment::class)
        ->and($scoped)->not->toBeNull()
        ->and($this->em()->find(Team::class, $scoped?->getScopeableId()))->toBeInstanceOf(Team::class)
        ->and($this->em()->find(User::class, $assignments[0]->getUserId()))->toBeInstanceOf(User::class);

    $this->em()->remove($user);
    $this->em()->flush();

    expect($this->em()->getRepository(RoleAssignment::class)->count([]))->toBe(1)
        ->and($other->roles()->all())->toBe([Role::Editor]);
});

it('keeps scoped roles separate and falls back to global permissions', function (): void {
    $user = $this->createUser('Taylor');
    $firstTeam = $this->createTeam('First');
    $secondTeam = $this->createTeam('Second');

    $user->grant(Role::Viewer);
    $user->grant(Role::Editor, on: $firstTeam);

    expect($user->roles($firstTeam)->all())->toBe([Role::Editor])
        ->and($user->roles($secondTeam))->toBeEmpty()
        ->and($user->permissions($firstTeam)->all())->toEqualCanonicalizing(Permission::cases())
        ->and($user->permissions($secondTeam)->all())->toBe([Permission::ViewReports]);
});

it('memoizes assignments per user and only bulk writes require a manual flush', function (): void {
    $user = $this->createUser('Taylor');
    $team = $this->createTeam('First');
    $user->grant(Role::Viewer)->grant(Role::Editor, on: $team);

    expect($user->roles()->all())->toBe([Role::Viewer])
        ->and($user->roles($team)->all())->toBe([Role::Editor])
        ->and(Gate::forUser($user)->allows(Permission::EditPosts, $team))->toBeTrue()
        ->and($this->em()->find(User::class, $user->getId())?->hasRole(Role::Viewer))->toBeTrue();

    $user->syncRoles([Role::Admin]);
    expect($user->roles()->all())->toBe([Role::Admin]);

    $this->em()->getConnection()->executeStatement(
        'INSERT INTO role_assignments (user_id, role) VALUES (?, ?)',
        [(int) $user->getId(), 'editor'],
    );

    expect($user->roles()->all())->toBe([Role::Admin]);

    Grant::flush($user);
    expect($user->roles()->all())->toEqualCanonicalizing([Role::Admin, Role::Editor]);
});

it('registers native Gate abilities with denial messages and scoped resolution', function (): void {
    $user = $this->createUser('Taylor');
    $team = $this->createTeam('First');
    $user->grant(Role::Editor, on: $team);

    expect(Gate::forUser($user)->allows(Permission::EditPosts, $team))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::EditPosts))->toBeFalse()
        ->and(Gate::forUser($user)->inspect(Permission::EditPosts)->message())->toBe('Editor access is required.');
});

it('denies instead of throwing for non-entity gate arguments and unsaved entities', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Viewer);

    expect(Gate::forUser($user)->allows(Permission::ViewReports, Post::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::ViewReports, [new Team('Unsaved'), 'extra']))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::EditPosts, 5))->toBeFalse()
        ->and(Gate::forUser(new User('Unsaved'))->allows(Permission::ViewReports))->toBeFalse();
});

it('rejects unsaved scopes', function (): void {
    $user = $this->createUser('Taylor');

    expect(fn () => $user->grant(Role::Editor, on: new Team('Unsaved')))
        ->toThrow(UnsavedEntityException::class, 'persisted Doctrine entity');
});

it('supports facade assignments and test helpers', function (): void {
    $user = $this->createUser('Taylor');
    Grant::grant($user, Role::Viewer);
    Grant::revoke($user, Role::Viewer);

    $this->actingAs($user)
        ->assertCannot(Permission::EditPosts)
        ->withRole(Role::Editor)
        ->assertCan(Permission::EditPosts);
});

it('allows a configured super admin before every other ability', function (): void {
    config()->set('grant.super_admin', Role::Admin);
    $user = $this->createUser('Taylor');
    $user->grant(Role::Admin);

    expect(Gate::forUser($user)->allows('undefined-ability'))->toBeTrue();
});

it('keeps Gate checks current after scoped instances are reset between jobs', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor);

    expect(Gate::forUser($user)->allows(Permission::EditPosts))->toBeTrue();

    $this->app?->forgetScopedInstances();
    $user->revoke(Role::Editor);

    expect(Gate::forUser($user)->allows(Permission::EditPosts))->toBeFalse();
});

it('writes through the entity manager and keeps unchanged rows when syncing', function (): void {
    $user = $this->createUser('Taylor');
    $user->grant(Role::Editor)->grant(Role::Viewer);

    $repository = $this->em()->getRepository(RoleAssignment::class);
    $editorId = $repository->findOneBy(['role' => 'editor'])?->getId();

    $user->syncRoles([Role::Editor, Role::Admin]);
    $user->revoke(Role::Admin);

    expect($repository->findOneBy(['role' => 'editor'])?->getId())->toBe($editorId)
        ->and($user->roles()->all())->toBe([Role::Editor]);
});

it('forgets the previous holder when an assignment moves to another user', function (): void {
    $user = $this->createUser('Taylor');
    $other = $this->createUser('Other');
    $user->grant(Role::Editor);

    expect($user->roles()->all())->toBe([Role::Editor]);

    $assignment = $this->em()->getRepository(RoleAssignment::class)->findOneBy(['userId' => $user->getId()]);
    $assignment?->setUserId((int) $other->getId());
    $this->em()->flush();

    expect($user->roles())->toBeEmpty()
        ->and($other->roles()->all())->toBe([Role::Editor]);
});

it('rejects a super admin that is not a case of the role enum', function (): void {
    config()->set('grant.super_admin', 'admin');
    $user = $this->createUser('Taylor');

    expect(fn () => Gate::forUser($user)->allows(Permission::EditPosts))
        ->toThrow(InvalidConfigurationException::class, 'grant.super_admin');
});
