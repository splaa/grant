<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
