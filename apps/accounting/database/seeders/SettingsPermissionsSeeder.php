<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Services\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Permissions for the Pengaturan cluster pages. Idempotent (PermissionRegistry
 * upserts by app+code). Run from DatabaseSeeder + safe to re-run on upgrades.
 *
 * Admins assign these to roles via the RBAC UI. super_admin bypasses every
 * check via the role-code short-circuit in User::hasPermission().
 */
class SettingsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistry::class)->registerMany('accounting', [
            [
                'code'        => 'settings.coa_template.manage',
                'description' => 'Akses halaman Pengaturan → Template CoA (terapkan template per industri).',
                'category'    => 'settings',
            ],
            [
                'code'        => 'settings.cron.manage',
                'description' => 'Akses halaman Pengaturan → Cron (status scheduler + activity log + retensi).',
                'category'    => 'settings',
            ],
        ]);
    }
}
