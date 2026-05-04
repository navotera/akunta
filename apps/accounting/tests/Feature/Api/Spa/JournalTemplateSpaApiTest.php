<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Tpl T', 'slug' => 'tpl-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Tpl Co']);
    $this->user = User::create([
        'name' => 'Tpl', 'email' => 'tpl-'.uniqid().'@x.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'tpl-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'tpl-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
});

it('rejects unauthenticated journal templates', function () {
    $this->getJson('/api/v1/spa/journal-templates')->assertStatus(401);
});

it('creates a template with lines', function () {
    $payload = [
        'code' => 'TPL-SAL', 'name' => 'Penjualan Tunai',
        'lines' => [
            ['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => '0', 'memo' => null],
            ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '0', 'memo' => null],
        ],
    ];

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journal-templates', $payload);

    $res->assertCreated()
        ->assertJsonPath('data.code', 'TPL-SAL')
        ->assertJsonCount(2, 'data.lines');

    expect(JournalTemplate::where('code', 'TPL-SAL')->exists())->toBeTrue();
});

it('updates a template by replacing lines', function () {
    $tpl = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'X', 'name' => 'X',
    ]);
    JournalTemplateLine::create([
        'template_id' => $tpl->id, 'line_no' => 1, 'side' => 'debit',
        'account_id' => $this->cash->id, 'amount' => 0,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journal-templates/{$tpl->id}", [
            'code' => 'X', 'name' => 'X-renamed',
            'lines' => [
                ['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => '0'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '0'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'X-renamed')
        ->assertJsonCount(2, 'data.lines');
});

it('deletes a template', function () {
    $tpl = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'D', 'name' => 'D',
    ]);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/journal-templates/{$tpl->id}")
        ->assertStatus(204);
    expect(JournalTemplate::find($tpl->id))->toBeNull();
});
