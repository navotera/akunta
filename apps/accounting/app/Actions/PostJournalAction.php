<?php

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\JournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;

class PostJournalAction extends BaseAction
{
    public function execute(Journal $journal, ?User $user = null): Journal
    {
        $this->authorize('journal.post', $journal);

        $this->validate($journal);

        $this->fireBefore(Hooks::JOURNAL_BEFORE_POST, $journal, $user);

        $this->runInTransaction(function () use ($journal, $user) {
            $journal->forceFill([
                'status' => Journal::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $user?->id,
            ])->save();

            $this->audit(
                action: 'journal.post',
                resourceType: Journal::class,
                resourceId: $journal->id,
                entityId: $journal->entity_id,
                metadata: [
                    'journal_number' => $journal->number,
                    'period_id' => $journal->period_id,
                    'total' => $journal->totalDebit(),
                ],
                actorUserId: $user?->id,
            );
        });

        $journal->refresh();

        $this->fireAfter(Hooks::JOURNAL_AFTER_POST, $journal, $user);

        return $journal;
    }

    protected function validate(Journal $journal): void
    {
        if ($journal->status !== Journal::STATUS_DRAFT) {
            throw JournalException::notDraft($journal->status);
        }

        $journal->loadMissing('entries.account', 'period');

        if ($journal->entries->isEmpty()) {
            throw JournalException::noEntries();
        }

        $period = $journal->period;
        if (! $period instanceof Period || ! $period->isOpen()) {
            throw JournalException::periodNotOpen($period?->status ?? 'missing');
        }

        foreach ($journal->entries as $entry) {
            $account = $entry->account;
            if (! $account instanceof Account) {
                throw JournalException::accountNotPostable('unknown');
            }
            if ($account->entity_id !== $journal->entity_id) {
                throw JournalException::entityMismatch();
            }
            if (! $account->is_postable) {
                throw JournalException::accountNotPostable($account->code);
            }
        }

        if (! $journal->isBalanced()) {
            throw JournalException::unbalanced($journal->totalDebit(), $journal->totalCredit());
        }
    }
}
