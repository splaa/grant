<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

final readonly class DoctrineUserProvider implements UserProvider
{
    /** @param class-string $model */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $model,
        private Hasher $hasher,
    ) {}

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        $user = $this->entityManager->find($this->model, $identifier);

        return $user instanceof Authenticatable ? $user : null;
    }

    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] mixed $token): ?Authenticatable
    {
        $user = $this->retrieveById($identifier);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $rememberToken = $user->getRememberToken();
        $token = $this->normalizeToken($token);

        return $rememberToken !== null && $token !== null && hash_equals($rememberToken, $token) ? $user : null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] mixed $token): void
    {
        $user->setRememberToken($token);

        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $criteria = [];

        foreach ($credentials as $key => $value) {
            if (str_contains((string) $key, 'password')) {
                continue;
            }

            $criteria[$key] = $value;
        }

        if ($criteria === []) {
            return null;
        }

        $user = $this->entityManager->getRepository($this->model)->findOneBy($criteria);

        return $user instanceof Authenticatable ? $user : null;
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        return is_string($password) && $this->hasher->check($password, $user->getAuthPassword());
    }

    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $this->hasher->needsRehash($user->getAuthPassword()) && ! $force) {
            return;
        }

        $password = $credentials['password'] ?? null;

        if (! is_string($password) || ! method_exists($user, 'setPassword')) {
            return;
        }

        $user->setPassword($this->hasher->make($password));

        $this->entityManager->flush();
    }

    private function normalizeToken(mixed $token): ?string
    {
        return is_string($token) ? $token : null;
    }
}
