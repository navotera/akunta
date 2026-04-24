<?php

namespace App\Filament\Resources\PayrollRunResource\Pages;

use App\Actions\ApprovePayrollAction;
use App\Actions\PayPayrollAction;
use App\Filament\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditPayrollRun extends EditRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->color('warning')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (PayrollRun $record) => $record->isDraft())
                ->action(function (PayrollRun $record) {
                    try {
                        app(ApprovePayrollAction::class)->execute($record, auth()->user());
                        Notification::make()
                            ->title('Payroll approved')
                            ->success()
                            ->send();
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
                ->visible(fn (PayrollRun $record) => $record->isApproved())
                ->action(function (PayrollRun $record) {
                    try {
                        app(PayPayrollAction::class)->execute($record, auth()->user());
                        Notification::make()
                            ->title('Payroll paid — journal posted')
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
            Actions\DeleteAction::make()->visible(fn (PayrollRun $record) => $record->isDraft()),
        ];
    }
}
