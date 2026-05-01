<?php

namespace App\Filament\Resources;

use Akunta\Rbac\Models\Role;
use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Resources\RoleResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $activeNavigationIcon = 'heroicon-s-shield-check';

    protected static ?string $cluster = \App\Filament\Clusters\Settings::class;

    protected static ?string $navigationLabel = 'Peran';

    protected static ?string $modelLabel = 'Peran';

    protected static ?string $pluralModelLabel = 'Peran';

    protected static ?int $navigationSort = 20;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Peran')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Kode')
                        ->required()
                        ->maxLength(64)
                        ->disabled(fn ($record) => $record?->is_preset ?? false)
                        ->dehydrated(true)
                        ->helperText('Identifier unik. Tidak boleh diubah jika preset.'),
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(120),
                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(2)
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_preset')
                        ->label('Preset (locked)')
                        ->disabled()
                        ->helperText('Preset roles dikelola sistem.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(60)
                    ->tooltip(fn ($state) => $state)
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_preset')
                    ->label('Preset')
                    ->boolean()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('Global')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_preset')
                    ->label('Tipe')
                    ->placeholder('Semua')
                    ->trueLabel('Preset')
                    ->falseLabel('Custom')
                    ->queries(
                        true: fn (Builder $q) => $q->where('is_preset', true),
                        false: fn (Builder $q) => $q->where('is_preset', false),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Role $r) => ! $r->is_preset),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $r) => ! $r->is_preset),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Detail Peran')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('code')->label('Kode')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('name')->label('Nama'),
                    Infolists\Components\IconEntry::make('is_preset')->label('Preset')->boolean(),
                    Infolists\Components\TextEntry::make('description')->label('Deskripsi')->columnSpanFull()->placeholder('—'),
                    Infolists\Components\TextEntry::make('parent.name')->label('Parent Role')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tenant.name')->label('Tenant')->placeholder('Global'),
                ]),
            Infolists\Components\Section::make('Permissions')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('permissions')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('code')->label('Kode')->fontFamily('mono')->size('sm'),
                            Infolists\Components\TextEntry::make('description')->label('Deskripsi')->placeholder('—'),
                            Infolists\Components\TextEntry::make('category')->label('Kategori')->badge()->color('gray')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PermissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
