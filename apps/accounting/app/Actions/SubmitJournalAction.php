<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Rbac\Models\User;
use App\Models\Journal;
use App\Services\JournalRevision;
use Illuminate\Validation\ValidationException;

class SubmitJournalAction extends BaseAction
{
    public function __construct(
        private readonly PostJournalAction $validator,
        private readonly JournalRevision $revision,
    ) {}

    public function execute(Journal $journal, ?User $user = null): Journal
    {
        $this->authorize('journal.submit', $journal);

        return $this->runInTransaction(function () use ($journal, $user): Journal {
            $journal = Journal::where('entity_id', $journal->entity_id)->lockForUpdate()->findOrFail($journal->id);
            $statusFrom = $journal->status;
            if (! in_array($statusFrom, [Journal::STATUS_DRAFT, Journal::STATUS_REJECTED], true)) {
                throw new \DomainException('Jurnal hanya dapat diajukan dari status Draft atau Perlu Revisi.');
            }

            $journal->assertPeriodOpen(true);
            if ($statusFrom === Journal::STATUS_REJECTED && ! $this->revision->hasChanges($journal)) {
                throw ValidationException::withMessages([
                    'submit' => 'Ubah data jurnal atau lampirannya sebelum mengajukan kembali untuk review.',
                ]);
            }

            $this->validator->validateContents($journal);
            $journal->forceFill([
                'status' => Journal::STATUS_SUBMITTED,
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
            $this->audit('journal.submit', Journal::class, $journal->id, $journal->entity_id, [
                'journal_number' => $journal->number,
                'status_from' => $statusFrom,
                'status_to' => Journal::STATUS_SUBMITTED,
            ], $user?->id);

            return $journal;
        });
    }
}
