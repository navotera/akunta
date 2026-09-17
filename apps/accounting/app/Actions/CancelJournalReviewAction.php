<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Rbac\Models\User;
use App\Models\Journal;

class CancelJournalReviewAction extends BaseAction
{
    public function execute(Journal $journal, ?User $user = null): Journal
    {
        $this->authorize('journal.submit', $journal);

        if ($journal->status !== Journal::STATUS_SUBMITTED) {
            throw new \DomainException('Hanya jurnal yang sedang direview yang dapat dibatalkan.');
        }

        $journals = Journal::query()
            ->where('entity_id', $journal->entity_id)
            ->where('status', Journal::STATUS_SUBMITTED)
            ->where(function ($query) use ($journal): void {
                $query->whereKey($journal->id);
                if ($journal->input_group_id) {
                    $query->orWhere('input_group_id', $journal->input_group_id);
                }
            })
            ->get();

        $this->runInTransaction(function () use ($journals, $user): void {
            foreach ($journals as $item) {
                $item->forceFill([
                    'status' => Journal::STATUS_DRAFT,
                    'review_note' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ])->save();

                $this->audit(
                    action: 'journal.cancel_review',
                    resourceType: Journal::class,
                    resourceId: $item->id,
                    entityId: $item->entity_id,
                    metadata: [
                        'journal_number' => $item->number,
                        'status_from' => Journal::STATUS_SUBMITTED,
                        'status_to' => Journal::STATUS_DRAFT,
                    ],
                    actorUserId: $user?->id,
                );
            }
        });

        return $journal->refresh();
    }
}
