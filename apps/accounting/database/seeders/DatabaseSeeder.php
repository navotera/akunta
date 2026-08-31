<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PresetRolesSeeder::class,
            AccountingAppSeeder::class,
            SettingsPermissionsSeeder::class,
            JournalWorkflowPermissionsSeeder::class,
        ]);
    }
}
