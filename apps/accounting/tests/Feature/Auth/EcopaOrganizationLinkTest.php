<?php

declare(strict_types=1);

use Akunta\EcopaClient\EcopaClient;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\EcopaConfigIntegration;
use App\Models\User;
use App\Services\EcopaOrganizationLinker;
use Illuminate\Support\Facades\DB;

/**
 * Flow 1 of docs/plans/ecopa-multi-org-workspaces.md: data that existed before
 * Ecopa became multi-organisation belongs to Ecopa's default organisation and
 * stays reachable by the Platform Owner and the users who already had access.
 */
const DEFAULT_ORG = ['id' => '1', 'slug' => 'akunta-default', 'name' => 'Organisasi Default'];
const OTHER_ORG = ['id' => '7', 'slug' => 'pt-aba', 'name' => 'PT ABA'];

beforeEach(function () {
    config()->set('app.spa_url', 'https://spa.example.test');
    config()->set('ecopa.client_id', 'test-client');
    EcopaConfigIntegration::query()->create([
        'name' => 'installation_onboarding_completed_at',
        'value' => '2026-08-31T00:00:00+00:00',
    ]);
});

/**
 * Pre-migration data: one tenant, two entities, a legacy admin with an
 * app-wide preset role, a member with an entity role, a tenant-custom role,
 * and one journal row count baseline. Assignment tenant ids are cleared to
 * mimic rows written before the column existed.
 *
 * @return array{tenant: Tenant, entities: array<int, Entity>, admin: User, member: User}
 */
function legacyInstallation(): array
{
    $tenant = Tenant::query()->create(['name' => 'Akunta Lama', 'slug' => 'akunta-lama', 'status' => 'active']);
    $entityA = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT Lama A']);
    $entityB = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT Lama B']);
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0']);
    $superAdmin = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'super_admin'], ['name' => 'Super Admin', 'is_preset' => true]);
    $operator = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);
    $custom = Role::query()->create(['tenant_id' => $tenant->id, 'code' => 'auditor_lama', 'name' => 'Auditor Lama']);

    $admin = User::query()->create([
        'name' => 'Admin Lama', 'email' => 'admin-lama@example.test',
        'main_tier_user_id' => 'ecopa-admin-lama', 'email_verified_at' => now(),
    ]);
    $member = User::query()->create([
        'name' => 'Anggota Lama', 'email' => 'anggota-lama@example.test',
        'main_tier_user_id' => 'ecopa-anggota-lama', 'email_verified_at' => now(),
    ]);

    UserAppAssignment::query()->create(['user_id' => $admin->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => $superAdmin->id, 'ecopa_role' => 'admin']);
    UserAppAssignment::query()->create(['user_id' => $member->id, 'app_id' => $app->id, 'entity_id' => $entityA->id, 'role_id' => $operator->id]);
    UserAppAssignment::query()->create(['user_id' => $member->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => $custom->id]);

    DB::table('user_app_assignments')->update(['tenant_id' => null]);

    return ['tenant' => $tenant, 'entities' => [$entityA, $entityB], 'admin' => $admin, 'member' => $member];
}

/**
 * The route caches its controller (and so the injected client) across
 * requests in one test, so a single mock reads the claims for each login.
 */
function ecopaLogin(array $claims)
{
    $GLOBALS['ecopaTestClaims'] = $claims;

    if (! app()->bound('ecopaTestClientBound')) {
        $client = Mockery::mock(EcopaClient::class);
        $client->shouldReceive('verifyState')->andReturnTrue();
        $client->shouldReceive('exchangeCode')->andReturnUsing(fn () => $GLOBALS['ecopaTestClaims']);
        app()->instance(EcopaClient::class, $client);
        app()->instance('ecopaTestClientBound', true);
    }

    return test()->withSession(['ecopa.state' => 'state'])
        ->get('/auth/ecopa/callback?code=code&state=state');
}

function claimsFor(string $sub, string $email, string $appRole, ?array $organization, bool $platformAdmin = false): array
{
    return array_filter([
        'sub' => $sub,
        'email' => $email,
        'name' => $email,
        'app_role' => $appRole,
        'app_scopes' => [],
        'divisions' => [],
        'organization' => $organization,
        'platform_admin' => $platformAdmin,
    ], fn ($value) => $value !== null);
}

it('backfills assignment tenant ids idempotently without changing row counts', function () {
    $legacy = legacyInstallation();
    $before = app(EcopaOrganizationLinker::class)->counts();

    $migration = require base_path('../../modules/rbac/database/migrations/2026_11_19_000100_link_tenants_to_ecopa_organizations.php');
    $migration->up();
    $migration->up();

    expect(app(EcopaOrganizationLinker::class)->counts())->toBe($before)
        ->and(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(0)
        ->and(DB::table('user_app_assignments')->distinct()->pluck('tenant_id')->all())->toBe([$legacy['tenant']->id])
        ->and($legacy['tenant']->fresh()->ecopa_organization_id)->toBeNull();
});

it('links the legacy tenant to the default organisation on the Platform Owner login and shows the existing workspaces', function () {
    $legacy = legacyInstallation();
    $before = app(EcopaOrganizationLinker::class)->counts();

    ecopaLogin(claimsFor('ecopa-platform-admin', 'platform@example.test', 'admin', DEFAULT_ORG, platformAdmin: true))
        ->assertRedirect('https://spa.example.test/dashboard');

    $tenant = $legacy['tenant']->fresh();
    expect($tenant->ecopa_organization_id)->toBe('1')
        ->and($tenant->ecopa_organization_slug)->toBe('akunta-default')
        ->and($tenant->ecopa_organization_linked_at)->not->toBeNull()
        // Only the Platform Owner's own local account is new; nothing else changes.
        ->and(app(EcopaOrganizationLinker::class)->counts())->toBe(array_merge($before, ['users' => $before['users'] + 1]))
        ->and(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'tenant.ecopa_organization_linked')->count())->toBe(1)
        ->and(session('ecopa.organization'))->toBe(DEFAULT_ORG)
        ->and(session('ecopa.platform_admin'))->toBeTrue();

    $platformAdmin = User::query()->where('main_tier_user_id', 'ecopa-platform-admin')->firstOrFail();
    $this->assertAuthenticatedAs($platformAdmin);
    // The signed claim is stored with the session value it must keep matching.
    expect($platformAdmin->ecopa_platform_admin)->toBeTrue();
    expect($platformAdmin->getTenants()->pluck('id')->sort()->values()->all())
        ->toBe(collect($legacy['entities'])->pluck('id')->sort()->values()->all());

    // The Platform Owner has no local assignment, yet its workspace calls work.
    expect($platformAdmin->assignments()->count())->toBe(0);
    $this->withHeader('X-Tenant-Slug', $legacy['entities'][1]->id)
        ->getJson('/api/v1/spa/periods')
        ->assertOk();

    // A second Platform Owner login is a no-op.
    ecopaLogin(claimsFor('ecopa-platform-admin', 'platform@example.test', 'admin', DEFAULT_ORG, platformAdmin: true))
        ->assertRedirect('https://spa.example.test/dashboard');
    expect(DB::table('audit_log')->where('action', 'tenant.ecopa_organization_linked')->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1);
});

it('keeps legacy users and their roles working after the link', function () {
    $legacy = legacyInstallation();
    ecopaLogin(claimsFor('ecopa-platform-admin', 'platform@example.test', 'admin', DEFAULT_ORG, platformAdmin: true));
    auth()->logout();

    ecopaLogin(claimsFor('ecopa-anggota-lama', 'anggota-lama@example.test', 'user', DEFAULT_ORG))
        ->assertRedirect('https://spa.example.test/dashboard');

    $this->assertAuthenticatedAs($legacy['member']);
    expect(User::query()->count())->toBe(3)
        ->and($legacy['member']->assignments()->whereNull('revoked_at')->count())->toBe(2)
        ->and($legacy['member']->fresh()->canAccessTenant($legacy['entities'][0]))->toBeTrue();
});

it('still refuses a workspace the user cannot access when the session is not an Ecopa admin', function () {
    $legacy = legacyInstallation();
    $outsider = User::query()->create([
        'name' => 'Tanpa Akses', 'email' => 'tanpa-akses@example.test',
        'main_tier_user_id' => 'ecopa-tanpa-akses', 'email_verified_at' => now(),
    ]);

    $this->actingAs($outsider)
        ->withSession(['ecopa.app_role' => 'user'])
        ->withHeader('X-Tenant-Slug', $legacy['entities'][0]->id)
        ->getJson('/api/v1/spa/periods')
        ->assertUnprocessable();
});

it('does not link on a login that is not from the Platform Owner', function () {
    $legacy = legacyInstallation();

    ecopaLogin(claimsFor('ecopa-admin-lama', 'admin-lama@example.test', 'admin', DEFAULT_ORG))
        ->assertRedirect('https://spa.example.test/dashboard');

    expect($legacy['tenant']->fresh()->ecopa_organization_id)->toBeNull()
        ->and($legacy['admin']->fresh()->ecopa_platform_admin)->toBeFalse()
        ->and(session('ecopa.platform_admin'))->toBeFalse();
    $this->assertAuthenticatedAs($legacy['admin']);
});

it('keeps logins without an organisation claim working as before', function () {
    $legacy = legacyInstallation();

    ecopaLogin(claimsFor('ecopa-admin-lama', 'admin-lama@example.test', 'admin', null))
        ->assertRedirect('https://spa.example.test/dashboard');

    expect($legacy['tenant']->fresh()->ecopa_organization_id)->toBeNull()
        ->and(session('ecopa.organization'))->toBeNull();
});

it('refuses users of another organisation once the default organisation is linked', function () {
    legacyInstallation();
    ecopaLogin(claimsFor('ecopa-platform-admin', 'platform@example.test', 'admin', DEFAULT_ORG, platformAdmin: true));
    auth()->logout();

    ecopaLogin(claimsFor('ecopa-aba-admin', 'admin@aba.example.test', 'admin', OTHER_ORG))
        ->assertForbidden();
    ecopaLogin(claimsFor('ecopa-aba-member', 'member@aba.example.test', 'user', OTHER_ORG))
        ->assertForbidden();

    $this->assertGuest();
    expect(User::query()->where('email', 'admin@aba.example.test')->exists())->toBeFalse();
});

it('refuses to link a second organisation', function () {
    $legacy = legacyInstallation();
    $linker = app(EcopaOrganizationLinker::class);
    $linker->linkLegacyTenants(DEFAULT_ORG, 'test');

    $result = $linker->linkLegacyTenants(OTHER_ORG, 'test');

    expect($result['status'])->toBe(EcopaOrganizationLinker::STATUS_OTHER_ORGANIZATION_LINKED)
        ->and($legacy['tenant']->fresh()->ecopa_organization_id)->toBe('1');
});

it('links nothing when several legacy tenants exist unless one is chosen explicitly', function () {
    $legacy = legacyInstallation();
    $second = Tenant::query()->create(['name' => 'Tenant Kedua', 'slug' => 'tenant-kedua', 'status' => 'active']);

    $this->artisan('ecopa:link-organization', ['organization_id' => '1'])->assertFailed();
    expect(Tenant::query()->whereNotNull('ecopa_organization_id')->count())->toBe(0);

    $this->artisan('ecopa:link-organization', ['organization_id' => '1', '--tenant' => $legacy['tenant']->id, '--slug' => 'akunta-default'])
        ->assertSuccessful();

    expect($legacy['tenant']->fresh()->ecopa_organization_id)->toBe('1')
        ->and($second->fresh()->ecopa_organization_id)->toBeNull();
});

it('reports a dry run without saving anything', function () {
    $legacy = legacyInstallation();

    $this->artisan('ecopa:link-organization', ['organization_id' => '1', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect($legacy['tenant']->fresh()->ecopa_organization_id)->toBeNull()
        ->and(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(3)
        ->and(DB::table('audit_log')->where('action', 'tenant.ecopa_organization_linked')->count())->toBe(0);
});

it('links the tenant created by installation onboarding to the installer organisation', function () {
    EcopaConfigIntegration::query()->where('name', 'installation_onboarding_completed_at')->delete();
    $installer = User::query()->create([
        'name' => 'Installer', 'email' => 'installer@example.test',
        'main_tier_user_id' => 'ecopa-installer', 'email_verified_at' => now(),
    ]);

    // The installation wizard is Platform Owner only.
    $this->actingAs($installer)
        ->withSession(platformOwnerSession($installer, ['ecopa.organization' => DEFAULT_ORG]))
        ->postJson('/api/v1/spa/installation-onboarding/entity', ['name' => 'PT Instalasi Baru'])
        ->assertCreated();

    $tenant = Entity::query()->where('name', 'PT Instalasi Baru')->firstOrFail()->tenant;
    expect($tenant->ecopa_organization_id)->toBe('1')
        ->and(UserAppAssignment::query()->where('user_id', $installer->id)->value('tenant_id'))->toBe($tenant->id);
});
