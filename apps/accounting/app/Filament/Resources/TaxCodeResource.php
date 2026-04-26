<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxCodeResource\Pages;
use App\Models\Account;
use App\Models\TaxCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TaxCodeResource extends Resource
{
    protected static ?string $model = TaxCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $activeNavigationIcon = 'heroicon-s-receipt-percent';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Kode Pajak';

    protected static ?string $modelLabel = 'Kode Pajak';

    protected static ?string $pluralModelLabel = 'Kode Pajak';

    protected static ?int $navigationSort = 40;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const KINDS = [
        TaxCode::KIND_OUTPUT_VAT  => 'PPN Keluaran',
        TaxCode::KIND_INPUT_VAT   => 'PPN Masukan',
        TaxCode::KIND_WHT_PPH_21  => 'PPh 21',
        TaxCode::KIND_WHT_PPH_23  => 'PPh 23',
        TaxCode::KIND_WHT_PPH_4_2 => 'PPh 4(2)',
        TaxCode::KIND_WHT_PPH_26  => 'PPh 26',
        TaxCode::KIND_OTHER       => 'Lainnya',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(40)
                    ->helperText('Kode unik per entitas (mis. PPN-OUT-11, PPH23-2).'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('kind')
                    ->options(self::KINDS)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('rate')
                    ->numeric()
                    ->step('0.0001')
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->required(),
                Forms\Components\Select::make('tax_account_id')
                    ->label('Akun Pajak')
                    ->relationship(
                        name: 'taxAccount',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($q) => $q->where('is_postable', true)->orderBy('code'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}")
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        TaxCode::KIND_OUTPUT_VAT, TaxCode::KIND_INPUT_VAT => 'success',
                        TaxCode::KIND_WHT_PPH_21, TaxCode::KIND_WHT_PPH_23,
                        TaxCode::KIND_WHT_PPH_4_2, TaxCode::KIND_WHT_PPH_26 => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('rate')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' %')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('taxAccount.code')->label('Akun')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->options(self::KINDS),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTaxCodes::route('/'),
            'create' => Pages\CreateTaxCode::route('/create'),
            'edit'   => Pages\EditTaxCode::route('/{record}/edit'),
        ];
    }
}
