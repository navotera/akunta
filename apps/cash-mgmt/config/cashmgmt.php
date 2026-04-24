<?php

return [
    'accounts' => [
        // Fallback cash account when fund.account_code is absent. Override per-fund.
        'cash' => env('CASHMGMT_ACCOUNT_CASH', '1101'),
    ],
];
