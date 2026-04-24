<?php

namespace App\Filament\Resources;

use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Filament\Resources\JournalResource\Pages;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

class JournalResource extends Resource
{
    protected static ?string $model = Journal::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $activeNavigationIcon = 'heroicon-s-pencil-square';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Jurnal';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal';

    protected static ?int $navigationSort = 10;

    protected static ?string $tenantOwnershipRelationshipName = 'entity';

    public const TYPES = [
        Journal::TYPE_GENERAL => 'Umum',
        Journal::TYPE_ADJUSTMENT => 'Penyesuaian',
        Journal::TYPE_CLOSING => 'Penutup',
        Journal::TYPE_REVERSING => 'Pembalik',
        Journal::TYPE_OPENING => 'Pembuka',
    ];

    public const STATUS_COLORS = [
        Journal::STATUS_DRAFT => 'gray',
        Journal::STATUS_POSTED => 'success',
        Journal::STATUS_REVERSED => 'warning',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Header')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('period_id')
                            ->label('Periode')
                            ->required()
                            ->relationship(
                                name: 'period',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', Period::STATUS_OPEN)->orderBy('start_date', 'desc'),
                            )
                            ->native(false),
                        Forms\Components\Select::make('type')
                            ->required()
                            ->options(self::TYPES)
                            ->default(Journal::TYPE_GENERAL)
                            ->native(false),
                        Forms\Components\DatePicker::make('date')
                            ->required()
                            ->native(false)
                            ->default(now()),
                        Forms\Components\TextInput::make('number')
                            ->required()
                            ->maxLength(40)
                            ->helperText('Manual v1; auto-numbering nanti.'),
                        Forms\Components\TextInput::make('reference')
                            ->maxLength(120),
                        Forms\Components\Textarea::make('memo')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Entries')
                    ->schema([
                        Forms\Components\Repeater::make('entries')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('account_id')
                                    ->label('Akun')
                                    ->required()
                                    ->relationship(
                                        name: 'account',
                                        modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('is_postable', true)->where('is_active', true)->orderBy('code'),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}")
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\TextInput::make('debit')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),
                                Forms\Components\TextInput::make('credit')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),
                                Forms\Components\TextInput::make('memo')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->defaultItems(2)
                            ->itemLabel(fn (array $state) => isset($state['account_id']) ? null : 'Baris baru')
                            ->reorderableWithButtons()
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $component) {
                                static $counter = 0;
                                $data['line_no'] = ++$counter;

                                return $data;
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('memo')
                    ->limit(40)
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('entries_sum_debit')
                    ->sum('entries', 'debit')
                    ->label('Total')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('posted_at')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Journal::STATUS_DRAFT => 'Draft',
                    Journal::STATUS_POSTED => 'Posted',
                    Journal::STATUS_REVERSED => 'Reversed',
                ]),
                Tables\Filters\SelectFilter::make('type')->options(self::TYPES),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (Journal $r) => $r->status === Journal::STATUS_DRAFT),
                Tables\Actions\Action::make('post')
                    ->label('Post')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Journal $r) => $r->status === Journal::STATUS_DRAFT)
                    ->action(function (Journal $r) {
                        try {
                            app(PostJournalAction::class)->execute($r, auth()->user());
                            Notification::make()->title('Journal posted.')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Gagal post jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->form([
                        Forms\Components\Textarea::make('reason')->required()->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Journal $r) => $r->status === Journal::STATUS_POSTED)
                    ->action(function (Journal $r, array $data) {
                        try {
                            app(ReverseJournalAction::class)->execute($r, auth()->user(), $data['reason'] ?? null);
                            Notification::make()->title('Journal reversed.')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Gagal reverse jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournals::route('/'),
            'create' => Pages\CreateJournal::route('/create'),
            'edit' => Pages\EditJournal::route('/{record}/edit'),
        ];
    }
}
