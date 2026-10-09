<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Illuminate\Contracts\Config\Repository;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;

final readonly class ConfigureRoleAssignmentMapping implements EventSubscriber
{
    /** @var array<string, int> */
    private const array LENGTHS = ['uuid' => 36, 'ulid' => 26];

    private const string TABLE = 'role_assignments';

    private const string GLOBAL_UNIQUE = 'role_assignments_user_role_global_unique';

    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata, ToolEvents::postGenerateSchema];
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $entityManager = $args->getEntityManager();

        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);
        $platform = $entityManager->getConnection()->getDatabasePlatform();

        match (true) {
            $platform instanceof AbstractMySQLPlatform => $this->addMySqlGlobalUnique($table),
            $platform->supportsPartialIndexes() => $table->addUniqueIndex(['user_id', 'role'], self::GLOBAL_UNIQUE, ['where' => '(scopeable_type IS NULL)']),
            default => null,
        };

        $user = $this->config->get('auth.providers.users.model');

        if (! is_string($user) || ! class_exists($user) || $entityManager->getMetadataFactory()->isTransient($user)) {
            return;
        }

        $metadata = $entityManager->getClassMetadata($user);

        if (! $schema->hasTable($metadata->getTableName()) || $metadata->isIdentifierComposite) {
            return;
        }

        $identifier = $schema->getTable($metadata->getTableName())->getColumn($metadata->getSingleIdentifierColumnName());

        foreach (['user_id', 'scopeable_id'] as $column) {
            $table->getColumn($column)
                ->setType($identifier->getType())
                ->setLength($identifier->getLength())
                ->setFixed($identifier->getFixed())
                ->setUnsigned($identifier->getUnsigned());
        }

        $table->addForeignKeyConstraint(
            $metadata->getTableName(),
            ['user_id'],
            [$metadata->getSingleIdentifierColumnName()],
            ['onDelete' => 'CASCADE'],
            'role_assignments_user_id_foreign',
        );
    }

    private function addMySqlGlobalUnique(Table $table): void
    {
        $table->addColumn('global_scope', Types::BOOLEAN, [
            'notnull' => false,
            'columnDefinition' => 'TINYINT(1) AS (IF(scopeable_type IS NULL, 1, NULL)) STORED',
        ]);

        $table->addUniqueIndex(['user_id', 'role', 'global_scope'], self::GLOBAL_UNIQUE);
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
