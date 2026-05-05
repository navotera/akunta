<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('SPA_ORIGIN', 'http://poso.akunta.local:5174'),
        'http://127.0.0.1:5174',
        'http://localhost:5174',
    ],
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)?akunta\.local(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Tenant-Slug', 'X-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => true,
];
