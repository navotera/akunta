<?php

declare(strict_types=1);

namespace Akunta\ApiClient;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class ApiClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/akunta-api-client.php', 'akunta-api-client');

        $this->app->singleton(AutoJournalClient::class, function ($app) {
            $cfg = $app['config']->get('akunta-api-client.auto_journal');

            return new AutoJournalClient(
                http: $app->make(HttpFactory::class),
                baseUrl: (string) ($cfg['base_url'] ?? ''),
                token: (string) ($cfg['token'] ?? ''),
                timeoutSeconds: (float) ($cfg['timeout_seconds'] ?? 10.0),
                retries: (int) ($cfg['retries'] ?? 2),
                retryBaseDelayMs: (int) ($cfg['retry_base_delay_ms'] ?? 200),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/akunta-api-client.php' => config_path('akunta-api-client.php'),
            ], 'akunta-api-client-config');
        }
    }
}
