<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

it('authenticates through the doctrine user provider', function (): void {
    config()->set('auth.providers.users.driver', 'doctrine');
    config()->set('auth.providers.users.model', User::class);

    $user = $this->createUser('Taylor');
    $user->setPassword(Hash::make('secret'));
    $this->em()->flush();

    $provider = Auth::createUserProvider('users');

    expect($provider)->not->toBeNull();

    $token = 'remember-me-token';
    $provider?->updateRememberToken($user, $token);

    expect($provider?->retrieveById($user->getId())?->getAuthIdentifier())->toBe($user->getId())
        ->and($provider?->retrieveByToken($user->getId(), $token)?->getId())->toBe($user->getId())
        ->and($provider?->retrieveByToken($user->getId(), 'wrong')?->getId())->toBeNull()
        ->and($provider?->retrieveByCredentials(['name' => 'Taylor'])?->getId())->toBe($user->getId())
        ->and($provider?->validateCredentials($user, ['password' => 'secret']))->toBeTrue()
        ->and($provider?->validateCredentials($user, ['password' => 'wrong']))->toBeFalse();
});

it('logs a user in through the session guard and rehashes their password', function (): void {
    config()->set('auth.providers.users.driver', 'doctrine');
    config()->set('auth.providers.users.model', User::class);

    $user = $this->createUser('Taylor');
    $weakHash = Hash::make('secret', ['rounds' => 4]);
    $user->setPassword($weakHash);
    $this->em()->flush();

    expect(Hash::needsRehash($weakHash))->toBeTrue()
        ->and(Auth::attempt(['name' => 'Taylor', 'password' => 'wrong']))->toBeFalse()
        ->and(Auth::attempt(['name' => 'Taylor', 'password' => 'secret']))->toBeTrue()
        ->and(Auth::user())->toBe($user)
        ->and($user->getAuthPassword())->not->toBe($weakHash)
        ->and(Hash::needsRehash($user->getAuthPassword()))->toBeFalse()
        ->and(Auth::user()?->can(Permission::EditPosts))->toBeFalse();

    $user->grant(Role::Editor);

    expect(Auth::user()?->can(Permission::EditPosts))->toBeTrue();
});

it('does not look a user up by password alone or by an unknown id', function (): void {
    config()->set('auth.providers.users.driver', 'doctrine');
    config()->set('auth.providers.users.model', User::class);

    $this->createUser('Taylor');
    $provider = Auth::createUserProvider('users');

    expect($provider?->retrieveByCredentials(['password' => 'secret']))->toBeNull()
        ->and($provider?->retrieveById(999))->toBeNull()
        ->and($provider?->retrieveByToken(999, 'token'))->toBeNull();
});

it('rejects a doctrine auth provider without an entity class', function (): void {
    config()->set('auth.providers.users.driver', 'doctrine');
    config()->set('auth.providers.users.model', 'App\\Entities\\Missing');

    expect(fn () => Auth::createUserProvider('users'))
        ->toThrow(InvalidConfigurationException::class, '[doctrine] auth provider');
});
