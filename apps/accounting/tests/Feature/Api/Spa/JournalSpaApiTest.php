<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Attachment;
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
        'workspace_settings' => [
            'bookkeeping_mode' => 'independent_books',
            'journal_number_format' => 'JU-{thn}{bln}-{incremented_number}',
        ],
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
        'availability' => 'both',
    ]);
    $this->revenue = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '4101',
        'name' => 'Penjualan',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'is_postable' => true,
        'availability' => 'both',
    ]);

    $this->user = User::create([
        'name' => 'SPA User',
        'email' => 'spa-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $app = RbacApp::create([
        'code' => 'accounting',
        'name' => 'Accounting',
        'version' => '0.1',
        'enabled' => true,
    ]);
    $role = Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'is_preset' => false,
    ]);
    $permissionIds = collect([
        'journal.read',
        'journal.create',
        'journal.update',
        'journal.delete',
        'journal.post',
        'journal.submit',
        'journal.review',
        'journal.reverse',
    ])->map(fn (string $code) => Permission::create([
        'app_id' => $app->id,
        'code' => $code,
    ])->id);
    $role->permissions()->attach($permissionIds);

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
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.status_counts.draft', 1)
        ->assertJsonPath('meta.status_counts.submitted', 0);
});

it('filters journals by period, account, type, and date range', function () {
    $createJournal = function (string $number, string $date, string $type, Account $account): Journal {
        $journal = Journal::create([
            'entity_id' => $this->entity->id,
            'period_id' => $this->period->id,
            'type' => $type,
            'journal_mode' => Journal::MODE_INTERNAL,
            'number' => $number,
            'date' => $date,
            'memo' => 'Filter test',
            'status' => Journal::STATUS_DRAFT,
        ]);
        JournalEntry::create([
            'journal_id' => $journal->id,
            'line_no' => 1,
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
        ]);

        return $journal;
    };

    $match = $createJournal('JU-FILTER-MATCH', '2026-05-12', Journal::TYPE_GENERAL, $this->cash);
    $createJournal('JU-FILTER-ACCOUNT', '2026-05-12', Journal::TYPE_GENERAL, $this->revenue);
    $createJournal('JU-FILTER-DATE', '2026-05-29', Journal::TYPE_GENERAL, $this->cash);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals?journal_mode=internal&period_id='.$this->period->id
            .'&account_id='.$this->cash->id.'&type='.Journal::TYPE_GENERAL
            .'&date_from=2026-05-10&date_to=2026-05-20')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $match->id);
});

it('previews the next journal number for the selected date and mode', function () {
    Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-4',
        'date' => '2026-05-04',
        'memo' => 'Existing journal',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-number?date=2026-05-04&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.number', 'JU-202605-5');
});

it('uses the configured number formats for the workspace', function () {
    $this->entity->update([
        'workspace_settings' => [
            'journal_number_format' => 'JP/{thn}{bln}/{incremented_number}',
            'transaction_number_format' => 'JR-{thn}{bln}-{incremented_number}',
        ],
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-number?date=2026-05-04&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.number', 'JP/202605/1');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-transaction-code?date=2026-05-04')
        ->assertOk()
        ->assertJsonPath('data.transaction_code', 'JR-202605-1');
});

it('uses entity-scoped increment starts without moving backward from existing numbers', function () {
    $this->entity->update([
        'workspace_settings' => [
            'journal_number_formats' => ['general' => 'J/{numbering}'],
            'journal_number_starts' => ['general' => 500],
            'transaction_number_format' => 'T/{numbering}',
            'transaction_number_start' => 900,
        ],
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-number?date=2026-05-04&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.number', 'J/500');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-transaction-code?date=2026-05-04')
        ->assertOk()
        ->assertJsonPath('data.transaction_code', 'T/900');

    Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'J/700',
        'date' => '2026-05-04',
        'memo' => 'Existing higher sequence',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/next-number?date=2026-05-04&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.number', 'J/701');
});

it('creates a balanced draft journal via SPA endpoint', function () {
    $payload = [
        'transaction_code' => 'TRX-2026-05-010',
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
        ->assertJsonPath('data.number', 'JU-202605-1')
        ->assertJsonPath('data.transaction_code', 'TRX-2026-05-010')
        ->assertJsonPath('data.reference', 'INV-2026-05-010')
        ->assertJsonPath('data.journal_mode', 'internal')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.audit_trail.0.action', 'journal.created')
        ->assertJsonPath('data.audit_trail.0.actor_name', 'SPA User')
        ->assertJsonCount(1, 'data.entries_debit')
        ->assertJsonCount(1, 'data.entries_credit');

    expect(Journal::where('number', 'JU-202605-1')
        ->where('reference', 'INV-2026-05-010')
        ->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'journal.created')
            ->where('resource_type', Journal::class)
            ->where('actor_user_id', $this->user->id)
            ->exists())->toBeTrue();
});

it('generates a fiscal journal number with the fiscal prefix', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'transaction_code' => 'TRX-2026-05-011',
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
        ->assertJsonPath('data.number', 'JU-202605-1')
        ->assertJsonPath('data.journal_mode', 'fiscal');
    expect(Journal::where('number', 'JU-202605-1')->value('journal_mode'))
        ->toBe(Journal::MODE_FISCAL);
});

it('creates paired intern and fiscal drafts for the combined input mode', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'journal_mode' => 'both',
            'date' => '2026-05-12',
            'memo' => 'Pembelian dicatat untuk kedua buku',
            'transaction_code' => 'TRX-DUAL-001',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.journal_mode', Journal::MODE_INTERNAL)
        ->assertJsonPath('data.transaction_code', 'TRX-DUAL-001')
        ->assertJsonPath('data.paired_journal.journal_mode', Journal::MODE_FISCAL)
        ->assertJsonPath('data.paired_journal.transaction_code', 'TRX-DUAL-001');

    $groupId = $res->json('data.input_group_id');
    expect($groupId)->not->toBeNull()
        ->and(Journal::where('input_group_id', $groupId)->count())->toBe(2)
        ->and(Journal::where('input_group_id', $groupId)->pluck('journal_mode')->sort()->values()->all())
        ->toBe([Journal::MODE_FISCAL, Journal::MODE_INTERNAL]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/'.$res->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.journal_mode', Journal::MODE_INTERNAL)
        ->assertJsonPath('data.combined_input', true);
});

it('maps existing attachment records to a newly created fiscal pair using the same stored object', function () {
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-060',
        'date' => '2026-05-16',
        'memo' => 'Jurnal dengan bukti',
        'status' => Journal::STATUS_DRAFT,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 100, 'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 2,
        'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 100,
    ]);
    $attachment = Attachment::create([
        'attachable_type' => Journal::class,
        'attachable_id' => $journal->id,
        'entity_id' => $this->entity->id,
        'filename' => 'bukti.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 123,
        'disk' => 'local',
        'path' => 'attachments/shared/bukti.pdf',
        'checksum_sha256' => str_repeat('a', 64),
        'uploaded_by' => $this->user->id,
    ]);

    $updated = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journals/{$journal->id}", [
            'journal_mode' => 'both',
            'type' => Journal::TYPE_GENERAL,
            'date' => '2026-05-16',
            'memo' => 'Jurnal dengan bukti',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ])
        ->assertOk()
        ->assertJsonPath('data.paired_journal.journal_mode', Journal::MODE_FISCAL);

    $pairedAttachment = Attachment::query()
        ->where('attachable_type', Journal::class)
        ->where('attachable_id', $updated->json('data.paired_journal.id'))
        ->sole();

    expect($pairedAttachment->id)->not->toBe($attachment->id)
        ->and($pairedAttachment->filename)->toBe($attachment->filename)
        ->and($pairedAttachment->path)->toBe($attachment->path)
        ->and(Attachment::where('attachable_id', $journal->id)->count())->toBe(1);
});

it('allows an admin to edit a submitted journal mode and send it for revision', function () {
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-050',
        'date' => '2026-05-14',
        'memo' => 'Review journal',
        'status' => Journal::STATUS_SUBMITTED,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 100, 'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 2,
        'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 100,
    ]);

    $payload = [
        'journal_mode' => 'both',
        'type' => Journal::TYPE_GENERAL,
        'date' => '2026-05-14',
        'memo' => 'Review journal diperbarui',
        'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
        'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
    ];

    $updated = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journals/{$journal->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.journal_mode', Journal::MODE_INTERNAL)
        ->assertJsonPath('data.paired_journal.journal_mode', Journal::MODE_FISCAL);

    expect($updated->json('data.paired_journal.status'))->toBe(Journal::STATUS_SUBMITTED)
        ->and(Journal::where('input_group_id', $updated->json('data.input_group_id'))->count())->toBe(2);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$journal->id}/reject", ['note' => 'Lengkapi bukti transaksi.'])
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_REJECTED)
        ->assertJsonPath('data.review_note', 'Lengkapi bukti transaksi.');
});

it('allows a submitter to cancel review and records the status transition', function () {
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-051',
        'date' => '2026-05-15',
        'memo' => 'Cancel review test',
        'status' => Journal::STATUS_SUBMITTED,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1,
        'account_id' => $this->cash->id, 'debit' => 100, 'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 2,
        'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 100,
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson("/api/v1/spa/journals/{$journal->id}/cancel-review");

    $response->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_DRAFT)
        ->assertJsonPath('data.audit_trail.0.action', 'journal.cancel_review')
        ->assertJsonPath('data.audit_trail.0.status_from', Journal::STATUS_SUBMITTED)
        ->assertJsonPath('data.audit_trail.0.status_to', Journal::STATUS_DRAFT);

    $audit = AuditLog::query()
        ->where('resource_id', $journal->id)
        ->where('action', 'journal.cancel_review')
        ->firstOrFail();

    expect($audit->metadata['status_from'])->toBe(Journal::STATUS_SUBMITTED);
});

it('soft deletes draft journals, lists them in trashed, and restores them as draft', function () {
    Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-051',
        'date' => '2026-05-15',
        'memo' => 'Active submitted journal',
        'status' => Journal::STATUS_SUBMITTED,
    ]);

    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-052',
        'date' => '2026-05-16',
        'memo' => 'Trash workflow test',
        'status' => Journal::STATUS_DRAFT,
    ]);

    $request = fn (string $method, string $url) => $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->{$method}($url);

    $request('delete', "/api/v1/spa/journals/{$journal->id}")->assertNoContent();

    expect(Journal::find($journal->id))->toBeNull()
        ->and(Journal::withTrashed()->find($journal->id)?->trashed())->toBeTrue();

    $request('get', '/api/v1/spa/journals?status=draft')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $request('get', '/api/v1/spa/journals?status=trashed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.status_counts.submitted', 1)
        ->assertJsonPath('meta.status_counts.draft', 0)
        ->assertJsonPath('data.0.number', 'JU-202605-052')
        ->assertJsonPath('data.0.status', Journal::STATUS_DRAFT);

    $request('post', "/api/v1/spa/journals/{$journal->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_DRAFT);

    expect(Journal::find($journal->id))->not->toBeNull()
        ->and(Journal::find($journal->id)?->status)->toBe(Journal::STATUS_DRAFT);
});

it('skips numbers still held by trashed journals when creating a new journal', function () {
    foreach ([3, 4] as $sequence) {
        Journal::create([
            'entity_id' => $this->entity->id,
            'period_id' => $this->period->id,
            'type' => Journal::TYPE_GENERAL,
            'journal_mode' => Journal::MODE_INTERNAL,
            'number' => "JU-202605-{$sequence}",
            'transaction_code' => "TRX/26/5/{$sequence}",
            'date' => '2026-05-04',
            'memo' => "Journal {$sequence}",
            'status' => Journal::STATUS_DRAFT,
        ]);
    }
    Journal::where('number', 'JU-202605-4')->firstOrFail()->delete();

    $request = fn (string $method, string $url, array $data = []) => $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->{$method}($url, $data);

    $request('getJson', '/api/v1/spa/journals/next-number?date=2026-05-04&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.number', 'JU-202605-5');

    $this->entity->update(['workspace_settings' => array_merge($this->entity->workspace_settings, [
        'transaction_number_format' => 'TRX/{tahun}/{bulan}/{numbering}',
    ])]);

    $request('postJson', '/api/v1/spa/journals', [
        'date' => '2026-05-04',
        'memo' => 'After trashing the latest journal',
        'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '1000']],
        'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '1000']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.number', 'JU-202605-5')
        ->assertJsonPath('data.transaction_code', 'TRX/26/5/5');
});

it('returns a validation error instead of a server error for a duplicate journal number', function () {
    Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-7',
        'date' => '2026-05-04',
        'memo' => 'Trashed journal',
        'status' => Journal::STATUS_DRAFT,
    ])->delete();

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'number' => 'JU-202605-7',
            'date' => '2026-05-04',
            'memo' => 'Manual duplicate number',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '1000']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '1000']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['number']);

    expect(Journal::withTrashed()->where('number', 'JU-202605-7')->count())->toBe(1);
});

it('allows a supervisor to trash a saved journal and records the audit trail', function () {
    $this->user->assignments()->first()->role->update(['code' => 'supervisor']);
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-053',
        'date' => '2026-05-17',
        'memo' => 'Saved journal for supervisor trash',
        'status' => Journal::STATUS_POSTED,
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/journals/{$journal->id}")
        ->assertNoContent();

    expect(Journal::find($journal->id))->toBeNull()
        ->and(Journal::withTrashed()->find($journal->id)?->trashed())->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'journal.trash')
            ->where('resource_id', $journal->id)
            ->first()?->metadata['status_from'])->toBe(Journal::STATUS_POSTED);
});

it('rejects an operator from trashing a saved journal', function () {
    $this->user->assignments()->first()->role->update(['code' => 'operator']);
    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'JU-202605-054',
        'date' => '2026-05-18',
        'memo' => 'Saved journal for operator restriction',
        'status' => Journal::STATUS_POSTED,
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/journals/{$journal->id}")
        ->assertForbidden();

    expect(Journal::find($journal->id))->not->toBeNull()
        ->and(AuditLog::query()
            ->where('action', 'journal.trash')
            ->where('resource_id', $journal->id)
            ->exists())->toBeFalse();
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

it('rejects an inactive journal account even when it is visible in suggestions', function (string $mode) {
    $this->cash->update(['is_active' => false]);

    $this->actingAs($this->user)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'journal_mode' => $mode, 'date' => '2026-05-04', 'memo' => 'Inactive account attempt',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ])->assertUnprocessable();

    expect(Journal::where('memo', 'Inactive account attempt')->exists())->toBeFalse();
})->with(['internal', 'fiscal']);

it('allows an active child account when its parent is inactive', function (string $mode) {
    $parent = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1000', 'name' => 'Bank',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false,
        'is_active' => false, 'availability' => 'both',
    ]);
    $this->cash->update(['parent_account_id' => $parent->id]);

    $this->actingAs($this->user)->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'journal_mode' => $mode, 'date' => '2026-05-04', 'memo' => 'Active child account',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ])->assertCreated()->assertJsonPath('data.journal_mode', $mode);
})->with(['internal', 'fiscal']);

it('rejects an account unavailable for the selected journal mode', function () {
    $this->cash->update(['availability' => 'intern']);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/journals', [
            'journal_mode' => Journal::MODE_FISCAL,
            'date' => '2026-05-04',
            'memo' => 'Fiscal journal',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ])
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

    expect(AuditLog::query()
        ->where('action', 'journal.updated')
        ->where('resource_id', $journal->id)
        ->first()?->metadata['snapshot']['memo'])->toBe('Updated');
});

it('allows an admin to update a posted journal and exposes its snapshot trail', function () {
    $this->user->assignments()->first()->role->update(['code' => 'admin']);
    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'JU-ADMIN-1', 'date' => '2026-05-04',
        'memo' => 'Before admin edit', 'status' => Journal::STATUS_POSTED,
        'posted_at' => now(), 'posted_by' => $this->user->id,
    ]);
    JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => 100, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 100]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/journals/{$journal->id}", [
            'date' => '2026-05-04', 'memo' => 'After admin edit',
            'entries_debit' => [['account_id' => $this->cash->id, 'amount' => '100']],
            'entries_credit' => [['account_id' => $this->revenue->id, 'amount' => '100']],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_POSTED)
        ->assertJsonPath('data.audit_trail.0.snapshot.memo', 'After admin edit');
    expect(AuditLog::where('action', 'journal.updated')->where('resource_id', $journal->id)->exists())->toBeTrue();
});
