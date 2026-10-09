<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Illuminate\Contracts\Config\Repository;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;

final readonly class ConfigureRoleAssignmentMapping implements EventSubscriber
{
    /** @var array<string, int> */
    private const array LENGTHS = ['uuid' => 36, 'ulid' => 26];

    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->getName() !== RoleAssignment::class) {
            return;
        }

        $keyType = $this->config->get('grant.key_type', 'int');

        if (! is_string($keyType)) {
            throw InvalidConfigurationException::keyType(get_debug_type($keyType));
        }

        if ($keyType === 'int') {
            return;
        }

        if (! isset(self::LENGTHS[$keyType])) {
            throw InvalidConfigurationException::keyType($keyType);
        }

        foreach (['userId', 'scopeableId'] as $field) {
            if (! isset($metadata->fieldMappings[$field])) {
                continue;
            }

            $metadata->fieldMappings[$field]['type'] = 'string';
            $metadata->fieldMappings[$field]['length'] = self::LENGTHS[$keyType];
        }
    }
}
