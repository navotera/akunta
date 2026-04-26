<?php

namespace App\Filament\Resources\JournalTemplateResource\Pages;

use App\Actions\InstantiateJournalTemplateAction;
use App\Exceptions\JournalException;
use App\Filament\Resources\JournalResource;
use App\Filament\Resources\JournalTemplateResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditJournalTemplate extends EditRecord
{
    protected static string $resource = JournalTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('instantiate')
                ->label('Buat Jurnal dari Template')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn () => $this->record !== null && $this->record->lines()->count() >= 2)
                ->form([
                    Forms\Components\DatePicker::make('date')
                        ->required()
                        ->default(now())
                        ->native(false),
                    Forms\Components\TextInput::make('reference')->maxLength(120),
                    Forms\Components\Textarea::make('memo')->rows(2)->maxLength(400),
                ])
                ->action(function (array $data) {
                    $template = $this->record;
                    if ($template === null) {
                        Notification::make()->title('Template tidak ditemukan')->danger()->send();

                        return;
                    }

                    try {
                        $journal = app(InstantiateJournalTemplateAction::class)->execute(
                            template: $template,
                            date: $data['date'],
                            reference: $data['reference'] ?? null,
                            memo: $data['memo'] ?? null,
                            createdBy: auth()->id(),
                        );

                        Notification::make()
                            ->title('Jurnal draft dibuat: '.$journal->number)
                            ->success()
                            ->send();

                        $this->redirect(JournalResource::getUrl('edit', ['record' => $journal->id]));
                    } catch (JournalException $e) {
                        Notification::make()
                            ->title('Gagal membuat jurnal')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error tidak terduga')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
