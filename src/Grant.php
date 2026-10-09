<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use ReflectionEnum;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Entities\RoleAssignmentRepository;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Exceptions\UnsavedEntityException;
use Shipfastlabs\Grant\Internal\AssignmentMemo;
use Shipfastlabs\Grant\Internal\Identity;

final readonly class Grant
{
    public function __construct(
        private Container $container,
        private AssignmentMemo $memo,
        private Repository $config,
    ) {}

    public function grant(object $user, Role $role, ?Scope $on = null): object
    {
        $this->assertPersisted($user, $on);

        $userId = $this->userId($user);

        if (! $this->repository()->existsFor($userId, $role->value, $on)) {
            $this->entityManager()->persist(new RoleAssignment($userId, $role->value, $on?->scopeType(), $on?->scopeId()));
            $this->entityManager()->flush();
        }

        $this->flush($user);

        return $user;
    }

    public function revoke(object $user, Role $role, ?Scope $on = null): object
    {
        $this->assertPersisted($user, $on);

        foreach ($this->repository()->findForUserAndScope($this->userId($user), $on) as $assignment) {
            if ($assignment->getRole() === $role->value) {
                $this->entityManager()->remove($assignment);
            }
        }

        $this->entityManager()->flush();
        $this->flush($user);

        return $user;
    }

    /** @param iterable<Role> $roles */
    public function syncRoles(object $user, iterable $roles, ?Scope $on = null): object
    {
        $this->assertPersisted($user, $on);

        $userId = $this->userId($user);
        $known = [];

        foreach ($roles as $role) {
            $known[$role->value] = $role->value;
        }

        $this->entityManager()->wrapInTransaction(function () use ($userId, $known, $on): void {
            foreach ($this->repository()->findForUserAndScope($userId, $on) as $assignment) {
                if (! in_array($assignment->getRole(), $known, true)) {
                    $this->entityManager()->remove($assignment);
                }
            }

            foreach ($known as $value) {
                if (! $this->repository()->existsFor($userId, $value, $on)) {
                    $this->entityManager()->persist(new RoleAssignment($userId, $value, $on?->scopeType(), $on?->scopeId()));
                }
            }
        });

        $this->flush($user);

        return $user;
    }

    public function hasRole(object $user, Role $role, ?Scope $on = null): bool
    {
        return $this->roles($user, $on)->containsStrict($role);
    }

    /** @return Collection<int, Role> */
    public function roles(object $user, ?Scope $on = null): Collection
    {
        $roleClass = $this->roleClass();
        $type = $on?->scopeType();
        $id = $on?->scopeId();
        $id = $id === null ? null : (string) $id;

        return $this->assignments($user)
            ->filter(static function (RoleAssignment $assignment) use ($type, $id): bool {
                $scopeId = $assignment->getScopeableId();
                $scopeId = $scopeId === null ? null : (string) $scopeId;

                return $assignment->getScopeableType() === $type && $scopeId === $id;
            })
            ->map(static fn (RoleAssignment $assignment): ?Role => $roleClass::tryFrom($assignment->getRole()))
            ->filter()
            ->values();
    }

    /** @return Collection<int, Ability> */
    public function permissions(object $user, ?Scope $on = null): Collection
    {
        $roles = $this->roles($user);

        if ($on instanceof Scope) {
            $roles = $roles->merge($this->roles($user, $on));
        }

        return $roles
            ->flatMap(static fn (Role $role): array => $role->permissions())
            ->unique(static fn (Ability $permission): string => $permission->value)
            ->values();
    }

    /** @return list<RoleAssignment> */
    public function assignmentsFor(object $user): array
    {
        return array_values($this->assignments($user)->all());
    }

    public function isPersisted(object $entity): bool
    {
        return Identity::isPersisted($this->entityManager(), $entity);
    }

    /** @return class-string<Role> */
    public function roleClass(): string
    {
        return $this->enumClass('roles', Role::class);
    }

    /** @return class-string<Ability> */
    public function permissionClass(): string
    {
        return $this->enumClass('permissions', Ability::class);
    }

    public function superAdmin(): ?Role
    {
        /** @var Role|null $role */
        $role = $this->config->get('grant.super_admin');

        if ($role === null) {
            return null;
        }

        $roleClass = $this->roleClass();

        if (! $role instanceof $roleClass) {
            throw InvalidConfigurationException::superAdmin($roleClass);
        }

        return $role;
    }

    public function flush(object|int|string|null $user = null): void
    {
        if ($user === null) {
            $this->memo->flush();

            return;
        }

        $this->memo->forget(is_object($user) ? $this->userId($user) : $user);
    }

    private function userId(object $user): int|string
    {
        $id = Identity::id($this->entityManager(), $user);

        if ($id === null) {
            throw UnsavedEntityException::user();
        }

        return $id;
    }

    private function memoKey(object $user): string
    {
        return (string) $this->userId($user);
    }

    /** @return Collection<int, RoleAssignment> */
    private function assignments(object $user): Collection
    {
        $load = fn (): Collection => collect($this->repository()->findForUser($this->userId($user)));

        return match ($this->entityManager()->getConnection()->isTransactionActive()) {
            true => $load(),
            false => $this->memo->remember($this->memoKey($user), $load),
        };
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->container->make(GrantServiceProvider::ENTITY_MANAGER);

        return $entityManager;
    }

    private function repository(): RoleAssignmentRepository
    {
        /** @var RoleAssignmentRepository $repository */
        $repository = $this->entityManager()->getRepository(RoleAssignment::class);

        return $repository;
    }

    private function assertPersisted(object $user, ?Scope $on): void
    {
        if (! Identity::isPersisted($this->entityManager(), $user)) {
            throw UnsavedEntityException::user();
        }

        if ($on instanceof Scope && ! Identity::isPersisted($this->entityManager(), $on)) {
            throw UnsavedEntityException::scope();
        }
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $interface
     * @return class-string<TEnum>
     */
    private function enumClass(string $key, string $interface): string
    {
        $enum = $this->config->get("grant.{$key}");

        if (! is_string($enum) || ! is_subclass_of($enum, $interface)) {
            throw InvalidConfigurationException::enum($key, $interface);
        }

        if ((string) new ReflectionEnum($enum)->getBackingType() !== 'string') {
            throw InvalidConfigurationException::backingType($key);
        }

        return $enum;
    }
}
