<?php

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Filament\Resources\ApiTokenResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    public function mount(): void
    {
        parent::mount();

        if (session()->has('api_token_plain')) {
            Notification::make()
                ->title('Token dibuat — simpan sekarang')
                ->body("Nilai plain token:\n\n".session()->pull('api_token_plain')."\n\nToken tidak akan ditampilkan lagi.")
                ->persistent()
                ->warning()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
