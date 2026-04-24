<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Event;
use Akunta\Core\Hooks as HookCatalog;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Exceptions\JournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('journal.post', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);
    Gate::define('journal.reverse', fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true);

    $tenant = Tenant::create(['name' => 'Rev Tenant', 'slug' => 'rev-'.uniqid()]);
    $entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Rev Co']);
    $this->entity = $entity;
    $this->period = Period::create([
        'entity_id' => $entity->id,
        'name' => 'Apr 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);
    $this->cash = Account::create([
        'entity_id' => $entity->id,
        'code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->revenue = Account::create([
        'entity_id' => $entity->id,
        'code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $this->user = User::create(['name' => 'U', 'email' => 'rev@example.test', 'password_hash' => bcrypt('x')]);

    $this->posted = Journal::create([
        'entity_id' => $entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'POST-'.uniqid(),
        'date' => '2026-04-15',
    ]);
    JournalEntry::create(['journal_id' => $this->posted->id, 'line_no' => 1, 'account_id' => $this->cash->id, 'debit' => '100.00', 'credit' => 0]);
    JournalEntry::create(['journal_id' => $this->posted->id, 'line_no' => 2, 'account_id' => $this->revenue->id, 'debit' => 0, 'credit' => '100.00']);
    app(PostJournalAction::class)->execute($this->posted, $this->user);
    $this->posted->refresh();
});

it('creates a mirror reversing journal with swapped debit/credit', function () {
    $reversal = app(ReverseJournalAction::class)->execute($this->posted, $this->user, 'Typo fix');

    expect($reversal)->toBeInstanceOf(Journal::class)
        ->and($reversal->type)->toBe(Journal::TYPE_REVERSING)
        ->and($reversal->status)->toBe(Journal::STATUS_POSTED)
        ->and($reversal->number)->toBe($this->posted->number.'-R')
        ->and($reversal->source_id)->toBe($this->posted->id);

    $origEntries = $this->posted->entries->keyBy('line_no');
    foreach ($reversal->entries as $mirror) {
        $orig = $origEntries[$mirror->line_no];
        expect((string) $mirror->debit)->toBe((string) $orig->credit)
            ->and((string) $mirror->credit)->toBe((string) $orig->debit);
    }

    expect($this->posted->fresh()->status)->toBe(Journal::STATUS_REVERSED)
        ->and($this->posted->fresh()->reversed_by_journal_id)->toBe($reversal->id);
});

it('fires before_reverse and after_reverse hooks', function () {
    Event::fake([HookCatalog::JOURNAL_BEFORE_REVERSE, HookCatalog::JOURNAL_AFTER_REVERSE]);

    app(ReverseJournalAction::class)->execute($this->posted, $this->user);

    Event::assertDispatched(HookCatalog::JOURNAL_BEFORE_REVERSE);
    Event::assertDispatched(HookCatalog::JOURNAL_AFTER_REVERSE);
});

it('writes journal.reverse audit log entry', function () {
    app(ReverseJournalAction::class)->execute($this->posted, $this->user, 'duplicate entry');

    expect(AuditLog::query()
        ->where('action', 'journal.reverse')
        ->where('resource_id', $this->posted->id)
        ->exists())->toBeTrue();
});

it('rejects reversing a non-posted journal', function () {
    $draft = Journal::create([
        'entity_id' => $this->entity->id,
        'period_id' => $this->period->id,
        'type' => Journal::TYPE_GENERAL,
        'number' => 'DRAFT-'.uniqid(),
        'date' => '2026-04-16',
    ]);

    expect(fn () => app(ReverseJournalAction::class)->execute($draft, $this->user))
        ->toThrow(JournalException::class, 'posted');
});
