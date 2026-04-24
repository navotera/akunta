<?php

declare(strict_types=1);

namespace Akunta\Audit;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLoggerContract::class, function ($app): AuditLogger {
            return new AuditLogger(
                $app->make(AuthFactory::class),
                $app->bound('request') ? $app->make(Request::class) : null,
            );
        });

        $this->app->alias(AuditLoggerContract::class, AuditLogger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
