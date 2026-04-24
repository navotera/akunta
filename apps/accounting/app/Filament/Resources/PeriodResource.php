<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PeriodResource\Pages;
use App\Models\Period;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PeriodResource extends Resource
{
    protected static ?string $model = Period::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Period & Close';

    protected static ?string $navigationLabel = 'Periode';

    protected static ?string $modelLabel = 'Periode';

    protected static ?string $pluralModelLabel = 'Periode';

    protected static ?int $navigationSort = 20;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const STATUS_COLORS = [
        Period::STATUS_OPEN => 'success',
        Period::STATUS_CLOSING => 'warning',
        Period::STATUS_CLOSED => 'gray',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('April 2026'),
                Forms\Components\DatePicker::make('start_date')
                    ->required()
                    ->native(false),
                Forms\Components\DatePicker::make('end_date')
                    ->required()
                    ->native(false)
                    ->afterOrEqual('start_date'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('closed_at')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Period::STATUS_OPEN => 'Open',
                    Period::STATUS_CLOSING => 'Closing',
                    Period::STATUS_CLOSED => 'Closed',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (Period $r) => $r->isOpen()),
                Tables\Actions\Action::make('close')
                    ->label('Close Period')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Period $r) => $r->isOpen())
                    ->action(function (Period $r) {
                        $r->forceFill([
                            'status' => Period::STATUS_CLOSED,
                            'closed_at' => now(),
                            'closed_by' => auth()->id(),
                        ])->save();

                        Notification::make()
                            ->title("Periode {$r->name} ditutup.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeriods::route('/'),
            'create' => Pages\CreatePeriod::route('/create'),
            'edit' => Pages\EditPeriod::route('/{record}/edit'),
        ];
    }
}
