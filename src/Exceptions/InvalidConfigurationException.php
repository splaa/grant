<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Exceptions;

final class InvalidConfigurationException extends GrantException
{
    public static function enum(string $key, string $interface): self
    {
        return new self(sprintf('The [grant.%s] config value must be a string-backed enum implementing [%s].', $key, $interface));
    }

    public static function backingType(string $key): self
    {
        return new self(sprintf('The [grant.%s] enum must be string-backed.', $key));
    }

    public static function superAdmin(string $roleClass): self
    {
        return new self(sprintf('The [grant.super_admin] config value must be null or a case of [%s].', $roleClass));
    }

    public static function identifier(string $class): self
    {
        return new self(sprintf('The entity [%s] must use a single-column identifier.', $class));
    }

    public static function keyType(string $value): self
    {
        return new self(sprintf('The [grant.key_type] config value must be one of [int, uuid, ulid], [%s] given.', $value));
    }

    public static function driver(string $driver): self
    {
        return new self(sprintf('The dbmate migration cannot be generated for the [%s] database driver.', $driver));
    }

    public static function authModel(): self
    {
        return new self('The [auth.providers.users.model] config value must be a Doctrine entity class.');
    }
}
