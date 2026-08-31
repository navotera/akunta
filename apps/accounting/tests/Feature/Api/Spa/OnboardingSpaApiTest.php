<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\EcopaConfigIntegration;
use App\Models\User as AccountingUser;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'OB', 'slug' => 'ob-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'OB Co']);
    $this->user = User::create([
        'name' => 'OB', 'email' => 'ob-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'ob-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'ob-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated onboarding endpoints', function () {
    $this->getJson('/api/v1/spa/onboarding/coa-templates')->assertStatus(401);
    $this->getJson('/api/v1/spa/onboarding/status')->assertStatus(401);
});

it('reports onboarding incomplete on a fresh entity', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/onboarding/status');

    $res->assertOk()
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.has_accounts', false)
        ->assertJsonPath('data.account_count', 0);
});

it('lists CoA templates with key + label + description', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/onboarding/coa-templates');

    $res->assertOk();
    $items = $res->json('data');
    expect($items)->toBeArray()->and(count($items))->toBeGreaterThan(0);
    expect($items[0])->toHaveKeys(['key', 'label', 'description']);
});

it('applies a CoA template and creates accounts', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/onboarding/apply-coa', [
            'template_key' => 'generic',
        ])
        ->assertOk()
        ->assertJsonPath('data.template_key', 'generic');

    expect(Account::where('entity_id', $this->entity->id)->count())->toBeGreaterThan(0);
});

it('completes onboarding globally and prevents a second wizard run', function () {
    $headers = ['X-Tenant-Slug' => $this->entity->id];

    $this->actingAs($this->user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/onboarding/bookkeeping-mode', [
            'bookkeeping_mode' => 'independent_books',
        ])
        ->assertOk();

    $this->actingAs($this->user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/onboarding/apply-coa', [
            'template_key' => 'generic',
        ])
        ->assertOk();

    $this->actingAs($this->user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/periods', [
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])
        ->assertCreated();

    $this->actingAs($this->user)
        ->getJson('/api/v1/spa/installation-onboarding/status')
        ->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.has_entity', true)
        ->assertJsonPath('data.entity_count', 1);

    $this->actingAs($this->user)
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/onboarding/status')
        ->assertOk()
        ->assertJsonPath('data.completed', true);

    expect(EcopaConfigIntegration::query()
        ->where('name', 'installation_onboarding_completed_at')
        ->value('value'))->not->toBeNull();

    EcopaConfigIntegration::query()
        ->where('name', 'installation_onboarding_completed_at')
        ->delete();
    $migration = require database_path('migrations/2026_08_31_000760_backfill_installation_onboarding_completion.php');
    $migration->up();

    expect(EcopaConfigIntegration::query()
        ->where('name', 'installation_onboarding_completed_at')
        ->value('value'))->not->toBeNull();

    $secondAdmin = AccountingUser::create([
        'name' => 'Second Admin',
        'email' => 'admin-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);

    $this->actingAs($secondAdmin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->postJson('/api/v1/spa/installation-onboarding/entity', [
            'name' => 'PT Kedua',
        ])
        ->assertConflict();

    $this->actingAs($secondAdmin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/onboarding/bookkeeping-mode', [
            'bookkeeping_mode' => 'internal_only',
        ])
        ->assertConflict();

    $this->actingAs($this->user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/periods', [
            'name' => '2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ])
        ->assertCreated();
});

it('does not treat the native demo entity as an initial entity', function () {
    $this->entity->update(['is_fake_data' => true]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/spa/installation-onboarding/status')
        ->assertOk()
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.has_entity', false)
        ->assertJsonPath('data.entity_count', 0);
});
