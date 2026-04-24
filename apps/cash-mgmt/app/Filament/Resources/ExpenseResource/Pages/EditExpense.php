<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Actions\ApproveExpenseAction;
use App\Actions\PayExpenseAction;
use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->color('warning')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (Expense $record) => $record->isDraft())
                ->action(function (Expense $record) {
                    try {
                        app(ApproveExpenseAction::class)->execute($record, auth()->user());
                        Notification::make()->title('Expense approved')->success()->send();
                        $this->refreshFormData(['status', 'approved_at']);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Approve failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('pay')
                ->label('Pay')
                ->color('success')
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalDescription('Posting will call the Accounting auto-journal API. Confirm to proceed.')
                ->visible(fn (Expense $record) => $record->isApproved())
                ->action(function (Expense $record) {
                    try {
                        app(PayExpenseAction::class)->execute($record, auth()->user());
                        Notification::make()
                            ->title('Expense paid — journal posted')
                            ->body('Journal ID: '.$record->fresh()->journal_id)
                            ->success()
                            ->send();
                        $this->refreshFormData(['status', 'journal_id', 'paid_at']);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Pay failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make()->visible(fn (Expense $record) => $record->isDraft()),
        ];
    }
}
