<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use Akunta\Rbac\Scopes\TenantScope;
use Akunta\Rbac\Support\TenantContext;
use App\Http\Middleware\VerifyEcopaSignature;
use App\Jobs\Middleware\RunInTenant;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Journal;
use App\Models\Period;
use App\Models\User;
use App\Services\AccountingPermissions;
use App\Support\Tenancy\EachTenant;
use App\Support\Tenancy\TenantCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Prompt stage 5: every request, job, and scheduled run works for ONE tenant
 * (Ecopa organisation), derived from the authenticated identity. Organisation B
 * can neither see nor reach organisation A. (The full two-organisation gate is
 * stage 8; these tests cover each point changed in stage 5.)
 */
beforeEach(function () {
    $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $this->operator = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);

    foreach (['a', 'b'] as $key) {
        $tenant = Tenant::query()->create(['name' => 'Org '.strtoupper($key), 'slug' => "org-{$key}-".uniqid(), 'status' => 'active', 'ecopa_organization_id' => "org-{$key}"]);
        $entity = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT '.strtoupper($key)]);
        $period = Period::query()->create(['entity_id' => $entity->id, 'name' => 'P', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $account = Account::query()->create(['entity_id' => $entity->id, 'code' => '1101', 'name' => 'Kas '.$key, 'type' => 'asset', 'normal_balance' => 'debit']);
        $journal = Journal::query()->create([
            'entity_id' => $entity->id, 'period_id' => $period->id, 'type' => 'general', 'number' => 'J-'.$key,
            'date' => '2026-02-01', 'idempotency_key' => 'shared-key',
        ]);
        $this->{"tenant_$key"} = $tenant;
        $this->{"entity_$key"} = $entity;
        $this->{"account_$key"} = $account;
        $this->{"journal_$key"} = $journal;
    }

    $this->admin = fn (string $name) => User::query()->create([
        'name' => $name, 'email' => Str::slug($name).'-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-'.Str::slug($name).'-'.uniqid(),
    ]);
    $this->session = fn (string $org, string $role = 'admin') => [
        'ecopa.app_role' => $role, 'ecopa.organization' => ['id' => $org, 'slug' => $org, 'name' => $org],
    ];
});

it('scopes tenant-owned models and workspaces to the current context only', function () {
    // Without a context (artisan, seeders) nothing is filtered.
    expect(Account::query()->count())->toBe(2)->and(Entity::query()->count())->toBe(2);

    TenantContext::run($this->tenant_a->id, function () {
        expect(Account::query()->pluck('id')->all())->toBe([$this->account_a->id])
            ->and(Journal::query()->pluck('id')->all())->toBe([$this->journal_a->id])
            ->and(Entity::query()->pluck('id')->all())->toBe([$this->entity_a->id])
            ->and(Entity::find($this->entity_b->id))->toBeNull()
            ->and(Account::find($this->account_b->id))->toBeNull()
            // The explicit cross-tenant path still exists and is visible in code.
            ->and(Account::withoutGlobalScope(TenantScope::class)->count())->toBe(2);
    });

    expect(TenantContext::id())->toBeNull(); // restored afterwards
});

it('derives the tenant from the signed organisation, and a header can only pick a workspace inside it', function () {
    $adminA = ($this->admin)('Admin A');
    $adminB = ($this->admin)('Admin B');

    $this->actingAs($adminA)->withSession(($this->session)('org-a'));
    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.tenants.0.id', $this->entity_a->id)
        ->assertJsonCount(1, 'data.tenants');

    $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/periods')->assertOk();
    // Naming the other organisation's workspace is refused, not replaced by a default.
    $this->withHeader('X-Tenant-Slug', $this->entity_b->id)->getJson('/api/v1/spa/periods')
        ->assertUnprocessable()->assertJsonValidationErrors('tenant');

    // The mirror image for organisation B.
    $this->actingAs($adminB)->withSession(($this->session)('org-b'));
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenants.0.id', $this->entity_b->id)->assertJsonCount(1, 'data.tenants');
    $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/periods')->assertUnprocessable();
});

it('refuses a session whose organisation has no tenant instead of serving it unscoped', function () {
    $this->actingAs(($this->admin)('Admin Z'))->withSession(($this->session)('org-zzz'));

    // It may ask where it stands (nothing visible, a state to show), but nothing else.
    $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(0, 'data.tenants')->assertJsonPath('data.organization.state', 'needs_workspace');
    $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/periods')
        ->assertForbidden()->assertJsonPath('message', 'Workspace organisasi Anda belum disiapkan. Hubungi Organization Owner.');
});

it('lets an application-wide assignment act only inside its own tenant', function () {
    $roleB = Role::query()->create(['tenant_id' => $this->tenant_b->id, 'code' => 'staf-b', 'name' => 'Staf B']);
    $user = ($this->admin)('Orang Dua Organisasi');
    // Application-wide assignment (no entity) that belongs to organisation B.
    UserAppAssignment::query()->create([
        'user_id' => $user->id, 'app_id' => $this->rbacApp->id, 'entity_id' => null, 'role_id' => $roleB->id, 'tenant_id' => $this->tenant_b->id, 'assigned_at' => now(),
    ]);

    // Signed into organisation A, the same person gets nothing from B's grant.
    $this->actingAs($user)->withSession(($this->session)('org-a', 'user'));
    $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(0, 'data.tenants');
    $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/periods')->assertStatus(422);

    // Signed into organisation B, the grant applies to B's workspace and to no other.
    $this->withSession(($this->session)('org-b', 'user'));
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenants.0.id', $this->entity_b->id)->assertJsonCount(1, 'data.tenants');

    expect($user->canAccessTenant($this->entity_a))->toBeFalse()
        ->and($user->fresh()->assignments()->count())->toBe(1);
});

it('binds Ecopa admin rights to the organisation of the session', function () {
    $admin = ($this->admin)('Admin Org A');
    $permissions = app(AccountingPermissions::class);

    $this->actingAs($admin)->withSession(($this->session)('org-a'));
    expect($permissions->canManage($admin, $this->entity_a))->toBeTrue()
        ->and($permissions->canManage($admin, $this->entity_b))->toBeFalse()
        ->and($admin->canAccessTenant($this->entity_a))->toBeTrue()
        ->and($admin->canAccessTenant($this->entity_b))->toBeFalse()
        ->and($admin->getTenants()->pluck('id')->all())->toBe([$this->entity_a->id]);

    // The same role claim under another organisation's session manages that organisation only.
    $this->withSession(($this->session)('org-b'));
    expect($permissions->canManage($admin, $this->entity_b))->toBeTrue()
        ->and($permissions->canManage($admin, $this->entity_a))->toBeFalse();
});

it('lists only the acting organisation in role management', function () {
    $app = $this->rbacApp;
    $memberA = ($this->admin)('Anggota A');
    $memberB = ($this->admin)('Anggota B');
    $waitingA = ($this->admin)('Menunggu A');
    $waitingB = ($this->admin)('Menunggu B');
    $roleB = Role::query()->create(['tenant_id' => $this->tenant_b->id, 'code' => 'staf-b', 'name' => 'Staf B']);

    UserAppAssignment::query()->create(['user_id' => $memberA->id, 'app_id' => $app->id, 'entity_id' => $this->entity_a->id, 'role_id' => $this->operator->id, 'assigned_at' => now()]);
    UserAppAssignment::query()->create(['user_id' => $memberB->id, 'app_id' => $app->id, 'entity_id' => $this->entity_b->id, 'role_id' => $this->operator->id, 'assigned_at' => now()]);
    // A tenant-wide role-bearing grant in B must not appear in A's list.
    UserAppAssignment::query()->create(['user_id' => $memberB->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => $roleB->id, 'tenant_id' => $this->tenant_b->id, 'assigned_at' => now()]);
    // Role-less Ecopa shadow records: who has access but no local role yet.
    UserAppAssignment::query()->create(['user_id' => $waitingA->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => null, 'tenant_id' => $this->tenant_a->id, 'assigned_at' => now()]);
    UserAppAssignment::query()->create(['user_id' => $waitingB->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => null, 'tenant_id' => $this->tenant_b->id, 'assigned_at' => now()]);

    $this->actingAs(($this->admin)('Admin A'))->withSession(($this->session)('org-a'));
    $response = $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/role-management')->assertOk();

    expect(collect($response->json('data.users'))->pluck('user_id')->all())->toBe([$memberA->id])
        ->and(collect($response->json('data.unassigned_users'))->pluck('user_id')->all())->toBe([$waitingA->id]);

    // And organisation B's admin may not manage organisation A's workspace at all.
    $this->actingAs(($this->admin)('Admin B'))->withSession(($this->session)('org-b'));
    $this->withHeader('X-Tenant-Slug', $this->entity_a->id)->getJson('/api/v1/spa/role-management')->assertUnprocessable();
});

function organizationEvent(string $event, array $subject)
{
    return test()->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => $event, 'event_id' => 'evt-'.Str::uuid(), 'subject' => $subject,
    ]);
}

it('attaches a webhook grant to the tenant of the named organisation only', function () {
    $subject = fn (string $org) => ['user_id' => 'ecopa-shared', 'email' => 'shared@example.test', 'name' => 'Shared', 'ecopa_role' => 'admin', 'organization' => ['id' => $org]];

    organizationEvent('user.assigned', $subject('org-a'))->assertOk()->assertJsonPath('code', 'user_access_assigned');
    organizationEvent('user.assigned', $subject('org-b'))->assertOk()->assertJsonPath('code', 'user_access_assigned');

    $user = User::query()->where('main_tier_user_id', 'ecopa-shared')->firstOrFail();
    $rows = UserAppAssignment::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('entity_id')->pluck('tenant_id')->sort()->values()->all();
    expect($rows)->toBe(collect([$this->tenant_a->id, $this->tenant_b->id])->sort()->values()->all());

    // Revoking in A leaves B's grant (and tenant B's tokens) alone.
    organizationEvent('app.access.revoked', $subject('org-a') + ['app' => ['slug' => 'accounting']])->assertOk();
    $byTenant = UserAppAssignment::withoutGlobalScopes()->where('user_id', $user->id)->pluck('revoked_at', 'tenant_id');
    expect($byTenant[$this->tenant_a->id])->not->toBeNull()->and($byTenant[$this->tenant_b->id])->toBeNull();
});

it('stores only the identity for an organisation without a tenant, and never creates or reuses another tenant', function () {
    $tenants = Tenant::query()->count();

    organizationEvent('user.assigned', ['user_id' => 'ecopa-new-org', 'email' => 'new@example.test', 'ecopa_role' => 'admin', 'organization' => ['id' => 'org-new']])
        ->assertOk()->assertJsonPath('code', 'organization_not_provisioned');

    $user = User::query()->where('main_tier_user_id', 'ecopa-new-org')->firstOrFail();
    expect(UserAppAssignment::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Tenant::query()->count())->toBe($tenants);

    // An entity of an organisation that has no tenant waits; it is not parked in "the first tenant".
    organizationEvent('entity.created', ['id' => (string) Str::ulid(), 'name' => 'PT Baru', 'organization' => ['id' => 'org-new']])
        ->assertStatus(409)->assertJsonPath('code', 'organization_not_provisioned');
    expect(Tenant::query()->count())->toBe($tenants);
});

it('mirrors an entity into its own organisation and refuses to move one between organisations', function () {
    $id = (string) Str::ulid();

    organizationEvent('entity.created', ['id' => $id, 'name' => 'PT Sinkron', 'organization' => ['id' => 'org-b']])->assertOk();
    expect(Entity::withoutGlobalScopes()->find($id)->tenant_id)->toBe($this->tenant_b->id);

    organizationEvent('entity.updated', ['id' => $id, 'name' => 'Dicoba pindah', 'organization' => ['id' => 'org-a']])
        ->assertStatus(422)->assertJsonPath('code', 'entity_tenant_mismatch');
    expect(Entity::withoutGlobalScopes()->find($id)->tenant_id)->toBe($this->tenant_b->id);

    // A per-entity grant cannot reach into another organisation's workspace either.
    $user = ($this->admin)('Anggota');
    organizationEvent('assignment.granted', [
        'user_id' => $user->main_tier_user_id, 'entity_id' => $this->entity_b->id, 'app_code' => 'accounting', 'ecopa_role' => 'member', 'organization' => ['id' => 'org-a'],
    ])->assertStatus(422)->assertJsonPath('code', 'entity_tenant_mismatch');
    expect(UserAppAssignment::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0);
});

it('reconciles Ecopa access per organisation without crossing tenants or touching roles', function () {
    config()->set('ecopa.url', 'https://ecopa.example.test');
    config()->set('ecopa.api_token', 'token');

    $inA = ($this->admin)('Di A');
    $inB = ($this->admin)('Di B');
    $inNone = ($this->admin)('Di Org Baru');
    $revokedB = ($this->admin)('Dicabut di B');
    $existing = UserAppAssignment::query()->create([
        'user_id' => $revokedB->id, 'app_id' => $this->rbacApp->id, 'entity_id' => null, 'role_id' => $this->operator->id,
        'tenant_id' => $this->tenant_b->id, 'ecopa_role' => 'member', 'assigned_at' => now(),
    ]);

    Http::fake([
        'ecopa.example.test/api/users*' => Http::response(['data' => [
            ['id' => $inA->main_tier_user_id, 'organization' => ['id' => 'org-a']],
            ['id' => $inB->main_tier_user_id, 'organization' => ['id' => 'org-b']],
            ['id' => $inNone->main_tier_user_id, 'organization' => ['id' => 'org-new']],
            ['id' => $revokedB->main_tier_user_id, 'organization' => ['id' => 'org-b']],
            ['id' => 'ecopa-never-provisioned', 'organization' => ['id' => 'org-a']],
        ], 'meta' => ['last_page' => 1]]),
        'ecopa.example.test/api/apps/accounting/accesses*' => Http::response(['data' => [
            ['user_id' => $inA->main_tier_user_id, 'revoked_at' => null],
            ['user_id' => $inB->main_tier_user_id, 'revoked_at' => null],
            ['user_id' => $inNone->main_tier_user_id, 'revoked_at' => null],
            ['user_id' => $revokedB->main_tier_user_id, 'revoked_at' => '2026-11-01T00:00:00+00:00'],
            ['user_id' => 'ecopa-never-provisioned', 'revoked_at' => null],
        ], 'meta' => ['last_page' => 1]]),
    ]);

    $tenants = Tenant::query()->count();
    $this->artisan('ecopa:reconcile')->assertSuccessful();

    $assignment = fn (User $user) => UserAppAssignment::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('entity_id')->get();
    expect($assignment($inA)->pluck('tenant_id')->all())->toBe([$this->tenant_a->id])
        ->and($assignment($inB)->pluck('tenant_id')->all())->toBe([$this->tenant_b->id])
        ->and($assignment($inNone))->toHaveCount(0)                 // organisation without a tenant: skipped
        ->and($assignment($revokedB)->first()->revoked_at)->not->toBeNull()
        ->and($existing->fresh()->role_id)->toBe($this->operator->id) // child-owned role untouched
        ->and(User::query()->where('main_tier_user_id', 'ecopa-never-provisioned')->exists())->toBeFalse()
        ->and(Tenant::query()->count())->toBe($tenants);
});

it('fails observably when Ecopa cannot be read during reconciliation', function () {
    config()->set('ecopa.url', 'https://ecopa.example.test');
    config()->set('ecopa.api_token', 'token');
    Http::fake(['ecopa.example.test/*' => Http::response(['message' => 'boom'], 500)]);

    $this->artisan('ecopa:reconcile')->assertFailed();
});

it('keeps an API token inside its own tenant, and idempotency keys per tenant', function () {
    [$token] = ApiToken::issue(['name' => 'T', 'tenant_id' => $this->tenant_a->id, 'user_id' => null, 'app_id' => $this->rbacApp->id, 'permissions' => ['journal.create', 'journal.post']]);

    // The token works for tenant A's context; tenant B's journal does not exist inside it.
    TenantContext::run((string) $token->getAttribute('tenant_id'), function () {
        expect(Entity::find($this->entity_b->id))->toBeNull()
            ->and(Journal::query()->where('idempotency_key', 'shared-key')->value('id'))->toBe($this->journal_a->id);
    });

    // beforeEach already stored the SAME key for both tenants: unique per (tenant, key), not global.
    expect(Journal::withoutGlobalScopes()->where('idempotency_key', 'shared-key')->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => Journal::query()->create([
        'entity_id' => $this->entity_a->id, 'period_id' => $this->journal_a->period_id, 'type' => 'general', 'number' => 'J-dup',
        'date' => '2026-02-02', 'idempotency_key' => 'shared-key',
    ])))->toThrow(QueryException::class);
});

it('runs scheduled work once per tenant in that tenant context and survives one tenant failing', function () {
    $seen = [];
    $failures = EachTenant::run(function (string $tenantId) use (&$seen): void {
        $seen[$tenantId] = TenantContext::id();
        if ($tenantId === $this->tenant_a->id) {
            throw new RuntimeException('A rusak');
        }
    });

    expect($seen)->toHaveKeys([$this->tenant_a->id, $this->tenant_b->id])
        ->and($seen[$this->tenant_a->id])->toBe($this->tenant_a->id)
        ->and($seen[$this->tenant_b->id])->toBe($this->tenant_b->id)
        ->and($failures)->toBe([$this->tenant_a->id => 'A rusak'])
        ->and(TenantContext::id())->toBeNull();
});

it('visits every tenant in the scheduled command and shows each one only its own journals', function () {
    foreach (['a', 'b'] as $key) {
        $this->{"journal_$key"}->forceFill(['status' => Journal::STATUS_POSTED, 'auto_reverse_on' => '2026-03-01'])->save();
    }

    Illuminate\Support\Facades\Artisan::call('accounting:run-auto-reversals', ['--date' => '2026-03-02', '--dry-run' => true]);
    $blocks = collect(explode('Tenant ', Illuminate\Support\Facades\Artisan::output()))->filter()->mapWithKeys(
        fn (string $block): array => [trim(strtok($block, "
")) => $block],
    );

    expect($blocks)->toHaveKeys([$this->tenant_a->id, $this->tenant_b->id])
        ->and($blocks[$this->tenant_a->id])->toContain($this->journal_a->id)->not->toContain($this->journal_b->id)
        ->and($blocks[$this->tenant_b->id])->toContain($this->journal_b->id)->not->toContain($this->journal_a->id)
        ->and(TenantContext::id())->toBeNull();
});

it('runs a queued job in the tenant it was dispatched for and never inherits another', function () {
    $middleware = new RunInTenant;
    $inside = fn (object $job) => $middleware->handle($job, fn () => TenantContext::id());

    expect($inside((object) ['tenantId' => $this->tenant_a->id]))->toBe($this->tenant_a->id)
        ->and($inside((object) ['tenantId' => $this->tenant_b->id]))->toBe($this->tenant_b->id)
        ->and($inside((object) ['tenantId' => null]))->toBeNull()
        ->and(TenantContext::id())->toBeNull();

    // A previous job's context is not leaked into a tenant-less one.
    TenantContext::set($this->tenant_a->id);
    expect($inside((object) ['tenantId' => null]))->toBeNull()
        ->and(TenantContext::id())->toBe($this->tenant_a->id);
    TenantContext::clear();

    // The jobs carry their tenant in the payload when dispatched without a context.
    $job = new App\Jobs\PurgeArchivedWorkspace($this->entity_b->id);
    expect($job->tenantId)->toBe($this->tenant_b->id);
});

it('namespaces cache keys per tenant and refuses a tenant key without context', function () {
    expect(TenantCache::key('summary', $this->tenant_a->id))->toBe("tenant:{$this->tenant_a->id}:summary")
        ->and(fn () => TenantCache::key('summary'))->toThrow(LogicException::class);

    TenantContext::run($this->tenant_b->id, fn () => expect(TenantCache::key('summary'))->toBe("tenant:{$this->tenant_b->id}:summary"));
});

it('refuses an API token that names a workspace of another organisation and never reveals it', function () {
    [, $plain] = ApiToken::issue(['name' => 'Bot A', 'tenant_id' => $this->tenant_a->id, 'user_id' => null, 'app_id' => $this->rbacApp->id, 'permissions' => ['journal.create', 'journal.post']]);
    $payload = fn (string $entityId) => [
        'entity_id' => $entityId, 'date' => '2026-02-10', 'metadata' => ['source_app' => 'accounting'],
        'lines' => [['account_code' => '1101', 'debit' => 10, 'credit' => 0], ['account_code' => '1101', 'debit' => 0, 'credit' => 10]],
        'idempotency_key' => 'token-key',
    ];

    // Organisation B's workspace does not exist for A's token — same answer as a made-up id.
    $foreign = $this->withToken($plain)->postJson('/api/v1/journals', $payload($this->entity_b->id));
    $unknown = $this->withToken($plain)->postJson('/api/v1/journals', $payload((string) Str::ulid()));
    expect($foreign->status())->toBe(422)->and($foreign->json())->toBe($unknown->json())
        ->and(Journal::withoutGlobalScopes()->where('entity_id', $this->entity_b->id)->count())->toBe(1); // only the fixture's journal
    expect(TenantContext::id())->toBeNull();
});
