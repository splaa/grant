<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Illuminate\Support\Collection;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\UnsavedEntityException;

trait HasRoles
{
    private ?Grant $grantService = null;

    /** @internal */
    public function injectGrantService(Grant $grant): void
    {
        $this->grantService = $grant;
    }

    /** @return list<RoleAssignment> */
    public function roleAssignments(): array
    {
        return $this->grantService()->assignmentsFor($this);
    }

    public function grant(Role $role, ?Scope $on = null): static
    {
        $this->grantService()->grant($this, $role, $on);

        return $this;
    }

    public function revoke(Role $role, ?Scope $on = null): static
    {
        $this->grantService()->revoke($this, $role, $on);

        return $this;
    }

    /** @param iterable<Role> $roles */
    public function syncRoles(iterable $roles, ?Scope $on = null): static
    {
        $this->grantService()->syncRoles($this, $roles, $on);

        return $this;
    }

    public function hasRole(Role $role, ?Scope $on = null): bool
    {
        return $this->grantService()->hasRole($this, $role, $on);
    }

    /** @return Collection<int, Role> */
    public function roles(?Scope $on = null): Collection
    {
        return $this->grantService()->roles($this, $on);
    }

    /** @return Collection<int, Ability> */
    public function permissions(?Scope $on = null): Collection
    {
        return $this->grantService()->permissions($this, $on);
    }

    private function grantService(): Grant
    {
        if ($this->grantService === null) {
            throw UnsavedEntityException::unmanaged();
        }

        return $this->grantService;
    }
}
