<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollRunResource\Pages;
use App\Models\PayrollRun;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Penggajian';

    protected static ?string $modelLabel = 'Payroll Run';

    protected static ?string $pluralModelLabel = 'Payroll Runs';

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('period_label')
                ->label('Periode (YYYY-MM)')
                ->required()
                ->maxLength(20)
                ->helperText('Contoh: 2026-04'),
            Forms\Components\DatePicker::make('run_date')
                ->label('Tanggal')
                ->required(),
            Forms\Components\TextInput::make('total_wages')
                ->label('Total Gaji (IDR)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required()
                ->helperText('v1: manual total. Sum employees feature deferred.'),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period_label')->label('Periode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('run_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('total_wages')->label('Total')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => PayrollRun::STATUS_DRAFT,
                        'warning' => PayrollRun::STATUS_APPROVED,
                        'success' => PayrollRun::STATUS_PAID,
                    ]),
                Tables\Columns\TextColumn::make('journal_id')->label('Journal ID')->toggleable()->copyable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    PayrollRun::STATUS_DRAFT => 'Draft',
                    PayrollRun::STATUS_APPROVED => 'Approved',
                    PayrollRun::STATUS_PAID => 'Paid',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (PayrollRun $r) => $r->isDraft()),
            ])
            ->defaultSort('period_label', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollRuns::route('/'),
            'create' => Pages\CreatePayrollRun::route('/create'),
            'edit' => Pages\EditPayrollRun::route('/{record}/edit'),
        ];
    }
}
