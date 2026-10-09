<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Exceptions;

final class UnsavedEntityException extends GrantException
{
    public static function user(): self
    {
        return new self('Roles can only be assigned to persisted entities.');
    }

    public static function scope(): self
    {
        return new self('Scoped roles require a persisted Doctrine entity as the scope.');
    }

    public static function unmanaged(): self
    {
        return new self('Roles can only be used on entities that are persisted or loaded through Doctrine.');
    }
}
