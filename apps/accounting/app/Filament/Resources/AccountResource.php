<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $activeNavigationIcon = 'heroicon-s-folder-open';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Bagan Akun';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Bagan Akun';

    protected static ?int $navigationSort = 10;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const TYPES = [
        'asset' => 'Aktiva',
        'liability' => 'Kewajiban',
        'equity' => 'Ekuitas',
        'revenue' => 'Pendapatan',
        'expense' => 'Biaya',
        'cogs' => 'HPP',
        'other' => 'Lain-lain',
    ];

    public const NORMAL_BALANCES = [
        'debit' => 'Debit',
        'credit' => 'Credit',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->helperText('4-digit recommended (per spec §8.4)'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->required()
                    ->options(self::TYPES)
                    ->native(false),
                Forms\Components\Select::make('normal_balance')
                    ->required()
                    ->options(self::NORMAL_BALANCES)
                    ->native(false),
                Forms\Components\Select::make('parent_account_id')
                    ->label('Parent Account')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query, Forms\Get $get) => $query
                            ->where('is_postable', false)
                            ->whereKeyNot($get('id'))
                            ->orderBy('code'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}")
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Forms\Components\Toggle::make('is_postable')
                    ->helperText('Leaf (postable) accounts can receive journal entries. Aggregator parents should be non-postable.')
                    ->default(true),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('parent.code')
                    ->label('Parent')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('normal_balance')
                    ->badge()
                    ->color(fn (string $state) => $state === 'debit' ? 'info' : 'warning'),
                Tables\Columns\IconColumn::make('is_postable')
                    ->boolean()
                    ->label('Postable'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(self::TYPES),
                Tables\Filters\TernaryFilter::make('is_postable'),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
