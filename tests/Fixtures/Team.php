<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Shipfastlabs\Grant\Concerns\IsScope;
use Shipfastlabs\Grant\Scope;

#[ORM\Entity]
#[ORM\Table(name: 'teams')]
class Team implements Scope
{
    use IsScope;

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
