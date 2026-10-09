<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LaravelDoctrine\ORM\DoctrineServiceProvider as LaravelDoctrineServiceProvider;
use ReflectionMethod;
use Shipfastlabs\Grant\Auth\DoctrineUserProvider;
use Shipfastlabs\Grant\Console\Commands\InstallCommand;
use Shipfastlabs\Grant\Console\Commands\ListCommand;
use Shipfastlabs\Grant\Console\Commands\ShowCommand;
use Shipfastlabs\Grant\Console\Commands\SyncCommand;
use Shipfastlabs\Grant\Doctrine\EntityManagerFactory;
use Shipfastlabs\Grant\Entities\RoleAssignment;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Internal\AssignmentMemo;
use Shipfastlabs\Grant\Listeners\InjectGrantService;

final class GrantServiceProvider extends ServiceProvider
{
    public const string ENTITY_MANAGER = 'grant.entity_manager';

    private const string OWN_ENTITY_MANAGER = 'grant.entity_manager.own';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/grant.php', 'grant');

        $this->app->scoped(AssignmentMemo::class);
        $this->app->scoped(Grant::class);

        $this->app->scoped(self::OWN_ENTITY_MANAGER, fn (): EntityManagerInterface => $this->factory()->make());

        $this->app->bind(self::ENTITY_MANAGER, fn (): EntityManagerInterface => match ($this->managerSource()) {
            null => $this->ownEntityManager(),
            default => $this->factory()->attach($this->sourceManager()),
        });

        $this->app->afterResolving(function (mixed $resolved): void {
            match (true) {
                ! $resolved instanceof EntityManagerInterface => null,
                $this->isSourceManager($resolved) => $this->factory()->attach($resolved),
                default => $this->injectGrantService($resolved),
            };
        });

        $this->app->booting(function (): void {
            match ($this->managerSource() === null && ! $this->app->bound(EntityManagerInterface::class)) {
                true => $this->app->alias(self::ENTITY_MANAGER, EntityManagerInterface::class),
                false => null,
            };
        });
    }

    public function boot(): void
    {
        $permissions = $this->config()->get('grant.permissions');

        if (is_string($permissions) && is_subclass_of($permissions, Ability::class)) {
            $this->registerGate($permissions);
        }

        match ($this->managerSource()) {
            null => $this->forgetRolledBackAssignments(),
            default => null,
        };

        match ($this->managerSource() !== null && $this->app->bound(ManagerRegistry::class) && class_exists(LaravelDoctrineServiceProvider::class)) {
            true => null,
            false => $this->registerAuthProvider(),
        };

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }
    }

    private function config(): Repository
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        return $config;
    }

    private function managerSource(): ?string
    {
        $source = $this->config()->get('grant.entity_manager');

        return is_string($source) && $source !== '' ? $source : null;
    }

    private function factory(): EntityManagerFactory
    {
        /** @var ConnectionResolverInterface $connections */
        $connections = $this->app->make('db');

        return new EntityManagerFactory($this->config(), $connections, $this->app);
    }

    private function sourceManager(): EntityManagerInterface
    {
        $source = (string) $this->managerSource();

        $manager = match (true) {
            $this->app->bound($source) => $this->app->make($source),
            $this->app->bound(ManagerRegistry::class) => $this->registry()->getManager($source),
            default => throw InvalidConfigurationException::entityManager($source),
        };

        return match (true) {
            $manager instanceof EntityManagerInterface => $manager,
            default => throw InvalidConfigurationException::entityManager($source),
        };
    }

    private function isSourceManager(EntityManagerInterface $entityManager): bool
    {
        $source = $this->managerSource();

        return match (true) {
            $source === null => false,
            $this->app->bound($source) => $this->app->isShared($this->app->getAlias($source)) && $this->app->make($source) === $entityManager,
            $this->app->bound(ManagerRegistry::class) => array_key_exists($source, $this->registry()->getManagerNames()) && $this->registry()->getManager($source) === $entityManager,
            default => false,
        };
    }

    private function registry(): ManagerRegistry
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->app->make(ManagerRegistry::class);

        return $registry;
    }

    private function injectGrantService(EntityManagerInterface $entityManager): void
    {
        $eventManager = $entityManager->getEventManager();
        $injected = array_filter($eventManager->getListeners(Events::postLoad), static fn (object $listener): bool => $listener instanceof InjectGrantService);

        if ($injected === []) {
            $eventManager->addEventSubscriber(new InjectGrantService($this->app));
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->app->make(self::ENTITY_MANAGER);

        return $entityManager;
    }

    private function ownEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->app->make(self::OWN_ENTITY_MANAGER);

        return $entityManager;
    }

    private function forgetRolledBackAssignments(): void
    {
        $this->app->make(Dispatcher::class)->listen(TransactionRolledBack::class, function (): void {
            if (! $this->app->resolved(self::OWN_ENTITY_MANAGER)) {
                return;
            }

            $entityManager = $this->ownEntityManager();

            foreach ($entityManager->getUnitOfWork()->getIdentityMap()[RoleAssignment::class] ?? [] as $assignment) {
                $entityManager->detach($assignment);
            }

            $this->app->make(AssignmentMemo::class)->flush();
        });
    }

    private function registerAuthProvider(): void
    {
        $this->app->make(AuthManager::class)->provider('doctrine', function (Application $app, array $config): DoctrineUserProvider {
            $model = $config['model'] ?? null;

            if (! is_string($model) || ! class_exists($model)) {
                throw InvalidConfigurationException::authModel();
            }

            return new DoctrineUserProvider($this->entityManager(), $model, $app->make(Hasher::class));
        });
    }

    /** @param  class-string<Ability>  $permissions */
    private function registerGate(string $permissions): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(Gate::class);

        $resolveGrant = fn (): Grant => $this->app->make(Grant::class);

        $this->defineAbilities($gate, $resolveGrant, $permissions);
        $this->allowSuperAdmin($gate, $resolveGrant);
        $this->enforcePolicyAttributes($gate);
    }

    /**
     * @param  Closure(): Grant  $resolveGrant
     * @param  class-string<Ability>  $permissions
     */
    private function defineAbilities(Gate $gate, Closure $resolveGrant, string $permissions): void
    {
        foreach ($permissions::cases() as $permission) {
            $gate->define($permission, static function (?object $user, mixed ...$arguments) use ($resolveGrant, $permission): Response {
                $grant = $resolveGrant();
                $scope = $arguments[0] ?? null;
                $scope = $scope instanceof Scope && $grant->isPersisted($scope) ? $scope : null;

                $allowed = $user !== null
                    && $grant->isPersisted($user)
                    && $grant->permissions($user, $scope)->containsStrict($permission);

                return $allowed ? Response::allow() : Response::deny($permission->deniedMessage());
            });
        }
    }

    /** @param  Closure(): Grant  $resolveGrant */
    private function allowSuperAdmin(Gate $gate, Closure $resolveGrant): void
    {
        $gate->before(static function (?object $user) use ($resolveGrant): ?bool {
            $grant = $resolveGrant();
            $superAdmin = $grant->superAdmin();

            $isSuperAdmin = $superAdmin instanceof Role
                && $user !== null
                && $grant->isPersisted($user)
                && $grant->hasRole($user, $superAdmin);

            return $isSuperAdmin ? true : null;
        });
    }

    private function enforcePolicyAttributes(Gate $gate): void
    {
        $gate->before(static function (?object $user, string $ability, array $arguments) use ($gate): ?Response {
            $subject = $arguments[0] ?? null;

            if (! is_object($subject) && ! is_string($subject)) {
                return null;
            }

            $policy = $gate->getPolicyFor(is_object($subject) ? $subject::class : $subject);
            $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

            if (! is_object($policy) || ! method_exists($policy, $method)) {
                return null;
            }

            $requires = new ReflectionMethod($policy, $method)->getAttributes(Requires::class)[0] ?? null;
            $required = $requires?->newInstance()->ability;

            if ($required === null || $required->value === $ability) {
                return null;
            }

            $response = $gate->forUser($user)->inspect($required, $arguments);

            return $response->allowed() ? null : $response;
        });
    }

    private function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
            ListCommand::class,
            ShowCommand::class,
            SyncCommand::class,
        ]);
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/grant.php' => config_path('grant.php'),
        ], ['grant', 'grant-config']);
    }
}
