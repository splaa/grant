<?php

declare(strict_types=1);

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Illuminate\Contracts\Config\Repository;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Listeners\ConfigureRoleAssignmentMapping;

it('maps identifiers as strings when the key type is uuid or ulid', function (string $keyType, int $length): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('grant.key_type', $keyType);

    $entityManager = $this->em();
    $metadata = $entityManager->getClassMetadata(RoleAssignment::class);

    new ConfigureRoleAssignmentMapping($config)->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $entityManager));

    expect($metadata->fieldMappings['userId']['type'])->toBe('string')
        ->and($metadata->fieldMappings['userId']['length'])->toBe($length)
        ->and($metadata->fieldMappings['scopeableId']['type'])->toBe('string')
        ->and($metadata->fieldMappings['scopeableId']['length'])->toBe($length);
})->with([
    'uuid' => ['uuid', 36],
    'ulid' => ['ulid', 26],
]);

it('rejects an unsupported key type while loading metadata', function (): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('grant.key_type', 'bogus');

    $entityManager = $this->em();
    $metadata = $entityManager->getClassMetadata(RoleAssignment::class);

    expect(fn () => new ConfigureRoleAssignmentMapping($config)->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $entityManager)))
        ->toThrow(InvalidConfigurationException::class, 'grant.key_type');
});
