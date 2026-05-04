<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Journal;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PR', 'slug' => 'pr-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'PR Co']);
    $this->user = User::create([
        'name' => 'PR',
        'email' => 'pr-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'pr-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'pr-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated periods', function () {
    $this->getJson('/api/v1/spa/periods')->assertStatus(401);
});

it('creates a period and rejects overlap', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/periods', [
            'name' => 'Apr 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/periods', [
            'name' => 'Overlap',
            'start_date' => '2026-04-15',
            'end_date' => '2026-05-15',
        ])
        ->assertStatus(422);
});

it('closes a period without drafts and reopens it', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/periods/{$period->id}/close", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/periods/{$period->id}/reopen", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('blocks closing when drafts exist', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'M',
        'start_date' => '2026-05-01', 'end_date' => '2026-05-31',
    ]);
    Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-DRAFT',
        'date' => '2026-05-10', 'status' => Journal::STATUS_DRAFT,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/periods/{$period->id}/close", [])
        ->assertStatus(422);
});
