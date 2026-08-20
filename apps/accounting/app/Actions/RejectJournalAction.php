<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Rbac\Models\User;
use App\Models\Journal;

class RejectJournalAction extends BaseAction
{
    public function execute(Journal $journal, User $user, string $note): Journal
    {
        $this->authorize('journal.review', $journal);
        if ($journal->status !== Journal::STATUS_SUBMITTED) {
            throw new \DomainException('Hanya jurnal yang diajukan yang dapat ditolak.');
        }
        $journal->forceFill([
            'status' => Journal::STATUS_REJECTED,
            'review_note' => $note,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();
        $this->audit('journal.reject', Journal::class, $journal->id, $journal->entity_id, ['journal_number' => $journal->number, 'note' => $note], $user->id);
        return $journal->refresh();
    }
}
