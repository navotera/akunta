<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\JournalResource;
use App\Models\Journal;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentJournalsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Jurnal Terbaru';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->label('No. Jurnal')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Referensi')
                    ->limit(40)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'posted' => 'success',
                        'draft' => 'warning',
                        'reversed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total')
                    ->label('Nilai')
                    ->getStateUsing(fn (Journal $record): string => 'Rp '.number_format(
                        (float) $record->entries()->sum('debit'),
                        0,
                        ',',
                        '.'
                    ))
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Diposting')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Lihat')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Journal $record): string => JournalResource::getUrl('edit', ['record' => $record->id])),
            ])
            ->paginated(false)
            ->defaultSort('date', 'desc');
    }

    protected function query(): Builder
    {
        $entity = Filament::getTenant();

        return Journal::query()
            ->where('entity_id', $entity?->id ?? '00000000000000000000000000')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->limit(10);
    }
}
