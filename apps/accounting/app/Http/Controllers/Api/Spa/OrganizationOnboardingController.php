<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EcopaOrganizationLinker;
use App\Services\OrganizationEntryState;
use App\Services\RequiredAccountService;
use App\Support\Tenancy\RequestTenant;
use Database\Seeders\PresetRolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flow 2 and 3 of the Ecopa "Tenant onboarding flows" (docs/plans/ecopa-multi-org-agent-prompt.md,
 * stage 7). Reachable by a session whose organisation has no tenant yet (see
 * EstablishTenantContext): the Organization Owner creates the organisation's
 * workspace; a member is only told to contact the Organization Owner.
 *
 * The tenant is keyed by the organisation id from the SIGNED session — never by a
 * request field — and no physical database is created for it (pooled tenancy).
 */
class OrganizationOnboardingController extends Controller
{
    public function __construct(
        private readonly OrganizationEntryState $entryState,
        private readonly EcopaOrganizationLinker $linker,
        private readonly RequiredAccountService $requiredAccounts,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->entryState->for($user)]);
    }

    public function workspace(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            session('ecopa.app_role') === 'admin' && $user->disabled_at === null,
            403,
            'Hanya Organization Owner yang dapat menyiapkan workspace organisasi.',
        );
        $organization = EcopaOrganizationLinker::normalize(session('ecopa.organization'));
        abort_if($organization === null, 422, 'Sesi ini tidak membawa organisasi Ecopa.');
        abort_unless($this->linker->organizationMayEnter($organization['id']), 403, 'Workspace untuk organisasi Anda belum tersedia di Akunta. Hubungi Platform Owner Ecopa.');
        abort_if(
            RequestTenant::tenantOfOrganization($organization['id']) !== null,
            409,
            'Workspace organisasi Anda sudah ada.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_form' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $entity = DB::transaction(fn (): Entity => $this->create($user, $organization, $data));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the tenant for this organisation first.
            abort(409, 'Workspace organisasi Anda sudah ada.');
        }

        return response()->json(['data' => [
            'id' => $entity->id,
            'tenant_id' => $entity->tenant_id,
            'name' => $entity->name,
        ]], 201);
    }

    /**
     * @param  array{id: string, slug: string|null, name: string|null}  $organization
     * @param  array{name: string, legal_form?: string|null}  $data
     */
    private function create(User $user, array $organization, array $data): Entity
    {
        (new PresetRolesSeeder)->run();
        $adminRole = Role::withoutGlobalScopes()->whereNull('tenant_id')->where('code', 'admin')->firstOrFail();
        $app = RbacApp::query()->firstOrCreate(
            ['code' => 'accounting'],
            ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true],
        );

        $slugBase = Str::slug($organization['slug'] ?? $organization['name'] ?? $data['name']) ?: 'organisasi';
        $slug = $slugBase;
        for ($suffix = 2; Tenant::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $slugBase.'-'.$suffix;
        }

        // A tenant row only: pooled tenancy needs no physical database, so the
        // TenantProvisioner is deliberately not involved.
        $tenant = Tenant::query()->create([
            'name' => $organization['name'] ?? $data['name'],
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        abort_unless(
            $this->linker->linkNewTenant($tenant, $organization, 'organization_onboarding'),
            403,
            'Organisasi ini belum dapat memakai Akunta.',
        );

        return TenantContext::run($tenant->id, function () use ($user, $tenant, $adminRole, $app, $data, $organization): Entity {
            $entity = Entity::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'legal_form' => $data['legal_form'] ?? null,
                'relation_type' => 'independent',
            ]);

            // The Organization Owner administers this tenant — and only this one.
            UserAppAssignment::query()->create([
                'user_id' => $user->id,
                'app_id' => $app->id,
                'entity_id' => null,
                'tenant_id' => $tenant->id,
                'role_id' => $adminRole->id,
                'ecopa_role' => 'admin',
                'assigned_at' => now(),
            ]);

            $this->requiredAccounts->ensure($entity);
            $this->auditLogger->record(
                action: 'organization.workspace_created',
                resourceType: Entity::class,
                resourceId: $entity->id,
                entityId: $entity->id,
                metadata: ['tenant_id' => $tenant->id, 'ecopa_organization_id' => $organization['id']],
            );

            return $entity;
        });
    }
}
