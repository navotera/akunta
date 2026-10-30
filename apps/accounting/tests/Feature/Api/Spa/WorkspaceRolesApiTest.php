<?php

declare(strict_types=1);

use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use App\Models\User;
use App\Services\AccountingPermissions;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Workspace roles', 'slug' => 'roles-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Workspace A']);
    $this->otherEntity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Workspace B']);
    $this->rbacApp = RbacApp::firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'enabled' => true, 'version' => '1']);
    foreach (['admin', 'operator', 'supervisor', 'inspector'] as $code) {
        $role = Role::firstOrCreate(['code' => $code, 'tenant_id' => null], ['name' => ucfirst($code), 'is_preset' => true]);
        $this->{$code} = User::create(['name' => $code, 'email' => uniqid().$code.'@test.local', 'password_hash' => bcrypt('x')]);
        $this->{$code}->assignments()->create(['app_id' => $this->rbacApp->id, 'entity_id' => $this->entity->id, 'role_id' => $role->id]);
    }
});

function workspaceRoleSnapshot($test): array
{
    return $test->actingAs($test->admin)->withHeader('X-Tenant-Slug', $test->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertOk()->json('data');
}

function createWorkspaceRole($test, string $name = 'Accounting Assistant', ?string $base = 'operator'): array
{
    $snapshot = workspaceRoleSnapshot($test);

    return $test->postJson('/api/v1/spa/permission-management/roles', [
        'name' => $name, 'base_role' => $base, 'revision' => $snapshot['revision'],
    ])->assertCreated()->json('data');
}

it('applies every catalog grant and revoke to bootstrap and Gates within the assigned workspace', function (string $preset) {
    $snapshot = workspaceRoleSnapshot($this);
    $code = $preset;
    $actor = $this->{$preset === 'custom' || $preset === 'inspector_copy' ? 'operator' : $preset};
    if (in_array($preset, ['custom', 'inspector_copy'], true)) {
        $snapshot = createWorkspaceRole($this, 'Matrix role', $preset === 'inspector_copy' ? 'inspector' : null);
        $code = $snapshot['created_role'];
        $definition = collect($snapshot['roles'])->firstWhere('code', $code);
        $actor->assignments()->first()->update(['role_id' => $definition['id']]);
    }
    $roleId = $actor->assignments()->first()->role_id;
    $actor->assignments()->create(['app_id' => $this->rbacApp->id, 'entity_id' => $this->otherEntity->id, 'role_id' => $roleId]);
    $catalog = array_column(app(AccountingPermissions::class)->catalog(), 'code');
    $grants = in_array($preset, ['inspector', 'inspector_copy'], true)
        ? config('accounting_permissions.inspector_permissions') : $catalog;
    $otherBefore = app(AccountingPermissions::class)->roleCodes($this->otherEntity, $code);
    foreach ([$grants, []] as $permissions) {
        $snapshot = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
            ->putJson('/api/v1/spa/permission-management/'.$code, [
                'permissions' => $permissions, 'revision' => $snapshot['revision'],
            ])->assertOk()->json('data');
        $tenants = collect($this->actingAs($actor)->getJson('/api/v1/me')->assertOk()->json('data.tenants'))->keyBy('id');
        expect($tenants[$this->entity->id]['permissions'])->toEqualCanonicalizing($permissions);
        foreach ($catalog as $permission) {
            expect(Gate::forUser($actor)->allows('accounting.permission', [$permission, $this->entity->fresh()]))
                ->toBe(in_array($permission, $permissions, true));
        }
        expect(app(AccountingPermissions::class)->roleCodes($this->otherEntity->fresh(), $code))->toBe($otherBefore);
    }
    $account = Account::create([
        'entity_id' => $this->entity->id, 'code' => 'MATRIX', 'name' => 'Matrix account',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true, 'is_postable' => true,
    ]);
    // A raw request must be denied across every configurable feature family after revocation.
    foreach ([
        ['GET', 'accounts'], ['POST', 'accounts'], ['PATCH', 'accounts/'.$account->id], ['DELETE', 'accounts/'.$account->id],
        ['GET', 'journal-templates'], ['POST', 'journal-templates'], ['PATCH', 'journal-templates/'.$this->entity->id], ['DELETE', 'journal-templates/'.$this->entity->id], ['PATCH', 'journal-templates/'.$this->entity->id.'/bookmark'],
        ['GET', 'journals'], ['POST', 'journals'], ['PATCH', 'journals/'.$this->entity->id], ['DELETE', 'journals/'.$this->entity->id],
        ['GET', 'periods'], ['POST', 'periods'], ['PATCH', 'periods/'.$this->entity->id], ['DELETE', 'periods/'.$this->entity->id],
        ['GET', 'recurring-journals'], ['POST', 'recurring-journals'], ['GET', 'auto-mapping'],
        ['GET', 'reports/trial-balance'], ['GET', 'reports/balance-sheet'], ['GET', 'reports/income-statement'], ['GET', 'reports/general-ledger'], ['GET', 'reports/fiscal-reconciliation'],
        ['GET', 'fiscal-adjustments'], ['POST', 'fiscal-adjustments'], ['GET', 'tax-provisions/current'], ['POST', 'tax-provisions/preview'], ['POST', 'tax-provisions'],
        ['GET', 'attachments'], ['POST', 'attachments'], ['GET', 'scheduler/status'], ['GET', 'fake-data'], ['GET', 'widgets/financial-pulse'],
    ] as [$method, $path]) {
        $this->json($method, '/api/v1/spa/'.$path)->assertForbidden();
    }
    foreach ([
        'journals' => ['restore', 'post', 'submit', 'cancel-review', 'reject', 'reverse', 'replicate'],
        'periods' => ['close', 'reopen', 'deactivate'],
        'recurring-journals' => ['pause', 'resume', 'run'],
    ] as $resource => $actions) {
        foreach ($actions as $action) {
            $this->postJson('/api/v1/spa/'.$resource.'/'.$this->entity->id.'/'.$action)->assertForbidden();
        }
    }
})->with(['supervisor', 'operator', 'inspector', 'custom', 'inspector_copy']);

it('includes all three defaults and keeps Inspector Fiskal read-only', function () {
    $snapshot = workspaceRoleSnapshot($this);
    $roles = collect($snapshot['roles'])->keyBy('code');
    expect($roles->keys()->all())->toBe(['supervisor', 'operator', 'inspector'])
        ->and($roles['inspector']['name'])->toBe('Inspector Fiskal')
        ->and($roles['inspector']['defaults'])->toEqualCanonicalizing(config('accounting_permissions.inspector_permissions'));
    $this->putJson('/api/v1/spa/permission-management/inspector', [
        'permissions' => [...$roles['inspector']['permissions'], 'dashboard.read'], 'revision' => $snapshot['revision'],
    ])->assertUnprocessable()->assertJsonValidationErrors('permissions');
    $this->putJson('/api/v1/spa/permission-management/inspector', [
        'permissions' => [], 'revision' => $snapshot['revision'],
    ])->assertOk();
    $this->actingAs($this->inspector)->getJson('/api/v1/spa/journals')->assertForbidden();
});

it('creates an entity-scoped named role from a default and makes it assignable without shared permission pivots', function () {
    $result = createWorkspaceRole($this);
    $role = collect($result['roles'])->firstWhere('code', $result['created_role']);
    expect($role['name'])->toBe('Accounting Assistant')->and($role['is_preset'])->toBeFalse()
        ->and($role['permissions'])->toEqualCanonicalizing(app(AccountingPermissions::class)->defaults('operator'));
    $model = Role::findOrFail($role['id']);
    expect($model->tenant_id)->toBe($this->entity->tenant_id)->and($model->permissions()->count())->toBe(0);
    $this->getJson('/api/v1/spa/role-management')->assertOk()->assertJsonFragment(['id' => $role['id'], 'name' => 'Accounting Assistant', 'code' => $role['code']]);
    $this->withHeader('X-Tenant-Slug', $this->otherEntity->id)->withSession(['ecopa.app_role' => 'admin'])
        ->getJson('/api/v1/spa/permission-management')->assertOk()->assertJsonMissing(['code' => $role['code']]);
    $this->getJson('/api/v1/spa/role-management')->assertOk()->assertJsonMissing(['id' => $role['id']]);
    $this->patchJson('/api/v1/spa/role-management/'.$this->supervisor->assignments()->first()->id, ['role_id' => $role['id']])->assertUnprocessable();
});

it('assigns a named role to multiple users and applies its effective permissions', function () {
    $result = createWorkspaceRole($this);
    $code = $result['created_role'];
    $ids = [$this->operator->assignments()->first()->id, $this->supervisor->assignments()->first()->id];
    $this->postJson('/api/v1/spa/role-management/bulk-assign', ['role_code' => $code, 'assignment_ids' => $ids])->assertOk();
    foreach ([$this->operator, $this->supervisor] as $user) {
        expect($user->assignments()->first()->role->code)->toBe($code);
    }
    $snapshot = workspaceRoleSnapshot($this);
    expect(collect($snapshot['roles'])->firstWhere('code', $code)['member_count'])->toBe(2);
    $this->actingAs($this->operator)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenants.0.roles.0', $code);
    $this->postJson('/api/v1/spa/journal-templates', ['code' => 'NO', 'name' => 'Denied', 'lines' => []])->assertForbidden();
    $this->actingAs($this->admin)->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $snapshot['revision'], 'permissions' => [...app(AccountingPermissions::class)->defaults('operator'), 'template.create'],
    ])->assertOk();
    $this->actingAs($this->operator)->postJson('/api/v1/spa/journal-templates', ['code' => 'YES', 'name' => 'Granted', 'lines' => []])->assertCreated();
});

it('rolls back a bulk change when any selected assignment is protected or outside the entity', function () {
    $result = createWorkspaceRole($this);
    $before = $this->operator->assignments()->first()->role_id;
    $this->postJson('/api/v1/spa/role-management/bulk-assign', [
        'role_code' => $result['created_role'],
        'assignment_ids' => [$this->operator->assignments()->first()->id, $this->admin->assignments()->first()->id],
    ])->assertForbidden();
    expect($this->operator->assignments()->first()->role_id)->toBe($before);
    $outside = $this->operator->assignments()->create([
        'entity_id' => $this->otherEntity->id, 'app_id' => $this->rbacApp->id, 'role_id' => $before,
    ]);
    $this->postJson('/api/v1/spa/role-management/bulk-assign', [
        'role_code' => $result['created_role'], 'assignment_ids' => [$this->operator->assignments()->first()->id, $outside->id],
    ])->assertUnprocessable();
    expect($this->operator->assignments()->first()->role_id)->toBe($before);
});

it('preserves fiscal-only restrictions on copied inspector roles even with an operator assignment', function () {
    $result = createWorkspaceRole($this, 'Asisten Pemeriksa', 'inspector');
    $code = $result['created_role'];
    $this->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $result['revision'], 'permissions' => [...config('accounting_permissions.inspector_permissions'), 'journal.create'],
    ])->assertUnprocessable()->assertJsonValidationErrors('permissions');
    $this->postJson('/api/v1/spa/role-management/bulk-assign', [
        'role_code' => $code, 'assignment_ids' => [$this->inspector->assignments()->first()->id],
    ])->assertOk();
    $this->inspector->assignments()->create([
        'app_id' => $this->rbacApp->id, 'entity_id' => $this->entity->id,
        'role_id' => $this->operator->assignments()->first()->role_id,
    ]);
    $period = Period::create(['entity_id' => $this->entity->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    foreach (['internal', 'fiscal'] as $mode) {
        $journal = Journal::create([
            'entity_id' => $this->entity->id, 'period_id' => $period->id, 'number' => 'JR-'.$mode,
            'date' => '2026-05-01', 'type' => 'general', 'status' => 'draft', 'journal_mode' => $mode,
        ]);
        $this->{$mode.'Journal'} = $journal;
    }
    $this->actingAs($this->inspector)->getJson('/api/v1/spa/widgets/financial-pulse')->assertForbidden();
    $this->getJson('/api/v1/spa/journals/'.$this->internalJournal->id)->assertForbidden();
    $this->getJson('/api/v1/spa/journals/'.$this->fiscalJournal->id)->assertOk();
    $this->getJson('/api/v1/spa/journals')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.journal_mode', 'fiscal');
    $this->postJson('/api/v1/spa/journals', [])->assertForbidden();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenants.0.is_fiscal_inspector', true);
});

it('supports role details, reset to copied defaults, and safe removal with historical assignments preserved', function () {
    $created = createWorkspaceRole($this);
    $code = $created['created_role'];
    $role = collect($created['roles'])->firstWhere('code', $code);
    $renamed = $this->patchJson('/api/v1/spa/permission-management/roles/'.$code, [
        'name' => 'Accounting Support', 'description' => 'Membantu input jurnal', 'revision' => $created['revision'],
    ])->assertOk()->json('data');
    expect(Role::find($role['id'])->name)->toBe('Accounting Support');
    $updated = $this->putJson('/api/v1/spa/permission-management/'.$code, [
        'revision' => $renamed['revision'], 'permissions' => [...$role['defaults'], 'account.rename'],
    ])->assertOk()->json('data');
    $renamed = $this->deleteJson('/api/v1/spa/permission-management/'.$code, ['revision' => $updated['revision']])
        ->assertOk()->json('data');
    expect(collect($renamed['roles'])->firstWhere('code', $code)['permissions'])->toEqualCanonicalizing($role['defaults']);
    $this->postJson('/api/v1/spa/role-management/bulk-assign', [
        'role_code' => $code, 'assignment_ids' => [$this->operator->assignments()->first()->id],
    ])->assertOk();
    $this->deleteJson('/api/v1/spa/permission-management/roles/'.$code, ['revision' => $renamed['revision']])->assertConflict();
    $assignment = $this->operator->assignments()->first();
    $assignment->update(['revoked_at' => now()]);
    $this->deleteJson('/api/v1/spa/permission-management/roles/'.$code, ['revision' => $renamed['revision']])
        ->assertOk()->assertJsonMissing(['code' => $code]);
    expect($assignment->refresh()->role_id)->toBe($role['id'])->and(Role::find($role['id']))->not->toBeNull();
    $this->operator->assignments()->create([
        'app_id' => $this->rbacApp->id, 'entity_id' => $this->otherEntity->id, 'role_id' => $role['id'],
    ]);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->otherEntity->id)
        ->getJson('/api/v1/spa/journals')->assertForbidden();
});

it('rejects duplicate names, stale role creation, and non-admin role mutations', function () {
    $result = createWorkspaceRole($this);
    $this->postJson('/api/v1/spa/permission-management/roles', [
        'name' => ' accounting assistant ', 'base_role' => null, 'revision' => $result['revision'],
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/spa/permission-management/roles', [
        'name' => 'Other', 'base_role' => null, 'revision' => str_repeat('0', 64),
    ])->assertConflict();
    $this->actingAs($this->operator)->postJson('/api/v1/spa/permission-management/roles', [])->assertForbidden();
    $this->postJson('/api/v1/spa/role-management/bulk-assign', [])->assertForbidden();
});

it('rejects inactive and all-workspace assignments during entity-local bulk assignment', function () {
    $snapshot = workspaceRoleSnapshot($this);
    $assignment = $this->operator->assignments()->first();
    $assignment->update(['valid_until' => now()->subDay()]);
    $this->getJson('/api/v1/spa/role-management')->assertOk()->assertJsonFragment([
        'assignment_id' => $assignment->id, 'assignment_active' => false,
    ]);
    $payload = ['role_code' => 'supervisor', 'assignment_ids' => [$assignment->id]];
    $this->postJson('/api/v1/spa/role-management/bulk-assign', $payload)->assertUnprocessable();
    $assignment->update(['valid_until' => null, 'entity_id' => null]);
    $this->postJson('/api/v1/spa/role-management/bulk-assign', $payload)->assertUnprocessable();
    expect($assignment->refresh()->role->code)->toBe('operator');
});

it('does not retain a role when its creation audit fails', function () {
    $snapshot = workspaceRoleSnapshot($this);
    $before = Role::count();
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(AuditLogger::class, $audit);
    $this->postJson('/api/v1/spa/permission-management/roles', [
        'name' => 'Never saved', 'revision' => $snapshot['revision'],
    ])->assertStatus(500);
    expect(Role::count())->toBe($before)->and(app(AccountingPermissions::class)->customRoles($this->entity->refresh()))->toBe([]);
});
