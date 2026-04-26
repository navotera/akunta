<?php

namespace Akunta\EcopaClient;

use Illuminate\Support\ServiceProvider;

class EcopaClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ecopa.php', 'ecopa');

        $this->app->singleton(EcopaClient::class, function ($app) {
            return new EcopaClient($app['config']->get('ecopa'));
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ecopa-client');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ecopa.php' => config_path('ecopa.php'),
            ], 'ecopa-config');
        }
    }
}
