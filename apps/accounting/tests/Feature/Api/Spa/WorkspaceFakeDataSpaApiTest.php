<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\User;
use App\Services\RequiredAccountService;

beforeEach(function () {
    $this->tenant = Tenant::create(['name' => 'Workspace Tenant', 'slug' => 'workspace-'.uniqid()]);
    $this->regular = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Regular',
        'workspace_code' => 'REGULAR',
        'is_active' => true,
    ]);
    $this->fake = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Fake Data',
        'workspace_code' => 'FAKE-DATA',
        'is_active' => true,
        'is_fake_data' => true,
    ]);
    $this->user = User::create([
        'name' => 'Workspace Admin',
        'email' => 'workspace-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $app = RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::create(['code' => 'workspace-admin', 'name' => 'Workspace Admin', 'is_preset' => false]);
    foreach ([$this->regular, $this->fake] as $entity) {
        $this->user->assignments()->create([
            'entity_id' => $entity->id,
            'app_id' => $app->id,
            'role_id' => $role->id,
        ]);
    }
});

it('exposes the fake badge contract and independently deactivates the demo workspace', function () {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->getJson('/api/v1/spa/workspaces')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->fake->id,
            'name' => 'PT. Fake Data',
            'is_active' => true,
            'is_fake_data' => true,
        ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->fake->id, [
            'name' => 'PT. Fake Data',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_fake_data', true);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->fake->id,
            'is_active' => false,
            'is_fake_data' => true,
        ]);

    expect($this->regular->refresh()->is_active)->toBeTrue();
});

it('does not allow the last active workspace in a tenant to be disabled', function () {
    $this->fake->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'name' => 'PT. Regular',
            'is_active' => false,
        ])
        ->assertUnprocessable();

    expect($this->regular->refresh()->is_active)->toBeTrue();
});

it('persists the issue report redirect URL in workspace settings and auth bootstrap', function () {
    $url = 'https://support.example.test/akunta/issues';

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'name' => 'PT. Regular',
            'issue_report_url' => $url,
        ])
        ->assertOk()
        ->assertJsonPath('data.issue_report_url', $url);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->regular->id,
            'issue_report_url' => $url,
        ]);
});

it('creates the required tax accounts with a new workspace', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->postJson('/api/v1/spa/workspaces', [
            'tenant_id' => $this->tenant->id,
            'name' => 'PT. Baru',
            'bookkeeping_mode' => 'independent_books',
        ])
        ->assertCreated();

    $entityId = $response->json('data.id');
    expect(Account::query()->where('entity_id', $entityId)->whereNotNull('system_key')->count())->toBe(4)
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::PREPAID_TAX)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_PROVISION)->value('availability'))->toBe('intern')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_DEFINITIVE)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_EXPENSE)->value('availability'))->toBe('intern');
});
