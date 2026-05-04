<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Allow the SvelteKit SPA dev server (vite) and production accounting-web
    | host to call this Laravel API with credentials (cookies + CSRF token).
    | Same eTLD+1 cookie sharing requires `supports_credentials => true` and
    | the `withCredentials` flag on the SPA fetch client.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*', 'oauth/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('SPA_ORIGIN', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://accounting.akunta.local:5173',
    ],

    'allowed_origins_patterns' => [
        // dev: any port on akunta.local subdomains
        '#^https?://([a-z0-9-]+\.)?akunta\.local(:\d+)?$#',
        // dev: any localhost / 127.0.0.1 port (vite, etc.)
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Tenant-Slug', 'X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
