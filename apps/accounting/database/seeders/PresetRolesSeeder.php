<?php

namespace Database\Seeders;

use Akunta\Rbac\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Preset roles per spec §5.5. Global (tenant_id=null), idempotent via firstOrCreate on code.
 * Permissions intentionally NOT attached here — each app registers its own permissions via
 * `PermissionRegistry` on install, then admin UI lets tenants pick-and-mix per role. See §5.8.
 */
class PresetRolesSeeder extends Seeder
{
    public const ROLES = [
        ['super_admin', 'Super Admin', 'Pemilik tenant, akses semua'],
        ['app_admin', 'App Admin', 'Admin per aplikasi'],
        ['owner', 'Owner / Direktur', 'View all across apps & entities, no edit'],
        ['finance_manager', 'Finance Manager', 'Approval level tinggi, cross-app financial oversight'],
        ['accountant', 'Accountant', 'Full CRUD journal, view report'],
        ['accountant_assistant', 'Accountant Assistant', 'Create draft journal, no post'],
        ['approver', 'Approver', 'Post/approve journal yang dibuat orang lain'],
        ['tax_officer', 'Tax Officer', 'Khusus handle perpajakan'],
        ['hr_manager', 'HR Manager', 'Full Payroll + HR'],
        ['hr_staff', 'HR Staff', 'Create only'],
        ['cashier', 'Cashier', 'Khusus kas kecil (Cash Management app)'],
        ['internal_auditor', 'Internal Auditor', 'Akses audit log + read-only seluruh app/entity'],
        ['auditor_external', 'Auditor (External)', 'Read-only, bisa di-lock per periode'],
        ['viewer', 'Viewer', 'Read-only dashboard'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as [$code, $name, $description]) {
            Role::firstOrCreate(
                ['code' => $code, 'tenant_id' => null],
                [
                    'name' => $name,
                    'description' => $description,
                    'is_preset' => true,
                ]
            );
        }
    }
}
