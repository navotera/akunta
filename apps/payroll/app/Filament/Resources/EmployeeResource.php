<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\Employee;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(160),
            Forms\Components\TextInput::make('email')
                ->email()
                ->maxLength(160),
            Forms\Components\TextInput::make('monthly_wage')
                ->label('Gaji Bulanan (IDR)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->toggleable()->searchable(),
                Tables\Columns\TextColumn::make('monthly_wage')
                    ->label('Gaji Bulanan')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Aktif'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
