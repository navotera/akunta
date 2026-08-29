<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\User;

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
    $this->actingAs($this->admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertOk()
        ->assertJsonPath('data.users.0.email', $this->managedUser->email)
        ->assertJsonPath('data.users.0.role_id', null)
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
    $this->assertDatabaseHas('audit_log', [
        'action' => 'user.role_changed',
        'resource_id' => $this->assignment->id,
        'entity_id' => $this->entity->id,
    ]);
});

it('rejects role management by a regular Ecopa user', function () {
    $this->actingAs($this->managedUser)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/role-management')
        ->assertForbidden();
});
