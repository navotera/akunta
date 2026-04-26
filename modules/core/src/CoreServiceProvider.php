<?php

declare(strict_types=1);

namespace Akunta\Core;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('hooks', fn ($app) => new HookManager($app->make(Dispatcher::class)));
        $this->app->alias('hooks', HookManager::class);

        $this->app->singleton(PluginManager::class, fn ($app) => new PluginManager(
            $app,
            $app->make(HookManager::class),
        ));
        $this->app->alias(PluginManager::class, 'plugins');
    }

    public function boot(): void
    {
        // Hook constants in Akunta\Core\Hooks. Modules/apps register listeners
        // via Event::listen(Hooks::JOURNAL_AFTER_POST, …) or Hooks::addFilter(…).
        //
        // Plugin discovery + boot runs from each app's AppServiceProvider — see
        // docs/plugins.md for the WP-style plugin pattern.
    }
}
