<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Concerns;

use Akunta\Rbac\Models\Entity;
use App\Models\Journal;

trait ProtectsNativeFakeData
{
    protected function assertNativeFakePeriodMutable(Entity $entity): void
    {
        abort_if(
            $entity->is_fake_data,
            409,
            'Periode Demo 2026 dikunci. Gunakan Reset Dataset Demo untuk mengembalikan data bawaan.',
        );
    }

    protected function assertNativeFakeRecordedJournalMutable(Entity $entity, Journal $journal): void
    {
        abort_if(
            $entity->is_fake_data
                && in_array($journal->status, [Journal::STATUS_POSTED, Journal::STATUS_REVERSED], true),
            409,
            'Jurnal Tersimpan pada PT. Fake Data bersifat read-only. Gunakan Reset Dataset Demo untuk memulihkannya.',
        );
    }
}
