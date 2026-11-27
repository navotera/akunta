<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Middleware\VerifyEcopaSignature;
use App\Models\EcopaWebhookLog;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prompt stage 3: the Platform Owner sees usage metadata and aggregates per
 * organisation - never transaction content.
 */
const USAGE_SECRETS = ['RAHASIA-MEMO', 'JRN-RAHASIA', 'Akun Rahasia', 'RAHASIA-META', 'rahasia-aktor@example.test', '203.0.113.9'];

beforeEach(function () {
    $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $this->role = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);

    $this->po = User::query()->create([
        'name' => 'Platform Owner', 'email' => 'po-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-po-'.uniqid(), 'email_verified_at' => now(),
    ]);

    // Organisation A: linked, two workspaces (one archived), journals in both books.
    $this->tenantA = Tenant::query()->create(['name' => 'PT Alpha', 'slug' => 'alpha', 'ecopa_organization_id' => 'org-a']);
    $this->entityA = Entity::query()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Alpha Utama']);
    Entity::query()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Alpha Lama', 'is_active' => false, 'archived_at' => now()]);
    // The practice sandbox is not usage.
    $this->fakeA = Entity::query()->create(['tenant_id' => $this->tenantA->id, 'name' => 'PT. Fake Data', 'is_fake_data' => true]);

    // Organisation B: linked, nothing else.
    $this->tenantB = Tenant::query()->create(['name' => 'PT Beta', 'slug' => 'beta', 'ecopa_organization_id' => 'org-b']);
    $this->entityB = Entity::query()->create(['tenant_id' => $this->tenantB->id, 'name' => 'Beta Utama']);

    $this->loginA = now()->subDays(2)->startOfSecond();
    foreach ([['A', 2], ['B', 1]] as [$org, $count]) {
        $tenant = $org === 'A' ? $this->tenantA : $this->tenantB;
        $entity = $org === 'A' ? $this->entityA : $this->entityB;
        for ($i = 0; $i < $count; $i++) {
            $user = User::query()->create([
                'name' => "Anggota $org$i", 'email' => "anggota-$org$i-".uniqid().'@example.test',
                'main_tier_user_id' => 'ecopa-'.uniqid(), 'email_verified_at' => now(),
            ]);
            $user->forceFill(['last_login_at' => $i === 0 ? $this->loginA : now()->subDays(20)])->save();
            UserAppAssignment::query()->create([
                'user_id' => $user->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $entity->id,
                'role_id' => $this->role->id, 'assigned_at' => now(),
            ]);
        }
    }
    // A disabled member and a revoked assignment do not count as active users.
    $gone = User::query()->create([
        'name' => 'Nonaktif', 'email' => 'nonaktif-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-'.uniqid(), 'email_verified_at' => now(),
    ]);
    $gone->forceFill(['disabled_at' => now()])->save();
    UserAppAssignment::query()->create([
        'user_id' => $gone->id, 'app_id' => $this->rbacApp->id, 'entity_id' => $this->entityA->id,
        'role_id' => $this->role->id, 'assigned_at' => now(),
    ]);
});

function usageJournal(Entity $entity, string $mode, string $memo = 'RAHASIA-MEMO', ?Carbon\Carbon $createdAt = null): void
{
    static $period = [];
    $period[$entity->id] ??= (function () use ($entity): string {
        $id = (string) Str::ulid();
        DB::table('periods')->insert([
            'id' => $id, 'tenant_id' => $entity->tenant_id, 'entity_id' => $entity->id, 'name' => 'Periode', 'start_date' => '2026-01-01',
            'end_date' => '2026-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    })();

    DB::table('journals')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => $entity->tenant_id, 'entity_id' => $entity->id, 'period_id' => $period[$entity->id],
        'type' => 'general', 'journal_mode' => $mode, 'number' => 'JRN-RAHASIA-'.Str::random(6), 'date' => '2026-02-01',
        'memo' => $memo, 'status' => 'draft', 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
    ]);
}

function usageAudit(Entity $entity, string $action): void
{
    DB::table('audit_log')->insert([
        'id' => (string) Str::ulid(), 'actor_user_id' => null, 'action' => $action, 'resource_type' => 'x',
        'resource_id' => 'x', 'entity_id' => $entity->id, 'metadata' => json_encode(['note' => 'RAHASIA-META', 'who' => 'rahasia-aktor@example.test']),
        'ip_address' => '203.0.113.9', 'user_agent' => 'RAHASIA-META', 'created_at' => now(),
    ]);
}

function usageOrganization(array $data, string $slug): array
{
    return collect($data['organizations'])->firstWhere('slug', $slug);
}

it('shows the Platform Owner aggregates for every organisation', function () {
    usageJournal($this->entityA, 'internal');
    usageJournal($this->entityA, 'internal');
    usageJournal($this->entityA, 'fiscal');
    usageJournal($this->entityA, 'internal', createdAt: now()->subMonths(2));
    usageJournal($this->entityA, 'internal', createdAt: now()->subMonths(9)); // outside the six months
    usageJournal($this->fakeA, 'internal'); // sandbox
    usageJournal($this->entityB, 'fiscal');
    DB::table('journals')->where('entity_id', $this->entityB->id)->update(['deleted_at' => now()]); // trashed journals are not counted

    usageAudit($this->entityA, 'workspace.archive');
    usageAudit($this->entityA, 'workspace.archive');
    usageAudit($this->entityA, 'permission.role_created');
    usageAudit($this->entityA, 'journal.updated'); // business action, never reported
    usageAudit($this->entityB, 'account.deleted');

    $log = fn (?string $org, string $outcome, $at) => EcopaWebhookLog::query()->create([
        'event' => 'user.updated', 'ecopa_organization_id' => $org, 'outcome' => $outcome, 'http_status' => 200,
        'received_at' => $at, 'completed_at' => $at,
    ]);
    $log('org-a', 'processed', now()->subDay());
    $log('org-a', 'processed', now()->subHours(2));
    $log('org-a', 'rejected', now()->subDays(3));
    $log('org-b', 'error', now()->subHour());
    $log(null, 'unauthorized', now()->subHour());
    $log('org-zzz', 'processed', now()->subHour());
    $log('org-a', 'processed', now()->subDays(45)); // outside the window

    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'x', 'queue' => 'default', 'payload' => '{"RAHASIA":1}', 'exception' => 'RAHASIA-META', 'failed_at' => now()->subDay()],
        ['uuid' => (string) Str::uuid(), 'connection' => 'x', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(60)],
    ]);
    DB::table('cron_run_logs')->insert([
        'id' => (string) Str::ulid(), 'command' => 'x', 'started_at' => now()->subDay(), 'failed' => true, 'exception' => 'RAHASIA-META',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->po)->withSession(platformOwnerSession($this->po, ['ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'PT Alpha']]));
    $response = $this->getJson('/api/v1/spa/platform/usage')->assertOk();
    $data = $response->json('data');

    $alpha = usageOrganization($data, 'alpha');
    $beta = usageOrganization($data, 'beta');

    expect($data['window_days'])->toBe(30)
        ->and($data['journal_months'])->toHaveCount(6)
        ->and($alpha['linked'])->toBeTrue()
        ->and($alpha['ecopa_organization_id'])->toBe('org-a')
        ->and($alpha['active_users'])->toBe(2)
        ->and($beta['active_users'])->toBe(1)
        ->and(Carbon\Carbon::parse($alpha['last_login_at'])->timestamp)->toBe($this->loginA->timestamp)
        ->and($alpha['workspaces'])->toBe(['active' => 1, 'archived' => 1])
        ->and($beta['workspaces'])->toBe(['active' => 1, 'archived' => 0]);

    $months = collect($alpha['journals'])->keyBy('month');
    expect($months[now()->format('Y-m')])->toMatchArray(['internal' => 2, 'fiscal' => 1])
        ->and($months[now()->subMonths(2)->format('Y-m')])->toMatchArray(['internal' => 1, 'fiscal' => 0])
        ->and(collect($alpha['journals'])->sum(fn ($m) => $m['internal'] + $m['fiscal']))->toBe(4)
        ->and(collect($beta['journals'])->sum(fn ($m) => $m['internal'] + $m['fiscal']))->toBe(0);

    expect(collect($alpha['setting_activity'])->pluck('count', 'action')->all())->toBe([
        'permission.role_created' => 1,
        'workspace.archive' => 2,
    ])->and($beta['setting_activity'])->toBe([]);

    expect($alpha['webhooks']['total'])->toBe(3)
        ->and($alpha['webhooks']['by_outcome'])->toBe(['processed' => 2, 'rejected' => 1])
        ->and($beta['webhooks']['by_outcome'])->toBe(['error' => 1]);

    expect($data['installation']['webhooks']['total'])->toBe(6)
        ->and($data['installation']['webhooks']['unattributed'])->toBe(2)
        ->and($data['installation']['queue'])->toMatchArray(['failed_jobs' => 2, 'failed_jobs_in_window' => 1])
        ->and($data['installation']['cron'])->toMatchArray(['failed_runs_in_window' => 1]);
});

it('never returns transaction content, audit metadata, or personal data', function () {
    usageJournal($this->entityA, 'internal');
    usageAudit($this->entityA, 'workspace.archive');
    usageAudit($this->entityA, 'journal.updated');
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'x', 'queue' => 'default', 'payload' => '{"memo":"RAHASIA-MEMO"}',
        'exception' => 'RAHASIA-META', 'failed_at' => now(),
    ]);
    EcopaWebhookLog::query()->create([
        'event' => 'user.updated', 'ecopa_organization_id' => 'org-a', 'subject_reference' => 'user_id:rahasia-aktor@example.test',
        'outcome' => 'error', 'message' => 'RAHASIA-META', 'http_status' => 500, 'received_at' => now(), 'completed_at' => now(),
    ]);
    DB::table('accounts')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantA->id, 'entity_id' => $this->entityA->id, 'code' => '1-9999', 'name' => 'Akun Rahasia',
        'type' => 'asset', 'normal_balance' => 'debit', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->po)->withSession(platformOwnerSession($this->po, ['ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'PT Alpha']]));
    $body = $this->getJson('/api/v1/spa/platform/usage')->assertOk()->getContent();

    foreach (USAGE_SECRETS as $secret) {
        expect($body)->not->toContain($secret);
    }
    // The member e-mail and names are personal data that are not approved either.
    expect($body)->not->toContain('@example.test')->not->toContain('Anggota');

    $organization = usageOrganization(json_decode($body, true)['data'], 'alpha');
    expect(array_keys($organization))->toBe([
        'tenant_id', 'name', 'slug', 'status', 'ecopa_organization_id', 'linked', 'last_login_at',
        'active_users', 'workspaces', 'journals', 'webhooks', 'setting_activity',
    ])->and(array_keys($organization['webhooks']))->toBe(['total', 'by_outcome', 'last_received_at'])
        ->and(array_keys($organization['journals'][0]))->toBe(['month', 'internal', 'fiscal'])
        ->and(array_keys($organization['setting_activity'][0]))->toBe(['action', 'count', 'last_at']);
});

it('refuses everyone but the Platform Owner', function (string $who) {
    $session = match ($who) {
        'Organization Owner' => ['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false],
        'session without claim' => ['ecopa.app_role' => 'admin'],
        'impersonating Platform Owner' => [...platformOwnerSession($this->po, ['ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'PT Alpha']]), 'impersonator_id' => $this->po->id],
    };
    $this->actingAs($this->po)->withSession($session);

    $this->getJson('/api/v1/spa/platform/usage')->assertForbidden();
    expect(PlatformAuditLog::query()->where('action', 'platform.usage_viewed')->count())->toBe(0);
})->with(['Organization Owner', 'session without claim', 'impersonating Platform Owner']);

it('refuses a guest', function () {
    $this->getJson('/api/v1/spa/platform/usage')->assertUnauthorized();
});

it('records each Platform Owner view in the platform audit log', function () {
    $this->actingAs($this->po)->withSession(platformOwnerSession($this->po, ['ecopa.organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'PT Alpha']]));
    $this->getJson('/api/v1/spa/platform/usage')->assertOk();

    $row = PlatformAuditLog::query()->where('action', 'platform.usage_viewed')->sole();
    expect($row->actor_user_id)->toBe($this->po->id)->and($row->actor_kind)->toBe('platform_owner');
});

it('tags Ecopa webhook deliveries with their organisation', function (array $subject, ?string $expected) {
    $this->withoutMiddleware(VerifyEcopaSignature::class)->postJson('/webhooks/ecopa', [
        'event' => 'user.updated', 'event_id' => 'evt-'.uniqid(), 'subject' => $subject,
    ]);

    expect(EcopaWebhookLog::query()->latest('received_at')->first()->ecopa_organization_id)->toBe($expected);
})->with([
    'subject organization' => [['id' => 'u1', 'organization' => ['id' => 'org-a', 'slug' => 'a', 'name' => 'A']], 'org-a'],
    'one shared admin organization' => [['admins' => [['organization' => ['id' => 'org-b']], ['organization' => ['id' => 'org-b']]]], 'org-b'],
    'mixed admin organizations stay unidentified' => [['admins' => [['organization' => ['id' => 'org-a']], ['organization' => ['id' => 'org-b']]]], null],
    'no organization' => [['id' => 'u1'], null],
]);
