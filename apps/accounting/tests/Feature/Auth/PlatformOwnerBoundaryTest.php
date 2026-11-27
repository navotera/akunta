<?php

declare(strict_types=1);

use Akunta\Audit\Exceptions\ImmutableAuditException;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\EcopaConfigIntegration;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\AccountingPermissions;
use App\Services\PlatformAuditLogger;

/**
 * Prompt stage 2c–2g: installation-level endpoints answer only to the Platform
 * Owner; Organization Owner endpoints stay open to the Organization Owner.
 */
beforeEach(function () {
    $this->tenant = Tenant::query()->create(['name' => 'Boundary', 'slug' => 'boundary-'.uniqid()]);
    $this->entity = Entity::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'PT Boundary', 'is_fake_data' => true]);
    $this->archived = Entity::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'PT Arsip', 'is_active' => false, 'archived_at' => now(),
    ]);
    $this->rbacApp = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    EcopaConfigIntegration::query()->create(['name' => 'integration_status', 'value' => 'on']);

    $this->user = User::query()->create([
        'name' => 'Admin Boundary',
        'email' => 'boundary-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-boundary-'.uniqid(),
        'email_verified_at' => now(),
    ]);
});

/** @return array<string, array{0: string, 1: string}> */
function platformOwnerEndpoints(): array
{
    return [
        'scheduler status' => ['GET', '/api/v1/spa/scheduler/status'],
        'platform usage' => ['GET', '/api/v1/spa/platform/usage'],
        'branding update' => ['POST', '/api/v1/spa/platform/branding'],
        'Ecopa integration status' => ['GET', '/api/v1/spa/ecopa-integration'],
        'Ecopa webhook logs' => ['GET', '/api/v1/spa/ecopa-integration/webhook-logs'],
        'fake data overview' => ['GET', '/api/v1/spa/fake-data'],
        'fake data reset preview' => ['GET', '/api/v1/spa/fake-data/reset-preview'],
        'fake data reset' => ['POST', '/api/v1/spa/fake-data/reset'],
        'fake data import' => ['POST', '/api/v1/spa/fake-data/accounts/import'],
        'fake data clear' => ['DELETE', '/api/v1/spa/fake-data/accounts'],
        'installation wizard' => ['POST', '/api/v1/spa/installation-onboarding/entity'],
        'workspace purge' => ['DELETE', '/api/v1/spa/workspaces/{archived}/permanent'],
    ];
}

function callBoundary($test, string $method, string $uri)
{
    return $test->withHeader('X-Tenant-Slug', $test->entity->id)
        ->json($method, str_replace('{archived}', $test->archived->id, $uri));
}

/** A workspace role holding every catalog permission plus workspace.manage. */
function grantEverything($test): void
{
    $role = Role::query()->create(['tenant_id' => $test->tenant->id, 'code' => 'semua-'.uniqid(), 'name' => 'Semua Izin']);
    foreach ([...array_column(app(AccountingPermissions::class)->catalog(), 'code'), 'workspace.manage', 'settings.cron.manage', 'settings.fake_data.manage'] as $code) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['app_id' => $test->rbacApp->id, 'code' => $code])->id);
    }
    UserAppAssignment::query()->create([
        'user_id' => $test->user->id, 'app_id' => $test->rbacApp->id, 'entity_id' => $test->entity->id, 'role_id' => $role->id,
    ]);
}

it('refuses installation-level endpoints to anyone but the Platform Owner', function (string $method, string $uri, string $who) {
    $session = match ($who) {
        'Organization Owner' => ['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false],
        'session without claim' => ['ecopa.app_role' => 'admin'],
        'custom role with every permission' => ['ecopa.app_role' => 'user'],
        'impersonating Platform Owner' => [...platformOwnerSession($this->user), 'impersonator_id' => $this->user->id],
    };
    if ($who === 'custom role with every permission') {
        grantEverything($this);
    }

    $this->actingAs($this->user)->withSession($session);

    callBoundary($this, $method, $uri)->assertForbidden();
})->with(platformOwnerEndpoints())->with([
    'Organization Owner',
    'session without claim',
    'custom role with every permission',
    'impersonating Platform Owner',
]);

it('lets the Platform Owner through the same endpoints', function (string $method, string $uri) {
    $this->actingAs($this->user)->withSession(platformOwnerSession($this->user));

    // Past the gate the endpoints answer with their own business rules
    // (validation, conflicts, …), never with the Platform Owner refusal.
    expect(callBoundary($this, $method, $uri)->status())->not->toBe(403);
})->with(platformOwnerEndpoints());

it('keeps Organization Owner endpoints open to the Organization Owner', function (string $uri) {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson($uri)
        ->assertOk();
})->with([
    'workspaces' => '/api/v1/spa/workspaces',
    'periods' => '/api/v1/spa/periods',
    'permission management' => '/api/v1/spa/permission-management',
    'role management' => '/api/v1/spa/role-management',
    'outbound webhooks' => '/api/v1/spa/webhooks',
    'auto mapping' => '/api/v1/spa/auto-mapping',
    'recurring journals' => '/api/v1/spa/recurring-journals',
    'journal templates' => '/api/v1/spa/journal-templates',
    'installation status' => '/api/v1/spa/installation-onboarding/status',
]);

it('treats the standalone installation super admin as the Platform Owner', function () {
    EcopaConfigIntegration::query()->where('name', 'integration_status')->delete();
    $superAdmin = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'super_admin'], ['name' => 'Super Admin', 'is_preset' => true]);
    UserAppAssignment::query()->create([
        'user_id' => $this->user->id, 'app_id' => $this->rbacApp->id, 'entity_id' => null, 'role_id' => $superAdmin->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/scheduler/status')
        ->assertOk();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_platform_admin', true);
});

it('reports the Platform Owner status in the auth bootstrap without letting impersonation carry it', function () {
    $this->actingAs($this->user)->withSession(platformOwnerSession($this->user))
        ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_platform_admin', true);

    $this->withSession(['impersonator_id' => $this->user->id])
        ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_platform_admin', false);
});

it('requires the Platform Owner to register Ecopa again once the integration is configured', function () {
    // Integration is on: anonymous and Organization Owner requests are refused.
    $this->postJson('/api/auth/ecopa-registration', [
        'ecopa_url' => 'https://ecopa.example.test', 'registration_token' => 'token-12345',
    ])->assertForbidden();
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false])
        ->postJson('/api/auth/ecopa-registration', [
            'ecopa_url' => 'https://ecopa.example.test', 'registration_token' => 'token-12345',
        ])->assertForbidden();

    // The Platform Owner passes the gate (an invalid body then fails validation).
    $this->actingAs($this->user)
        ->withSession(platformOwnerSession($this->user))
        ->postJson('/api/auth/ecopa-registration', [])
        ->assertUnprocessable();
});

it('keeps platform audit rows append-only and never stores secret values', function () {
    $this->actingAs($this->user)->withSession(platformOwnerSession($this->user));
    $row = app(PlatformAuditLogger::class)->record('ecopa.registration_requested', 'ecopa_integration', null, [
        'ecopa_url' => 'https://ecopa.example.test',
        'registration_token' => 'plain-token',
        'nested' => ['client_secret' => 'plain-secret', 'keep' => 'ok'],
    ]);

    expect($row->actor_kind)->toBe('platform_owner')
        ->and($row->actor_user_id)->toBe($this->user->id)
        ->and($row->metadata['registration_token'])->toBe('[redacted]')
        ->and($row->metadata['nested']['client_secret'])->toBe('[redacted]')
        ->and($row->metadata['nested']['keep'])->toBe('ok')
        ->and(json_encode(PlatformAuditLog::query()->sole()->metadata))->not->toContain('plain-');

    expect(fn () => $row->update(['action' => 'x']))->toThrow(ImmutableAuditException::class)
        ->and(fn () => $row->delete())->toThrow(ImmutableAuditException::class);
});
