<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Acc T', 'slug' => 'acc-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Acc Co']);
    $this->user = User::create([
        'name' => 'AC',
        'email' => 'ac-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'acc-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'acc-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated /api/v1/spa/accounts', function () {
    $this->getJson('/api/v1/spa/accounts')->assertStatus(401);
});

it('creates an account via SPA', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/accounts', [
            'code' => '1101', 'name' => 'Kas', 'type' => 'asset',
            'normal_balance' => 'debit', 'is_postable' => true,
            'availability' => 'both',
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.code', '1101')
        ->assertJsonPath('data.name', 'Kas');
    $created = Account::where('code', '1101')->firstOrFail();
    expect($created->availability)->toBe('both');
    $res->assertJsonPath('data.availability', 'both');
});

it('updates an existing account', function () {
    $account = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1102', 'name' => 'Bank',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);

    expect($account->refresh()->availability)->toBe('intern');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/accounts/{$account->id}", [
            'code' => '1102', 'name' => 'Bank Mandiri',
            'type' => 'asset', 'normal_balance' => 'debit',
            'availability' => 'fiskal',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bank Mandiri')
        ->assertJsonPath('data.availability', 'fiskal');
    expect($account->refresh()->availability)->toBe('fiskal');
});

it('blocks delete when account has journal entries', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'P',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $account = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-1', 'date' => '2026-01-05',
        'status' => Journal::STATUS_POSTED, 'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $account->id,
        'debit' => 0, 'credit' => 100,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/accounts/{$account->id}")
        ->assertStatus(422);
});

it('rejects duplicate code in same tenant', function () {
    Account::create([
        'entity_id' => $this->entity->id, 'code' => '5101', 'name' => 'X',
        'type' => 'expense', 'normal_balance' => 'debit',
    ]);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/accounts', [
            'code' => '5101', 'name' => 'Y', 'type' => 'expense', 'normal_balance' => 'debit',
        ])
        ->assertStatus(422);
});
