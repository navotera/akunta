<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Source Drill-Back URLs
    |--------------------------------------------------------------------------
    |
    | When a journal arrives via the API with `metadata.source_app` + `source_id`,
    | the journal table renders a clickable Sumber badge linking back to the
    | originating record in that app. Templates accept `{entity}` and `{source_id}`
    | placeholders. Set per-app to enable; leave unset to render an inert badge.
    |
    | Example: Sales App invoice INV-2026-1234 lives at
    |   https://sales.akunta.local/admin-sales/{entity}/invoices/{source_id}
    |
    */
    'source_drill_urls' => [
        'sales'     => env('AKUNTA_DRILL_SALES_URL'),
        'purchase'  => env('AKUNTA_DRILL_PURCHASE_URL'),
        'inventory' => env('AKUNTA_DRILL_INVENTORY_URL'),
        'payroll'   => env('AKUNTA_DRILL_PAYROLL_URL'),
        'cash-mgmt' => env('AKUNTA_DRILL_CASH_MGMT_URL'),
    ],
];
