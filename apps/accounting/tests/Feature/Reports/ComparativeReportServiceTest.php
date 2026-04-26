<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\Reporting\ComparativeReportService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Cmp', 'slug' => 'cmp-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Cmp Co']);

    Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Mar 2026',
        'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
    ]);
    Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
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
});

function postCmp($entity, string $name, string $start, string $end, $cash, $rev, string $date, $amount): void
{
    $period = Period::where('entity_id', $entity->id)
        ->whereDate('start_date', $start)
        ->first();
    if ($period === null) {
        $period = Period::where('entity_id', $entity->id)
            ->orderBy('start_date')
            ->first();
    }
    $j = Journal::create([
        'entity_id' => $entity->id, 'period_id' => $period->id,
        'type' => 'general', 'number' => $name, 'date' => $date,
        'status' => 'posted', 'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $cash->id, 'debit' => $amount,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $rev->id, 'credit' => $amount,
    ]);
}

it('produces side-by-side P&L comparison with delta', function () {
    postCmp($this->entity, 'GJ-MAR', '2026-03-01', '2026-03-31', $this->cash, $this->rev, '2026-03-15', 1000000);
    postCmp($this->entity, 'GJ-APR', '2026-04-01', '2026-04-30', $this->cash, $this->rev, '2026-04-15', 1500000);

    $cmp = app(ComparativeReportService::class)->incomeStatement(
        $this->entity->id,
        '2026-04-01', '2026-04-30',
        '2026-03-01', '2026-03-31',
    );

    expect($cmp['net_income_curr'])->toBe('1500000.00')
        ->and($cmp['net_income_prev'])->toBe('1000000.00')
        ->and($cmp['net_income_delta'])->toBe('500000.00');

    $rev = $cmp['sections']['revenue'];
    expect($rev['curr_total'])->toBe('1500000.00')
        ->and($rev['prev_total'])->toBe('1000000.00')
        ->and($rev['total_delta'])->toBe('500000.00')
        ->and($rev['lines'][0]->delta_pct)->toBe('50.00');
});

it('computes prior-period helper dates correctly', function () {
    $cmp = app(ComparativeReportService::class);

    $prev = $cmp->priorPeriod('2026-04-01', '2026-04-30');
    expect($prev['start'])->toBe('2026-03-02')
        ->and($prev['end'])->toBe('2026-03-31');

    $py = $cmp->priorYear('2026-04-01', '2026-04-30');
    expect($py['start'])->toBe('2025-04-01')
        ->and($py['end'])->toBe('2025-04-30');
});
