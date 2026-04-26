<?php

namespace App\Filament\Resources\RecurringJournalResource\Pages;

use App\Filament\Resources\RecurringJournalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRecurringJournals extends ListRecords
{
    protected static string $resource = RecurringJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
