<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // akunta_entity cookie is shared across ECOSYSTEM_BASE_DOMAIN so all
        // sibling apps read the same plaintext value — exempt from Laravel's
        // default cookie encryption (matches apps/accounting/bootstrap/app.php).
        $middleware->encryptCookies(except: [
            'akunta_entity',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

