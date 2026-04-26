<?php

namespace App\Filament\Resources\RecurringJournalResource\Pages;

use App\Filament\Resources\RecurringJournalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringJournal extends CreateRecord
{
    protected static string $resource = RecurringJournalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['next_run_at'] = $data['next_run_at'] ?? $data['start_date'];

        return $data;
    }
}
