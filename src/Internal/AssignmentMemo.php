<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Internal;

use Closure;
use Illuminate\Support\Collection;
use Shipfastlabs\Grant\Entities\RoleAssignment;

final class AssignmentMemo
{
    /** @var array<string, Collection<int, RoleAssignment>> */
    private array $entries = [];

    /**
     * @param  Closure(): Collection<int, RoleAssignment>  $resolver
     * @return Collection<int, RoleAssignment>
     */
    public function remember(string $key, Closure $resolver): Collection
    {
        return $this->entries[$key] ??= $resolver();
    }

    public function forget(int|string $userId): void
    {
        unset($this->entries[(string) $userId]);
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
