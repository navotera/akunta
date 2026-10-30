<?php

declare(strict_types=1);

use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\User;
use App\Services\AccountingPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Permission tenant', 'slug' => 'perms-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Permissions A']);
    $this->otherEntity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Permissions B']);
    $this->permissionApp = RbacApp::firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'enabled' => true, 'version' => '1']);
    foreach (['admin', 'supervisor', 'operator'] as $code) {
        $role = Role::firstOrCreate(['code' => $code, 'tenant_id' => null], ['name' => ucfirst($code), 'is_preset' => true]);
        $this->{$code} = User::create(['name' => $code, 'email' => $code.'-'.uniqid().'@test.local', 'password_hash' => bcrypt('secret')]);
        $this->{$code}->assignments()->create(['app_id' => $this->permissionApp->id, 'entity_id' => $this->entity->id, 'role_id' => $role->id]);
    }
    $this->account = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Bank BCA',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
    ]);
});

function permissionPayload(Account $account, array $changes = []): array
{
    return array_merge([
        'code' => $account->code, 'name' => $account->name,
        'type' => $account->type, 'normal_balance' => $account->normal_balance,
    ], $changes);
}

function saveOperatorPermissions($test, array $codes): array
{
    $snapshot = $test->actingAs($test->admin)->withHeader('X-Tenant-Slug', $test->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertOk()->json('data');

    return $test->putJson('/api/v1/spa/permission-management/operator', [
        'permissions' => $codes, 'revision' => $snapshot['revision'],
    ])->assertOk()->json('data');
}

it('prefills supervisor and operator with their defaults', function () {
    $roles = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertOk()->json('data.roles');
    $roles = collect($roles)->keyBy('code');
    expect($roles['supervisor']['permissions'])->toContain('journal.review', 'journal.post', 'account.rename')
        ->and($roles['operator']['permissions'])->toContain('journal.create', 'journal.submit')
        ->and($roles['operator']['permissions'])->not->toContain('account.rename', 'template.create', 'journal.post')
        ->and($roles['operator']['customized'])->toBeFalse();
    foreach ($roles as $role) {
        foreach ($role['permissions'] as $code) {
            foreach (config('accounting_permissions.dependencies')[$code] ?? [] as $dependency) {
                expect($role['permissions'])->toContain($dependency);
            }
        }
    }
});

it('only allows admins of the target workspace to configure permissions', function () {
    $this->getJson('/api/v1/spa/permission-management')->assertUnauthorized();
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertForbidden();
    $this->actingAs($this->supervisor)->getJson('/api/v1/spa/permission-management')->assertForbidden();
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->otherEntity->id)
        ->getJson('/api/v1/spa/permission-management')->assertForbidden();
});

it('supports rename-only permission without allowing structural edits, activation, or deletion', function () {
    $codes = app(AccountingPermissions::class)->defaults('operator');
    saveOperatorPermissions($this, [...$codes, 'account.rename']);

    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['name' => 'BCA Operasional']))
        ->assertOk()->assertJsonPath('data.name', 'BCA Operasional');
    $this->account->refresh();
    $this->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['code' => '1199']))
        ->assertForbidden();
    $this->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['is_active' => false]))
        ->assertForbidden();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->account->id)->assertForbidden();
    expect($this->account->refresh()->code)->toBe('1101')->and($this->account->is_active)->toBeTrue();
});

it('applies template grants and revocations immediately without changing shared preset roles', function () {
    $operatorRole = $this->operator->assignments()->first()->role;
    grantSpaPermissionsForTest($operatorRole, $this->permissionApp, ['template.create']);
    $template = ['code' => 'TEST-TPL', 'name' => 'Template permission', 'lines' => []];
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journal-templates', $template)->assertForbidden();
    $pivotCount = DB::table('role_permissions')->count();
    $defaults = app(AccountingPermissions::class)->defaults('operator');
    saveOperatorPermissions($this, [...$defaults, 'template.create']);
    $this->actingAs($this->operator)->postJson('/api/v1/spa/journal-templates', $template)->assertCreated();
    saveOperatorPermissions($this, $defaults);
    $this->actingAs($this->operator)->postJson('/api/v1/spa/journal-templates', [...$template, 'code' => 'DENIED'])
        ->assertForbidden();
    expect(DB::table('role_permissions')->count())->toBe($pivotCount);
});

it('applies saved template edit and delete grants during operator impersonation', function () {
    $template = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'PERM-TPL', 'name' => 'Template awal',
    ]);
    $url = '/api/v1/spa/journal-templates/'.$template->id;
    $payload = ['code' => 'PERM-TPL', 'name' => 'Template diperbarui', 'lines' => []];
    $defaults = app(AccountingPermissions::class)->defaults('operator');

    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withSession(['ecopa.app_role' => 'admin', 'impersonator_id' => $this->admin->id])
        ->patchJson($url, $payload)->assertForbidden();
    $this->deleteJson($url)->assertForbidden();

    session()->forget(['impersonator_id', 'ecopa.app_role']);
    saveOperatorPermissions($this, [...$defaults, 'template.update', 'template.delete']);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withSession(['ecopa.app_role' => 'admin', 'impersonator_id' => $this->admin->id]);
    $permissions = collect($this->getJson('/api/v1/me')->assertOk()->json('data.tenants'))
        ->firstWhere('id', $this->entity->id)['permissions'];
    expect($permissions)->toContain('template.update', 'template.delete');
    $this->patchJson($url, $payload)->assertOk()->assertJsonPath('data.name', 'Template diperbarui');
    $this->deleteJson($url)->assertNoContent();

    session()->forget(['impersonator_id', 'ecopa.app_role']);
    saveOperatorPermissions($this, $defaults);
    $second = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'PERM-TPL-2', 'name' => 'Template kedua',
    ]);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->withSession(['ecopa.app_role' => 'admin', 'impersonator_id' => $this->admin->id])
        ->patchJson('/api/v1/spa/journal-templates/'.$second->id, [
            'code' => $second->code, 'name' => 'Ditolak', 'lines' => [],
        ])->assertForbidden();
    $this->deleteJson('/api/v1/spa/journal-templates/'.$second->id)->assertForbidden();
});

it('keeps inspector boundaries even if a second role grants dashboard access', function () {
    $inspector = Role::create(['code' => 'inspector', 'name' => 'Inspector']);
    $this->supervisor->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => $this->permissionApp->id, 'role_id' => $inspector->id]);
    $this->actingAs($this->supervisor)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/widgets/financial-pulse')->assertForbidden();
    $this->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['name' => 'Denied']))
        ->assertForbidden();
});

it('allows independently granted structural edits and deletion without granting renaming or activation', function () {
    saveOperatorPermissions($this, [...app(AccountingPermissions::class)->defaults('operator'), 'account.update', 'account.delete']);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['code' => '1102']))
        ->assertOk()->assertJsonPath('data.code', '1102');
    $this->account->refresh();
    $this->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['name' => 'Denied name']))
        ->assertForbidden();
    $this->patchJson('/api/v1/spa/accounts/'.$this->account->id, permissionPayload($this->account, ['is_active' => false]))
        ->assertForbidden();
    $this->deleteJson('/api/v1/spa/accounts/'.$this->account->id)->assertNoContent();
});

it('does not let a recurring automation grant bypass posting permission', function () {
    $codes = [...app(AccountingPermissions::class)->defaults('operator'), 'recurring.create'];
    saveOperatorPermissions($this, $codes);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/recurring-journals', ['auto_post' => true])
        ->assertForbidden();
    saveOperatorPermissions($this, [...$codes, 'journal.post']);
    $this->actingAs($this->operator)->postJson('/api/v1/spa/recurring-journals', ['auto_post' => true])
        ->assertUnprocessable();
});

it('can delegate review and posting while keeping saved journals locked for operators', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => '2026',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $revenue = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'is_active' => true,
    ]);
    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id,
        'journal_mode' => 'internal', 'type' => 'general', 'number' => 'JU-PERM-1',
        'date' => '2026-05-04', 'status' => 'submitted', 'created_by' => $this->admin->id,
    ]);
    saveOperatorPermissions($this, [...app(AccountingPermissions::class)->defaults('operator'), 'journal.review', 'journal.post']);
    $payload = [
        'journal_mode' => 'internal', 'date' => '2026-05-04', 'memo' => 'Delegated review',
        'entries_debit' => [['account_id' => $this->account->id, 'amount' => '100']],
        'entries_credit' => [['account_id' => $revenue->id, 'amount' => '100']],
    ];
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/journals/'.$journal->id, $payload)->assertOk();
    $this->postJson('/api/v1/spa/journals/'.$journal->id.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
    $this->patchJson('/api/v1/spa/journals/'.$journal->id, $payload)->assertForbidden();
    $this->withSession(['ecopa.app_role' => 'admin', 'impersonator_id' => $this->admin->id])
        ->patchJson('/api/v1/spa/journals/'.$journal->id, $payload)->assertForbidden();
});

it('does not inherit accounting role defaults from another app', function () {
    $otherApp = RbacApp::create(['code' => 'payroll', 'name' => 'Payroll', 'enabled' => true, 'version' => '1']);
    $this->admin->assignments()->first()->update(['app_id' => $otherApp->id]);
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertForbidden();
    $this->postJson('/api/v1/spa/journal-templates', ['code' => 'NO', 'name' => 'No access', 'lines' => []])->assertForbidden();
});

it('does not use another apps identically named permission to authorize accounting requests', function () {
    $otherApp = RbacApp::create(['code' => 'payroll', 'name' => 'Payroll', 'enabled' => true, 'version' => '1']);
    $role = Role::create(['code' => 'payroll_editor', 'name' => 'Payroll editor']);
    grantSpaPermissionsForTest($role, $otherApp, ['account.read', 'template.create']);
    $this->operator->assignments()->first()->update(['app_id' => $otherApp->id, 'role_id' => $role->id]);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/accounts')->assertForbidden();
    $this->postJson('/api/v1/spa/journal-templates', ['code' => 'NO', 'name' => 'Other app', 'lines' => []])->assertForbidden();
});

it('allows report source lookups without granting journal access', function () {
    saveOperatorPermissions($this, ['account.read', 'period.read', 'report.read']);
    $this->actingAs($this->operator)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/source-refs')->assertOk();
    $this->getJson('/api/v1/spa/journals')->assertForbidden();
    saveOperatorPermissions($this, ['account.read', 'period.read']);
    $this->actingAs($this->operator)->getJson('/api/v1/spa/source-refs')->assertForbidden();
});

it('enforces overrides in action Gates even when the actor is loaded through the shared RBAC model', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Gate period',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id, 'journal_mode' => 'internal',
        'type' => 'general', 'number' => 'JU-GATE-PERM', 'date' => '2026-05-04', 'status' => 'draft',
    ]);
    $role = $this->operator->assignments()->first()->role;
    grantSpaPermissionsForTest($role, $this->permissionApp, ['journal.post']);
    $actor = Akunta\Rbac\Models\User::findOrFail($this->operator->id);
    expect(Gate::forUser($actor)->allows('journal.post', $journal))->toBeFalse();
    $defaults = app(AccountingPermissions::class)->defaults('operator');
    saveOperatorPermissions($this, [...$defaults, 'journal.post']);
    expect(Gate::forUser($actor)->allows('journal.post', $journal))->toBeTrue();
    saveOperatorPermissions($this, $defaults);
    expect(Gate::forUser($actor)->allows('journal.post', $journal))->toBeFalse();
});

it('keeps grants scoped to the entity and exposes effective permissions in auth bootstrap', function () {
    $roleId = $this->operator->assignments()->first()->role_id;
    $this->operator->assignments()->create(['app_id' => $this->permissionApp->id, 'entity_id' => $this->otherEntity->id, 'role_id' => $roleId]);
    saveOperatorPermissions($this, [...app(AccountingPermissions::class)->defaults('operator'), 'account.rename']);

    $tenants = collect($this->actingAs($this->operator)->getJson('/api/v1/me')->assertOk()->json('data.tenants'))->keyBy('id');
    expect($tenants[$this->entity->id]['permissions'])->toContain('account.rename')
        ->and($tenants[$this->otherEntity->id]['permissions'])->not->toContain('account.rename')
        ->and($tenants[$this->entity->id]['can_manage_permissions'])->toBeFalse();
});

it('requires an explicit grant to reactivate a period for supervisor or operator', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Closed period', 'status' => 'closed',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    foreach ([$this->supervisor, $this->operator] as $actor) {
        $this->actingAs($actor)->withHeader('X-Tenant-Slug', $this->entity->id)
            ->postJson('/api/v1/spa/periods/'.$period->id.'/reopen')->assertForbidden();
    }
    saveOperatorPermissions($this, [...app(AccountingPermissions::class)->defaults('operator'), 'period.reopen']);
    $this->actingAs($this->operator)->postJson('/api/v1/spa/periods/'.$period->id.'/reopen', ['reason' => 'Koreksi dengan izin eksplisit', 'confirmed' => true])
        ->assertOk()->assertJsonPath('data.status', 'open');
});

it('exposes delegated settings grants in auth bootstrap and clears them after revocation', function () {
    $defaults = app(AccountingPermissions::class)->defaults('operator');
    saveOperatorPermissions($this, [...$defaults, 'settings.cron.manage', 'settings.fake_data.manage']);
    $response = $this->actingAs($this->operator)->getJson('/api/v1/me')->assertOk();
    $entity = collect($response->json('data.tenants'))->firstWhere('id', $this->entity->id);
    expect($entity['can_manage_cron'])->toBeTrue()->and($entity['can_manage_fake_data'])->toBeTrue()
        ->and($entity['roles'])->toBe(['operator']);
    saveOperatorPermissions($this, $defaults);
    $response = $this->actingAs($this->operator)->getJson('/api/v1/me')->assertOk();
    $entity = collect($response->json('data.tenants'))->firstWhere('id', $this->entity->id);
    expect($entity['can_manage_cron'])->toBeFalse()->and($entity['can_manage_fake_data'])->toBeFalse();
});

it('rejects stale saves and can restore the default permissions', function () {
    $original = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->json('data');
    $saved = saveOperatorPermissions($this, [...app(AccountingPermissions::class)->defaults('operator'), 'account.rename']);
    $this->putJson('/api/v1/spa/permission-management/operator', [
        'permissions' => [], 'revision' => $original['revision'],
    ])->assertConflict();
    $this->deleteJson('/api/v1/spa/permission-management/operator', ['revision' => $saved['revision']])
        ->assertOk()->assertJsonPath('data.roles.1.customized', false);
    expect($this->entity->refresh()->workspace_settings[AccountingPermissions::SETTINGS_KEY] ?? [])->toBe([]);
});

it('rejects protected roles, unknown permission codes, and missing dependencies', function () {
    $snapshot = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->json('data');
    $this->putJson('/api/v1/spa/permission-management/admin', ['permissions' => [], 'revision' => $snapshot['revision']])
        ->assertUnprocessable();
    $this->putJson('/api/v1/spa/permission-management/operator', ['permissions' => ['workspace.manage'], 'revision' => $snapshot['revision']])
        ->assertUnprocessable();
    $this->putJson('/api/v1/spa/permission-management/operator', ['permissions' => ['account.rename'], 'revision' => $snapshot['revision']])
        ->assertUnprocessable()->assertJsonValidationErrors('permissions');
});

it('does not retain a permission change if writing the audit log fails', function () {
    $snapshot = $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->json('data');
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(AuditLogger::class, $audit);
    $this->putJson('/api/v1/spa/permission-management/operator', ['permissions' => [], 'revision' => $snapshot['revision']])
        ->assertStatus(500);
    expect(app(AccountingPermissions::class)->roleCodes($this->entity->refresh(), 'operator'))
        ->toContain('journal.create');
});

it('does not give an expired, revoked, other-app, or impersonated assignment admin authority', function () {
    $assignment = $this->admin->assignments()->first();
    $assignment->update(['valid_until' => now()->subDay()]);
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/permission-management')->assertForbidden();
    $assignment->update(['valid_until' => null, 'revoked_at' => now()]);
    $this->getJson('/api/v1/spa/permission-management')->assertForbidden();
    $assignment->update(['revoked_at' => null]);
    $this->withSession(['ecopa.app_role' => 'admin', 'impersonator_id' => $this->admin->id])
        ->actingAs($this->operator)->getJson('/api/v1/spa/permission-management')->assertForbidden();
});
