<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionMethod;
use Shipfastlabs\Grant\Auth\DoctrineUserProvider;
use Shipfastlabs\Grant\Console\Commands\InstallCommand;
use Shipfastlabs\Grant\Console\Commands\ListCommand;
use Shipfastlabs\Grant\Console\Commands\ShowCommand;
use Shipfastlabs\Grant\Console\Commands\SyncCommand;
use Shipfastlabs\Grant\Doctrine\EntityManagerFactory;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Internal\AssignmentMemo;
use Shipfastlabs\Grant\Listeners\InjectGrantService;

final class GrantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/grant.php', 'grant');

        $this->app->scoped(AssignmentMemo::class);
        $this->app->scoped(Grant::class);

        $this->app->scoped(
            EntityManagerInterface::class,
            fn (Application $app): EntityManagerInterface => new EntityManagerFactory($this->config(), $app->make(AssignmentMemo::class))->make(),
        );

        $this->app->afterResolving(EntityManagerInterface::class, function (EntityManagerInterface $entityManager): void {
            $entityManager->getEventManager()->addEventSubscriber(
                new InjectGrantService(fn (): Grant => $this->app->make(Grant::class)),
            );
        });
    }

    public function boot(): void
    {
        $permissions = $this->config()->get('grant.permissions');

        if (is_string($permissions) && is_subclass_of($permissions, Ability::class)) {
            $this->registerGate();
        }

        $this->registerAuthProvider();

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

    private function registerAuthProvider(): void
    {
        $this->app->make(AuthManager::class)->provider('doctrine', function (Application $app, array $config): DoctrineUserProvider {
            $model = $config['model'] ?? null;

            if (! is_string($model) || ! class_exists($model)) {
                throw InvalidConfigurationException::authModel();
            }

            return new DoctrineUserProvider($app->make(EntityManagerInterface::class), $model, $app->make(Hasher::class));
        });
    }

    private function registerGate(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(Gate::class);
        $permissions = $this->app->make(Grant::class)->permissionClass();

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
