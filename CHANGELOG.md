# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Changed

- **Breaking:** rewritten on Doctrine ORM (`doctrine/orm` + `doctrine/dbal`) with a self-contained EntityManager integration. Role assignments are now Doctrine entities and the auth user must be a Doctrine entity using `HasRoles`.
- **Breaking:** the `role_assignments` schema ships as a dbmate SQL migration instead of a Laravel migration; run `dbmate up` after `grant:install`.
- **Breaking:** scoped roles accept a `Shipfastlabs\Grant\Scope` contract (with the `Concerns\IsScope` trait) instead of any Eloquent model.
- **Breaking:** removed the `grant.model` config option; `UnsavedModelException` is renamed to `UnsavedEntityException`.
- Requires PHP 8.4+.

### Added

- `grant.key_type` (`int`, `uuid`, `ulid`) and `grant.dbmate_path` configuration.
- Doctrine lifecycle subscribers that invalidate the per-user assignment memo on writes.
- Initial enum-first roles and permissions implementation.

### Fixed

- Grant's EntityManager runs on the PDO of the Laravel connection and delegates transactions to it, so `DB::transaction()`, `RefreshDatabase`, SQLite `:memory:`, `DB_URL`, and SQLite foreign keys behave as they do for Laravel. Rolled back role assignments are detached and the memo is cleared.
- Grant no longer overrides another package's `EntityManagerInterface` binding (for example laravel-doctrine/orm) in either provider order; its manager lives under the `grant.entity_manager` container key and is no longer built while the application boots.
- Doctrine subscribers resolve the memo and `Grant` lazily, and entities loaded by another entity manager still receive the Grant service.
- The `role_assignments` schema rejects duplicate global roles (partial unique index, or a generated column on MySQL), and the `RoleAssignment` mapping matches the dbmate schema (`scopeable_id` type, `user_id` index).
- `grant:install` renders the migration for the `grant.connection` driver, keeps an existing `create_role_assignments` migration instead of writing a duplicate (`--force` rewrites it), and defaults `--key-type` to `grant.key_type`.
