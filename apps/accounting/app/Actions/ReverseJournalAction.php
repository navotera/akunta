<?php

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\JournalException;
use App\Models\Journal;
use App\Models\JournalEntry;
use Illuminate\Support\Str;

class ReverseJournalAction extends BaseAction
{
    public function execute(Journal $journal, ?User $user = null, ?string $reason = null): Journal
    {
        $this->authorize('journal.reverse', $journal);

        if ($journal->status !== Journal::STATUS_POSTED) {
            throw JournalException::notPosted($journal->status);
        }

        $journal->loadMissing('entries');

        $this->fireBefore(Hooks::JOURNAL_BEFORE_REVERSE, $journal, $user);

        $reversal = $this->runInTransaction(function () use ($journal, $user, $reason) {
            $reversal = Journal::create([
                'entity_id' => $journal->entity_id,
                'period_id' => $journal->period_id,
                'type' => Journal::TYPE_REVERSING,
                'number' => $journal->number.'-R',
                'date' => now()->toDateString(),
                'reference' => $journal->reference,
                'memo' => $reason ?? 'Reversal of '.$journal->number,
                'source_app' => 'accounting',
                'source_id' => $journal->id,
                'status' => Journal::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $user?->id,
                'created_by' => $user?->id,
            ]);

            foreach ($journal->entries as $entry) {
                JournalEntry::create([
                    'journal_id' => $reversal->id,
                    'line_no' => $entry->line_no,
                    'account_id' => $entry->account_id,
                    'debit' => $entry->credit,
                    'credit' => $entry->debit,
                    'memo' => $entry->memo,
                    'metadata' => $entry->metadata,
                ]);
            }

            $journal->forceFill([
                'status' => Journal::STATUS_REVERSED,
                'reversed_by_journal_id' => $reversal->id,
            ])->save();

            $this->audit(
                action: 'journal.reverse',
                resourceType: Journal::class,
                resourceId: $journal->id,
                entityId: $journal->entity_id,
                metadata: [
                    'original_number' => $journal->number,
                    'reversal_id' => $reversal->id,
                    'reason' => $reason,
                ],
                actorUserId: $user?->id,
            );

            return $reversal;
        });

        $journal->refresh();

        $this->fireAfter(Hooks::JOURNAL_AFTER_REVERSE, $journal, $reversal, $user);

        return $reversal;
    }
}
