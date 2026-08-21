<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Dual Book Tenant', 'slug' => 'dual-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dual Book Co',
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    $this->cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);
    $this->expense = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '6101',
        'name' => 'Beban Representasi',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);

    $this->rbacApp = RbacApp::create(['code' => 'accounting-test-'.uniqid(), 'name' => 'Accounting', 'version' => '1', 'enabled' => true]);
    foreach (['journal.read', 'fiscal.adjustment.read', 'fiscal.adjustment.manage', 'fiscal.adjustment.approve'] as $code) {
        $this->permissions[$code] = Permission::create(['app_id' => $this->rbacApp->id, 'code' => $code]);
    }

    $userWithRole = function (string $roleCode, array $permissionCodes): User {
        $user = User::create([
            'name' => ucfirst($roleCode),
            'email' => $roleCode.'-'.uniqid().'@example.test',
            'password_hash' => bcrypt('secret'),
        ]);
        $role = Role::create(['code' => $roleCode, 'name' => ucfirst($roleCode), 'is_preset' => false]);
        $role->permissions()->attach(collect($permissionCodes)->map(fn (string $code) => $this->permissions[$code]->id));
        $user->assignments()->create([
            'entity_id' => $this->entity->id,
            'app_id' => $this->rbacApp->id,
            'role_id' => $role->id,
        ]);

        return $user;
    };
    $this->taxUserId = $userWithRole('tax_officer', array_keys($this->permissions))->id;
    $this->inspectorUserId = $userWithRole('inspector', ['journal.read', 'fiscal.adjustment.read'])->id;

    $postJournal = function (string $mode, string $number, string $amount): Journal {
        $journal = Journal::create([
            'entity_id' => $this->entity->id,
            'period_id' => $this->period->id,
            'type' => Journal::TYPE_GENERAL,
            'journal_mode' => $mode,
            'number' => $number,
            'date' => '2026-04-15',
            'status' => Journal::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $this->expense->id, 'debit' => $amount, 'credit' => 0]);
        JournalEntry::create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $this->cash->id, 'debit' => 0, 'credit' => $amount]);

        return $journal;
    };
    $this->internalJournal = $postJournal(Journal::MODE_INTERNAL, 'JI-001', '20000000.00');
    $this->fiscalJournal = $postJournal(Journal::MODE_FISCAL, 'JF-001', '10000000.00');
});

it('keeps both books independent and applies approved corrections only to the final tax report', function () {
    $beforeEntries = JournalEntry::count();

    $created = $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fiscal-adjustments', [
            'journal_id' => $this->fiscalJournal->id,
            'account_id' => $this->expense->id,
            'date' => '2026-04-30',
            'direction' => 'positive',
            'amount' => '4000000.00',
            'reason' => 'Sebagian beban tidak memenuhi ketentuan deductibility.',
            'legal_basis' => 'Kebijakan pajak perusahaan',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', FiscalAdjustment::STATUS_DRAFT)
        ->assertJsonPath('data.created_by_name', 'Tax_officer');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/fiscal-reconciliation?period_start=2026-01-01&period_end=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.book_net_income', '-10000000.00')
        ->assertJsonPath('data.positive_adjustments', '0.00')
        ->assertJsonPath('data.final_net_income', '-10000000.00');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fiscal-adjustments/'.$created->json('data.id').'/approve')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachments');

    Storage::fake(config('filesystems.default'));
    $attachment = $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => FiscalAdjustment::class,
            'attachable_id' => $created->json('data.id'),
            'file' => UploadedFile::fake()->create('bukti-koreksi.pdf', 100, 'application/pdf'),
        ])
        ->assertCreated();

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fiscal-adjustments/'.$created->json('data.id').'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', FiscalAdjustment::STATUS_APPROVED)
        ->assertJsonPath('data.attachments_count', 1)
        ->assertJsonPath('data.approved_by_name', 'Tax_officer');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fiscal-adjustments?period_start=2026-01-01&period_end=2026-12-31')
        ->assertOk()
        ->assertJsonPath('meta.can_manage', true)
        ->assertJsonPath('meta.can_approve', true)
        ->assertJsonPath('data.0.approved_by_name', 'Tax_officer');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson('/api/v1/spa/fiscal-adjustments/'.$created->json('data.id'), [
            'journal_id' => $this->fiscalJournal->id,
            'account_id' => $this->expense->id,
            'date' => '2026-04-30',
            'direction' => 'negative',
            'amount' => '1000000.00',
            'reason' => 'Perubahan yang tidak boleh tersimpan.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson('/api/v1/spa/attachments/'.$attachment->json('data.id'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson('/api/v1/spa/fiscal-adjustments/'.$created->json('data.id'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect(JournalEntry::count())->toBe($beforeEntries)
        ->and($this->internalJournal->entries()->sum('debit'))->toEqual('20000000')
        ->and($this->fiscalJournal->entries()->sum('debit'))->toEqual('10000000');

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/fiscal-reconciliation?period_start=2026-01-01&period_end=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.book_net_income', '-10000000.00')
        ->assertJsonPath('data.positive_adjustments', '4000000.00')
        ->assertJsonPath('data.final_net_income', '-6000000.00')
        ->assertJsonPath('data.rows.0.book_amount', '10000000.00')
        ->assertJsonPath('data.rows.0.final_amount', '6000000.00');
});

it('applies approved negative corrections as a reduction to fiscal net income', function () {
    $user = User::findOrFail($this->taxUserId);
    $headers = ['X-Tenant-Slug' => $this->entity->id];

    $created = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/fiscal-adjustments', [
            'journal_id' => $this->fiscalJournal->id,
            'account_id' => $this->expense->id,
            'date' => '2026-04-30',
            'direction' => 'negative',
            'amount' => '2000000.00',
            'reason' => 'Beban dapat menjadi pengurang penghasilan Fiskal.',
        ])
        ->assertCreated();

    Storage::fake(config('filesystems.default'));
    $this->actingAs($user)
        ->withHeaders($headers)
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => FiscalAdjustment::class,
            'attachable_id' => $created->json('data.id'),
            'file' => UploadedFile::fake()->create('bukti-koreksi-negatif.pdf', 100, 'application/pdf'),
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson('/api/v1/spa/fiscal-adjustments/'.$created->json('data.id').'/approve')
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/reports/fiscal-reconciliation?period_start=2026-01-01&period_end=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.book_net_income', '-10000000.00')
        ->assertJsonPath('data.negative_adjustments', '2000000.00')
        ->assertJsonPath('data.final_net_income', '-12000000.00')
        ->assertJsonPath('data.rows.0.final_amount', '12000000.00');
});

it('forces inspector reads to the fiscal book and rejects internal drill down', function () {
    $this->travelTo('2026-04-20');

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fiscal-adjustments')
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonPath('meta.can_approve', false);

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fiscal-adjustments', [
            'account_id' => $this->expense->id,
            'date' => '2026-04-30',
            'direction' => 'positive',
            'amount' => '1000000.00',
            'reason' => 'Inspector tidak boleh membuat koreksi.',
        ])
        ->assertForbidden();

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals?journal_mode=internal')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->fiscalJournal->id);

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/journals/'.$this->internalJournal->id)
        ->assertForbidden();

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-12-31&journal_mode=internal')
        ->assertForbidden();

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-12-31&journal_mode=fiscal')
        ->assertOk()
        ->assertJsonPath('data.total_debit', '10000000.00');

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/widgets/recent-journals')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->fiscalJournal->id);

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/widgets/financial-pulse')
        ->assertOk()
        ->assertJsonPath('data.expenses.current', '10000000.00');
});

it('returns independent internal and fiscal values in combined financial reports', function () {
    $user = User::findOrFail($this->taxUserId);
    $headers = ['X-Tenant-Slug' => $this->entity->id];

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/reports/balance-sheet?as_of=2026-12-31&journal_mode=both')
        ->assertOk()
        ->assertJsonPath('data.equity.net_income_ytd', '-20000000.00')
        ->assertJsonPath('data.fiscal.equity.net_income_ytd', '-10000000.00');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/reports/income-statement?period_start=2026-01-01&period_end=2026-12-31&journal_mode=both')
        ->assertOk()
        ->assertJsonPath('data.expenses.total', '20000000.00')
        ->assertJsonPath('data.fiscal.expenses.total', '10000000.00');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/reports/general-ledger?account_id='.$this->expense->id.'&period_start=2026-01-01&period_end=2026-12-31&journal_mode=both')
        ->assertOk()
        ->assertJsonPath('data.total_debit', '20000000.00')
        ->assertJsonPath('data.fiscal.total_debit', '10000000.00');

    $this->actingAs(User::findOrFail($this->inspectorUserId))
        ->withHeaders($headers)
        ->getJson('/api/v1/spa/reports/income-statement?period_start=2026-01-01&period_end=2026-12-31&journal_mode=both')
        ->assertForbidden();
});

it('does not expose fiscal corrections when the entity uses internal only books', function () {
    $this->entity->update([
        'workspace_settings' => ['bookkeeping_mode' => 'internal_only'],
    ]);

    $this->actingAs(User::findOrFail($this->taxUserId))
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fiscal-adjustments', [
            'account_id' => $this->expense->id,
            'date' => '2026-04-30',
            'direction' => 'positive',
            'amount' => '4000000.00',
            'reason' => 'Tidak boleh tersimpan pada mode buku Intern saja.',
        ])
        ->assertNotFound();

    expect(FiscalAdjustment::query()->where('entity_id', $this->entity->id)->exists())->toBeFalse();
});
