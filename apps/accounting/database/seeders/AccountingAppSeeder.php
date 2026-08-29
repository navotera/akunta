<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\App as RbacApp;
use Illuminate\Database\Seeder;

final class AccountingAppSeeder extends Seeder
{
    public function run(): void
    {
        RbacApp::query()->firstOrCreate(
            ['code' => 'accounting'],
            ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true],
        );
    }
}
