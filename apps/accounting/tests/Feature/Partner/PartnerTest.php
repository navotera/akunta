<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Co']);
    $tenant2 = Tenant::create(['name' => 'PT Other', 'slug' => 'other-'.uniqid()]);
    $this->otherEntity = Entity::create(['tenant_id' => $tenant2->id, 'name' => 'Other Co']);
});

it('creates a partner scoped to its entity', function () {
    $p = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'code'      => 'C-001',
        'name'      => 'PT Pelanggan Sejati',
        'npwp'      => '01.234.567.8-901.000',
        'email'     => 'finance@pelanggan.test',
    ])->refresh();

    expect($p->entity_id)->toBe($this->entity->id)
        ->and($p->type)->toBe('customer')
        ->and($p->is_active)->toBeTrue();
});

it('rejects duplicate partner code within the same entity', function () {
    Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_VENDOR,
        'code'      => 'V-100',
        'name'      => 'Vendor A',
    ]);

    expect(fn () => Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_VENDOR,
        'code'      => 'V-100',
        'name'      => 'Vendor B',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows the same partner code across different entities', function () {
    Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'code'      => 'C-001',
        'name'      => 'X',
    ]);

    $other = Partner::create([
        'entity_id' => $this->otherEntity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'code'      => 'C-001',
        'name'      => 'Y',
    ]);

    expect($other->id)->not->toBeNull();
});

it('links a partner to a journal_entry and exposes the relation', function () {
    $period = Period::create([
        'entity_id'  => $this->entity->id,
        'name'       => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date'   => '2026-04-30',
    ]);

    $ar = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '1201',
        'name'           => 'Piutang Usaha',
        'type'           => 'asset',
        'normal_balance' => 'debit',
        'is_postable'    => true,
    ]);
    $rev = Account::create([
        'entity_id'      => $this->entity->id,
        'code'           => '4101',
        'name'           => 'Pendapatan',
        'type'           => 'revenue',
        'normal_balance' => 'credit',
        'is_postable'    => true,
    ]);

    $partner = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'name'      => 'PT Customer',
    ]);

    $journal = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $period->id,
        'type'      => 'general',
        'number'    => 'GJ-001',
        'date'      => '2026-04-15',
        'status'    => 'draft',
    ]);

    $line = JournalEntry::create([
        'journal_id' => $journal->id,
        'line_no'    => 1,
        'account_id' => $ar->id,
        'partner_id' => $partner->id,
        'debit'      => 1000000,
        'credit'     => 0,
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id,
        'line_no'    => 2,
        'account_id' => $rev->id,
        'debit'      => 0,
        'credit'     => 1000000,
    ]);

    $line->refresh();
    expect($line->partner_id)->toBe($partner->id)
        ->and($line->partner->name)->toBe('PT Customer');

    expect($partner->journalEntries()->count())->toBe(1);
});
