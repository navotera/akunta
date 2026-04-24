<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Actions\ProvisionTenantAction;
use App\DTO\ProvisionResult;
use App\Exceptions\ProvisionException;
use App\Models\Account;
use App\Models\ApiToken;
use Database\Seeders\PresetRolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

function provisionInput(array $overrides = []): array
{
    return array_merge([
        'slug' => 'acme',
        'name' => 'Acme Corp',
        'admin_email' => 'admin+'.uniqid().'@acme.test',
    ], $overrides);
}

it('provisions tenant + entity + COA + preset roles + admin user + API token in one shot', function () {
    $result = app(ProvisionTenantAction::class)->execute(provisionInput([
        'slug' => 'acme',
        'name' => 'Acme Corp',
        'plan' => 'basic',
        'legal_form' => 'PT',
        'admin_email' => 'admin@acme.test',
    ]));

    expect($result)->toBeInstanceOf(ProvisionResult::class);

    $tenant = $result->tenant;
    expect($tenant->status)->toBe(Tenant::STATUS_ACTIVE)
        ->and($tenant->slug)->toBe('acme')
        ->and($tenant->db_name)->toBe('tenant_'.$tenant->id)
        ->and($tenant->provisioned_at)->not->toBeNull();

    $entity = $result->entity;
    expect($entity->tenant_id)->toBe($tenant->id)
        ->and(Account::where('entity_id', $entity->id)->count())->toBe(46);

    expect(Role::whereNull('tenant_id')->where('is_preset', true)->count())
        ->toBe(count(PresetRolesSeeder::ROLES));

    $admin = $result->adminUser;
    expect($admin->email)->toBe('admin@acme.test')
        ->and(strlen($result->adminPasswordPlain))->toBe(16)
        ->and(Hash::check($result->adminPasswordPlain, $admin->password_hash))->toBeTrue();

    $app = RbacApp::where('code', 'accounting')->first();
    expect($app)->not->toBeNull();

    $assignment = UserAppAssignment::where('user_id', $admin->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->app_id)->toBe($app->id)
        ->and($assignment->entity_id)->toBe($entity->id);

    $role = Role::find($assignment->role_id);
    expect($role->code)->toBe('super_admin');

    $token = $result->apiToken;
    expect($token->user_id)->toBe($admin->id)
        ->and($token->app_id)->toBe($app->id)
        ->and($token->permissions)->toBe(['journal.create', 'journal.post'])
        ->and($token->isActive())->toBeTrue()
        ->and($result->apiTokenPlain)->toStartWith(ApiToken::PREFIX)
        ->and(ApiToken::findByPlain($result->apiTokenPlain)?->id)->toBe($token->id);

    $audit = AuditLog::where('action', 'tenant.provision')->where('resource_id', $tenant->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->metadata['admin_user_id'] ?? null)->toBe($admin->id)
        ->and($audit->metadata['api_token_id'] ?? null)->toBe($token->id);
});

it('rejects duplicate slug', function () {
    app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 'dup', 'admin_email' => 'a@dup.test']));

    expect(fn () => app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 'dup', 'admin_email' => 'b@dup.test'])))
        ->toThrow(ProvisionException::class, 'already exists');

    expect(Tenant::where('slug', 'dup')->count())->toBe(1);
});

it('rejects duplicate admin email across tenants', function () {
    app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 't1', 'admin_email' => 'reuse@test.test']));

    expect(fn () => app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 't2', 'admin_email' => 'reuse@test.test'])))
        ->toThrow(ProvisionException::class, 'already registered');

    expect(Tenant::where('slug', 't2')->count())->toBe(0)
        ->and(User::where('email', 'reuse@test.test')->count())->toBe(1);
});

it('reuses existing accounting app row across multiple provisions', function () {
    app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 't1', 'admin_email' => 'a@t1.test']));
    app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 't2', 'admin_email' => 'a@t2.test']));

    expect(RbacApp::where('code', 'accounting')->count())->toBe(1);
});

it('fires tenant.before_provision and tenant.after_provision hooks', function () {
    $fired = [];
    Event::listen(HookCatalog::TENANT_BEFORE_PROVISION, function ($input) use (&$fired) {
        $fired[] = 'before:'.$input['slug'];
    });
    Event::listen(HookCatalog::TENANT_AFTER_PROVISION, function ($tenant) use (&$fired) {
        $fired[] = 'after:'.$tenant->status.':'.$tenant->slug;
    });

    app(ProvisionTenantAction::class)->execute(provisionInput(['slug' => 'hooktest', 'admin_email' => 'hook@t.test']));

    expect($fired)->toBe(['before:hooktest', 'after:active:hooktest']);
});

it('accepts custom token_permissions override', function () {
    $result = app(ProvisionTenantAction::class)->execute(provisionInput([
        'slug' => 'custom-perms',
        'admin_email' => 'cp@test.test',
        'token_permissions' => ['journal.create', 'journal.post', 'journal.reverse'],
    ]));

    expect($result->apiToken->permissions)->toBe(['journal.create', 'journal.post', 'journal.reverse']);
});

it('uses tenant name as default entity name when entity_name not provided', function () {
    $result = app(ProvisionTenantAction::class)->execute(provisionInput([
        'slug' => 'defaults',
        'name' => 'Default Co',
        'admin_email' => 'd@default.test',
    ]));

    expect($result->entity->name)->toBe('Default Co');
});

it('bootstrap token posts a valid journal to /api/v1/journals end-to-end', function () {
    $result = app(ProvisionTenantAction::class)->execute(provisionInput([
        'slug' => 'e2e',
        'admin_email' => 'e2e@test.test',
    ]));

    $period = App\Models\Period::create([
        'entity_id' => $result->entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$result->apiTokenPlain)
        ->postJson('/api/v1/journals', [
            'entity_id' => $result->entity->id,
            'date' => '2026-04-15',
            'reference' => 'E2E-1',
            'metadata' => ['source_app' => 'accounting'],
            'lines' => [
                ['account_code' => '1101', 'debit' => 10000, 'credit' => 0],
                ['account_code' => '4101', 'debit' => 0, 'credit' => 10000],
            ],
        ]);

    $response->assertStatus(201);
    expect($response->json('status'))->toBe('posted');
});
