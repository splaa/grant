<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Internal;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;

final class Identity
{
    public static function isMapped(EntityManagerInterface $entityManager, object $entity): bool
    {
        try {
            $entityManager->getClassMetadata($entity::class);

            return true;
        } catch (MappingException) {
            return false;
        }
    }

    public static function id(EntityManagerInterface $entityManager, object $entity): int|string|null
    {
        if (! self::isMapped($entityManager, $entity)) {
            return null;
        }

        $metadata = $entityManager->getClassMetadata($entity::class);

        if ($metadata->isIdentifierComposite) {
            throw InvalidConfigurationException::identifier($entity::class);
        }

        $values = $metadata->getIdentifierValues($entity);
        $value = array_values($values)[0] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    public static function isPersisted(EntityManagerInterface $entityManager, object $entity): bool
    {
        if (! self::isMapped($entityManager, $entity)) {
            return false;
        }

        $metadata = $entityManager->getClassMetadata($entity::class);
        $values = $metadata->getIdentifierValues($entity);

        return count($values) === count($metadata->getIdentifier())
            && ! in_array(null, $values, true);
    }
}
