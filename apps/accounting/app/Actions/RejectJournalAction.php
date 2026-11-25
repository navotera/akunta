<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Rbac\Models\User;
use App\Models\Journal;
use App\Services\JournalRevision;

class RejectJournalAction extends BaseAction
{
    public function __construct(private readonly JournalRevision $revision) {}

    public function execute(Journal $journal, User $user, string $note): Journal
    {
        $this->authorize('journal.review', $journal);

        return $this->runInTransaction(function () use ($journal, $user, $note): Journal {
            $journal = Journal::where('entity_id', $journal->entity_id)->lockForUpdate()->findOrFail($journal->id);
            $journal->assertPeriodOpen(true);
            if ($journal->status !== Journal::STATUS_SUBMITTED) {
                throw new \DomainException('Hanya jurnal yang diajukan yang dapat ditolak.');
            }

            $journal->forceFill([
                'status' => Journal::STATUS_REJECTED,
                'review_note' => $note,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ])->save();
            $this->audit('journal.reject', Journal::class, $journal->id, $journal->entity_id, [
                'journal_number' => $journal->number,
                'note' => $note,
                'status_from' => Journal::STATUS_SUBMITTED,
                'status_to' => Journal::STATUS_REJECTED,
                'revision_fingerprint' => $this->revision->fingerprint($journal),
            ], $user->id);

            return $journal;
        });
    }
}
