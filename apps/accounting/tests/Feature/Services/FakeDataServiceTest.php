<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\FakeDataRecord;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\RecurringJournal;
use App\Models\SourceRefRegistry;
use App\Services\FakeDataService;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\FiscalReconciliationService;
use App\Services\Reporting\GeneralLedgerService;
use App\Services\Reporting\TrialBalanceService;
use App\Services\RequiredAccountService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo Teknologi', 'slug' => 'fake-it-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'PT Demo Teknologi',
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'Tahun Berjalan',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => Period::STATUS_OPEN,
    ]);
    $this->service = app(FakeDataService::class);
});

it('builds a complete technology demo for dashboards reports templates and recurring journals', function () {
    expect($this->service->import($this->entity, 'accounts'))->toBeGreaterThan(50)
        ->and($this->service->import($this->entity, 'journal_templates'))->toBe(6)
        ->and($this->service->import($this->entity, 'recurring_journals', $this->period))->toBe(3)
        ->and($this->service->import($this->entity, 'journals', $this->period))->toBeGreaterThan(25);

    expect(Account::where('entity_id', $this->entity->id)->where('code', '1102')->value('availability'))->toBe(Account::AVAILABILITY_BOTH)
        ->and(Account::where('entity_id', $this->entity->id)->where('code', '1202')->value('availability'))->toBe(Account::AVAILABILITY_INTERN)
        ->and(Account::where('entity_id', $this->entity->id)->where('code', '1592')->value('availability'))->toBe(Account::AVAILABILITY_FISKAL)
        ->and(Account::where('entity_id', $this->entity->id)->where('code', '6603')->value('availability'))->toBe(Account::AVAILABILITY_BOTH)
        ->and(Account::where('entity_id', $this->entity->id)->whereNull('description')->exists())->toBeFalse()
        ->and(Account::where('entity_id', $this->entity->id)->where('code', '6603')->value('description'))
        ->not->toStartWith('Definisi:')
        ->toContain('Digunakan', "\n\nContoh:");

    expect(JournalTemplate::where('entity_id', $this->entity->id)->count())->toBe(6)
        ->and(RecurringJournal::where('entity_id', $this->entity->id)->count())->toBe(3)
        ->and(Journal::where('entity_id', $this->entity->id)->where('journal_mode', Journal::MODE_INTERNAL)->where('status', Journal::STATUS_POSTED)->count())->toBeGreaterThan(10)
        ->and(Journal::where('entity_id', $this->entity->id)->where('journal_mode', Journal::MODE_FISCAL)->where('status', Journal::STATUS_POSTED)->count())->toBeGreaterThan(10)
        ->and(Journal::where('entity_id', $this->entity->id)->where('status', Journal::STATUS_DRAFT)->exists())->toBeTrue()
        ->and(Journal::where('entity_id', $this->entity->id)->where('status', Journal::STATUS_SUBMITTED)->exists())->toBeTrue()
        ->and(Journal::where('entity_id', $this->entity->id)->where('status', Journal::STATUS_REJECTED)->exists())->toBeTrue()
        ->and(FiscalAdjustment::where('entity_id', $this->entity->id)->exists())->toBeTrue()
        ->and(SourceRefRegistry::where('entity_id', $this->entity->id)->exists())->toBeTrue();

    $currentMonthJournalIds = Journal::query()
        ->where('entity_id', $this->entity->id)
        ->where('journal_mode', Journal::MODE_INTERNAL)
        ->where('status', Journal::STATUS_POSTED)
        ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
        ->pluck('id');
    expect(JournalEntry::whereIn('journal_id', $currentMonthJournalIds)->whereHas('account', fn ($query) => $query->where('type', 'revenue'))->exists())->toBeTrue()
        ->and(JournalEntry::whereIn('journal_id', $currentMonthJournalIds)->whereHas('account', fn ($query) => $query->where('type', 'expense'))->exists())->toBeTrue();

    $asOf = $this->period->end_date->toDateString();
    $trial = app(TrialBalanceService::class)->compute($this->entity->id, $asOf, Journal::MODE_INTERNAL);
    expect($trial['rows'])->not->toBeEmpty()
        ->and($trial['total_debit'])->toBe($trial['total_credit']);

    $balanceSheet = app(BalanceSheetService::class)->compute(
        $this->entity->id,
        $asOf,
        $this->period->start_date->toDateString(),
        Journal::MODE_INTERNAL,
    );
    expect($balanceSheet['assets']['lines'])->not->toBeEmpty()
        ->and($balanceSheet['equity']['lines'])->not->toBeEmpty()
        ->and($balanceSheet['balanced'])->toBeTrue();

    $fiscal = app(FiscalReconciliationService::class)->compute(
        $this->entity->id,
        $this->period->start_date->toDateString(),
        $asOf,
    );
    expect($fiscal['positive_adjustments'])->toBe('2000000.00')
        ->and($fiscal['rows'])->not->toBeEmpty();

    $bank = Account::where('entity_id', $this->entity->id)->where('code', '1102')->firstOrFail();
    $ledger = app(GeneralLedgerService::class)->compute(
        $this->entity->id,
        $bank->id,
        $this->period->start_date->toDateString(),
        $asOf,
    );
    expect($ledger['lines'])->not->toBeEmpty()
        ->and(JournalEntry::where('source_ref_type', 'customer')->exists())->toBeTrue();
});

it('backfills descriptions only on accounts marked as fake', function () {
    $manualBank = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1102',
        'name' => 'Bank Buatan User',
        'description' => 'Deskripsi manual yang tidak boleh ditimpa.',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);

    $this->service->import($this->entity, 'accounts');

    expect($manualBank->refresh()->name)->toBe('Bank Buatan User')
        ->and($manualBank->description)->toBe('Deskripsi manual yang tidak boleh ditimpa.')
        ->and(FakeDataRecord::where('model_id', $manualBank->id)->exists())->toBeFalse();

    $fakeCash = Account::where('entity_id', $this->entity->id)->where('code', '1101')->firstOrFail();
    $fakeCash->update(['description' => null]);
    $this->service->import($this->entity, 'accounts');

    expect($fakeCash->refresh()->description)
        ->not->toStartWith('Definisi:')
        ->toContain('Digunakan', "\n\nContoh:");
});

it('reuses a strongly equivalent manual account without duplicating or marking it fake', function () {
    $manualBank = Account::create([
        'entity_id' => $this->entity->id,
        'code' => 'BANK-OPS',
        'name' => 'Bank Operasional Utama',
        'description' => 'Rekening operasional yang dibuat manual.',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);

    $this->service->import($this->entity, 'accounts');

    expect(Account::where('entity_id', $this->entity->id)->where('code', '1102')->exists())->toBeFalse()
        ->and(Account::where('entity_id', $this->entity->id)->whereIn('name', ['Bank Operasional', 'Bank Operasional Utama'])->count())->toBe(1)
        ->and(FakeDataRecord::where('model_id', $manualBank->id)->exists())->toBeFalse();

    expect($this->service->import($this->entity, 'journal_templates'))->toBe(6)
        ->and($this->service->import($this->entity, 'journals', $this->period))->toBeGreaterThan(25)
        ->and(JournalEntry::where('account_id', $manualBank->id)->exists())->toBeTrue()
        ->and(FakeDataRecord::where('model_id', $manualBank->id)->exists())->toBeFalse();
});

it('does not treat a conflicting legacy code as the same economic account', function () {
    $legacyReturn = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '4102',
        'name' => 'Retur Penjualan',
        'description' => 'Akun kontra pendapatan manual.',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);

    $this->service->import($this->entity, 'accounts');

    $saasRevenue = Account::where('entity_id', $this->entity->id)
        ->where('name', 'Pendapatan Langganan SaaS')
        ->firstOrFail();

    expect($legacyReturn->refresh()->name)->toBe('Retur Penjualan')
        ->and($saasRevenue->code)->not->toBe('4102')
        ->and(FakeDataRecord::where('model_id', $legacyReturn->id)->exists())->toBeFalse()
        ->and(FakeDataRecord::where('model_id', $saasRevenue->id)->exists())->toBeTrue();

    $this->service->import($this->entity, 'journal_templates');
    $this->service->import($this->entity, 'journals', $this->period);

    expect(JournalEntry::where('account_id', $saasRevenue->id)->exists())->toBeTrue()
        ->and(JournalEntry::where('account_id', $legacyReturn->id)->exists())->toBeFalse();
});

it('promotes a fake account and its hierarchy when a real journal uses it', function () {
    $this->service->import($this->entity, 'accounts');
    $saasRevenue = Account::where('entity_id', $this->entity->id)->where('code', '4102')->firstOrFail();
    $revenueParent = Account::where('entity_id', $this->entity->id)->where('code', '4000')->firstOrFail();

    expect(FakeDataRecord::where('model_id', $saasRevenue->id)->exists())->toBeTrue()
        ->and(FakeDataRecord::where('model_id', $revenueParent->id)->exists())->toBeTrue();

    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'MANUAL-PROMOTE-001',
        'date' => $this->period->start_date,
        'memo' => 'Jurnal dibuat user',
        'status' => Journal::STATUS_DRAFT,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id,
        'line_no' => 1,
        'account_id' => $saasRevenue->id,
        'debit' => 0,
        'credit' => 1_000_000,
        'memo' => 'Pendapatan SaaS manual',
    ]);

    expect(FakeDataRecord::where('model_id', $saasRevenue->id)->exists())->toBeFalse()
        ->and(FakeDataRecord::where('model_id', $revenueParent->id)->exists())->toBeFalse()
        ->and(Account::find($saasRevenue->id))->not->toBeNull();
});

it('deletes only marked fake records and preserves all manual financial data', function () {
    $manualDebit = Account::create([
        'entity_id' => $this->entity->id,
        'code' => 'MAN-1101',
        'name' => 'Kas Manual',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);
    $manualCredit = Account::create([
        'entity_id' => $this->entity->id,
        'code' => 'MAN-3101',
        'name' => 'Modal Manual',
        'type' => 'equity',
        'normal_balance' => 'credit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);
    $manualJournal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_OPENING,
        'journal_mode' => Journal::MODE_INTERNAL,
        'number' => 'MANUAL-001',
        'date' => $this->period->start_date,
        'memo' => 'Input manual user',
        'status' => Journal::STATUS_POSTED,
    ]);
    JournalEntry::create(['journal_id' => $manualJournal->id, 'line_no' => 1, 'account_id' => $manualDebit->id, 'debit' => 1_000_000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $manualJournal->id, 'line_no' => 2, 'account_id' => $manualCredit->id, 'debit' => 0, 'credit' => 1_000_000]);

    $this->service->import($this->entity, 'accounts');
    $this->service->import($this->entity, 'journal_templates');
    $this->service->import($this->entity, 'recurring_journals', $this->period);
    $this->service->import($this->entity, 'journals', $this->period);

    $this->service->delete($this->entity, 'journals');
    $this->service->delete($this->entity, 'recurring_journals');
    $this->service->delete($this->entity, 'journal_templates');
    $this->service->delete($this->entity, 'accounts');

    expect(Journal::find($manualJournal->id))->not->toBeNull()
        ->and(Account::find($manualDebit->id))->not->toBeNull()
        ->and(Account::find($manualCredit->id))->not->toBeNull()
        ->and(Journal::where('entity_id', $this->entity->id)->where('source_app', 'fake-data')->exists())->toBeFalse();
});

it('never follows a corrupt marker across tenant boundaries', function () {
    $otherTenant = Tenant::create(['name' => 'Tenant Lain', 'slug' => 'other-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $otherTenant->id, 'name' => 'Entitas Lain']);
    $manualOtherAccount = Account::create([
        'entity_id' => $otherEntity->id,
        'code' => 'OTHER-1101',
        'name' => 'Akun Manual Tenant Lain',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_BOTH,
    ]);
    FakeDataRecord::create([
        'entity_id' => $this->entity->id,
        'group_key' => 'accounts',
        'model_type' => Account::class,
        'model_id' => $manualOtherAccount->id,
    ]);

    $this->service->delete($this->entity, 'accounts');

    expect(Account::find($manualOtherAccount->id))->not->toBeNull()
        ->and(FakeDataRecord::where('model_id', $manualOtherAccount->id)->exists())->toBeFalse();
});

it('keeps required system accounts when fake COA is cleared', function () {
    $this->service->import($this->entity, 'accounts');
    $required = Account::query()
        ->where('entity_id', $this->entity->id)
        ->where('system_key', RequiredAccountService::CURRENT_TAX_EXPENSE)
        ->firstOrFail();

    $this->service->delete($this->entity, 'accounts');

    expect(Account::find($required->id))->not->toBeNull()
        ->and(Account::query()->where('entity_id', $this->entity->id)->whereNotNull('system_key')->count())->toBe(4)
        ->and(FakeDataRecord::query()->where('model_id', $required->id)->exists())->toBeFalse();
});

it('adapts the complete demo to an Intern-only workspace', function () {
    $this->entity->update(['workspace_settings' => ['bookkeeping_mode' => 'internal_only']]);

    $this->service->import($this->entity, 'accounts');
    expect($this->service->import($this->entity, 'journal_templates'))->toBe(5)
        ->and($this->service->import($this->entity, 'recurring_journals', $this->period))->toBe(3)
        ->and($this->service->import($this->entity, 'journals', $this->period))->toBeGreaterThan(10)
        ->and(Account::where('entity_id', $this->entity->id)->where('availability', Account::AVAILABILITY_FISKAL)->exists())->toBeFalse()
        ->and(Journal::where('entity_id', $this->entity->id)->where('journal_mode', Journal::MODE_FISCAL)->exists())->toBeFalse();
});
