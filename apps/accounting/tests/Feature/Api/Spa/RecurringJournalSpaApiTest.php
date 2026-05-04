<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\JournalTemplate;
use App\Models\RecurringJournal;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'RC', 'slug' => 'rc-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'RC Co']);
    $this->user = User::create([
        'name' => 'RC', 'email' => 'rc-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'rc-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'rc-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);

    $this->template = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'TPL-RC', 'name' => 'Sewa',
    ]);
});

it('creates a recurring schedule and pauses it', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/recurring-journals', [
            'template_id' => $this->template->id,
            'name' => 'Sewa Bulanan',
            'frequency' => 'monthly',
            'day' => 1,
            'start_date' => '2026-01-01',
        ]);

    $res->assertCreated()->assertJsonPath('data.status', 'active');
    $id = $res->json('data.id');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/recurring-journals/{$id}/pause", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/recurring-journals/{$id}/resume", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('lists recurring schedules per tenant', function () {
    RecurringJournal::create([
        'entity_id' => $this->entity->id,
        'template_id' => $this->template->id,
        'name' => 'A',
        'frequency' => 'daily',
        'start_date' => '2026-01-01',
        'next_run_at' => '2026-01-01',
        'created_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/recurring-journals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
