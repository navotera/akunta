<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;

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
