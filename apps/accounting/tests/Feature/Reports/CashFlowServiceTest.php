<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\Reporting\CashFlowService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT CF', 'slug' => 'cf-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'CF Co']);
    $this->period = Period::create([
        'entity_id'  => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rev = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $this->equipment = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1501', 'name' => 'Peralatan',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->loan = Account::create([
        'entity_id' => $this->entity->id, 'code' => '2201', 'name' => 'Pinjaman Bank',
        'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
});

function postCf($entity, $period, string $number, string $date, array $lines): void
{
    $j = Journal::create([
        'entity_id' => $entity->id, 'period_id' => $period->id,
        'type' => 'general', 'number' => $number, 'date' => $date,
        'status' => 'posted', 'posted_at' => now(),
    ]);
    foreach ($lines as $i => $l) {
        JournalEntry::create([
            'journal_id' => $j->id, 'line_no' => $i + 1,
            'account_id' => $l['account_id'],
            'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0,
        ]);
    }
}

it('classifies sales receipts as operating inflow', function () {
    postCf($this->entity, $this->period, 'BC-1', '2026-04-05', [
        ['account_id' => $this->cash->id, 'debit' => 5000000],
        ['account_id' => $this->rev->id,  'credit' => 5000000],
    ]);

    $r = app(CashFlowService::class)->compute($this->entity->id, '2026-04-01', '2026-04-30');

    expect($r['operating']['total'])->toBe('5000000.00')
        ->and($r['investing']['total'])->toBe('0.00')
        ->and($r['financing']['total'])->toBe('0.00')
        ->and($r['net_change'])->toBe('5000000.00');
});

it('classifies fixed-asset purchase as investing outflow', function () {
    postCf($this->entity, $this->period, 'BP-1', '2026-04-10', [
        ['account_id' => $this->equipment->id, 'debit'  => 8000000],
        ['account_id' => $this->cash->id,      'credit' => 8000000],
    ]);

    $r = app(CashFlowService::class)->compute($this->entity->id, '2026-04-01', '2026-04-30');

    expect($r['investing']['total'])->toBe('-8000000.00')
        ->and($r['operating']['total'])->toBe('0.00');
});

it('classifies long-term loan proceeds as financing inflow', function () {
    postCf($this->entity, $this->period, 'BC-2', '2026-04-15', [
        ['account_id' => $this->cash->id, 'debit'  => 50000000],
        ['account_id' => $this->loan->id, 'credit' => 50000000],
    ]);

    $r = app(CashFlowService::class)->compute($this->entity->id, '2026-04-01', '2026-04-30');

    expect($r['financing']['total'])->toBe('50000000.00');
});

it('reports zero buckets when no cash accounts exist', function () {
    $other = Tenant::create(['name' => 'PT Empty', 'slug' => 'empty-'.uniqid()]);
    $emptyEntity = Entity::create(['tenant_id' => $other->id, 'name' => 'Empty']);

    $r = app(CashFlowService::class)->compute($emptyEntity->id, '2026-04-01', '2026-04-30');

    expect($r['opening_cash'])->toBe('0.00')
        ->and($r['ending_cash'])->toBe('0.00')
        ->and($r['net_change'])->toBe('0.00');
});
