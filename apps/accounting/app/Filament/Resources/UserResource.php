<?php

namespace App\Filament\Resources;

use Akunta\Rbac\Models\User;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $activeNavigationIcon = 'heroicon-s-users';

    protected static ?string $cluster = \App\Filament\Clusters\Settings::class;

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?int $navigationSort = 10;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(120),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('main_tier_user_id')
                        ->label('Main Tier User ID (Ecopa)')
                        ->maxLength(64)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Identitas dikelola Ecopa.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('active_assignments_count')
                    ->label('Peran Aktif')
                    ->counts([
                        'assignments' => fn (Builder $q) => $q->whereNull('revoked_at'),
                    ])
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('social_accounts_count')
                    ->label('SSO')
                    ->counts('socialAccounts')
                    ->alignCenter()
                    ->toggleable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Login Terakhir')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('d M Y H:i') ?? '—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Email Terverifikasi')
                    ->placeholder('Semua')
                    ->trueLabel('Verified')
                    ->falseLabel('Belum verified')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('email_verified_at'),
                        false: fn (Builder $q) => $q->whereNull('email_verified_at'),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\Filter::make('active_assignment')
                    ->label('Punya peran aktif')
                    ->toggle()
                    ->query(fn (Builder $q) => $q->whereHas('assignments', fn ($s) => $s->whereNull('revoked_at'))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identitas')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Nama'),
                    Infolists\Components\TextEntry::make('email')->label('Email')->fontFamily('mono')->copyable(),
                    Infolists\Components\TextEntry::make('id')->label('ULID')->fontFamily('mono')->copyable(),
                    Infolists\Components\TextEntry::make('main_tier_user_id')->label('Ecopa ID')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\IconEntry::make('email_verified_at')->label('Verified')->boolean(),
                    Infolists\Components\TextEntry::make('last_login_at')->label('Login Terakhir')->dateTime('d M Y H:i')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Akun Sosial (SSO)')
                ->collapsed()
                ->schema([
                    Infolists\Components\RepeatableEntry::make('socialAccounts')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('provider')->label('Provider')->badge(),
                            Infolists\Components\TextEntry::make('email')->label('Email')->fontFamily('mono'),
                            Infolists\Components\TextEntry::make('linked_at')->label('Linked')->dateTime('d M Y'),
                            Infolists\Components\TextEntry::make('last_used_at')->label('Last Used')->since()->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AssignmentsRelationManager::class,
            RelationManagers\AuditLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
