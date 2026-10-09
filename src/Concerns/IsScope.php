<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Concerns;

use Shipfastlabs\Grant\Exceptions\UnsavedEntityException;
use Shipfastlabs\Grant\Scope;

/**
 * Implements {@see Scope} for a Doctrine entity, so a scoped entity only needs
 */
trait IsScope
{
    public function scopeType(): string
    {
        return static::class;
    }

    public function scopeId(): int|string
    {
        $id = $this->getId();

        if ($id === null) {
            throw UnsavedEntityException::scope();
        }

        return $id;
    }
}
