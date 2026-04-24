<?php

declare(strict_types=1);

namespace Akunta\Rbac;

use Akunta\Rbac\Services\AssignmentService;
use Akunta\Rbac\Services\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rbac.php', 'rbac');

        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(AssignmentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/rbac.php' => config_path('rbac.php'),
            ], 'akunta-rbac-config');
        }
    }
}
