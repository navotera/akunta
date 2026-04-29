<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Akunta\Audit\Models\AuditLog;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Aktivitas';

    protected static ?string $modelLabel = 'Aktivitas';

    protected static ?string $pluralModelLabel = 'Aktivitas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('d M Y H:i:s'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->searchable(),
                Tables\Columns\TextColumn::make('resource_type')
                    ->label('Resource')
                    ->limit(40)
                    ->fontFamily('mono')
                    ->size('sm')
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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Aksi')
                    ->multiple()
                    ->options(fn () => AuditLog::query()
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->infolist([
                        Section::make('Audit Entry')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('action')->fontFamily('mono'),
                                TextEntry::make('created_at')->dateTime('d M Y H:i:s'),
                                TextEntry::make('resource_type')->fontFamily('mono'),
                                TextEntry::make('resource_id')->fontFamily('mono')->copyable(),
                                TextEntry::make('entity_id')->fontFamily('mono')->copyable()->placeholder('—'),
                                TextEntry::make('ip_address')->fontFamily('mono')->placeholder('—'),
                                TextEntry::make('user_agent')->columnSpanFull()->placeholder('—'),
                                KeyValueEntry::make('metadata')->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->bulkActions([])
            ->headerActions([]);
    }
}
