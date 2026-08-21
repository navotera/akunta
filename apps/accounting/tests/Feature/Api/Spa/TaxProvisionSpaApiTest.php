<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\TaxProvision;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Tax Provision Tenant', 'slug' => 'tax-provision-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'Tax Provision Co',
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => Period::STATUS_OPEN,
    ]);

    $account = function (
        string $code,
        string $name,
        string $type,
        string $normalBalance,
        string $availability,
    ): Account {
        return Account::create([
            'entity_id' => $this->entity->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_postable' => true,
            'is_active' => true,
            'availability' => $availability,
        ]);
    };

    $this->cash = $account('1101', 'Kas', 'asset', 'debit', Account::AVAILABILITY_BOTH);
    $this->prepaidTax = $account('1403', 'Pajak Dibayar di Muka', 'asset', 'debit', Account::AVAILABILITY_BOTH);
    $this->taxPayable = $account('2113', 'Utang PPh Badan', 'liability', 'credit', Account::AVAILABILITY_INTERN);
    $this->revenue = $account('4101', 'Pendapatan', 'revenue', 'credit', Account::AVAILABILITY_BOTH);
    $this->expense = $account('6101', 'Beban Representasi', 'expense', 'debit', Account::AVAILABILITY_BOTH);
    $this->taxExpense = $account('6901', 'Beban Pajak Penghasilan Kini', 'expense', 'debit', Account::AVAILABILITY_INTERN);

    $fiscalJournal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_FISCAL,
        'number' => 'JF-REVENUE-001',
        'date' => '2026-12-20',
        'status' => Journal::STATUS_POSTED,
        'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $fiscalJournal->id,
        'line_no' => 1,
        'account_id' => $this->cash->id,
        'debit' => '100000000.00',
        'credit' => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $fiscalJournal->id,
        'line_no' => 2,
        'account_id' => $this->revenue->id,
        'debit' => 0,
        'credit' => '100000000.00',
    ]);

    $app = RbacApp::create([
        'code' => 'tax-provision-test-'.uniqid(),
        'name' => 'Accounting',
        'version' => '1',
        'enabled' => true,
    ]);
    $permissionCodes = [
        'journal.read',
        'journal.post',
        'journal.reverse',
        'fiscal.adjustment.read',
        'fiscal.tax_provision.read',
        'fiscal.tax_provision.manage',
    ];
    $permissions = collect($permissionCodes)->mapWithKeys(function (string $code) use ($app): array {
        $permission = Permission::create(['app_id' => $app->id, 'code' => $code]);

        return [$code => $permission];
    });
    $role = Role::create(['code' => 'tax_provision_manager', 'name' => 'Tax Provision Manager']);
    $role->permissions()->attach($permissions->pluck('id'));
    $this->user = User::create([
        'name' => 'Tax Manager',
        'email' => 'tax-manager-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id,
        'app_id' => $app->id,
        'role_id' => $role->id,
    ]);
    $this->headers = ['X-Tenant-Slug' => $this->entity->id];
});

it('calculates current tax and creates a separate internal draft journal', function () {
    $preview = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions/preview', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'tax_rate' => '22',
            'loss_compensation' => '10000000',
            'tax_credits' => '5000000',
        ])
        ->assertOk()
        ->assertJsonPath('data.fiscal_net_income', '100000000.00')
        ->assertJsonPath('data.taxable_income', '90000000.00')
        ->assertJsonPath('data.gross_current_tax', '19800000.00')
        ->assertJsonPath('data.tax_credits_applied', '5000000.00')
        ->assertJsonPath('data.current_tax_payable', '14800000.00')
        ->assertJsonPath('data.deferred_tax_status', 'not_calculated');

    expect($preview->json('data.approved_adjustments.positive'))->toBe('0.00');

    $created = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'recognition_date' => '2026-12-31',
            'tax_rate' => '22',
            'loss_compensation' => '10000000',
            'tax_credits' => '5000000',
            'expense_account_id' => $this->taxExpense->id,
            'payable_account_id' => $this->taxPayable->id,
            'prepaid_tax_account_id' => $this->prepaidTax->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.journal.status', Journal::STATUS_DRAFT)
        ->assertJsonPath('data.journal.journal_mode', Journal::MODE_INTERNAL)
        ->assertJsonPath('data.journal.total', '19800000.00');

    $provision = TaxProvision::findOrFail($created->json('data.id'));
    $journal = Journal::with('entries')->findOrFail($created->json('data.journal.id'));
    expect($journal->source_app)->toBe('tax_provision')
        ->and($journal->source_id)->toBe($provision->id)
        ->and($journal->entries)->toHaveCount(3)
        ->and((string) $journal->entries->sum('debit'))->toBe('19800000')
        ->and((string) $journal->entries->sum('credit'))->toBe('19800000');

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-12-31&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.total_debit', '0.00');

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/journals/'.$journal->id.'/post')
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_POSTED);

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/spa/reports/trial-balance?as_of=2026-12-31&journal_mode=internal')
        ->assertOk()
        ->assertJsonPath('data.total_debit', '19800000.00')
        ->assertJsonPath('data.total_credit', '19800000.00');

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/spa/reports/fiscal-reconciliation?period_start=2026-01-01&period_end=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.final_net_income', '100000000.00');
});

it('updates the same draft provision and refuses fiscal-only posting accounts', function () {
    $fiscalTaxExpense = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '8103',
        'name' => 'Beban PPh Fiskal Lama',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_FISKAL,
        'legal_basis' => 'Legacy',
    ]);

    $payload = [
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'recognition_date' => '2026-12-31',
        'tax_rate' => '22',
        'loss_compensation' => '0',
        'tax_credits' => '0',
        'expense_account_id' => $fiscalTaxExpense->id,
        'payable_account_id' => $this->taxPayable->id,
    ];

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expense_account_id');

    $payload['expense_account_id'] = $this->taxExpense->id;
    $first = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', $payload)
        ->assertCreated();
    $second = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', [...$payload, 'tax_rate' => '20'])
        ->assertCreated();

    expect(TaxProvision::count())->toBe(1)
        ->and(Journal::query()->where('source_app', 'tax_provision')->count())->toBe(1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.journal.id'))->toBe($first->json('data.journal.id'))
        ->and($second->json('data.gross_current_tax'))->toBe('20000000.00');
});

it('creates a replacement draft after the posted provision journal is reversed', function () {
    $payload = [
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'recognition_date' => '2026-12-31',
        'tax_rate' => '22',
        'loss_compensation' => '0',
        'tax_credits' => '0',
        'expense_account_id' => $this->taxExpense->id,
        'payable_account_id' => $this->taxPayable->id,
    ];

    $first = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', $payload)
        ->assertCreated();
    $firstJournalId = $first->json('data.journal.id');

    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/journals/'.$firstJournalId.'/post')
        ->assertOk();
    $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/journals/'.$firstJournalId.'/reverse', [
            'reason' => 'Perhitungan pajak diperbarui',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_REVERSED);

    $replacement = $this->actingAs($this->user)
        ->withHeaders($this->headers)
        ->postJson('/api/v1/spa/tax-provisions', [...$payload, 'tax_rate' => '20'])
        ->assertCreated()
        ->assertJsonPath('data.journal.status', Journal::STATUS_DRAFT);

    expect($replacement->json('data.journal.id'))->not->toBe($firstJournalId)
        ->and(Journal::query()->where('source_app', 'tax_provision')->count())->toBe(2);
});

it('does not expose the internal tax provision through fiscal adjustment read access', function () {
    $role = Role::create(['code' => 'fiscal_inspector', 'name' => 'Fiscal Inspector']);
    $role->permissions()->attach(
        Permission::query()->where('code', 'fiscal.adjustment.read')->firstOrFail()->id,
    );
    $inspector = User::create([
        'name' => 'Fiscal Inspector',
        'email' => 'fiscal-inspector-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $inspector->assignments()->create([
        'entity_id' => $this->entity->id,
        'app_id' => $role->permissions()->firstOrFail()->app_id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($inspector)
        ->withHeaders($this->headers)
        ->getJson('/api/v1/spa/tax-provisions/current?period_start=2026-01-01&period_end=2026-12-31')
        ->assertForbidden();
});
