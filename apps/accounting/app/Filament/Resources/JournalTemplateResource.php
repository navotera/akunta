<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JournalTemplateResource\Pages;
use App\Filament\Resources\JournalTemplateResource\RelationManagers;
use App\Models\Journal;
use App\Models\JournalTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class JournalTemplateResource extends Resource
{
    protected static ?string $model = JournalTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $activeNavigationIcon = 'heroicon-s-clipboard-document-list';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Template Jurnal';

    protected static ?string $modelLabel = 'Template Jurnal';

    protected static ?string $pluralModelLabel = 'Template Jurnal';

    protected static ?int $navigationSort = 20;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const JOURNAL_TYPES = [
        Journal::TYPE_GENERAL    => 'Umum',
        Journal::TYPE_ADJUSTMENT => 'Penyesuaian',
        Journal::TYPE_CLOSING    => 'Penutup',
        Journal::TYPE_REVERSING  => 'Pembalik',
        Journal::TYPE_OPENING    => 'Pembukaan',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->maxLength(80)
                        ->helperText('Kode unik (mis. RENT, GAJI-BLN).'),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('journal_type')
                        ->label('Tipe Jurnal')
                        ->options(self::JOURNAL_TYPES)
                        ->default(Journal::TYPE_GENERAL)
                        ->native(false)
                        ->required(),
                    Forms\Components\Toggle::make('is_active')->default(true),
                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('default_memo')
                        ->maxLength(400)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('default_reference')
                        ->maxLength(120)
                        ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('journal_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::JOURNAL_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines')
                    ->alignRight(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListJournalTemplates::route('/'),
            'create' => Pages\CreateJournalTemplate::route('/create'),
            'edit'   => Pages\EditJournalTemplate::route('/{record}/edit'),
        ];
    }
}
