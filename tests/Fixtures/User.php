<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests\Fixtures;

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

    public function __construct(
        #[ORM\Column(type: 'string')]
        private string $name
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
