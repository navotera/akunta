<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\JournalEntry;
use App\Services\FakeAccountProvenanceService;

class JournalEntryObserver
{
    public function created(JournalEntry $entry): void
    {
        $this->promoteAccountForRealEntry($entry);
    }

    public function updated(JournalEntry $entry): void
    {
        if ($entry->wasChanged('account_id')) {
            $this->promoteAccountForRealEntry($entry);
        }
    }

    private function promoteAccountForRealEntry(JournalEntry $entry): void
    {
        if (($entry->metadata['fake_data'] ?? false) === true) {
            return;
        }

        $journal = $entry->journal()->first();
        if ($journal?->source_app === 'fake-data') {
            return;
        }

        app(FakeAccountProvenanceService::class)->promote($entry->account_id);
    }
}
