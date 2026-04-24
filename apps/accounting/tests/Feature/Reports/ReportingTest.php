<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\IncomeStatementService;
use App\Services\Reporting\TrialBalanceService;
use Database\Seeders\CoaTemplateSeeder;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Test Co']);
    (new CoaTemplateSeeder)->run($this->entity->id);

    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $this->user = User::create([
        'name' => 'T',
        'email' => 't+'.uniqid().'@test.test',
        'password_hash' => bcrypt('x'),
    ]);

    $this->accountId = fn (string $code) => Account::where('entity_id', $this->entity->id)
        ->where('code', $code)->firstOrFail()->id;

    $this->postJournal = function (string $date, array $lines) {
        $j = Journal::create([
            'entity_id' => $this->entity->id,
            'period_id' => $this->period->id,
            'type' => Journal::TYPE_GENERAL,
            'number' => 'JRN-'.uniqid(),
            'date' => $date,
            'status' => Journal::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);
        foreach ($lines as $i => [$code, $debit, $credit]) {
            JournalEntry::create([
                'journal_id' => $j->id,
                'line_no' => $i + 1,
                'account_id' => ($this->accountId)($code),
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return $j;
    };
});

it('trial balance sums debit + credit totals per account + grand totals tie', function () {
    // 3 balanced journals.
    ($this->postJournal)('2026-04-05', [
        ['1101', '1000000', '0'],        // Kas debit
        ['3101', '0', '1000000'],        // Modal credit (opening capital)
    ]);
    ($this->postJournal)('2026-04-10', [
        ['1101', '500000', '0'],         // Kas debit
        ['4101', '0', '500000'],         // Penjualan credit
    ]);
    ($this->postJournal)('2026-04-15', [
        ['6101', '200000', '0'],         // Biaya Gaji debit
        ['1101', '0', '200000'],         // Kas credit
    ]);

    $tb = app(TrialBalanceService::class)->compute($this->entity->id, '2026-04-30');

    expect($tb['total_debit'])->toBe($tb['total_credit'])
        ->and($tb['total_debit'])->toBe('1700000.00');

    $byCode = $tb['rows']->keyBy('code');
    expect($byCode['1101']->balance)->toBe('1300000.00')  // 1500k debit - 200k credit
        ->and($byCode['3101']->balance)->toBe('1000000.00')
        ->and($byCode['4101']->balance)->toBe('500000.00')
        ->and($byCode['6101']->balance)->toBe('200000.00');
});

it('trial balance excludes draft journals', function () {
    // Posted.
    ($this->postJournal)('2026-04-05', [
        ['1101', '100000', '0'],
        ['4101', '0', '100000'],
    ]);
    // Draft (should NOT appear in trial balance).
    $draft = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JRN-DRAFT',
        'date' => '2026-04-10',
    ]);
    JournalEntry::create(['journal_id' => $draft->id, 'line_no' => 1, 'account_id' => ($this->accountId)('1101'), 'debit' => '999999', 'credit' => '0']);
    JournalEntry::create(['journal_id' => $draft->id, 'line_no' => 2, 'account_id' => ($this->accountId)('4101'), 'debit' => '0', 'credit' => '999999']);

    $tb = app(TrialBalanceService::class)->compute($this->entity->id, '2026-04-30');
    $byCode = $tb['rows']->keyBy('code');

    expect($byCode['1101']->balance)->toBe('100000.00');
});

it('trial balance filters by as_of date', function () {
    ($this->postJournal)('2026-04-05', [['1101', '100000', '0'], ['4101', '0', '100000']]);
    ($this->postJournal)('2026-04-20', [['1101', '50000', '0'], ['4101', '0', '50000']]);

    $tbEarly = app(TrialBalanceService::class)->compute($this->entity->id, '2026-04-10');
    $tbLate = app(TrialBalanceService::class)->compute($this->entity->id, '2026-04-30');

    expect($tbEarly['total_debit'])->toBe('100000.00')
        ->and($tbLate['total_debit'])->toBe('150000.00');
});

it('income statement computes revenue - cogs - expense = net income', function () {
    ($this->postJournal)('2026-04-10', [['1101', '1000000', '0'], ['4101', '0', '1000000']]);  // revenue
    ($this->postJournal)('2026-04-12', [['5101', '300000', '0'], ['1101', '0', '300000']]);   // cogs
    ($this->postJournal)('2026-04-15', [['6101', '200000', '0'], ['1101', '0', '200000']]);   // expense (Biaya Gaji)

    $is = app(IncomeStatementService::class)->compute($this->entity->id, '2026-04-01', '2026-04-30');

    expect($is['revenue']['total'])->toBe('1000000.00')
        ->and($is['cogs']['total'])->toBe('300000.00')
        ->and($is['gross_profit'])->toBe('700000.00')
        ->and($is['expenses']['total'])->toBe('200000.00')
        ->and($is['net_income'])->toBe('500000.00');
});

it('income statement only includes journals within date range', function () {
    ($this->postJournal)('2026-03-31', [['1101', '999999', '0'], ['4101', '0', '999999']]);
    ($this->postJournal)('2026-04-10', [['1101', '500000', '0'], ['4101', '0', '500000']]);
    ($this->postJournal)('2026-05-01', [['1101', '888888', '0'], ['4101', '0', '888888']]);

    $is = app(IncomeStatementService::class)->compute($this->entity->id, '2026-04-01', '2026-04-30');

    expect($is['revenue']['total'])->toBe('500000.00');
});

it('balance sheet is balanced when assets = liabilities + equity + net income', function () {
    // Opening capital.
    ($this->postJournal)('2026-01-01', [['1101', '10000000', '0'], ['3101', '0', '10000000']]);
    // Sale during period (profit).
    ($this->postJournal)('2026-04-10', [['1101', '2000000', '0'], ['4101', '0', '2000000']]);
    // Expense.
    ($this->postJournal)('2026-04-15', [['6101', '500000', '0'], ['1101', '0', '500000']]);

    $bs = app(BalanceSheetService::class)->compute($this->entity->id, '2026-04-30');

    expect($bs['balanced'])->toBeTrue();

    // Assets: Kas = 10M + 2M - 500k = 11.5M.
    expect($bs['assets']['total'])->toBe('11500000.00');

    // Liabilities: none.
    expect($bs['liabilities']['total'])->toBe('0.00');

    // Equity: 10M capital + 1.5M YTD net income (2M revenue - 500k expense).
    expect($bs['equity']['total'])->toBe('11500000.00')
        ->and($bs['equity']['net_income_ytd'])->toBe('1500000.00');
});

it('balance sheet balances after a reversal', function () {
    ($this->postJournal)('2026-04-10', [['1101', '1000000', '0'], ['4101', '0', '1000000']]);
    // Reversal would normally be via ReverseJournalAction; simulate by posting mirror + marking original reversed manually.
    $mirror = ($this->postJournal)('2026-04-11', [['1101', '0', '1000000'], ['4101', '1000000', '0']]);

    $bs = app(BalanceSheetService::class)->compute($this->entity->id, '2026-04-30');

    expect($bs['balanced'])->toBeTrue()
        ->and($bs['assets']['total'])->toBe('0.00');
});
