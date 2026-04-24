<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Exceptions\HookAbortException;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\PostJournalAction;
use App\Exceptions\JournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // Skeleton smoke tests — override Gate (permissions tested separately at rbac layer).
    // Closure must accept nullable Authenticatable to allow guest/null-user calls.
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);
    Gate::define('journal.reverse', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Test Co']);

    $this->entity = $entity;
    $this->period = Period::create([
        'entity_id' => $entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $this->cash = Account::create([
        'entity_id' => $entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $entity->id,
        'code' => '4101',
        'name' => 'Penjualan',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'is_postable' => true,
    ]);
    $this->parent = Account::create([
        'entity_id' => $entity->id,
        'code' => '1000',
        'name' => 'Aktiva',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => false,
    ]);

    $this->user = User::create([
        'name' => 'Accountant',
        'email' => 'a@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
});

function makeDraftJournal($entity, $period): Journal
{
    $journal = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'JRN-'.uniqid(),
        'date' => '2026-04-15',
    ]);

    return $journal;
}

it('posts a balanced journal and writes audit log', function () {
    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '100000.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '100000.00']);

    app(PostJournalAction::class)->execute($j, $this->user);

    expect($j->fresh()->status)->toBe(Journal::STATUS_POSTED)
        ->and($j->fresh()->posted_by)->toBe($this->user->id);

    expect(AuditLog::query()
        ->where('action', 'journal.post')
        ->where('resource_id', $j->id)
        ->exists())->toBeTrue();
});

it('fires before and after hooks around posting', function () {
    $fired = [];
    Event::listen(HookCatalog::JOURNAL_BEFORE_POST, function ($journal) use (&$fired) {
        $fired[] = 'before:'.$journal->number;
    });
    Event::listen(HookCatalog::JOURNAL_AFTER_POST, function ($journal) use (&$fired) {
        $fired[] = 'after:'.$journal->status;
    });

    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '50.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '50.00']);

    app(PostJournalAction::class)->execute($j, $this->user);

    expect($fired)->toBe(['before:'.$j->number, 'after:posted']);
});

it('lets a before_post listener abort the action via HookAbortException', function () {
    Event::listen(HookCatalog::JOURNAL_BEFORE_POST, function () {
        throw new HookAbortException('SoD violation');
    });

    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '10.00']);

    expect(fn () => app(PostJournalAction::class)->execute($j, $this->user))
        ->toThrow(HookAbortException::class);

    expect($j->fresh()->status)->toBe(Journal::STATUS_DRAFT);
});

it('rejects unbalanced journals', function () {
    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '9.99']);

    expect(fn () => app(PostJournalAction::class)->execute($j, $this->user))
        ->toThrow(JournalException::class, 'unbalanced');
});

it('rejects posting into a closed period', function () {
    $this->period->update(['status' => Period::STATUS_CLOSED, 'closed_at' => now()]);

    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '10.00']);

    expect(fn () => app(PostJournalAction::class)->execute($j, $this->user))
        ->toThrow(JournalException::class, 'period');
});

it('rejects non-postable (parent aggregator) accounts', function () {
    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->parent->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '10.00']);

    expect(fn () => app(PostJournalAction::class)->execute($j, $this->user))
        ->toThrow(JournalException::class, '1000');
});

it('rejects re-posting an already posted journal', function () {
    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '10.00']);
    app(PostJournalAction::class)->execute($j, $this->user);

    expect(fn () => app(PostJournalAction::class)->execute($j->fresh(), $this->user))
        ->toThrow(JournalException::class, 'draft');
});

it('rejects an account belonging to a different entity', function () {
    $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid()]);
    $otherEntity = Entity::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Co']);
    $foreign = Account::create([
        'entity_id' => $otherEntity->id,
        'code' => '1101',
        'name' => 'Foreign Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
    ]);

    $j = makeDraftJournal($this->entity, $this->period);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 1, 'account_id' => $foreign->id, 'debit' => '10.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '10.00']);

    expect(fn () => app(PostJournalAction::class)->execute($j, $this->user))
        ->toThrow(JournalException::class, 'different entity');
});
