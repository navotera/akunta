<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Filament\Resources\JournalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;

class EditJournal extends EditRecord
{
    protected static string $resource = JournalResource::class;

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Hydrate amount + side from existing debit/credit so the new UI works on edit
        if (! empty($data['entries']) && is_array($data['entries'])) {
            foreach ($data['entries'] as $i => $row) {
                $debit = (float) ($row['debit'] ?? 0);
                $credit = (float) ($row['credit'] ?? 0);
                $data['entries'][$i]['amount'] = $debit > 0 ? $debit : $credit;
                $data['entries'][$i]['side'] = $debit > 0 ? 'debit' : 'credit';
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
