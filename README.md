<p align="center">
    <img src="./art/og.png" alt="Grant: roles and permissions as PHP enums for Laravel">
</p>

<p align="center">
    <a href="https://github.com/shipfastlabs/grant/actions"><img alt="Tests" src="https://github.com/shipfastlabs/grant/actions/workflows/tests.yml/badge.svg"></a>
    <a href="https://packagist.org/packages/shipfastlabs/grant"><img alt="Latest Version" src="https://img.shields.io/packagist/v/shipfastlabs/grant"></a>
    <a href="https://packagist.org/packages/shipfastlabs/grant"><img alt="License" src="https://img.shields.io/packagist/l/shipfastlabs/grant"></a>
</p>

## Introduction

Grant gives your Doctrine-backed Laravel app roles and permissions with almost nothing to learn. Permissions are a PHP enum. Roles are a PHP enum. Only the assignments live in the database, stored as Doctrine ORM entities, and every check goes through Laravel's native Gate:

```php
$user->grant(Role::Editor);

$user->can(Permission::EditPosts);
```

Grant has no check API of its own, so if you ever remove it, every `can` call in your app keeps working.

## Requirements

- PHP 8.4+
- Laravel 13
- Doctrine ORM 3 (`doctrine/orm` and `doctrine/dbal`, installed for you)
- The [dbmate](https://github.com/amacneil/dbmate) CLI to run the shipped SQL migration

## Installation

```shell
composer require shipfastlabs/grant

php artisan grant:install
php artisan migrate
dbmate up
```

Run `php artisan migrate` before `dbmate up`: the `role_assignments` table references `users`, which comes from Laravel's own migrations. dbmate reads the database from `DATABASE_URL`, either from the environment or from your `.env`:

```shell
# .env
DATABASE_URL="sqlite:database/database.sqlite"
# DATABASE_URL="mysql://user:secret@127.0.0.1:3306/app"
# DATABASE_URL="postgres://user:secret@127.0.0.1:5432/app?sslmode=disable"
```

dbmate also writes `db/schema.sql` after migrating; commit it or pass `--no-dump-schema`.

`grant:install` publishes the config and writes a dbmate migration into `db/migrations`, and creates starter `app/Enums/Permission.php` and `app/Enums/Role.php` enums if you don't have them yet. When your application already registers a Doctrine `EntityManager`, the command offers to share it instead and skips the dbmate migration; see [Sharing an Existing EntityManager](#sharing-an-existing-entitymanager). The SQL dialect follows the driver of `grant.connection` (or your default connection): SQLite, MySQL/MariaDB, or PostgreSQL. Pass `--key-type=uuid` or `--key-type=ulid` when your user entity uses string keys (it defaults to `grant.key_type`), and `--migrate` to run `dbmate up` for you when the binary is available. Running the command again keeps the existing migration; add `--force` to rewrite it and the published config.

The migration adds a foreign key from `role_assignments.user_id` to `users.id` with `ON DELETE CASCADE`. Edit the generated SQL if your users live in another table.

Grant builds its own Doctrine `EntityManager` on top of your Laravel database connection, so there is no separate Doctrine integration package to configure. Point `grant.entities` at your entity directory (it defaults to `app/Entities`).

Add the `HasRoles` and `Authenticatable` traits, plus Laravel's `Authorizable` for `$user->can()`, to your user entity:

```php
use Doctrine\ORM\Mapping as ORM;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Shipfastlabs\Grant\Auth\Authenticatable;
use Shipfastlabs\Grant\HasRoles;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
    use HasRoles;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    // ...
}
```

The `Authenticatable` trait maps the `password` and `remember_token` columns and reads the identifier through `getId()`. Role methods work on entities that Doctrine has persisted or loaded; calling them on a fresh `new User()` throws an `UnsavedEntityException`.

Point Laravel's auth at it with the `doctrine` driver Grant registers:

```php
// config/auth.php
'providers' => [
    'users' => [
        'driver' => 'doctrine',
        'model' => App\Entities\User::class,
    ],
],
```

Grant registers its own entity path automatically, so you only need to map your application entities.

A fresh Laravel app still ships the Eloquent `App\Models\User`, `Database\Factories\UserFactory`, and a `DatabaseSeeder` that uses them. Once auth points at the entity, delete the model and factory (or keep them only for code you have not migrated yet), and create seed users through the entity manager instead:

```php
// database/seeders/DatabaseSeeder.php
public function run(EntityManagerInterface $entityManager): void
{
    $user = new User('Test User', 'test@example.com');
    $user->setPassword(Hash::make('password'));

    $entityManager->persist($user);
    $entityManager->flush();

    $user->grant(Role::Admin);
}
```

## Quick Start

### Define permissions

A permission is a string-backed enum that implements `Ability`. Each case's value is the ability name registered with the Gate.

```php
namespace App\Enums;

use Shipfastlabs\Grant\Ability;

enum Permission: string implements Ability
{
    case EditPosts = 'edit-posts';
    case DeletePosts = 'delete-posts';
    case ViewReports = 'view-reports';

    public function deniedMessage(): string
    {
        return 'You are not allowed to do that.';
    }
}
```

### Define roles

A role is a string-backed enum that implements `Role` and lists the permissions it holds.

```php
namespace App\Enums;

use Shipfastlabs\Grant\Role as RoleContract;

enum Role: string implements RoleContract
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [Permission::EditPosts, Permission::DeletePosts],
            self::Viewer => [Permission::ViewReports],
        };
    }
}
```

> [!NOTE]
> Roles are for assigning, permissions are for checking. Grant intentionally has no `role:` middleware or `@role` directive. If you need to gate on a role, give it a permission.

Since `permissions` returns an array, one role can build on another with a plain spread:

```php
self::Editor => [
    Permission::EditPosts,
    ...self::Viewer->permissions(),
],
```

### Assign roles

```php
$user->grant(Role::Editor);
$user->revoke(Role::Editor);
$user->syncRoles([Role::Editor, Role::Viewer]);

$user->hasRole(Role::Admin);
$user->roles();          // Collection<Role>
$user->permissions();    // Collection<Permission>
$user->roleAssignments(); // list<RoleAssignment> entities
```

### Check permissions

Use Laravel's authorization features exactly as you already do:

```php
$user->can(Permission::EditPosts);

Gate::authorize(Permission::DeletePosts);
```

```blade
@can(Permission::EditPosts)
    <x-edit-button />
@endcan
```

```php
Route::put('/posts/{post}', UpdatePostController::class)
    ->can(Permission::EditPosts);
```

That's it. The rest of this document covers the optional extras.

## Scoped Roles

Pass any entity that implements `Shipfastlabs\Grant\Scope` as `on` to scope a role to a team, project, or anything else. Grant ships an `IsScope` trait that uses the entity's class name as the scope type and its `getId()` as the scope id:

```php
use Doctrine\ORM\Mapping as ORM;
use Shipfastlabs\Grant\Concerns\IsScope;
use Shipfastlabs\Grant\Scope;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
class Team implements Scope
{
    use IsScope;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;
}
```

```php
$user->grant(Role::Admin, on: $team);
$user->revoke(Role::Admin, on: $team);
$user->syncRoles([Role::Admin], on: $team);

$user->roles(on: $team);
$user->permissions(on: $team);

$user->can(Permission::EditPosts, $team);
```

A scoped check also includes the user's global roles, so it never grants less than an unscoped check. Grant stores a scope as an opaque (type, id) pair — Doctrine has no polymorphic `morphTo` — so resolve the entity yourself with `EntityManager::find($type, $id)` if you need it back.

The `scopeable_id` column uses the same `key_type` as `user_id`. With `uuid` or `ulid` it is a string column and accepts integer scope ids too; with `int` every scope entity needs an integer identifier.

## Policies

Policies keep owning model-level rules like ownership. Grant supplies the capability, your policy adds the context:

```php
public function update(User $user, Post $post): bool
{
    return $user->can(Permission::EditPosts)
        && $post->getAuthorId() === $user->getId();
}
```

To skip the repeated check, add the `Requires` attribute. The Gate denies the action unless the user holds the permission, then runs your method:

```php
use Shipfastlabs\Grant\Requires;

#[Requires(Permission::EditPosts)]
public function update(User $user, Post $post): bool
{
    return $post->getAuthorId() === $user->getId();
}
```

Gate arguments are forwarded, so `#[Requires]` respects roles scoped to the policy's entity.

## Super Admins

Pick one role in `config/grant.php` to pass every Gate check:

```php
'super_admin' => App\Enums\Role::Admin,
```

Set it to `null` to turn the bypass off. Only a globally granted role bypasses the Gate; the same role granted `on: $team` behaves like any other role.

## Renaming and Removing Roles

Only a role's backing value is stored. Renaming a case, or any permission, needs no data changes. Changing a role's value or deleting a case leaves orphaned rows, which Grant ignores, so those users quietly lose the role.

Run `grant:sync` after changing the enum to remap or delete them:

```shell
php artisan grant:sync
```

```
 Stored role [admin] no longer exists. What should its 12 assignments become?
 › administrator
   editor
   viewer
   Delete them
```

With `--no-interaction` it only reports orphans and exits with a failure status, which makes it a handy deploy check.

## Caching

Grant loads a user's assignments once per request and answers every later `can`, `hasRole`, `roles`, and `permissions` call from memory. Authorizing fifty entities in a loop costs one query.

The memo is cleared per request (and per job in queue workers, and under Octane). Granting, revoking, and syncing clear it, and a Doctrine lifecycle subscriber clears it whenever a `RoleAssignment` entity is persisted, updated, or removed. Bulk writes that bypass Doctrine — DQL `UPDATE`/`DELETE` or raw SQL — do not, so flush manually after those:

```php
use Shipfastlabs\Grant\Facades\Grant;

Grant::flush($user); // one user
Grant::flush();      // everyone
```

## The Grant Facade

The facade mirrors the assignment methods for places where calling the entity is awkward. It never performs checks.

```php
Grant::grant($user, Role::Editor);
Grant::revoke($user, Role::Editor);
Grant::syncRoles($user, [Role::Viewer]);
Grant::hasRole($user, Role::Editor);
```

## Configuration

`config/grant.php` has these options:

```php
return [
    'roles' => App\Enums\Role::class,
    'permissions' => App\Enums\Permission::class,
    'super_admin' => null,
    'entities' => [app_path('Entities')],
    'connection' => null,
    'entity_manager' => env('GRANT_ENTITY_MANAGER'),
    'key_type' => 'int',
    'dbmate_path' => 'db/migrations',
];
```

`entities` lists the directories Doctrine scans for attribute-mapped entities; `connection` picks a Laravel database connection and defaults to your app default. Both only apply to Grant's own `EntityManager`; `entity_manager` switches to one your application already registers (see [Sharing an Existing EntityManager](#sharing-an-existing-entitymanager)). `key_type` matches your user entity's primary key — `int` (default), `uuid` (36 chars), or `ulid` (26 chars). `grant:install` writes the matching SQL and config together; if you edit it by hand, keep the `role_assignments` columns and this value in sync.

Grant supports a single user entity: the one set in `auth.providers.users.model`.

### Database Connection

Grant's `EntityManager` runs on the PDO of the Laravel connection named in `grant.connection`, so everything Laravel applies to that connection (a `url`/`DB_URL`, SQLite `foreign_key_constraints`, PDO options) applies to Grant too, and Doctrine transactions are Laravel transactions:

- `DB::transaction()` covers `grant`, `revoke`, and `syncRoles`; when it rolls back, the role assignments roll back with it and Grant forgets them.
- SQLite `:memory:` works, because Laravel and Grant share one database.
- A table `prefix` is not applied to Grant's tables.

Grant registers this manager under the `grant.entity_manager` container key. It is also bound as `Doctrine\ORM\EntityManagerInterface` unless another package already binds that interface. `grant`, `revoke`, and `syncRoles` call `flush()` on it, which also writes any other pending changes in the same manager.

## Sharing an Existing EntityManager

If your application already has a Doctrine `EntityManager`, from [laravel-doctrine/orm](https://github.com/laravel-doctrine/orm) or your own service provider, Grant can use it instead of building one. Set `grant.entity_manager` (or `GRANT_ENTITY_MANAGER`) to where that manager lives:

| Value | Resolved as |
|---|---|
| `null` (default) | Grant builds its own manager on `grant.connection`. |
| A container key or class, such as `em` or `Doctrine\ORM\EntityManagerInterface` | `app($value)` |
| A manager name, such as `default` | `app(Doctrine\Persistence\ManagerRegistry::class)->getManager($value)` |

`grant:install` looks for a `Doctrine\Persistence\ManagerRegistry` or an `EntityManagerInterface` binding that is not Grant's. It lists what it found, asks whether to share it (yes by default and with `--no-interaction`), asks which manager when the registry has several (the registry default without interaction), and writes the choice as the default of `env('GRANT_ENTITY_MANAGER', ...)` in `config/grant.php`. With nothing found, it keeps the dbmate setup above.

When Grant shares a manager:

- Grant adds the `RoleAssignment` mapping and its listeners to that manager's own metadata driver and `EventManager`. You do not add Grant to laravel-doctrine's `paths` or `events`. Managers that laravel-doctrine rebuilds after `resetManager()` get them too.
- `role_assignments` belongs to your Doctrine schema tooling, so `grant:install` writes no dbmate migration. Run `php artisan doctrine:schema:update --force` or generate a migration with `php artisan doctrine:migrations:diff`. The generated table matches the dbmate migration: the same indexes, the global-role unique index (a partial index on PostgreSQL, a generated column on MySQL/MariaDB), and a cascading foreign key to your user entity's table, with `user_id` typed like that entity's identifier. SQLite cannot express the partial index through Doctrine, so there Grant relies on its own duplicate check, and `schema:update` against a database migrated with dbmate offers to drop the index.
- One identity map and one connection: Doctrine transactions on the shared manager cover `grant`, `revoke`, and `syncRoles`. Inside a transaction Grant reads assignments from the database instead of its memo, so a rollback cannot leave stale roles behind.
- With laravel-doctrine's `ManagerRegistry`, Grant does not register its `doctrine` auth driver; laravel-doctrine's `doctrine` driver works with the `Authenticatable` trait. With a plain container binding, Grant's driver stays and uses the shared manager.
- `grant.connection` and `grant.entities` are ignored; the shared manager's own connection and mapping apply.

### Running next to laravel-doctrine/orm with Grant's own manager

With `entity_manager` left `null`, both packages install side by side without Composer conflicts, and Grant keeps its own `EntityManager`:

- `EntityManagerInterface` stays laravel-doctrine's manager in either provider order; Grant uses `grant.entity_manager` internally.
- Role methods work on users loaded through either manager. Grant attaches itself to laravel-doctrine's manager when it is resolved, but the two managers have separate identity maps, so the same row can be two different objects.
- laravel-doctrine opens its own database connection. Its transactions do not cover Grant's writes, and the reverse is true too. Flush a new user and commit laravel-doctrine's transaction before granting roles; on SQLite a role granted inside that transaction waits for the lock, on PostgreSQL it fails the foreign key check. Wrap the work in `DB::transaction()` instead when you need one atomic unit with Grant.
- Write role assignments through Grant (`grant`, `revoke`, `syncRoles`, or the facade). Rows written by laravel-doctrine's manager do not clear Grant's memo, so call `Grant::flush()` after them.
- Both packages register a `doctrine` auth driver, and the provider registered last wins. Both work with the `Authenticatable` trait.
- `RoleAssignment` is not in laravel-doctrine's entity paths, so `doctrine:schema:update` and `doctrine:migrations:diff` see `role_assignments` as an unknown table. Exclude it with a schema assets filter (for example `~^(?!role_assignments$)~`) and keep managing it with dbmate.

## Artisan Commands

```shell
# List every permission registered with the Gate...
php artisan grant:list

# Show a user's roles and resolved permissions...
php artisan grant:show 1
php artisan grant:show 1 --on='App\Entities\Team:5'

# Remap or delete stored roles that no longer match the enum...
php artisan grant:sync
```

To add a permission or role, add a case to the enum. For a new role, map its permissions in `Role::permissions`.

## Filament

Grant ships no admin panel plugin. Filament already handles backed enums, so a `Select` on your user resource is enough:

```php
Select::make('roles')
    ->multiple()
    ->options(Role::class)
    ->dehydrated(false)
    ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state(
        $record?->roles()->map->value->all() ?? [],
    ))
    ->saveRelationshipsUsing(fn (User $record, array $state) => $record->syncRoles(
        array_map(Role::from(...), $state),
    )),
```

Implement Filament's `HasLabel` on your role enum to control the labels.

## Testing

Seed roles on a Doctrine entity in your test setup:

```php
$user = new User('Taylor');
$entityManager->persist($user);
$entityManager->flush();

$user->grant(Role::Editor);
```

Grant writes through the Laravel connection, so `RefreshDatabase` and `DatabaseTransactions` roll its rows back like any other. `migrate:fresh` (which `RefreshDatabase` runs) only knows Laravel's migrations, though, and drops `role_assignments`. Recreate it from the dbmate migration after each refresh:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        if (Schema::hasTable('role_assignments')) {
            return;
        }

        $migration = glob(base_path('db/migrations/*_create_role_assignments.sql'))[0];

        DB::unprepared(Str::between(file_get_contents($migration), '-- migrate:up', '-- migrate:down'));
    }
}
```

For fluent assertions, use the `InteractsWithGrant` trait in your base test case:

```php
use Shipfastlabs\Grant\Testing\InteractsWithGrant;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithGrant;
}
```

```php
$this->actingAs($user)
    ->assertCannot(Permission::EditPosts)
    ->withRole(Role::Editor)
    ->assertCan(Permission::EditPosts);
```

## Contributing

```shell
composer test
```

Please read the [contribution guide](CONTRIBUTING.md) first. For security issues, see the [security policy](SECURITY.md).

## Credits

Grant is maintained by [Shipfastlabs](https://shipfastlabs.com) and released under the [MIT license](LICENSE.md).
