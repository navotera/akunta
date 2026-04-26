<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Actions\InstantiateJournalTemplateAction;
use App\Actions\RunRecurringJournalAction;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\RecurringJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $u = null) => true);

    $tenant = Tenant::create(['name' => 'PT Demo', 'slug' => 'demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Demo']);

    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);
    $this->period2 = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'May 2026',
        'start_date' => '2026-05-01', 'end_date' => '2026-05-31',
    ]);
    $this->period3 = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Jun 2026',
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
    ]);

    $this->cash = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rent = Account::create([
        'entity_id' => $this->entity->id, 'code' => '6201', 'name' => 'Beban Sewa',
        'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
});

function makeRentTemplate($entity, $rent, $cash): JournalTemplate
{
    $t = JournalTemplate::create([
        'entity_id'    => $entity->id,
        'code'         => 'RENT',
        'name'         => 'Sewa Kantor Bulanan',
        'journal_type' => 'general',
        'default_memo' => 'Beban sewa kantor',
    ]);
    JournalTemplateLine::create([
        'template_id' => $t->id, 'line_no' => 1,
        'account_id'  => $rent->id, 'side' => 'debit', 'amount' => 5000000,
    ]);
    JournalTemplateLine::create([
        'template_id' => $t->id, 'line_no' => 2,
        'account_id'  => $cash->id, 'side' => 'credit', 'amount' => 5000000,
    ]);

    return $t;
}

it('instantiates a balanced journal from a template', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $journal = app(InstantiateJournalTemplateAction::class)->execute(
        template: $tmpl,
        date: '2026-04-15',
    );

    $journal->refresh()->load('entries');
    expect($journal->status)->toBe('draft')
        ->and($journal->template_id)->toBe($tmpl->id)
        ->and($journal->entries)->toHaveCount(2)
        ->and((string) $journal->entries[0]->debit)->toBe('5000000.00')
        ->and((string) $journal->entries[1]->credit)->toBe('5000000.00');
});

it('applies amount overrides at instantiate time', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $journal = app(InstantiateJournalTemplateAction::class)->execute(
        template: $tmpl,
        date: '2026-04-15',
        overrides: [
            1 => ['amount' => '7500000'],
            2 => ['amount' => '7500000'],
        ],
    );

    $journal->load('entries');
    expect((string) $journal->entries[0]->debit)->toBe('7500000.00')
        ->and((string) $journal->entries[1]->credit)->toBe('7500000.00');
});

it('rejects unbalanced overrides', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    expect(fn () => app(InstantiateJournalTemplateAction::class)->execute(
        template: $tmpl,
        date: '2026-04-15',
        overrides: [
            1 => ['amount' => '7500000'],
            2 => ['amount' => '6000000'],
        ],
    ))->toThrow(\App\Exceptions\JournalException::class);
});

it('runs a monthly recurring journal and advances next_run_at', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $rec = RecurringJournal::create([
        'entity_id'   => $this->entity->id,
        'template_id' => $tmpl->id,
        'name'        => 'Rent — monthly',
        'frequency'   => RecurringJournal::FREQUENCY_MONTHLY,
        'start_date'  => '2026-04-15',
        'next_run_at' => '2026-04-15',
        'status'      => 'active',
    ]);

    $journal = app(RunRecurringJournalAction::class)->execute($rec, '2026-04-20');

    $rec->refresh();
    expect($journal)->not->toBeNull()
        ->and($journal->date->toDateString())->toBe('2026-04-15')
        ->and($rec->next_run_at->toDateString())->toBe('2026-05-15')
        ->and($rec->last_journal_id)->toBe($journal->id);
});

it('skips a paused recurring schedule', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $rec = RecurringJournal::create([
        'entity_id'   => $this->entity->id,
        'template_id' => $tmpl->id,
        'name'        => 'Rent — paused',
        'frequency'   => RecurringJournal::FREQUENCY_MONTHLY,
        'start_date'  => '2026-04-15',
        'next_run_at' => '2026-04-15',
        'status'      => RecurringJournal::STATUS_PAUSED,
    ]);

    $journal = app(RunRecurringJournalAction::class)->execute($rec, '2026-04-20');

    expect($journal)->toBeNull()
        ->and(Journal::where('template_id', $tmpl->id)->count())->toBe(0);
});

it('is idempotent on a single run-date — second run reuses the same journal', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $rec = RecurringJournal::create([
        'entity_id'   => $this->entity->id,
        'template_id' => $tmpl->id,
        'name'        => 'Rent',
        'frequency'   => RecurringJournal::FREQUENCY_MONTHLY,
        'start_date'  => '2026-04-15',
        'next_run_at' => '2026-04-15',
        'status'      => 'active',
    ]);

    // Roll back next_run_at to retry same run-date
    $journal1 = app(RunRecurringJournalAction::class)->execute($rec, '2026-04-20');
    $rec->refresh();
    $rec->update(['next_run_at' => '2026-04-15']);

    $journal2 = app(RunRecurringJournalAction::class)->execute($rec, '2026-04-20');

    expect($journal1->id)->toBe($journal2->id)
        ->and(Journal::where('template_id', $tmpl->id)->count())->toBe(1);
});

it('ends a recurring schedule when next_run_at exceeds end_date', function () {
    $tmpl = makeRentTemplate($this->entity, $this->rent, $this->cash);

    $rec = RecurringJournal::create([
        'entity_id'   => $this->entity->id,
        'template_id' => $tmpl->id,
        'name'        => 'Rent — short',
        'frequency'   => RecurringJournal::FREQUENCY_MONTHLY,
        'start_date'  => '2026-04-15',
        'end_date'    => '2026-04-30',
        'next_run_at' => '2026-04-15',
        'status'      => 'active',
    ]);

    app(RunRecurringJournalAction::class)->execute($rec, '2026-04-20');
    $rec->refresh();

    expect($rec->status)->toBe(RecurringJournal::STATUS_ENDED);
});
