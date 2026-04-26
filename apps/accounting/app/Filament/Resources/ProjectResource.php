<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Partner;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $activeNavigationIcon = 'heroicon-s-briefcase';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Project';

    protected static ?string $modelLabel = 'Project';

    protected static ?string $pluralModelLabel = 'Projects';

    protected static ?int $navigationSort = 31;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const STATUSES = [
        Project::STATUS_ACTIVE  => 'Aktif',
        Project::STATUS_ON_HOLD => 'Ditunda',
        Project::STATUS_CLOSED  => 'Selesai',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()->columns(2)->schema([
                    Forms\Components\TextInput::make('code')->required()->maxLength(40),
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\Select::make('partner_id')
                        ->label('Pelanggan (opsional)')
                        ->relationship(
                            name: 'partner',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($q) => $q->where('type', Partner::TYPE_CUSTOMER)->orderBy('name'),
                        )
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Select::make('status')
                        ->options(self::STATUSES)
                        ->default(Project::STATUS_ACTIVE)
                        ->required()
                        ->native(false),
                    Forms\Components\DatePicker::make('start_date')->native(false),
                    Forms\Components\DatePicker::make('end_date')->native(false),
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
                Tables\Columns\TextColumn::make('partner.name')->label('Pelanggan')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Project::STATUS_ACTIVE  => 'success',
                        Project::STATUS_ON_HOLD => 'warning',
                        Project::STATUS_CLOSED  => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('start_date')->date()->toggleable(),
                Tables\Columns\TextColumn::make('end_date')->date()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUSES),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit'   => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
