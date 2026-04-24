<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Period;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Default super-admin chain for local/dev — env-driven, idempotent.
 * Seeds: Tenant → N Entities → App → User → UserAppAssignment(super_admin)
 *        + per-entity CoA + current-year open period.
 *
 * Required so Filament multi-tenant panel (`->tenant(Entity::class)`) has
 * resolvable tenants after login. Multiple entities prove tenant-switcher.
 *
 * Production tenant onboarding uses ProvisionTenantAction with its own
 * generated credentials. This seeder is for raw `db:seed` only.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL', 'superadmin@akunta.local');
        $password = env('SUPER_ADMIN_PASSWORD', 'ChangeMe!2026');
        $name = env('SUPER_ADMIN_NAME', 'Super Admin');
        $tenantSlug = env('SUPER_ADMIN_TENANT_SLUG', 'akunta-dev');
        $tenantName = env('SUPER_ADMIN_TENANT_NAME', 'Akunta Dev Tenant');

        $entities = [
            env('SUPER_ADMIN_ENTITY_NAME', 'PT. Dummy A'),
            env('SUPER_ADMIN_ENTITY_NAME_2', 'PT. Dummy B'),
        ];

        DB::transaction(function () use ($email, $password, $name, $tenantSlug, $tenantName, $entities): void {
            $tenant = Tenant::firstOrCreate(
                ['slug' => $tenantSlug],
                ['name' => $tenantName],
            );

            $app = RbacApp::firstOrCreate(
                ['code' => 'accounting'],
                ['name' => 'Accounting', 'version' => '0.1', 'enabled' => true],
            );

            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password_hash' => Hash::make($password)],
            );

            $superAdminRole = Role::whereNull('tenant_id')->where('code', 'super_admin')->first();

            if ($superAdminRole === null) {
                throw new \RuntimeException('super_admin preset role missing — run PresetRolesSeeder first.');
            }

            $year = Carbon::now()->year;

            foreach ($entities as $entityName) {
                $entity = Entity::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $entityName],
                    ['relation_type' => 'independent'],
                );

                UserAppAssignment::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'app_id' => $app->id,
                        'entity_id' => $entity->id,
                        'role_id' => $superAdminRole->id,
                    ],
                    ['assigned_at' => now()],
                );

                (new CoaTemplateSeeder)->run($entity->id);

                Period::firstOrCreate(
                    ['entity_id' => $entity->id, 'name' => (string) $year],
                    [
                        'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                        'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                        'status' => 'open',
                    ],
                );
            }
        });
    }
}
