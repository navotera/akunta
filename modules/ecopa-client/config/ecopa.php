<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ecopa Main Tier
    |--------------------------------------------------------------------------
    |
    | Configuration for the Ecopa SSO/portal integration. Each second-tier app
    | (Accounting, Payroll, Cash Mgmt, etc.) registers as an SSOIntegration
    | inside Ecopa and uses these credentials.
    |
    */

    'url' => env('ECOPA_URL', 'https://home.opensynergic.com'),

    'client_id'     => env('ECOPA_CLIENT_ID'),
    'client_secret' => env('ECOPA_CLIENT_SECRET'),
    'redirect_uri'  => env('ECOPA_REDIRECT_URI'),

    // Bearer token for /api/* server-to-server calls (Ecopa "Company App" token)
    'api_token'     => env('ECOPA_API_TOKEN'),

    // Shared secret used to verify webhook signatures from Ecopa.
    // Provided by Ecopa admin during app registration (auto-generated on first
    // metadata sync; copy from Ecopa Apps Management → SSO/Webhook tab).
    'webhook_secret' => env('ECOPA_WEBHOOK_SECRET'),

    'expected_issuer' => env('ECOPA_EXPECTED_ISSUER', 'ecopa'),

    'jwks_cache_seconds' => (int) env('ECOPA_JWKS_CACHE_SECONDS', 3600),

    'http_timeout' => (int) env('ECOPA_HTTP_TIMEOUT', 8),

    // Show "Profil Saya" page in top navigation. When false, page route still
    // works (so user-menu items can link to it) but it's hidden from main nav.
    'profile_in_nav' => (bool) env('ECOPA_PROFILE_IN_NAV', true),

    // Routes — set on the Laravel side that consumes this module
    'routes' => [
        'login'    => env('ECOPA_LOGIN_ROUTE', 'ecopa.login'),
        'callback' => env('ECOPA_CALLBACK_ROUTE', 'ecopa.callback'),
        'logout'   => env('ECOPA_LOGOUT_ROUTE', 'ecopa.logout'),
    ],
];
