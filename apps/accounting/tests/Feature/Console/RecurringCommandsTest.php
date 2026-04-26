<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\RecurringJournal;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $u = null) => true);
    Gate::define('journal.reverse', fn (?\Illuminate\Contracts\Auth\Authenticatable $u = null) => true);

    $tenant = Tenant::create(['name' => 'PT C', 'slug' => 'c-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'C']);
    $this->period = Period::create([
        'entity_id'  => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);
    Period::create([
        'entity_id'  => $this->entity->id, 'name' => 'May 2026',
        'start_date' => '2026-05-01', 'end_date' => '2026-05-31',
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rent = Account::create([
        'entity_id' => $this->entity->id, 'code' => '6201', 'name' => 'Sewa',
        'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);

    $this->tmpl = JournalTemplate::create([
        'entity_id' => $this->entity->id, 'code' => 'RENT', 'name' => 'Rent',
    ]);
    JournalTemplateLine::create([
        'template_id' => $this->tmpl->id, 'line_no' => 1,
        'account_id' => $this->rent->id, 'side' => 'debit', 'amount' => 1000000,
    ]);
    JournalTemplateLine::create([
        'template_id' => $this->tmpl->id, 'line_no' => 2,
        'account_id' => $this->cash->id, 'side' => 'credit', 'amount' => 1000000,
    ]);
});

it('runs the run-recurring command and instantiates due schedules', function () {
    RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->tmpl->id,
        'name' => 'A', 'frequency' => 'monthly',
        'start_date' => '2026-04-15', 'next_run_at' => '2026-04-15', 'status' => 'active',
    ]);
    RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->tmpl->id,
        'name' => 'P', 'frequency' => 'monthly',
        'start_date' => '2026-04-15', 'next_run_at' => '2026-04-15', 'status' => 'paused',
    ]);

    $this->artisan('accounting:run-recurring', ['--date' => '2026-04-20'])
        ->assertSuccessful();

    expect(Journal::where('template_id', $this->tmpl->id)->count())->toBe(1);
});

it('dry-run does not instantiate any journals', function () {
    RecurringJournal::create([
        'entity_id' => $this->entity->id, 'template_id' => $this->tmpl->id,
        'name' => 'A', 'frequency' => 'monthly',
        'start_date' => '2026-04-15', 'next_run_at' => '2026-04-15', 'status' => 'active',
    ]);

    $this->artisan('accounting:run-recurring', ['--date' => '2026-04-20', '--dry-run' => true])
        ->assertSuccessful();

    expect(Journal::where('template_id', $this->tmpl->id)->count())->toBe(0);
});

it('runs the run-auto-reversals command', function () {
    // Posted journal flagged for auto-reverse on 2026-05-01
    $j = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $this->period->id,
        'type' => 'general', 'number' => 'GJ-ACR-1', 'date' => '2026-04-30',
        'status' => 'posted', 'posted_at' => now(),
        'auto_reverse_on' => '2026-05-01',
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $this->rent->id, 'debit' => 1000000,
    ]);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 2,
        'account_id' => $this->cash->id, 'credit' => 1000000,
    ]);

    $this->artisan('accounting:run-auto-reversals', ['--date' => '2026-05-01'])
        ->assertSuccessful();

    $j->refresh();
    expect($j->status)->toBe(Journal::STATUS_REVERSED)
        ->and(Journal::where('type', Journal::TYPE_REVERSING)->count())->toBe(1);
});
