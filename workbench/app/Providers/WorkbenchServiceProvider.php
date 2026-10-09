<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Enums\Permission;
use Workbench\App\Enums\Role;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        $config->set('grant.permissions', Permission::class);
        $config->set('grant.roles', Role::class);
        $config->set('grant.entities', [dirname(__DIR__).'/Entities']);
        $config->set('grant.connection', null);
    }
}
