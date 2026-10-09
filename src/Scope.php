<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

interface Scope
{
    public function scopeType(): string;

    public function scopeId(): int|string;
}
