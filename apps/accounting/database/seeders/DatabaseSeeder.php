<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PresetRolesSeeder::class,
            SuperAdminSeeder::class,
            SettingsPermissionsSeeder::class,
        ]);

        if (filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call([
                DemoDataSeeder::class,
            ]);
        }
    }
}
