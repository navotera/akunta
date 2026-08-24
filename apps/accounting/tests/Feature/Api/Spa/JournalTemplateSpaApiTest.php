<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
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
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'availability' => 'both',
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true, 'availability' => 'both',
    ]);
});

it('rejects unauthenticated journal templates', function () {
    $this->getJson('/api/v1/spa/journal-templates')->assertStatus(401);
});

it('creates a template with lines', function () {
    $payload = [
        'code' => 'TPL-SAL', 'name' => 'Penjualan Tunai',
        'journal_mode' => Journal::MODE_FISCAL,
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
        ->assertJsonPath('data.journal_mode', Journal::MODE_FISCAL)
        ->assertJsonCount(2, 'data.lines');

    expect(JournalTemplate::where('code', 'TPL-SAL')->exists())->toBeTrue();
});

it('filters templates by journal mode', function () {
    JournalTemplate::create([
        'entity_id' => $this->entity->id,
        'code' => 'TPL-INT',
        'name' => 'Template Internal',
        'journal_mode' => Journal::MODE_INTERNAL,
    ]);
    JournalTemplate::create([
        'entity_id' => $this->entity->id,
        'code' => 'TPL-FIS',
        'name' => 'Template Fiskal',
        'journal_mode' => Journal::MODE_FISCAL,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journal-templates?journal_mode=fiscal')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'TPL-FIS')
        ->assertJsonPath('data.0.journal_mode', Journal::MODE_FISCAL);
});

it('only lists templates belonging to the active entity', function () {
    $otherEntity = Entity::create([
        'tenant_id' => $this->entity->tenant_id,
        'name' => 'Other Co',
    ]);
    $otherApp = RbacApp::create(['code' => 'tpl-other-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $otherRole = Role::create(['code' => 'tpl-other-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $otherEntity->id, 'app_id' => $otherApp->id, 'role_id' => $otherRole->id,
    ]);
    JournalTemplate::create([
        'entity_id' => $otherEntity->id, 'code' => 'OTHER', 'name' => 'Other Entity Template',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journal-templates')
        ->assertOk()
        ->assertJsonMissing(['code' => 'OTHER']);
});

it('cannot use a template from another entity for a recurring journal', function () {
    $otherEntity = Entity::create([
        'tenant_id' => $this->entity->tenant_id,
        'name' => 'Other Co',
    ]);
    $otherTemplate = JournalTemplate::create([
        'entity_id' => $otherEntity->id, 'code' => 'OTHER', 'name' => 'Other Entity Template',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/recurring-journals', [
            'template_id' => $otherTemplate->id,
            'name' => 'Cross entity',
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
        ])
        ->assertStatus(422);
});

it('toggles a tenant template bookmark', function () {
    $template = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'TPL-BOOK', 'name' => 'Bookmarked',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journal-templates/{$template->id}/bookmark", [
            'is_bookmarked' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_bookmarked', true);

    expect($template->refresh()->is_bookmarked)->toBeTrue();
});

it('rejects template lines unavailable for the template mode', function () {
    $this->cash->update(['availability' => 'intern']);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journal-templates', [
            'code' => 'TPL-BAD',
            'name' => 'Invalid Fiscal Template',
            'journal_mode' => Journal::MODE_FISCAL,
            'lines' => [
                ['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => '0'],
            ],
        ])
        ->assertStatus(422);
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
