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

    'provisioner' => [
        // Driver selection inferred from database.connections.{control_connection}.driver.
        // Override to force a specific impl regardless of control driver (useful in tests).
        'force_driver' => env('TENANT_PROVISIONER_FORCE_DRIVER'),

        // Where SqliteTenantProvisioner writes tenant DB files.
        'sqlite_storage_path' => env('TENANT_SQLITE_STORAGE', storage_path('tenant-dbs')),
    ],

    // Cookie domain for cross-app entity sync (step 13). Null = same-origin only.
    // Set to leading-dot base (e.g. .akunta.local, .akunta.app) so cookies written
    // by /admin-accounting survive navigation to /admin-payroll etc.
    'ecosystem_base_domain' => env('ECOSYSTEM_BASE_DOMAIN'),

    'exempt_paths' => [
        '/',
        '/up',
        '/oauth/*',
        '/accounting/oauth/*',
        '/api/v1/tenants/*',
        '/livewire/*',
        '/_ignition/*',
        // Public well-known + Ecopa SSO + webhook receivers
        '/.well-known/*',
        '/auth/ecopa/*',
        '/sso/*',
        '/oidc/backchannel-logout',
        '/webhooks/ecopa',
        '/login',
        // Dev: Filament panel uses Entity as Filament-tenant; tenant DB provisioning deferred to step 12.
        // Remove these exemptions once SaaS provisioning lands + control DB is live.
        '/admin-accounting',
        '/admin-accounting/*',
        // Auto-journal API — runs on default connection until tenant DB provisioning (step 12).
        // Once provisioning lands, remove this and resolve tenant from token.app_id or header.
        '/api/v1/journals',
        '/api/v1/journals/*',
        '/api/v1/journal-templates',
        '/api/v1/journal-templates/*',
        '/api/v1/recurring-journals',
        '/api/v1/recurring-journals/*',
        '/api/v1/accounts/*',
        '/api/v1/webhooks',
        '/api/v1/webhooks/*',
    ],
];
