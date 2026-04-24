<?php

declare(strict_types=1);

return [
    'user_model' => env('RBAC_USER_MODEL', App\Models\User::class),

    'preset_roles' => [
        'super_admin',
        'app_admin',
        'owner_direktur',
        'finance_manager',
        'accountant',
        'accountant_assistant',
        'approver',
        'tax_officer',
        'hr_manager',
        'hr_staff',
        'cashier',
        'internal_auditor',
        'auditor_external',
        'viewer',
    ],

    'default_deny' => true,
];
