<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Testing;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Assert;
use Shipfastlabs\Grant\Ability;
use Shipfastlabs\Grant\Role;
use Shipfastlabs\Grant\Scope;

trait InteractsWithGrant
{
    public function assertCan(Ability $ability, mixed ...$arguments): static
    {
        Assert::assertTrue(Gate::forUser(Auth::user())->allows($ability, $arguments));

        return $this;
    }

    public function assertCannot(Ability $ability, mixed ...$arguments): static
    {
        Assert::assertFalse(Gate::forUser(Auth::user())->allows($ability, $arguments));

        return $this;
    }

    public function withRole(Role $role, ?Scope $on = null): static
    {
        $user = Auth::user();

        if (! is_object($user)) {
            Assert::fail('The authenticated user must be an object entity.');
        }

        $grant = [$user, 'grant'];

        if (! is_callable($grant)) {
            Assert::fail('The authenticated user must use the HasRoles trait.');
        }

        $grant($role, $on);

        return $this;
    }
}
