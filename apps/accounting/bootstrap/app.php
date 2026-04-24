<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\TenantResolver::class,
            'api.token' => \App\Http\Middleware\ApiTokenAuth::class,
            'require.token.perms' => \App\Http\Middleware\RequireTokenPermissions::class,
        ]);
        $middleware->append(\App\Http\Middleware\TenantResolver::class);
        // akunta_entity is a non-sensitive tenant/entity ID, needs to be readable
        // by sibling apps on ECOSYSTEM_BASE_DOMAIN — exempt from Laravel's default
        // cookie encryption so all 3 apps see the same plaintext value.
        $middleware->encryptCookies(except: [
            \App\Http\Middleware\SharedEntitySelector::COOKIE_NAME,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
