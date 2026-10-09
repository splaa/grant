<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Entities;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoleAssignmentRepository::class)]
#[ORM\Table(name: 'role_assignments')]
#[ORM\UniqueConstraint(
    name: 'role_assignments_user_role_scope_unique',
    columns: ['user_id', 'role', 'scopeable_type', 'scopeable_id'],
)]
class RoleAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'integer')]
        private int|string $userId,
        #[ORM\Column(type: 'string')]
        private string $role,
        #[ORM\Column(name: 'scopeable_type', type: 'string', nullable: true)]
        private ?string $scopeableType = null,
        #[ORM\Column(name: 'scopeable_id', type: 'string', nullable: true)]
        private int|string|null $scopeableId = null
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function setUserId(int|string $userId): void
    {
        $this->userId = $userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getScopeableType(): ?string
    {
        return $this->scopeableType;
    }

    public function getScopeableId(): int|string|null
    {
        return $this->scopeableId;
    }
}
