<?php

namespace App\Filament\Resources;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\User;
use App\Filament\Resources\ApiTokenResource\Pages;
use App\Models\ApiToken;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $activeNavigationIcon = 'heroicon-s-key';

    protected static ?string $navigationGroup = 'API';

    protected static ?string $navigationLabel = 'Teknis';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'API Token';

    protected static ?string $pluralModelLabel = 'API Tokens';

    /**
     * ApiToken is tenant-global (no entity scope), so skip Filament tenancy binding.
     */
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Token')
                ->required()
                ->maxLength(120)
                ->helperText('Contoh: "Payroll service bot" — buat deskriptif supaya mudah revoke'),
            Forms\Components\Select::make('user_id')
                ->label('User (service account)')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->helperText('Gate check di PostJournalAction butuh user; v1 wajib isi.'),
            Forms\Components\Select::make('app_id')
                ->label('App scope')
                ->options(fn () => RbacApp::query()->orderBy('code')->pluck('code', 'id'))
                ->searchable()
                ->required()
                ->helperText('Wajib — metadata.source_app di request harus cocok dgn app.code ini.'),
            Forms\Components\TagsInput::make('permissions')
                ->label('Permissions')
                ->required()
                ->placeholder('Tekan Enter setelah tiap code')
                ->helperText('Contoh minimum untuk auto-journal: journal.create, journal.post'),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->native(false)
                ->helperText('Kosongkan untuk token non-expiring'),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('User')->toggleable(),
                Tables\Columns\TextColumn::make('app.code')->label('App')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('permissions')
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(',', $state) : (string) $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('last_used_at')->dateTime()->toggleable(),
                Tables\Columns\IconColumn::make('revoked_at')
                    ->label('Revoked')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->getStateUsing(fn (ApiToken $r) => $r->revoked_at !== null),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('revoked')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('revoked_at'),
                        false: fn ($q) => $q->whereNull('revoked_at'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->visible(fn (ApiToken $record) => $record->revoked_at === null)
                    ->action(fn (ApiToken $record) => $record->forceFill(['revoked_at' => now()])->save()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiTokens::route('/'),
            'create' => Pages\CreateApiToken::route('/create'),
        ];
    }
}
