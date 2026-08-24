<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\User;
use App\Services\NativeFakeDataProvisioner;
use Illuminate\Database\Seeder;

final class FakeDataEntitySeeder extends Seeder
{
    public function run(NativeFakeDataProvisioner $provisioner): void
    {
        $tenant = Tenant::query()
            ->where('slug', env('SUPER_ADMIN_TENANT_SLUG', 'akunta-dev'))
            ->first();
        if (! $tenant) {
            $this->command?->warn('  ! FakeDataEntitySeeder: tenant lokal belum tersedia — skipping.');

            return;
        }

        $existing = Entity::query()
            ->where('tenant_id', $tenant->id)
            ->where('workspace_code', 'FAKE-DATA')
            ->first();
        if ($existing && ! $existing->is_fake_data) {
            throw new \RuntimeException('Workspace code FAKE-DATA sudah dipakai entitas non-demo; provisioning dibatalkan.');
        }

        $entity = $existing ?? Entity::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'PT. Fake Data',
            'workspace_code' => 'FAKE-DATA',
            'is_active' => true,
            'is_fake_data' => true,
            'relation_type' => 'independent',
            'theme_color' => 'violet',
            'legal_form' => 'PT',
            'npwp' => '00.000.000.0-000.000',
            'nib' => '0000000000000',
            'director_name' => 'Direktur Demo Akunta',
            'phone' => '+62 800 0000 0000',
            'email' => 'hello@fake-data.akunta.local',
            'address' => ['text' => 'Jl. Contoh Data No. 1, Indonesia'],
            'workspace_settings' => [
                'bookkeeping_mode' => 'independent_books',
                'journal_number_format' => 'JU/{tahun}/{bulan}/{numbering}',
                'transaction_number_format' => 'TRX/{tahun}/{bulan}/{numbering}',
                'native_fake_data' => true,
            ],
        ]);
        $workspaceSettings = is_array($entity->workspace_settings) ? $entity->workspace_settings : [];
        $entity->forceFill([
            'name' => 'PT. Fake Data',
            'is_fake_data' => true,
            'workspace_settings' => [
                ...$workspaceSettings,
                'bookkeeping_mode' => 'independent_books',
                'native_fake_data' => true,
            ],
        ])->save();

        $owner = User::query()
            ->where('email', env('SUPER_ADMIN_EMAIL', 'superadmin@akunta.local'))
            ->first();
        $app = RbacApp::query()->where('code', 'accounting')->first();
        $role = Role::query()->whereNull('tenant_id')->where('code', 'super_admin')->first();
        if (! $owner || ! $app || ! $role) {
            throw new \RuntimeException('Super admin, aplikasi accounting, atau role super_admin belum tersedia.');
        }

        UserAppAssignment::firstOrCreate(
            [
                'user_id' => $owner->id,
                'app_id' => $app->id,
                'entity_id' => $entity->id,
                'role_id' => $role->id,
            ],
            ['assigned_at' => now()],
        );

        $counts = $provisioner->provision($entity, $owner);
        $this->command?->info(sprintf(
            '  FakeDataEntitySeeder: PT. Fake Data ready (%d records created or enriched).',
            array_sum($counts),
        ));
    }
}
