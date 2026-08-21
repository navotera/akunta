<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Services\PermissionRegistry;
use Illuminate\Database\Seeder;

class JournalWorkflowPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = app(PermissionRegistry::class)->registerMany('accounting', [
            ['code' => 'journal.read', 'description' => 'Melihat jurnal', 'category' => 'journal'],
            ['code' => 'journal.create', 'description' => 'Membuat jurnal', 'category' => 'journal'],
            ['code' => 'journal.update', 'description' => 'Mengubah jurnal draft atau ditolak', 'category' => 'journal'],
            ['code' => 'journal.delete', 'description' => 'Menghapus jurnal draft', 'category' => 'journal'],
            ['code' => 'journal.submit', 'description' => 'Mengajukan jurnal untuk review', 'category' => 'journal'],
            ['code' => 'journal.review', 'description' => 'Menyetujui atau menolak jurnal', 'category' => 'journal'],
            ['code' => 'journal.post', 'description' => 'Posting jurnal', 'category' => 'journal'],
            ['code' => 'journal.reverse', 'description' => 'Membalik jurnal posted', 'category' => 'journal'],
            ['code' => 'automapping.manage', 'description' => 'Mengelola rule Auto Mapping dan raw data transaksi', 'category' => 'journal'],
            ['code' => 'fiscal.adjustment.read', 'description' => 'Melihat jurnal, koreksi, bukti, dan laporan Fiskal', 'category' => 'fiscal'],
            ['code' => 'fiscal.adjustment.manage', 'description' => 'Membuat dan mengubah koreksi Fiskal draft', 'category' => 'fiscal'],
            ['code' => 'fiscal.adjustment.approve', 'description' => 'Menyetujui koreksi yang masuk rekonsiliasi pajak', 'category' => 'fiscal'],
            ['code' => 'fiscal.tax_provision.read', 'description' => 'Melihat perhitungan dan jurnal provisi pajak', 'category' => 'fiscal'],
            ['code' => 'fiscal.tax_provision.manage', 'description' => 'Membuat perhitungan dan jurnal provisi pajak draft', 'category' => 'fiscal'],
        ])->keyBy('code');

        $roles = [
            'admin' => array_keys($permissions->all()),
            'supervisor' => ['journal.read', 'journal.review', 'journal.post', 'journal.reverse', 'journal.update', 'automapping.manage', 'fiscal.tax_provision.read', 'fiscal.tax_provision.manage'],
            'operator' => ['journal.read', 'journal.create', 'journal.update', 'journal.delete', 'journal.submit'],
            'accountant' => ['journal.read', 'journal.create', 'journal.update', 'journal.delete', 'journal.submit', 'fiscal.adjustment.read', 'fiscal.adjustment.manage', 'fiscal.tax_provision.read', 'fiscal.tax_provision.manage'],
            'tax_officer' => ['journal.read', 'fiscal.adjustment.read', 'fiscal.adjustment.manage', 'fiscal.adjustment.approve', 'fiscal.tax_provision.read', 'fiscal.tax_provision.manage'],
            'inspector' => ['journal.read', 'fiscal.adjustment.read'],
        ];
        foreach ($roles as $roleCode => $codes) {
            $role = Role::where('code', $roleCode)->whereNull('tenant_id')->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching(collect($codes)->map(fn (string $code) => $permissions[$code]->id)->all());
            }
        }
    }
}
