<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

/**
 * {@see Concerns\IsScope} trait for the default mapping-driven behaviour.
 */
interface Scope
{
    public function scopeType(): string;

    public function scopeId(): int|string;
}
