<?php

return [
    'control_connection' => env('CONTROL_DB_CONNECTION', 'ecosystem_control'),
    'tenant_connection' => env('TENANT_DB_CONNECTION', 'tenant'),

    'header' => env('TENANT_HEADER', 'X-Tenant-Slug'),

    'subdomain' => [
        'enabled' => env('TENANT_SUBDOMAIN_ENABLED', true),
        'base_domain' => env('TENANT_BASE_DOMAIN', 'localhost'),
        'reserved' => ['www', 'admin', 'api', 'app'],
    ],

    'jwt_claim' => env('TENANT_JWT_CLAIM', 'tenant_id'),

    'db_prefix' => env('TENANT_DB_PREFIX', 'tenant_'),

    // Cookie domain for cross-app entity sync (step 13). Null = same-origin.
    // Set to leading-dot base (e.g. .akunta.local) for multi-app propagation.
    'ecosystem_base_domain' => env('ECOSYSTEM_BASE_DOMAIN'),

    'exempt_paths' => [
        '/',
        '/up',
        '/oauth/*',
        '/cash-mgmt/oauth/*',
        '/livewire/*',
        '/_ignition/*',
        // Dev: tenant DB provisioning deferred to 12b-α-ii.
        '/admin-cash-mgmt',
        '/admin-cash-mgmt/*',
    ],
];
