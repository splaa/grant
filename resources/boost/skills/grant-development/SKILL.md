---
name: grant-development
description: >
  Install and use Grant's enum-first roles and native Laravel Gate integration on Doctrine ORM.
license: MIT
metadata:
  author: Pushpak Chhajed
---

# Grant

Use this skill when a Doctrine-backed Laravel application needs enum-defined roles and permissions with database-backed assignments.

## Primary Goal

Apply `shipfastlabs/grant` while keeping every authorization check in Laravel's native Gate API. Assignments are Doctrine ORM entities; the auth user is a Doctrine entity too.

## Workflow

1. Install with `composer require shipfastlabs/grant`, then `php artisan grant:install` and `dbmate up`. Pass `--key-type=uuid|ulid` for string-keyed users.
2. Define string-backed `App\Enums\Permission` cases implementing `Shipfastlabs\Grant\Ability` and denial messages.
3. Adjust the generated string-backed `App\Enums\Role` implementing `Shipfastlabs\Grant\Role`. It ships with `Admin` mapped to all permissions and `Member` mapped to none.
4. Make the auth user a Doctrine entity listed in `grant.entities`, add `Shipfastlabs\Grant\HasRoles` and `Shipfastlabs\Grant\Auth\Authenticatable`, and set `auth.providers.users.driver` to `doctrine` with `model` pointing at the entity.
5. Assign with `grant`, `revoke`, or `syncRoles`; the user must be persisted, and `on:` accepts a persisted entity implementing `Shipfastlabs\Grant\Scope` (use the `Shipfastlabs\Grant\Concerns\IsScope` trait — class name as type, `getId()` as id — for the default implementation).
6. Check only with `can()`, `Gate`, `@can`, `can:` middleware, Form Requests, or Policies.
7. Use `#[Requires(Permission::...)]` on a policy method when capability and model-context checks should compose; a denial carries that permission's `deniedMessage()`.

## References

- `config/grant.php` — enum classes, super-admin role, `entities`, `connection`, `key_type`, and the `dbmate_path` for the shipped migration
- `app/Enums/Permission.php` and `app/Enums/Role.php` — application definitions
- `php artisan grant:list` — registered permissions
- `php artisan grant:show {user} --on='EntityClass:id'` — resolved assignments
- `php artisan grant:sync` — after changing a role's value or removing a case, remap or delete the stored assignments that no longer match; `--no-interaction` only reports them and fails
- `Shipfastlabs\Grant\Entities\RoleAssignment` — Doctrine entity for `role_assignments`; `$user->roleAssignments()` returns the rows as entities (there is no Eloquent relation)
- `database/dbmate/{sqlite,mysql,pgsql}` — the SQL migration rendered by `grant:install`
- `Shipfastlabs\Grant\Facades\Grant` — `grant`, `revoke`, `syncRoles`, `hasRole`, `roles`, `permissions` when an entity method is not convenient

## Examples

```php
$user->grant(Role::Editor);
$user->grant(Role::Admin, on: $team);

$user->can(Permission::EditPosts);
$user->can(Permission::EditPosts, $team); // scoped roles fall back to global roles
```

For tests, add `Shipfastlabs\Grant\Testing\InteractsWithGrant` to the base test case, then use `assertCan`, `assertCannot`, and `withRole`. Persist the user entity with the entity manager before assigning roles.

## Anti-patterns

- Do not add a package-specific check wrapper; use Laravel Gate.
- Do not check roles for authorization; check permissions.
- Do not store role or permission definitions in the database.
- Do not put ownership or model-state rules in role definitions; keep them in Policies.
- Do not build Eloquent relationships against `RoleAssignment`; it is a Doctrine entity, so resolve users and scopes with `EntityManager::find($class, $id)`.
- Assignments are memoized per user for the request (scoped binding). Doctrine lifecycle subscribers clear the memo on writes; after DQL or raw bulk writes call `Grant::flush($user)`.
- Keep `grant.key_type`, the user primary key type, and the dbmate migration in sync.
- The super-admin bypass only applies to the role granted globally, never scoped.
- No admin plugin: manage roles in Filament with a multiple `Select` bound to `roles()` and `syncRoles()`; see the README section.
