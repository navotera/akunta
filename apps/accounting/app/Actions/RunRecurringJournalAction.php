<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Journal;
use App\Models\RecurringJournal;
use Illuminate\Support\Carbon;

/**
 * Execute a single RecurringJournal occurrence:
 *   1. Skip when status != active or next_run_at > today.
 *   2. Instantiate template via InstantiateJournalTemplateAction.
 *   3. Optionally PostJournalAction (when auto_post=true).
 *   4. Advance next_run_at by frequency. End schedule if past end_date.
 *
 * Idempotent per (recurring_id, run_date) via Journal::idempotency_key.
 */
class RunRecurringJournalAction
{
    public function __construct(
        private readonly InstantiateJournalTemplateAction $instantiate,
        private readonly PostJournalAction $postJournal,
    ) {}

    public function execute(RecurringJournal $rec, ?string $today = null): ?Journal
    {
        $today = $today ?? Carbon::today()->toDateString();

        if (! $rec->isActive()) {
            return null;
        }
        if ($rec->next_run_at === null) {
            $rec->next_run_at = $rec->start_date;
        }
        if (Carbon::parse($rec->next_run_at)->greaterThan(Carbon::parse($today))) {
            return null;
        }
        if ($rec->end_date !== null && Carbon::parse($rec->next_run_at)->greaterThan(Carbon::parse($rec->end_date))) {
            $rec->update(['status' => RecurringJournal::STATUS_ENDED]);

            return null;
        }

        $runDate = Carbon::parse($rec->next_run_at)->toDateString();
        $idempotency = "rec:{$rec->id}:{$runDate}";

        // Skip if a journal for this occurrence already exists
        $existing = Journal::where('idempotency_key', $idempotency)->first();
        if ($existing !== null) {
            $rec->update([
                'next_run_at'      => $this->advance($runDate, $rec)->toDateString(),
                'last_run_at'      => now(),
                'last_journal_id'  => $existing->id,
            ]);

            return $existing;
        }

        $rec->loadMissing('template.lines');

        $journal = $this->instantiate->execute(
            template: $rec->template,
            date: $runDate,
            sourceApp: 'accounting',
            sourceId: $rec->id,
            idempotencyKey: $idempotency,
            createdBy: $rec->created_by,
        );

        if ($rec->auto_post) {
            $this->postJournal->execute($journal, $rec->createdByUser ?? null);
        }

        $next = $this->advance($runDate, $rec);

        $update = [
            'next_run_at'     => $next->toDateString(),
            'last_run_at'     => now(),
            'last_journal_id' => $journal->id,
        ];
        if ($rec->end_date !== null && $next->greaterThan(Carbon::parse($rec->end_date))) {
            $update['status'] = RecurringJournal::STATUS_ENDED;
        }
        $rec->update($update);

        return $journal;
    }

    private function advance(string $from, RecurringJournal $rec): Carbon
    {
        $d = Carbon::parse($from);

        return match ($rec->frequency) {
            RecurringJournal::FREQUENCY_DAILY     => $d->copy()->addDay(),
            RecurringJournal::FREQUENCY_WEEKLY    => $d->copy()->addWeek(),
            RecurringJournal::FREQUENCY_MONTHLY   => $d->copy()->addMonthNoOverflow(),
            RecurringJournal::FREQUENCY_QUARTERLY => $d->copy()->addMonthsNoOverflow(3),
            RecurringJournal::FREQUENCY_YEARLY    => $d->copy()->addYearNoOverflow(),
            default                               => $d->copy()->addMonthNoOverflow(),
        };
    }
}
