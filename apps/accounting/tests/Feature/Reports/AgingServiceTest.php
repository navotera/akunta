<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;
use App\Services\Reporting\AgingService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Co']);

    $this->period = Period::create([
        'entity_id'  => $this->entity->id,
        'name'       => 'Year 2026',
        'start_date' => '2026-01-01',
        'end_date'   => '2026-12-31',
    ]);

    $this->ar = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '1201',
        'name'           => 'Piutang Usaha',
        'type'           => 'asset',
        'normal_balance' => 'debit',
        'is_postable'    => true,
    ]);
    $this->rev = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '4101',
        'name'           => 'Penjualan',
        'type'           => 'revenue',
        'normal_balance' => 'credit',
        'is_postable'    => true,
    ]);
    $this->cash = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '1101',
        'name'           => 'Kas',
        'type'           => 'asset',
        'normal_balance' => 'debit',
        'is_postable'    => true,
    ]);

    $this->cust = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'code'      => 'C-AGE',
        'name'      => 'PT Aging Test',
    ]);
});

function postAr($entity, $period, $ar, $rev, $partner, string $number, string $date, $amount): void
{
    $j = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'type'      => 'general',
        'number'    => $number,
        'date'      => $date,
        'status'    => 'posted',
        'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $ar->id, 'partner_id' => $partner->id,
        'debit' => $amount,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $rev->id,
        'credit' => $amount,
    ]);
}

function postArPayment($entity, $period, $cash, $ar, $partner, string $number, string $date, $amount): void
{
    $j = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'type'      => 'general',
        'number'    => $number,
        'date'      => $date,
        'status'    => 'posted',
        'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $cash->id,
        'debit' => $amount,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $ar->id, 'partner_id' => $partner->id,
        'credit' => $amount,
    ]);
}

it('buckets a single open invoice into the right age band', function () {
    Carbon::setTestNow('2026-04-30');
    // Invoice 100 days ago → should land in >90 bucket
    postAr($this->entity, $this->period, $this->ar, $this->rev, $this->cust, 'INV-OLD', '2026-01-20', 1000000);

    $r = app(AgingService::class)->arAging($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(1)
        ->and($r['rows'][0]->buckets['>90'])->toBe('1000000.00')
        ->and($r['rows'][0]->total)->toBe('1000000.00')
        ->and($r['totals']['>90'])->toBe('1000000.00');

    Carbon::setTestNow();
});

it('FIFO-matches credits against oldest debits first', function () {
    Carbon::setTestNow('2026-04-30');

    // 3 invoices, each 1m
    postAr($this->entity, $this->period, $this->ar, $this->rev, $this->cust, 'INV-1', '2026-01-15', 1000000); // 105 days
    postAr($this->entity, $this->period, $this->ar, $this->rev, $this->cust, 'INV-2', '2026-03-15', 1000000); // 46 days
    postAr($this->entity, $this->period, $this->ar, $this->rev, $this->cust, 'INV-3', '2026-04-20', 1000000); // 10 days

    // Pay 1.5m → consumes INV-1 fully + 0.5m of INV-2
    postArPayment($this->entity, $this->period, $this->cash, $this->ar, $this->cust, 'BC-1', '2026-04-25', 1500000);

    $r = app(AgingService::class)->arAging($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(1)
        ->and($r['rows'][0]->buckets['31–60'])->toBe('500000.00')   // remainder of INV-2
        ->and($r['rows'][0]->buckets['1–30'])->toBe('1000000.00')   // INV-3 untouched
        ->and($r['rows'][0]->buckets['>90'])->toBe('0.00')
        ->and($r['rows'][0]->total)->toBe('1500000.00');

    Carbon::setTestNow();
});

it('returns no rows when balance is fully paid', function () {
    Carbon::setTestNow('2026-04-30');

    postAr($this->entity, $this->period, $this->ar, $this->rev, $this->cust, 'INV-PAID', '2026-04-05', 500000);
    postArPayment($this->entity, $this->period, $this->cash, $this->ar, $this->cust, 'BC-PAID', '2026-04-10', 500000);

    $r = app(AgingService::class)->arAging($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(0)
        ->and($r['totals']['total'])->toBe('0.00');

    Carbon::setTestNow();
});
