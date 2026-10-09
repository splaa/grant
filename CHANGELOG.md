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
