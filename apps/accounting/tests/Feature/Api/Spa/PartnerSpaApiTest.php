<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Partner;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT', 'slug' => 'pt-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Pt Co']);
    $this->user = User::create([
        'name' => 'PT',
        'email' => 'pt-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'pt-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'pt-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated partners', function () {
    $this->getJson('/api/v1/spa/partners')->assertStatus(401);
});

it('creates and lists a partner', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/partners', [
            'type' => 'customer', 'code' => 'CUS-001',
            'name' => 'PT Maju', 'email' => 'maju@example.test',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'PT Maju');

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/partners');

    $res->assertOk()->assertJsonPath('meta.total', 1);
});

it('updates a partner', function () {
    $p = Partner::create([
        'entity_id' => $this->entity->id, 'type' => 'vendor',
        'code' => 'V-1', 'name' => 'Old',
    ]);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/partners/{$p->id}", [
            'type' => 'vendor', 'code' => 'V-1', 'name' => 'New',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');
});

it('deletes a partner', function () {
    $p = Partner::create([
        'entity_id' => $this->entity->id, 'type' => 'other',
        'code' => null, 'name' => 'Tmp',
    ]);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/partners/{$p->id}")
        ->assertStatus(204);
    expect(Partner::find($p->id))->toBeNull();
});
