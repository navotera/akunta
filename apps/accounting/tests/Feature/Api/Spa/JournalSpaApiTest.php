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
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?Authenticatable $u = null) => true);
    Gate::define('journal.reverse', fn (?Authenticatable $u = null) => true);

    $tenant = Tenant::create(['name' => 'SPA Tenant', 'slug' => 'spa-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'SPA Co',
    ]);

    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '4101',
        'name' => 'Penjualan',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'is_postable' => true,
    ]);

    $this->user = User::create([
        'name' => 'SPA User',
        'email' => 'spa-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $app = RbacApp::create([
        'code' => 'accounting-spa-'.uniqid(),
        'name' => 'Accounting',
        'version' => '0.1',
        'enabled' => true,
    ]);
    $role = Role::create([
        'code' => 'admin-'.uniqid(),
        'name' => 'Admin',
        'is_preset' => false,
    ]);

    $this->user->assignments()->create([
        'entity_id' => $this->entity->id,
        'app_id' => $app->id,
        'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated /api/v1/spa/journals', function () {
    $this->getJson('/api/v1/spa/journals')->assertStatus(401);
});

it('lists journals scoped to the tenant', function () {
    Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JU-2026-05-001',
        'date' => '2026-05-04',
        'memo' => 'List test',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals');

    $res->assertOk()
        ->assertJsonPath('data.0.number', 'JU-2026-05-001')
        ->assertJsonPath('data.0.journal_mode', 'internal')
        ->assertJsonPath('meta.total', 1);
});

it('creates a balanced draft journal via SPA endpoint', function () {
    $payload = [
        'reference' => 'INV-2026-05-010',
        'date' => '2026-05-04',
        'memo' => 'Pembelian persediaan',
        'entries_debit' => [
            ['account_id' => $this->cash->id, 'amount' => '100000', 'memo' => 'Kas masuk'],
        ],
        'entries_credit' => [
            ['account_id' => $this->revenue->id, 'amount' => '100000', 'memo' => 'Penjualan'],
        ],
    ];

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', $payload);

    $res->assertCreated()
        ->assertJsonPath('data.number', 'JI-202605-0001')
        ->assertJsonPath('data.reference', 'INV-2026-05-010')
        ->assertJsonPath('data.journal_mode', 'internal')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonCount(1, 'data.entries_debit')
        ->assertJsonCount(1, 'data.entries_credit');

    expect(Journal::where('number', 'JI-202605-0001')
        ->where('reference', 'INV-2026-05-010')
        ->exists())->toBeTrue();
});

it('generates a fiscal journal number with the fiscal prefix', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'journal_mode' => Journal::MODE_FISCAL,
            'date' => '2026-05-05',
            'memo' => 'Jurnal fiskal',
            'entries_debit' => [
                ['account_id' => $this->cash->id, 'amount' => '25000', 'memo' => null],
            ],
            'entries_credit' => [
                ['account_id' => $this->revenue->id, 'amount' => '25000', 'memo' => null],
            ],
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.number', 'JF-202605-0001')
        ->assertJsonPath('data.journal_mode', 'fiscal');
    expect(Journal::where('number', 'JF-202605-0001')->value('journal_mode'))
        ->toBe(Journal::MODE_FISCAL);
});

it('rejects unbalanced journal create with 422', function () {
    $payload = [
        'number' => 'JU-X',
        'date' => '2026-05-04',
        'memo' => 'Unbalanced',
        'entries_debit' => [
            ['account_id' => $this->cash->id, 'amount' => '100000', 'memo' => null],
        ],
        'entries_credit' => [
            ['account_id' => $this->revenue->id, 'amount' => '50000', 'memo' => null],
        ],
    ];

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', $payload)
        ->assertStatus(422);
});

it('reverses a posted journal via SPA endpoint', function () {
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JU-2026-05-200',
        'date' => '2026-05-04',
        'memo' => 'To reverse',
        'status' => Journal::STATUS_DRAFT,
    ]);

    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 50000, 'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 2,
        'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 50000,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$journal->id}/post", [])
        ->assertOk();

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$journal->id}/reverse", ['reason' => 'wrong amount'])
        ->assertOk();

    expect(Journal::where('id', $journal->id)->value('status'))->toBe(Journal::STATUS_REVERSED);
    $reversalId = Journal::where('id', $journal->id)->value('reversed_by_journal_id');
    expect($reversalId)->not->toBeNull();
    expect(Journal::where('id', $reversalId)->value('type'))->toBe(Journal::TYPE_REVERSING);
});

it('replicates a journal as a fresh draft via SPA endpoint', function () {
    $source = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JU-2026-05-300',
        'date' => '2026-05-04',
        'memo' => 'Source for clone',
        'reference' => 'INV-001',
        'status' => Journal::STATUS_POSTED,
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);
    JournalEntry::create([
        'journal_id' => $source->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 75000, 'credit' => 0, 'memo' => 'd',
    ]);
    JournalEntry::create([
        'journal_id' => $source->id, 'line_no' => 2,
        'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 75000, 'memo' => 'c',
    ]);

    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$source->id}/replicate", []);

    $res->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.memo', 'Source for clone')
        ->assertJsonPath('data.reference', 'INV-001')
        ->assertJsonCount(1, 'data.entries_debit')
        ->assertJsonCount(1, 'data.entries_credit');

    $copyId = $res->json('data.id');
    expect($copyId)->not->toBe($source->id);
    expect(Journal::find($copyId)->status)->toBe(Journal::STATUS_DRAFT);
});

it('updates and posts a draft journal', function () {
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JU-2026-05-100',
        'date' => '2026-05-04',
        'memo' => 'Pre-update',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $update = [
        'number' => 'JU-2026-05-100',
        'date' => '2026-05-04',
        'memo' => 'Updated',
        'entries_debit' => [
            ['account_id' => $this->cash->id, 'amount' => '50000', 'memo' => null],
        ],
        'entries_credit' => [
            ['account_id' => $this->revenue->id, 'amount' => '50000', 'memo' => null],
        ],
    ];

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journals/{$journal->id}", $update)
        ->assertOk()
        ->assertJsonPath('data.memo', 'Updated');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$journal->id}/post", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'posted');
});
