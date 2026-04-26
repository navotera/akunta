<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;
use App\Services\Reporting\SubLedgerService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Co']);

    $this->period = Period::create([
        'entity_id'  => $this->entity->id,
        'name'       => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date'   => '2026-04-30',
    ]);

    $this->ar = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '1201',
        'name'           => 'Piutang Usaha',
        'type'           => 'asset',
        'normal_balance' => 'debit',
        'is_postable'    => true,
    ]);
    $this->ap = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '2101',
        'name'           => 'Hutang Usaha',
        'type'           => 'liability',
        'normal_balance' => 'credit',
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
    $this->cogs = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '5101',
        'name'           => 'HPP',
        'type'           => 'cogs',
        'normal_balance' => 'debit',
        'is_postable'    => true,
    ]);

    $this->cust = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'code'      => 'C-01',
        'name'      => 'PT Pelanggan A',
    ]);
    $this->vend = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_VENDOR,
        'code'      => 'V-01',
        'name'      => 'PT Vendor B',
    ]);
});

function postJournal($entity, $period, string $number, string $date, array $lines): Journal
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
    foreach ($lines as $i => $l) {
        JournalEntry::create([
            'journal_id' => $j->id,
            'line_no'    => $i + 1,
            'account_id' => $l['account_id'],
            'partner_id' => $l['partner_id'] ?? null,
            'debit'      => $l['debit']  ?? 0,
            'credit'     => $l['credit'] ?? 0,
        ]);
    }

    return $j;
}

it('aggregates AR balance per customer', function () {
    // Invoice 1: AR 1,000,000 / Sales 1,000,000
    postJournal($this->entity, $this->period, 'INV-001', '2026-04-05', [
        ['account_id' => $this->ar->id,  'partner_id' => $this->cust->id, 'debit'  => 1000000],
        ['account_id' => $this->rev->id,                                  'credit' => 1000000],
    ]);
    // Payment: Cash 400k / AR 400k
    postJournal($this->entity, $this->period, 'BC-001', '2026-04-10', [
        ['account_id' => $this->cash->id,                                'debit'  => 400000],
        ['account_id' => $this->ar->id,   'partner_id' => $this->cust->id, 'credit' => 400000],
    ]);

    $r = app(SubLedgerService::class)->arSubLedger($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(1)
        ->and($r['rows'][0]->balance)->toBe('600000.00')
        ->and($r['total_balance'])->toBe('600000.00');
});

it('aggregates AP balance per vendor', function () {
    // Bill: COGS 800k / AP 800k
    postJournal($this->entity, $this->period, 'PV-001', '2026-04-03', [
        ['account_id' => $this->cogs->id,                                'debit'  => 800000],
        ['account_id' => $this->ap->id,   'partner_id' => $this->vend->id, 'credit' => 800000],
    ]);
    // Payment: AP 300k / Cash 300k
    postJournal($this->entity, $this->period, 'BP-001', '2026-04-15', [
        ['account_id' => $this->ap->id,   'partner_id' => $this->vend->id, 'debit'  => 300000],
        ['account_id' => $this->cash->id,                                'credit' => 300000],
    ]);

    $r = app(SubLedgerService::class)->apSubLedger($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(1)
        ->and($r['rows'][0]->balance)->toBe('500000.00')
        ->and($r['total_balance'])->toBe('500000.00');
});

it('omits partners with zero balance', function () {
    postJournal($this->entity, $this->period, 'INV-002', '2026-04-05', [
        ['account_id' => $this->ar->id,  'partner_id' => $this->cust->id, 'debit'  => 500000],
        ['account_id' => $this->rev->id,                                  'credit' => 500000],
    ]);
    postJournal($this->entity, $this->period, 'BC-002', '2026-04-06', [
        ['account_id' => $this->cash->id,                                'debit'  => 500000],
        ['account_id' => $this->ar->id,   'partner_id' => $this->cust->id, 'credit' => 500000],
    ]);

    $r = app(SubLedgerService::class)->arSubLedger($this->entity->id, '2026-04-30');

    expect($r['rows'])->toHaveCount(0)
        ->and($r['total_balance'])->toBe('0.00');
});

it('returns chronological transactions for a partner', function () {
    postJournal($this->entity, $this->period, 'INV-A', '2026-04-05', [
        ['account_id' => $this->ar->id,  'partner_id' => $this->cust->id, 'debit'  => 100000],
        ['account_id' => $this->rev->id,                                  'credit' => 100000],
    ]);
    postJournal($this->entity, $this->period, 'INV-B', '2026-04-15', [
        ['account_id' => $this->ar->id,  'partner_id' => $this->cust->id, 'debit'  => 200000],
        ['account_id' => $this->rev->id,                                  'credit' => 200000],
    ]);

    $tx = app(SubLedgerService::class)->partnerTransactions($this->entity->id, $this->cust->id, 'asset', '2026-04-30');

    expect($tx)->toHaveCount(2)
        ->and($tx[0]->number)->toBe('INV-A')
        ->and($tx[1]->number)->toBe('INV-B');
});
