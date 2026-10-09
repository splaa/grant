<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Shipfastlabs\Grant\Ability;
use Shipfastlabs\Grant\Grant;
use Shipfastlabs\Grant\Role;
use Shipfastlabs\Grant\Scope;

use function Laravel\Prompts\text;

final class ShowCommand extends Command
{
    protected $signature = 'grant:show {user? : The user ID} {--on= : Scope as EntityClass:id}';

    protected $description = 'Show a user’s roles and resolved permissions.';

    public function __construct(private readonly Repository $config)
    {
        parent::__construct();
    }

    public function handle(Grant $grant, EntityManagerInterface $entityManager): int
    {
        $userClass = $this->config->get('auth.providers.users.model');

        if (! is_string($userClass) || ! class_exists($userClass)) {
            $this->components->error('The auth user entity could not be resolved.');

            return self::FAILURE;
        }

        $userId = $this->argument('user');
        $id = is_string($userId) && $userId !== '' ? $userId : text('Which user should be shown?', placeholder: 'E.g. 1', required: true);

        $user = $entityManager->find($userClass, is_numeric($id) ? (int) $id : $id);

        if (! is_object($user)) {
            $this->components->error("No [{$userClass}] with id [{$id}] was found.");

            return self::FAILURE;
        }

        $scope = $this->resolveScope($entityManager, $this->option('on'));

        $rows = [['Roles', $this->format($grant->roles($user))]];

        if ($scope instanceof Scope) {
            $rows[] = ['Scoped roles', $this->format($grant->roles($user, $scope))];
        }

        $rows[] = ['Permissions', $this->format($grant->permissions($user, $scope))];

        $this->table([], $rows);

        return self::SUCCESS;
    }

    /**
     * @template TValue of Role|Ability
     *
     * @param  Collection<int, TValue>  $values
     */
    private function format(Collection $values): string
    {
        return $values->map(static fn (Role|Ability $value): string => $value->value)->join(', ') ?: 'None';
    }

    private function resolveScope(EntityManagerInterface $entityManager, mixed $scope): ?Scope
    {
        if ($scope === null || $scope === '') {
            return null;
        }

        if (! is_string($scope)) {
            $this->fail('The --on option must use EntityClass:id.');
        }

        [$class, $id] = array_pad(explode(':', $scope, 2), 2, '');

        if ($id === '' || ! is_subclass_of($class, Scope::class)) {
            $this->fail('The --on option must use EntityClass:id.');
        }

        $resolved = $entityManager->find($class, is_numeric($id) ? (int) $id : $id);

        if (! $resolved instanceof Scope) {
            $this->fail("No scope [{$class}:{$id}] was found.");
        }

        return $resolved;
    }
}
