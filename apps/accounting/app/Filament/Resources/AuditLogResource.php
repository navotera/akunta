<?php

namespace App\Filament\Resources;

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\User;
use App\Filament\Resources\AuditLogResource\Pages;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $activeNavigationIcon = 'heroicon-s-clock';

    protected static ?string $cluster = \App\Filament\Clusters\Settings::class;

    protected static ?string $navigationLabel = 'Aktivitas';

    protected static ?string $modelLabel = 'Aktivitas';

    protected static ?string $pluralModelLabel = 'Aktivitas';

    protected static ?int $navigationSort = 30;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->striped()
            ->paginated([25, 50, 100, 250])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('d M Y H:i:s'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Pengguna')
                    ->placeholder('Sistem')
                    ->description(fn (AuditLog $r) => $r->actor?->email)
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resource_type')
                    ->label('Resource')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('resource_id')
                    ->label('Resource ID')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->limit(16)
                    ->copyable()
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('entity_id')
                    ->label('Entity')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->limit(16)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('actor_user_id')
                    ->label('Pengguna')
                    ->searchable()
                    ->preload()
                    ->options(fn () => User::query()->orderBy('name')->get()->mapWithKeys(fn (User $u) => [$u->id => $u->name.' · '.$u->email])->all()),
                Tables\Filters\SelectFilter::make('action')
                    ->label('Aksi')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => AuditLog::query()
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
                Tables\Filters\SelectFilter::make('resource_type')
                    ->label('Tipe Resource')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => AuditLog::query()
                        ->select('resource_type')
                        ->distinct()
                        ->orderBy('resource_type')
                        ->pluck('resource_type', 'resource_type')
                        ->all()),
                Tables\Filters\Filter::make('date_range')
                    ->label('Rentang Waktu')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari')->native(false)->displayFormat('d M Y'),
                        Forms\Components\DatePicker::make('until')->label('Sampai')->native(false)->displayFormat('d M Y'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    )
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if ($data['from'] ?? null) {
                            $i[] = 'Dari '.Carbon::parse($data['from'])->format('d M Y');
                        }
                        if ($data['until'] ?? null) {
                            $i[] = 'Sampai '.Carbon::parse($data['until'])->format('d M Y');
                        }

                        return $i;
                    }),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ViewAction::make()->slideOver(),
            ])
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading('Belum ada aktivitas')
            ->emptyStateDescription('Audit log akan terisi otomatis saat aksi sistem dijalankan.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Audit Entry')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('action')->label('Aksi')->fontFamily('mono')->badge(),
                    Infolists\Components\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                    Infolists\Components\TextEntry::make('actor.name')->label('Pengguna')->placeholder('Sistem'),
                    Infolists\Components\TextEntry::make('actor.email')->label('Email')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('resource_type')->label('Resource Type')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('resource_id')->label('Resource ID')->fontFamily('mono')->copyable(),
                    Infolists\Components\TextEntry::make('entity_id')->label('Entity')->fontFamily('mono')->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('ip_address')->label('IP')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('user_agent')->label('User Agent')->columnSpanFull()->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Metadata')
                ->collapsed()
                ->schema([
                    Infolists\Components\KeyValueEntry::make('metadata')
                        ->hiddenLabel()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
