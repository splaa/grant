# Grant

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Architecture

- The package targets PHP 8.4+, Laravel 13, and Doctrine ORM 3 (`doctrine/orm` + `doctrine/dbal`). It builds its own EntityManager (`Shipfastlabs\Grant\Doctrine\EntityManagerFactory`) on the PDO of the Laravel connection (transactions delegated to Laravel), bound as `grant.entity_manager`, with no framework integration package. Never override another package's `EntityManagerInterface` binding. `grant.entity_manager` may instead name an application manager (container key/class or `ManagerRegistry` manager name); `EntityManagerFactory::attach()` adds Grant's mapping and listeners to it, and laravel-doctrine/orm stays a `suggest`/`require-dev` dependency.
- Role assignments are Doctrine entities (`Shipfastlabs\Grant\Entities\RoleAssignment`); the auth user is a Doctrine entity carrying the `HasRoles` and `Shipfastlabs\Grant\Auth\Authenticatable` traits, exposed to Laravel through the package's `doctrine` auth driver. There is no Eloquent model and no `grant.model` config.
- The `role_assignments` schema ships as a dbmate SQL migration under `database/dbmate/{sqlite,mysql,pgsql}`, rendered by `grant:install`; do not add a Laravel migration.
- Roles and permissions are enums and authorization goes through Laravel's native Gate. Scopes implement the `Shipfastlabs\Grant\Scope` contract.
- Publish tags: `grant` and `grant-config`.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `shipfastlabs/grant`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer test:lint`
- Static analysis: `composer test:types`
- Type coverage: `composer test:type-coverage`
- Refactor check: `composer test:refactor`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
