<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\UserAccessRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Role Tenant', 'slug' => 'role-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Role Entity']);
    $this->rbacApp = RbacApp::create([
        'code' => 'accounting',
        'name' => 'Accounting',
        'version' => '1.0',
        'enabled' => true,
    ]);
    $this->operatorRole = Role::create([
        'code' => 'operator',
        'name' => 'Operator',
        'is_preset' => true,
    ]);
    $this->admin = User::create([
        'name' => 'Ecopa Admin',
        'email' => 'role-admin@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $this->managedUser = User::create([
        'name' => 'Ecopa User',
        'email' => 'role-user@example.test',
        'main_tier_user_id' => 'ecopa-role-user',
        'password_hash' => bcrypt('x'),
    ]);
    $this->assignment = $this->managedUser->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => null,
        'ecopa_role' => 'user',
        'assigned_at' => now(),
    ]);
});

it('lets an Ecopa app admin assign a local accounting role', function () {
    expect(Schema::getColumnType('personal_access_tokens', 'tokenable_id'))->toBe('varchar');

    [$managedApiToken] = ApiToken::issue([
        'name' => 'Managed user token',
        'user_id' => $this->managedUser->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create'],
    ]);
    $otherUser = User::create([
        'name' => 'Other User',
        'email' => 'other-role-user@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    [$otherApiToken] = ApiToken::issue([
        'name' => 'Other user token',
        'user_id' => $otherUser->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['journal.create'],
    ]);
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => $this->managedUser->getMorphClass(),
        'tokenable_id' => $this->managedUser->id,
        'name' => 'Managed Sanctum token',
        'token' => hash('sha256', 'managed-sanctum-token'),
        'abilities' => json_encode(['*']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => $otherUser->getMorphClass(),
        'tokenable_id' => $otherUser->id,
        'name' => 'Other Sanctum token',
        'token' => hash('sha256', 'other-sanctum-token'),
        'abilities' => json_encode(['*']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.email', $this->managedUser->email)
        ->assertJsonPath('data.users.0.role_id', null)
        ->assertJsonPath('data.users.0.can_update_role', true)
        ->assertJsonPath('data.roles.0.code', 'operator');

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.role_id', $this->operatorRole->id);

    expect($this->assignment->fresh()->role_id)->toBe($this->operatorRole->id);
    expect($managedApiToken->fresh()->revoked_at)->not->toBeNull();
    expect($otherApiToken->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => $this->managedUser->getMorphClass(),
        'tokenable_id' => $this->managedUser->id,
    ]);
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_type' => $otherUser->getMorphClass(),
        'tokenable_id' => $otherUser->id,
    ]);
    $this->assertDatabaseHas('audit_log', [
        'action' => 'user.role_changed',
        'resource_id' => $this->assignment->id,
        'entity_id' => $this->entity->id,
    ]);
});

it('rolls back a role change when access revocation fails', function () {
    $revoker = Mockery::mock(UserAccessRevoker::class);
    $revoker->shouldReceive('revokeSessionsAndTokens')
        ->once()
        ->andThrow(new RuntimeException('Token revocation failed'));
    $this->app->instance(UserAccessRevoker::class, $revoker);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertStatus(500);

    expect($this->assignment->fresh()->role_id)->toBeNull();
    $this->assertDatabaseMissing('audit_log', [
        'action' => 'user.role_changed',
        'resource_id' => $this->assignment->id,
        'entity_id' => $this->entity->id,
    ]);
});

it('returns the name of a persisted role that cannot be assigned through role management', function () {
    $superAdminRole = Role::create([
        'code' => 'super_admin',
        'name' => 'Super Admin',
        'is_preset' => true,
    ]);
    $this->assignment->update(['role_id' => $superAdminRole->id]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.role_id', $superAdminRole->id)
        ->assertJsonPath('data.users.0.role_code', 'super_admin')
        ->assertJsonPath('data.users.0.role_name', 'Super Admin')
        ->assertJsonCount(1, 'data.roles')
        ->assertJsonPath('data.roles.0.code', 'operator');
});

it('prevents everyone from changing a super admin role', function () {
    $superAdminRole = Role::create([
        'code' => 'super_admin',
        'name' => 'Super Admin',
        'is_preset' => true,
    ]);
    $this->assignment->update(['role_id' => $superAdminRole->id]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertForbidden();

    $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $superAdminRole->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertForbidden();

    expect($this->assignment->fresh()->role_id)->toBe($superAdminRole->id);
});

it('lets a super admin change another user role', function () {
    $superAdminRole = Role::create([
        'code' => 'super_admin',
        'name' => 'Super Admin',
        'is_preset' => true,
    ]);
    $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $superAdminRole->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertOk();

    expect($this->assignment->fresh()->role_id)->toBe($this->operatorRole->id);
});

it('limits changes to and from the admin role to super admins', function () {
    $adminRole = Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'is_preset' => true,
    ]);
    $this->assignment->update(['role_id' => $adminRole->id]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertForbidden();

    $this->assignment->update(['role_id' => null]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $adminRole->id,
        ])
        ->assertForbidden();

    $superAdminRole = Role::create([
        'code' => 'super_admin',
        'name' => 'Super Admin',
        'is_preset' => true,
    ]);
    $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $superAdminRole->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$this->assignment->id, [
            'role_id' => $adminRole->id,
        ])
        ->assertOk();

    expect($this->assignment->fresh()->role_id)->toBe($adminRole->id);
});

it('prevents an Ecopa admin from changing their own local role', function () {
    $assignment = $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => null,
        'ecopa_role' => 'admin',
        'assigned_at' => now(),
    ]);
    [$apiToken] = ApiToken::issue([
        'name' => 'Ecopa admin token',
        'user_id' => $this->admin->id,
        'app_id' => $this->rbacApp->id,
        'permissions' => ['workspace.manage'],
    ]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonFragment([
            'assignment_id' => $assignment->id,
            'can_update_role' => false,
        ]);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertForbidden();

    expect($assignment->fresh()->role_id)->toBeNull();
    expect($apiToken->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseMissing('audit_log', [
        'action' => 'user.role_changed',
        'resource_id' => $assignment->id,
        'entity_id' => $this->entity->id,
    ]);
});

it('prevents a local admin from changing their own role', function (string $roleCode) {
    $protectedRole = Role::create([
        'code' => $roleCode,
        'name' => str($roleCode)->replace('_', ' ')->title()->toString(),
        'is_preset' => true,
    ]);
    $assignment = $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $protectedRole->id,
        'ecopa_role' => 'user',
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/role-management/'.$assignment->id, [
            'role_id' => $this->operatorRole->id,
        ])
        ->assertForbidden();

    expect($assignment->fresh()->role_id)->toBe($protectedRole->id);
    $this->assertDatabaseMissing('audit_log', [
        'action' => 'user.role_changed',
        'resource_id' => $assignment->id,
        'entity_id' => $this->entity->id,
    ]);
})->with(['admin', 'super_admin']);

it('rejects role management by a regular Ecopa user', function () {
    $this->actingAs($this->managedUser)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertForbidden();
});

it('impersonates an active non-fake user through User & Roles', function () {
    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.can_impersonate', true);

    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/role-management/'.$this->assignment->id.'/impersonate')
        ->assertOk();

    expect(auth('web')->id())->toBe($this->managedUser->id);

    $this->postJson('/api/v1/spa/role-management/stop-impersonation')->assertOk();
    expect(auth('web')->id())->toBe($this->admin->id);
});

it('prevents chained impersonation while an impersonation session is active', function () {
    $session = [
        'ecopa.app_role' => 'admin',
        'impersonator_id' => $this->admin->id,
        'impersonation_entity_id' => $this->entity->id,
    ];

    $this->actingAs($this->admin)
        ->withSession($session)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.can_impersonate', false);

    $this->actingAs($this->admin)
        ->withSession($session)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/role-management/'.$this->assignment->id.'/impersonate')
        ->assertConflict();
});

it('allows impersonation of a user with the same role', function () {
    $workspaceManage = Permission::create([
        'app_id' => $this->rbacApp->id,
        'code' => 'workspace.manage',
    ]);
    $this->operatorRole->permissions()->attach($workspaceManage->id);
    $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $this->operatorRole->id,
        'assigned_at' => now(),
    ]);
    $this->assignment->update(['role_id' => $this->operatorRole->id]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.can_impersonate', true);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/role-management/'.$this->assignment->id.'/impersonate')
        ->assertOk();
});

it('does not impersonate a user with a higher role', function () {
    $supervisorRole = Role::create([
        'code' => 'supervisor',
        'name' => 'Supervisor',
        'is_preset' => true,
    ]);
    $adminRole = Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'is_preset' => true,
    ]);
    $workspaceManage = Permission::create([
        'app_id' => $this->rbacApp->id,
        'code' => 'workspace.manage',
    ]);
    $supervisorRole->permissions()->attach($workspaceManage->id);
    $this->admin->assignments()->create([
        'app_id' => $this->rbacApp->id,
        'entity_id' => $this->entity->id,
        'role_id' => $supervisorRole->id,
        'assigned_at' => now(),
    ]);
    $this->assignment->update(['role_id' => $adminRole->id]);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.can_impersonate', false);

    $this->actingAs($this->admin)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/role-management/'.$this->assignment->id.'/impersonate')
        ->assertForbidden();
});
