<?php

declare(strict_types=1);

use Akunta\EcopaClient\EcopaClient;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\EcopaConfigIntegration;
use App\Models\Period;
use App\Models\User;
use App\Tenancy\Contracts\TenantProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Prompt stage 7: the three standard entry flows of the Ecopa child-application
 * contract, per organisation.
 *   1. Platform Owner and existing users: straight to the existing data, no wizard.
 *   2. Organization Owner of an organisation without a tenant: create-workspace
 *      wizard; the OO administers that tenant only.
 *   3. Member: straight to the organisation's workspace; if it does not exist (or
 *      the member has no role) a message, never a wizard.
 */
const ENTRY_DEFAULT_ORG = ['id' => '1', 'slug' => 'akunta-default', 'name' => 'Organisasi Default'];
const ENTRY_ABA_ORG = ['id' => '7', 'slug' => 'pt-aba', 'name' => 'PT ABA'];

function entryLogin(array $claims)
{
    // One process serves many requests here; a real request starts with no remembered user.
    app('auth')->forgetGuards();
    session()->forget(['ecopa']);
    $GLOBALS['entryClaims'] = $claims;
    if (! app()->bound('entryClientBound')) {
        $client = Mockery::mock(EcopaClient::class);
        $client->shouldReceive('verifyState')->andReturnTrue();
        $client->shouldReceive('exchangeCode')->andReturnUsing(fn () => $GLOBALS['entryClaims']);
        app()->instance(EcopaClient::class, $client);
        app()->instance('entryClientBound', true);
    }

    return test()->withSession(['ecopa.state' => 'state'])->get('/auth/ecopa/callback?code=code&state=state');
}

function entryClaims(string $sub, string $email, string $appRole, array $organization, bool $platformAdmin = false): array
{
    return [
        'sub' => $sub, 'email' => $email, 'name' => $email, 'app_role' => $appRole, 'app_scopes' => [], 'divisions' => [],
        'organization' => $organization, 'platform_admin' => $platformAdmin,
    ];
}

beforeEach(function () {
    config()->set('app.spa_url', 'https://spa.example.test');
    config()->set('ecopa.client_id', 'test-client');
    config()->set('tenancy.multi_organization', true);

    $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $this->operator = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);

    // Organisation 1 = the default organisation: its workspace existed before multi-organisation (flow 1).
    $this->tenantA = Tenant::query()->create([
        'name' => 'Akunta Lama', 'slug' => 'akunta-lama', 'status' => 'active',
        'ecopa_organization_id' => '1', 'ecopa_organization_slug' => 'akunta-default', 'ecopa_organization_linked_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    $this->entityA = Entity::query()->create(['tenant_id' => $this->tenantA->id, 'name' => 'PT Lama', 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']]);

    $this->po = User::query()->create(['name' => 'PO', 'email' => 'po@example.test', 'main_tier_user_id' => 'ecopa-po', 'email_verified_at' => now()]);
    $this->memberA = User::query()->create(['name' => 'Anggota A', 'email' => 'anggota-a@example.test', 'main_tier_user_id' => 'ecopa-anggota-a', 'email_verified_at' => now()]);
    UserAppAssignment::query()->create(['user_id' => $this->memberA->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $this->entityA->id, 'role_id' => $this->operator->id, 'assigned_at' => now()]);

    // Identities Ecopa announced by webhook before the organisation's workspace existed (no assignment).
    $this->memberB = User::query()->create(['name' => 'Anggota B', 'email' => 'anggota-b@example.test', 'main_tier_user_id' => 'ecopa-anggota-b', 'email_verified_at' => now()]);
});

function meOf(): array
{
    return test()->getJson('/api/v1/me')->assertOk()->json('data');
}

it('flow 1: the Platform Owner and existing users go straight to the existing data, without a wizard', function () {
    entryLogin(entryClaims('ecopa-po', 'po@example.test', 'admin', ENTRY_DEFAULT_ORG, platformAdmin: true))
        ->assertRedirect('https://spa.example.test/dashboard');
    $me = meOf();
    expect($me['organization'])->toMatchArray(['id' => '1', 'state' => 'ready'])
        ->and(collect($me['tenants'])->pluck('id')->all())->toBe([$this->entityA->id]);

    entryLogin(entryClaims('ecopa-anggota-a', 'anggota-a@example.test', 'user', ENTRY_DEFAULT_ORG))->assertRedirect('https://spa.example.test/dashboard');
    $me = meOf();
    expect($me['organization']['state'])->toBe('ready')->and(collect($me['tenants'])->pluck('id')->all())->toBe([$this->entityA->id]);
});

it('flow 2: an Organization Owner of a new organisation gets the wizard, creates their own tenant, and administers only it', function () {
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldNotReceive('create');
    app()->instance(TenantProvisioner::class, $provisioner);

    entryLogin(entryClaims('ecopa-oo-aba', 'oo@aba.example.test', 'admin', ENTRY_ABA_ORG))->assertRedirect('https://spa.example.test/dashboard');
    $me = meOf();
    expect($me['organization'])->toMatchArray(['id' => '7', 'name' => 'PT ABA', 'state' => 'needs_workspace'])
        ->and($me['tenants'])->toBe([]);

    // Until the workspace exists nothing else answers, and organisation 1's data is out of reach.
    $this->withHeader('X-Tenant-Slug', $this->entityA->id)->getJson('/api/v1/spa/periods')->assertForbidden();
    $this->getJson('/api/v1/spa/organization-onboarding/status')->assertOk()->assertJsonPath('data.state', 'needs_workspace');

    $created = $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'PT ABA Utama', 'legal_form' => 'PT'])->assertCreated();
    $tenant = Tenant::query()->where('ecopa_organization_id', '7')->sole();
    expect($created->json('data.tenant_id'))->toBe($tenant->id)
        ->and($tenant->status)->toBe('active')
        ->and($tenant->ecopa_organization_slug)->toBe('pt-aba')
        ->and($tenant->onboarding_completed_at)->toBeNull()
        ->and(Tenant::query()->where('ecopa_organization_id', '1')->value('id'))->toBe($this->tenantA->id);

    // The OO administers the new tenant only (a tenant-bound assignment), and nothing of organisation 1.
    $oo = User::query()->where('main_tier_user_id', 'ecopa-oo-aba')->sole();
    $assignment = UserAppAssignment::withoutGlobalScopes()->where('user_id', $oo->id)->sole();
    expect($assignment->tenant_id)->toBe($tenant->id)->and($assignment->entity_id)->toBeNull()
        ->and(Role::withoutGlobalScopes()->find($assignment->role_id)->code)->toBe('admin');

    $me = meOf();
    expect($me['organization']['state'])->toBe('needs_setup')
        ->and(collect($me['tenants'])->pluck('id')->all())->toBe([$created->json('data.id')]);
    $entityB = $created->json('data.id');
    $this->withHeader('X-Tenant-Slug', $this->entityA->id)->getJson('/api/v1/spa/periods')->assertUnprocessable();

    // The existing wizard steps now finish the tenant's setup.
    $this->withHeader('X-Tenant-Slug', $entityB);
    $this->postJson('/api/v1/spa/onboarding/bookkeeping-mode', ['bookkeeping_mode' => 'independent_books'])->assertOk();
    $this->postJson('/api/v1/spa/onboarding/apply-coa', ['template_key' => 'generic'])->assertOk();
    $this->postJson('/api/v1/spa/periods', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated();
    expect(meOf()['organization']['state'])->toBe('ready')
        ->and(Tenant::query()->find($tenant->id)->onboarding_completed_at)->not->toBeNull()
        // Organisation 1's own state is not touched by organisation 7's wizard.
        ->and(Tenant::query()->find($this->tenantA->id)->onboarding_completed_at)->not->toBeNull()
        ->and(Account::withoutGlobalScopes()->where('entity_id', $this->entityA->id)->count())->toBe(0)
        ->and(Period::withoutGlobalScopes()->where('entity_id', $this->entityA->id)->count())->toBe(0);

    // A second run is refused: the organisation has its workspace.
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'Lagi'])->assertConflict();
    expect(Tenant::query()->where('ecopa_organization_id', '7')->count())->toBe(1);
});

it('flow 3: a member goes to the organisation workspace, or is told to contact the Organization Owner, and never sees the wizard', function () {
    // The workspace does not exist yet: a message, no wizard, no way to create one.
    entryLogin(entryClaims('ecopa-anggota-b', 'anggota-b@example.test', 'user', ENTRY_ABA_ORG))->assertRedirect('https://spa.example.test/dashboard');
    expect(meOf()['organization']['state'])->toBe('workspace_missing');
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'Coba buat'])->assertForbidden();
    expect(Tenant::query()->where('ecopa_organization_id', '7')->exists())->toBeFalse();

    // The Organization Owner creates the workspace; until their wizard is done the member still waits.
    entryLogin(entryClaims('ecopa-oo-aba', 'oo@aba.example.test', 'admin', ENTRY_ABA_ORG));
    $entityId = $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'PT ABA Utama'])->assertCreated()->json('data.id');
    entryLogin(entryClaims('ecopa-anggota-b', 'anggota-b@example.test', 'user', ENTRY_ABA_ORG));
    expect(meOf()['organization']['state'])->toBe('workspace_missing');

    // Set up, but the member holds no role yet.
    Tenant::query()->where('ecopa_organization_id', '7')->update(['onboarding_completed_at' => now()]);
    $me = meOf();
    expect($me['organization']['state'])->toBe('no_role')->and($me['tenants'])->toBe([]);

    // Once the Organization Owner gives a role the member lands in that workspace — and only that one.
    UserAppAssignment::query()->create([
        'user_id' => $this->memberB->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $entityId, 'role_id' => $this->operator->id,
        'tenant_id' => Tenant::query()->where('ecopa_organization_id', '7')->value('id'), 'assigned_at' => now(),
    ]);
    $me = meOf();
    expect($me['organization']['state'])->toBe('ready')->and(collect($me['tenants'])->pluck('id')->all())->toBe([$entityId]);
    $this->withHeader('X-Tenant-Slug', $this->entityA->id)->getJson('/api/v1/spa/periods')->assertUnprocessable();
});

it('shows no wizard to an organisation that already has its workspace, and keeps organisations apart', function () {
    entryLogin(entryClaims('ecopa-po', 'po@example.test', 'admin', ENTRY_DEFAULT_ORG, platformAdmin: true));
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'Seharusnya ditolak'])->assertConflict();

    entryLogin(entryClaims('ecopa-oo-aba', 'oo@aba.example.test', 'admin', ENTRY_ABA_ORG));
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'PT ABA Utama'])->assertCreated();
    $this->getJson('/api/v1/spa/workspaces')->assertStatus(400); // no active workspace chosen yet

    // Organisation 7's owner lists workspaces of organisation 7 only, and cannot pick organisation 1's.
    $entityB = Entity::withoutGlobalScopes()->where('name', 'PT ABA Utama')->value('id');
    $list = $this->withHeader('X-Tenant-Slug', $entityB)->getJson('/api/v1/spa/workspaces')->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$entityB]);
    $this->withHeader('X-Tenant-Slug', $this->entityA->id)->getJson('/api/v1/spa/workspaces')->assertNotFound();
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'Kedua'])->assertConflict();
});

it('keeps refusing a second organisation while multi-organisation mode is off', function () {
    config()->set('tenancy.multi_organization', false);

    entryLogin(entryClaims('ecopa-oo-aba', 'oo@aba.example.test', 'admin', ENTRY_ABA_ORG))->assertForbidden();
    entryLogin(entryClaims('ecopa-anggota-b', 'anggota-b@example.test', 'user', ENTRY_ABA_ORG))->assertForbidden();
    expect(Tenant::query()->where('ecopa_organization_id', '7')->exists())->toBeFalse()
        ->and(User::query()->where('main_tier_user_id', 'ecopa-oo-aba')->exists())->toBeFalse();
});

it('does not need a database per tenant and refuses a member without a local identity', function () {
    // Ecopa admits only people it knows; a member whose identity never arrived is turned away.
    entryLogin(entryClaims('ecopa-unknown', 'unknown@aba.example.test', 'user', ENTRY_ABA_ORG))->assertForbidden();

    // Creating the workspace made one tenant row and no physical database (no provisioner involved).
    entryLogin(entryClaims('ecopa-oo-aba', 'oo@aba.example.test', 'admin', ENTRY_ABA_ORG));
    $this->postJson('/api/v1/spa/organization-onboarding/workspace', ['name' => 'PT ABA'])->assertCreated();
    expect(Tenant::query()->where('ecopa_organization_id', '7')->value('provisioned_at'))->toBeNull()
        ->and(Tenant::query()->where('ecopa_organization_id', '7')->value('db_name'))->toBeNull();
});

it('carries the legacy installation completion onto existing tenants', function () {
    $migration = require database_path('migrations/2026_11_29_000100_add_onboarding_completed_at_to_tenants_table.php');
    DB::table('tenants')->update(['onboarding_completed_at' => null]);
    EcopaConfigIntegration::query()->create(['name' => 'installation_onboarding_completed_at', 'value' => '2026-08-31T00:00:00+00:00']);

    $migration->up();
    $migration->up(); // idempotent

    expect(Tenant::query()->find($this->tenantA->id)->onboarding_completed_at)->not->toBeNull();
});
