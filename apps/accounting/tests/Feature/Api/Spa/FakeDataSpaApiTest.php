<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\User;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Fake API', 'slug' => 'fake-api-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Fake API Co']);
    $this->user = User::create([
        'name' => 'Admin Fake',
        'email' => 'fake-admin-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
});

it('imports the complete demo dataset into the selected period', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'Tahun Berjalan',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => Period::STATUS_OPEN,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/import-all', ['period_id' => $period->id])
        ->assertOk()
        ->assertJsonPath('data.groups.3.requires_period', true)
        ->assertJsonPath('data.groups.4.requires_period', true);

    expect(Journal::where('entity_id', $this->entity->id)->exists())->toBeTrue()
        ->and(JournalTemplate::where('entity_id', $this->entity->id)->exists())->toBeTrue();
});

it('requires a selected open period for financial fake data', function () {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/journals/import', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period_id');
});

it('rejects a period belonging to another entity', function () {
    $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'fake-other-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Co']);
    $otherPeriod = Period::create([
        'entity_id' => $otherEntity->id,
        'name' => 'Other Period',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => Period::STATUS_OPEN,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/journals/import', ['period_id' => $otherPeriod->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period_id');
});

it('protects the built-in native fake entity from import and clear operations', function () {
    $this->entity->update(['is_fake_data' => true]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/import-all', ['period_id' => str_repeat('0', 26)])
        ->assertConflict();

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson('/api/v1/spa/fake-data/accounts')
        ->assertConflict();
});
