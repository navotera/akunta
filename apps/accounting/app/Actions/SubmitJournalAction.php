<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Rbac\Models\User;
use App\Models\Journal;

class SubmitJournalAction extends BaseAction
{
    public function __construct(private readonly PostJournalAction $validator) {}

    public function execute(Journal $journal, ?User $user = null): Journal
    {
        $this->authorize('journal.submit', $journal);
        if (! in_array($journal->status, [Journal::STATUS_DRAFT, Journal::STATUS_REJECTED], true)) {
            throw new \DomainException('Jurnal hanya dapat diajukan dari status draft atau ditolak.');
        }

        $this->validator->validate($journal);
        $journal->forceFill([
            'status' => Journal::STATUS_SUBMITTED,
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();
        $this->audit('journal.submit', Journal::class, $journal->id, $journal->entity_id, ['journal_number' => $journal->number], $user?->id);
        return $journal->refresh();
    }
}
