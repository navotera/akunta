<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Pages;
use App\Models\Account;
use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $activeNavigationIcon = 'heroicon-s-identification';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Mitra';

    protected static ?string $modelLabel = 'Mitra';

    protected static ?string $pluralModelLabel = 'Mitra';

    protected static ?int $navigationSort = 20;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const TYPES = [
        Partner::TYPE_CUSTOMER => 'Pelanggan',
        Partner::TYPE_VENDOR   => 'Pemasok',
        Partner::TYPE_EMPLOYEE => 'Karyawan',
        Partner::TYPE_OTHER    => 'Lainnya',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identitas')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->required()
                            ->options(self::TYPES)
                            ->default(Partner::TYPE_CUSTOMER)
                            ->native(false),
                        Forms\Components\TextInput::make('code')
                            ->maxLength(40)
                            ->helperText('Opsional. Kode unik per entitas (mis. C-001, V-042).'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('npwp')
                            ->label('NPWP')
                            ->maxLength(32)
                            ->helperText('Opsional. Format bebas (akan dinormalisasi saat e-Faktur export).'),
                        Forms\Components\TextInput::make('tax_id')
                            ->label('Tax ID (non-ID)')
                            ->maxLength(64),
                    ]),

                Forms\Components\Section::make('Kontak')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(191),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(40),
                        Forms\Components\Textarea::make('address')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(80),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(2)
                            ->default('ID')
                            ->helperText('ISO 3166-1 alpha-2'),
                    ]),

                Forms\Components\Section::make('Akun Default (opsional)')
                    ->description('Override akun pengendali AR/AP untuk mitra ini. Jika kosong, akan jatuh ke default entitas.')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Select::make('receivable_account_id')
                            ->label('Akun Piutang')
                            ->relationship(
                                name: 'receivableAccount',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($q) => $q->where('type', 'asset')->where('is_postable', true)->orderBy('code'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}")
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Forms\Components\Select::make('payable_account_id')
                            ->label('Akun Hutang')
                            ->relationship(
                                name: 'payableAccount',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($q) => $q->where('type', 'liability')->where('is_postable', true)->orderBy('code'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}")
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ]),

                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Partner::TYPE_CUSTOMER => 'success',
                        Partner::TYPE_VENDOR   => 'warning',
                        Partner::TYPE_EMPLOYEE => 'info',
                        default                => 'gray',
                    }),
                Tables\Columns\TextColumn::make('npwp')
                    ->label('NPWP')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')
                    ->toggleable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(self::TYPES),
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
            'index'  => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit'   => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
