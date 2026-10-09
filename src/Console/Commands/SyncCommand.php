<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Console\Command;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Entities\RoleAssignmentRepository;
use Shipfastlabs\Grant\Grant;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Role;

use function Laravel\Prompts\select;

final class SyncCommand extends Command
{
    protected $signature = 'grant:sync';

    protected $description = 'Remap or delete stored roles that no longer match a case of the role enum.';

    public function handle(Grant $grant): int
    {
        $entityManager = $this->entityManager();

        $roleClass = $grant->roleClass();
        $known = array_map(static fn (Role $role): string => $role->value, $roleClass::cases());

        /** @var RoleAssignmentRepository $repository */
        $repository = $entityManager->getRepository(RoleAssignment::class);
        $orphans = $repository->orphanRoles($known);

        if ($orphans === []) {
            $this->components->info("Every stored role matches a case of {$roleClass}.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Stored roles without a matching enum case: '.implode(', ', $orphans));

            return self::FAILURE;
        }

        foreach ($orphans as $orphan) {
            $count = $repository->countByRole($orphan);

            $choice = (string) select(
                "Stored role [{$orphan}] no longer exists. What should its {$count} assignments become?",
                [...array_combine($known, $known), '__delete' => 'Delete them'],
            );

            foreach ($repository->findBy(['role' => $orphan]) as $row) {
                if ($choice === '__delete' || $repository->otherHolderExists($row->getUserId(), $choice, $row->getScopeableType(), $row->getScopeableId())) {
                    $entityManager->remove($row);

                    continue;
                }

                $row->setRole($choice);
            }

            $entityManager->flush();

            $this->components->info($choice === '__delete'
                ? "Deleted {$count} assignments of [{$orphan}]."
                : "Mapped {$count} assignments of [{$orphan}] to [{$choice}].");
        }

        return self::SUCCESS;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->laravel->make(GrantServiceProvider::ENTITY_MANAGER);

        return $entityManager;
    }
}
