<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Entities;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Shipfastlabs\Grant\Scope;

/**
 * @extends EntityRepository<RoleAssignment>
 */
class RoleAssignmentRepository extends EntityRepository
{
    /** @return list<RoleAssignment> */
    public function findForUser(int|string $userId): array
    {
        /** @var list<RoleAssignment> $result */
        $result = $this->forUser($userId)->getQuery()->getResult();

        return $result;
    }

    /** @return list<RoleAssignment> */
    public function findForUserAndScope(int|string $userId, ?Scope $scope): array
    {
        /** @var list<RoleAssignment> $result */
        $result = $this->scoped($this->forUser($userId), $scope)->getQuery()->getResult();

        return $result;
    }

    public function existsFor(int|string $userId, string $role, ?Scope $scope): bool
    {
        $query = $this->scoped($this->forUser($userId), $scope)
            ->select('COUNT(a.id)')
            ->andWhere('a.role = :role')
            ->setParameter('role', $role);

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    public function orphanRoles(array $known): array
    {
        $values = $this->createQueryBuilder('a')
            ->select('DISTINCT a.role')
            ->where('a.role NOT IN (:known)')
            ->orderBy('a.role')
            ->setParameter('known', $known)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($values, is_string(...)));
    }

    public function countByRole(string $role): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.role = :role')
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function otherHolderExists(int|string $userId, string $role, ?string $scopeType, int|string|null $scopeId): bool
    {
        $query = $this->scopedRaw($this->createQueryBuilder('a'), $scopeType, $scopeId)
            ->select('COUNT(a.id)')
            ->andWhere('a.role = :role')
            ->andWhere('a.userId = :userId')
            ->setParameter('role', $role)
            ->setParameter('userId', $userId);

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    private function forUser(int|string $userId): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId);
    }

    private function scoped(QueryBuilder $builder, ?Scope $scope): QueryBuilder
    {
        return $this->scopedRaw($builder, $scope?->scopeType(), $scope?->scopeId());
    }

    private function scopedRaw(QueryBuilder $builder, ?string $scopeType, int|string|null $scopeId): QueryBuilder
    {
        if ($scopeType === null && $scopeId === null) {
            return $builder
                ->andWhere('a.scopeableType IS NULL')
                ->andWhere('a.scopeableId IS NULL');
        }

        return $builder
            ->andWhere('a.scopeableType = :scopeableType')
            ->andWhere('a.scopeableId = :scopeableId')
            ->setParameter('scopeableType', $scopeType)
            ->setParameter('scopeableId', $scopeId);
    }
}
