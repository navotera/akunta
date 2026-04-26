<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\Reporting\GeneralLedgerService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT GL', 'slug' => 'gl-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'GL Co']);
    $this->period = Period::create([
        'entity_id'  => $this->entity->id, 'name' => 'Year 2026',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $this->cash = Account::create([
        'entity_id'      => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rev = Account::create([
        'entity_id'      => $this->entity->id, 'code' => '4101', 'name' => 'Pendapatan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
});

function postSale($entity, $period, $cash, $rev, string $number, string $date, $amt): void
{
    $j = Journal::create([
        'entity_id' => $entity->id, 'period_id' => $period->id,
        'type' => 'general', 'number' => $number, 'date' => $date,
        'status' => 'posted', 'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $cash->id, 'debit' => $amt,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $rev->id, 'credit' => $amt,
    ]);
}

it('computes opening + ending + lines for a debit-normal account', function () {
    postSale($this->entity, $this->period, $this->cash, $this->rev, 'GJ-1', '2026-03-15', 1000000);
    postSale($this->entity, $this->period, $this->cash, $this->rev, 'GJ-2', '2026-04-10', 500000);
    postSale($this->entity, $this->period, $this->cash, $this->rev, 'GJ-3', '2026-04-20', 700000);

    $r = app(GeneralLedgerService::class)->compute(
        $this->entity->id, $this->cash->id, '2026-04-01', '2026-04-30'
    );

    expect($r['opening'])->toBe('1000000.00')
        ->and($r['lines'])->toHaveCount(2)
        ->and($r['lines'][0]->balance)->toBe('1500000.00')
        ->and($r['lines'][1]->balance)->toBe('2200000.00')
        ->and($r['ending'])->toBe('2200000.00')
        ->and($r['total_debit'])->toBe('1200000.00')
        ->and($r['total_credit'])->toBe('0.00');
});

it('computes running balance correctly for a credit-normal account', function () {
    postSale($this->entity, $this->period, $this->cash, $this->rev, 'GJ-A', '2026-04-05', 300000);
    postSale($this->entity, $this->period, $this->cash, $this->rev, 'GJ-B', '2026-04-15', 200000);

    $r = app(GeneralLedgerService::class)->compute(
        $this->entity->id, $this->rev->id, '2026-04-01', '2026-04-30'
    );

    expect($r['opening'])->toBe('0.00')
        ->and($r['lines'][0]->balance)->toBe('300000.00')
        ->and($r['lines'][1]->balance)->toBe('500000.00')
        ->and($r['ending'])->toBe('500000.00');
});
