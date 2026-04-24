<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FundResource\Pages;
use App\Models\Fund;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class FundResource extends Resource
{
    protected static ?string $model = Fund::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Kas Kecil';

    protected static ?string $modelLabel = 'Fund';

    protected static ?string $pluralModelLabel = 'Funds';

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Fund')
                ->required()
                ->maxLength(160),
            Forms\Components\TextInput::make('account_code')
                ->label('Kode Akun Kas')
                ->required()
                ->maxLength(40)
                ->helperText('Kode akun kas di accounting (contoh: 1101 Kas, 1102 Bank)'),
            Forms\Components\TextInput::make('balance')
                ->label('Saldo (IDR)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('Saldo fisik di fund ini — sekadar referensi, bukan source of truth (GL di accounting).'),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('account_code')->label('Kode Akun')->badge(),
                Tables\Columns\TextColumn::make('balance')->money('IDR')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFunds::route('/'),
            'create' => Pages\CreateFund::route('/create'),
            'edit' => Pages\EditFund::route('/{record}/edit'),
        ];
    }
}
