<?php

namespace App\Filament\Resources;

use App\Actions\RunRecurringJournalAction;
use App\Filament\Resources\RecurringJournalResource\Pages;
use App\Models\JournalTemplate;
use App\Models\RecurringJournal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RecurringJournalResource extends Resource
{
    protected static ?string $model = RecurringJournal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $activeNavigationIcon = 'heroicon-s-arrow-path';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Jurnal Berulang';

    protected static ?string $modelLabel = 'Jurnal Berulang';

    protected static ?string $pluralModelLabel = 'Jurnal Berulang';

    protected static ?int $navigationSort = 25;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const FREQUENCIES = [
        RecurringJournal::FREQUENCY_DAILY     => 'Harian',
        RecurringJournal::FREQUENCY_WEEKLY    => 'Mingguan',
        RecurringJournal::FREQUENCY_MONTHLY   => 'Bulanan',
        RecurringJournal::FREQUENCY_QUARTERLY => 'Triwulan',
        RecurringJournal::FREQUENCY_YEARLY    => 'Tahunan',
    ];

    public const STATUSES = [
        RecurringJournal::STATUS_ACTIVE => 'Aktif',
        RecurringJournal::STATUS_PAUSED => 'Dijeda',
        RecurringJournal::STATUS_ENDED  => 'Selesai',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('template_id')
                    ->label('Template')
                    ->relationship(
                        name: 'template',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($q) => $q->where('is_active', true)->orderBy('code'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (JournalTemplate $r) => "{$r->code} — {$r->name}")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('frequency')
                    ->options(self::FREQUENCIES)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('day')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(31)
                    ->helperText('Bulanan/Quarterly/Yearly: 1–31. Mingguan: 0 (Min) – 6 (Sab).'),
                Forms\Components\DatePicker::make('start_date')
                    ->required()
                    ->native(false),
                Forms\Components\DatePicker::make('end_date')
                    ->native(false)
                    ->after('start_date'),
                Forms\Components\Toggle::make('auto_post')
                    ->helperText('Posting otomatis tanpa review manual.'),
                Forms\Components\Select::make('status')
                    ->options(self::STATUSES)
                    ->default(RecurringJournal::STATUS_ACTIVE)
                    ->disabledOn('create')
                    ->native(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_run_at')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('template.name')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::FREQUENCIES[$state] ?? $state),
                Tables\Columns\TextColumn::make('next_run_at')->date()->sortable(),
                Tables\Columns\TextColumn::make('last_run_at')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        RecurringJournal::STATUS_ACTIVE => 'success',
                        RecurringJournal::STATUS_PAUSED => 'warning',
                        RecurringJournal::STATUS_ENDED  => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('auto_post')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('frequency')->options(self::FREQUENCIES),
            ])
            ->actions([
                Tables\Actions\Action::make('pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (RecurringJournal $r) => $r->status === RecurringJournal::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (RecurringJournal $r) {
                        $r->update(['status' => RecurringJournal::STATUS_PAUSED]);
                        Notification::make()->title('Schedule dijeda')->success()->send();
                    }),
                Tables\Actions\Action::make('resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (RecurringJournal $r) => $r->status === RecurringJournal::STATUS_PAUSED)
                    ->requiresConfirmation()
                    ->action(function (RecurringJournal $r) {
                        $r->update(['status' => RecurringJournal::STATUS_ACTIVE]);
                        Notification::make()->title('Schedule diaktifkan')->success()->send();
                    }),
                Tables\Actions\Action::make('run')
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->visible(fn (RecurringJournal $r) => $r->status === RecurringJournal::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->modalDescription('Buat jurnal sekarang sesuai template?')
                    ->action(function (RecurringJournal $r) {
                        $j = app(RunRecurringJournalAction::class)->execute($r);
                        if ($j === null) {
                            Notification::make()->title('Belum jatuh tempo / tidak aktif')->warning()->send();

                            return;
                        }
                        Notification::make()->title('Jurnal dibuat: '.$j->number)->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRecurringJournals::route('/'),
            'create' => Pages\CreateRecurringJournal::route('/create'),
            'edit'   => Pages\EditRecurringJournal::route('/{record}/edit'),
        ];
    }
}
