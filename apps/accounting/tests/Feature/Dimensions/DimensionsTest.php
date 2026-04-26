<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;
use App\Models\Project;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo Co']);

    $tenant2 = Tenant::create(['name' => 'PT Other', 'slug' => 'other-'.uniqid()]);
    $this->otherEntity = Entity::create(['tenant_id' => $tenant2->id, 'name' => 'Other']);
});

it('creates a cost center scoped to entity', function () {
    $cc = CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS',
        'name'      => 'Operasional',
    ])->refresh();

    expect($cc->is_active)->toBeTrue()
        ->and($cc->entity_id)->toBe($this->entity->id);
});

it('supports cost center parent-child hierarchy', function () {
    $parent = CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS',
        'name'      => 'Operasional',
    ]);

    $child = CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS-IT',
        'name'      => 'IT',
        'parent_id' => $parent->id,
    ]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children->pluck('id')->all())->toContain($child->id);
});

it('rejects duplicate cost center code per entity', function () {
    CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS',
        'name'      => 'A',
    ]);
    expect(fn () => CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS',
        'name'      => 'B',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows same cost center code across different entities', function () {
    CostCenter::create([
        'entity_id' => $this->entity->id,
        'code'      => 'OPS',
        'name'      => 'A',
    ]);
    $other = CostCenter::create([
        'entity_id' => $this->otherEntity->id,
        'code'      => 'OPS',
        'name'      => 'B',
    ]);
    expect($other->id)->not->toBeNull();
});

it('creates a project optionally linked to a customer partner', function () {
    $cust = Partner::create([
        'entity_id' => $this->entity->id,
        'type'      => Partner::TYPE_CUSTOMER,
        'name'      => 'PT Klien',
    ]);

    $p = Project::create([
        'entity_id' => $this->entity->id,
        'code'      => 'PRJ-001',
        'name'      => 'Renovasi Kantor',
        'partner_id'=> $cust->id,
        'status'    => Project::STATUS_ACTIVE,
        'start_date'=> '2026-04-01',
    ]);

    expect($p->partner->name)->toBe('PT Klien')
        ->and($p->status)->toBe('active');
});

it('creates a branch', function () {
    $b = Branch::create([
        'entity_id' => $this->entity->id,
        'code'      => 'JKT',
        'name'      => 'Cabang Jakarta',
        'city'      => 'Jakarta',
    ])->refresh();

    expect($b->city)->toBe('Jakarta')
        ->and($b->is_active)->toBeTrue();
});

it('attaches all 3 dimensions to a journal_entry and exposes relations', function () {
    $period = Period::create([
        'entity_id'  => $this->entity->id,
        'name'       => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date'   => '2026-04-30',
    ]);

    $exp = Account::create([
        'entity_id' => $this->entity->id, 'code' => '6101',
        'name' => 'Beban Operasional', 'type' => 'expense',
        'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101',
        'name' => 'Kas', 'type' => 'asset',
        'normal_balance' => 'debit', 'is_postable' => true,
    ]);

    $cc = CostCenter::create(['entity_id' => $this->entity->id, 'code' => 'OPS', 'name' => 'Op']);
    $pr = Project::create(['entity_id' => $this->entity->id, 'code' => 'P-1', 'name' => 'Klien A']);
    $br = Branch::create(['entity_id' => $this->entity->id, 'code' => 'JKT', 'name' => 'Jakarta']);

    $j = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $period->id,
        'type'      => 'general',
        'number'    => 'GJ-DIM-1',
        'date'      => '2026-04-15',
        'status'    => 'draft',
    ]);
    $line = JournalEntry::create([
        'journal_id'     => $j->id,
        'line_no'        => 1,
        'account_id'     => $exp->id,
        'cost_center_id' => $cc->id,
        'project_id'     => $pr->id,
        'branch_id'      => $br->id,
        'debit'          => 500000,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id,
        'line_no'    => 2,
        'account_id' => $cash->id,
        'credit'     => 500000,
    ]);

    $line->refresh();
    expect($line->costCenter->code)->toBe('OPS')
        ->and($line->project->code)->toBe('P-1')
        ->and($line->branch->code)->toBe('JKT');

    expect($cc->journalEntries()->count())->toBe(1)
        ->and($pr->journalEntries()->count())->toBe(1)
        ->and($br->journalEntries()->count())->toBe(1);
});
