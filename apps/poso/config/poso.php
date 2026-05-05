<?php

return [
    'tier' => 'second_tier_operations',

    'main_tier' => [
        'name' => 'ecopa',
        'base_url' => env('ECOPA_BASE_URL', 'http://ecopa.akunta.local'),
    ],

    'accounting_tier' => [
        'name' => 'akunta',
        'journal_webhook_url' => env('AKUNTA_JOURNAL_WEBHOOK_URL'),
        'api_token' => env('AKUNTA_API_TOKEN'),
        'database_connection' => env('AKUNTA_DB_CONNECTION', 'akunta'),
    ],

    'webhook' => [
        'events' => [
            'sales_invoice_published' => 'poso.sales_invoice.published',
            'purchase_bill_published' => 'poso.purchase_bill.published',
        ],
    ],
];
