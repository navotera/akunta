<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalTemplateResource\RelationManagers;

use App\Models\Account;
use App\Models\JournalTemplateLine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Pola Baris';

    protected static ?string $modelLabel = 'Baris';

    protected static ?string $pluralModelLabel = 'Baris';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('line_no')
                ->label('No.')
                ->numeric()
                ->minValue(1)
                ->required()
                ->default(fn () => $this->nextLineNo()),

            Forms\Components\Select::make('account_id')
                ->label('Akun')
                ->options(fn () => $this->accountOptions())
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\Select::make('side')
                ->options(['debit' => 'Debit', 'credit' => 'Credit'])
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('0 = wajib isi saat instansiasi. >0 = nilai tetap (mis. sewa 5.000.000).'),

            Forms\Components\TextInput::make('memo')
                ->maxLength(200),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('memo')
            ->defaultSort('line_no')
            ->columns([
                Tables\Columns\TextColumn::make('line_no')
                    ->label('No.')
                    ->sortable()
                    ->alignRight(),
                Tables\Columns\TextColumn::make('account.code')
                    ->label('Kode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('account.name')
                    ->label('Akun')
                    ->limit(40),
                Tables\Columns\TextColumn::make('side')
                    ->label('Sisi')
                    ->badge()
                    ->color(fn (string $state) => $state === 'debit' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Jumlah')
                    ->formatStateUsing(fn ($state) => bccomp((string) ($state ?? '0'), '0', 2) === 0
                        ? 'override saat pakai'
                        : number_format((float) $state, 0, ',', '.'))
                    ->alignRight(),
                Tables\Columns\TextColumn::make('memo')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Tambah Baris')
                    ->modalHeading('Tambah Baris Template')
                    ->using(function (array $data, RelationManager $livewire): JournalTemplateLine {
                        return $livewire->ownerRecord->lines()->create($data);
                    })
                    ->after(function () {
                        Notification::make()->title('Baris ditambahkan')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalHeading('Ubah Baris Template')
                    ->using(function (JournalTemplateLine $record, array $data): JournalTemplateLine {
                        $record->update($data);

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }

    /** @return array<string, string> */
    protected function accountOptions(): array
    {
        $entityId = $this->ownerRecord?->entity_id;
        if (! $entityId) {
            return [];
        }

        return Account::query()
            ->where('entity_id', $entityId)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
            ->all();
    }

    protected function nextLineNo(): int
    {
        $max = $this->ownerRecord
            ?->lines()
            ?->max('line_no') ?? 0;

        return ((int) $max) + 1;
    }
}
