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
use App\Services\EcopaOrganizationLinker;
use App\Services\InstallationOnboardingService;
use App\Services\PlatformAuditLogger;
use App\Services\RequiredAccountService;
use App\Support\PlatformOwner;
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
        private readonly InstallationOnboardingService $onboarding,
        private readonly EcopaOrganizationLinker $organizationLinker,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'completed' => $this->onboarding->isCompleted(),
            'completed_at' => $this->onboarding->completedAt(),
            'has_entity' => $this->onboarding->hasInitialEntity(),
            'entity_count' => $this->onboarding->initialEntityCount(),
        ]]);
    }

    public function entity(Request $request): JsonResponse
    {
        $this->authorizeInstaller($request);
        $this->onboarding->assertIncomplete();
        abort_if($this->onboarding->hasInitialEntity(), 409, 'Entitas awal sudah tersedia. Muat ulang onboarding.');

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
            $organization = EcopaOrganizationLinker::normalize(session('ecopa.organization'));
            if ($organization !== null) {
                $this->organizationLinker->linkNewTenant($tenant, $organization, 'installation_onboarding');
            }

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

            app(PlatformAuditLogger::class)->record('installation.entity_created', Entity::class, $entity->id, [
                'tenant_id' => $tenant->id,
                'name' => $entity->name,
            ]);

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
        abort_unless(
            PlatformOwner::check($request->user()),
            403,
            'Hanya Platform Owner yang dapat menyelesaikan setup instalasi Akunta.',
        );
    }
}
