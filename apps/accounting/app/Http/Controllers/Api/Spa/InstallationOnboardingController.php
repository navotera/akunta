<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RequiredAccountService;
use Database\Seeders\PresetRolesSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallationOnboardingController extends Controller
{
    public function __construct(
        private readonly RequiredAccountService $requiredAccounts,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->authorizeInstaller($request);

        return response()->json(['data' => [
            'has_entity' => Entity::query()->exists(),
            'entity_count' => Entity::query()->count(),
        ]]);
    }

    public function entity(Request $request): JsonResponse
    {
        $this->authorizeInstaller($request);
        abort_if(Entity::query()->exists(), 409, 'Entitas awal sudah tersedia. Muat ulang onboarding.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:16'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $entity = DB::transaction(function () use ($data, $user): Entity {
            (new PresetRolesSeeder)->run();
            $role = Role::query()->whereNull('tenant_id')->where('code', 'super_admin')->firstOrFail();
            $app = RbacApp::query()->firstOrCreate(
                ['code' => 'accounting'],
                ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true],
            );

            $slugBase = Str::slug($data['name']) ?: 'akunta';
            $slug = $slugBase;
            $suffix = 2;
            while (Tenant::query()->where('slug', $slug)->exists()) {
                $slug = $slugBase.'-'.$suffix++;
            }

            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $slug,
            ]);
            $entity = Entity::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'legal_form' => $data['legal_form'] ?? null,
                'relation_type' => 'independent',
            ]);

            UserAppAssignment::query()->updateOrCreate([
                'user_id' => $user->id,
                'app_id' => $app->id,
                'entity_id' => null,
            ], [
                'role_id' => $role->id,
                'ecopa_role' => 'admin',
                'assigned_at' => now(),
                'revoked_at' => null,
            ]);

            $this->requiredAccounts->ensure($entity);
            $this->auditLogger->record(
                action: 'installation.entity_created',
                resourceType: Entity::class,
                resourceId: $entity->id,
                entityId: $entity->id,
                metadata: ['tenant_id' => $tenant->id, 'source' => 'integrated_ecopa_wizard'],
            );

            return $entity;
        });

        return response()->json(['data' => [
            'id' => $entity->id,
            'tenant_id' => $entity->tenant_id,
            'name' => $entity->name,
        ]], 201);
    }

    private function authorizeInstaller(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user?->isSsoAdmin(), 403, 'Hanya admin Ecopa pertama yang dapat menyelesaikan setup Akunta.');
    }
}
