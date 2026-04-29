<?php

namespace App\Filament\Resources;

use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Filament\Resources\JournalResource\Pages;
use App\Filament\Resources\JournalResource\RelationManagers;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Support\ActivePeriod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
        $moneyMask = RawJs::make("\$money(\$input, ',', '.', 0)");

        return $form
            ->schema([
                Forms\Components\Grid::make(12)
                    ->schema([
                        // ====== LEFT — Konteks (sticky on wide screens) ======
                        Forms\Components\Section::make()
                            ->key('journal-context')
                            ->extraAttributes([
                                'class' => 'ak-journal-context',
                                'x-show' => '$store.akJournal.ctxOpen',
                                'x-transition.opacity.duration.250ms' => '',
                                'x-cloak' => '',
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 4])
                            ->schema([
                                Forms\Components\Placeholder::make('eyebrow')
                                    ->hiddenLabel()
                                    ->content(new HtmlString(
                                        '<div class="flex items-baseline justify-between border-y border-gray-900/40 dark:border-gray-100/30 py-1 mb-3">'
                                        .'<span class="ak-eyebrow">§ Transaksi — Konteks</span>'
                                        .'<span class="ak-mono text-[0.65rem] tracking-[0.18em] uppercase text-gray-500">JV / new</span>'
                                        .'</div>'
                                        .'<h2 class="ak-display text-3xl tracking-tight leading-none mb-1">Jurnal <em class="ak-italic ak-copper" style="font-style:italic;">Baru</em></h2>'
                                        .'<p class="text-sm text-gray-500 ak-italic" style="font-family:\'Fraunces\',serif;font-style:italic;">Catat transaksi. Setiap baris harus seimbang.</p>'
                                    )),

                                Forms\Components\DatePicker::make('date')
                                    ->label('Tanggal')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->default(now())
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        if (! $state) {
                                            return;
                                        }
                                        $period = Period::query()
                                            ->where('status', Period::STATUS_OPEN)
                                            ->whereDate('start_date', '<=', $state)
                                            ->whereDate('end_date', '>=', $state)
                                            ->orderByDesc('start_date')
                                            ->first();
                                        if ($period) {
                                            $set('period_id', $period->id);
                                        }
                                    })
                                    ->helperText('Periode otomatis terisi.'),

                                Forms\Components\Select::make('period_id')
                                    ->label('Periode')
                                    ->required()
                                    ->default(fn () => ActivePeriod::id())
                                    ->relationship(
                                        name: 'period',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query) => $query->where('status', Period::STATUS_OPEN)->orderBy('start_date', 'desc'),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Period $r) => $r->name.' ('.Carbon::parse($r->start_date)->format('d M').' — '.Carbon::parse($r->end_date)->format('d M Y').')')
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->helperText('Otomatis pakai periode aktif. Boleh override.'),

                                Forms\Components\Select::make('type')
                                    ->label('Jenis')
                                    ->required()
                                    ->options(self::TYPES)
                                    ->default(Journal::TYPE_GENERAL)
                                    ->native(false),

                                Forms\Components\TextInput::make('number')
                                    ->label('No. Jurnal')
                                    ->maxLength(40)
                                    ->placeholder('Otomatis saat simpan')
                                    ->prefixIcon('heroicon-o-hashtag')
                                    ->helperText('Kosongkan untuk auto-generate (JV-YYYYMM-####).')
                                    ->dehydrated(true),

                                Forms\Components\TextInput::make('reference')
                                    ->label('Referensi')
                                    ->maxLength(120)
                                    ->placeholder('No. invoice / dokumen sumber')
                                    ->prefixIcon('heroicon-o-link'),

                                Forms\Components\Textarea::make('memo')
                                    ->label('Keterangan')
                                    ->rows(3)
                                    ->placeholder('Deskripsi singkat transaksi…'),
                            ]),

                        // ====== RIGHT — Baris jurnal ======
                        Forms\Components\Section::make()
                            ->key('journal-lines')
                            ->extraAttributes([
                                'class' => 'ak-journal-lines',
                                'x-bind:class' => '$store.akJournal.ctxOpen ? \'\' : \'ak-journal-lines--full\'',
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 8])
                            ->footerActions([
                                Forms\Components\Actions\Action::make('autoBalance')
                                    ->label('Seimbangkan Otomatis')
                                    ->icon('heroicon-o-scale')
                                    ->color('gray')
                                    ->action(fn (Set $set, Get $get) => self::autoBalance($set, $get)),

                                Forms\Components\Actions\Action::make('addCounter')
                                    ->label('Tambah Lawan Akun')
                                    ->icon('heroicon-o-arrows-right-left')
                                    ->color('warning')
                                    ->action(fn (Set $set, Get $get) => self::addCounterEntry($set, $get)),

                                Forms\Components\Actions\Action::make('clearLines')
                                    ->label('Bersihkan')
                                    ->icon('heroicon-o-trash')
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->action(fn (Set $set) => $set('entries', [
                                        ['account_id' => null, 'amount' => 0, 'side' => 'debit', 'memo' => null, 'debit' => 0, 'credit' => 0],
                                        ['account_id' => null, 'amount' => 0, 'side' => 'credit', 'memo' => null, 'debit' => 0, 'credit' => 0],
                                    ])),
                            ])
                            ->schema([
                                Forms\Components\Placeholder::make('lines_eyebrow')
                                    ->hiddenLabel()
                                    ->content(new HtmlString(
                                        '<div class="flex items-baseline justify-between border-y border-gray-900/40 dark:border-gray-100/30 py-1 mb-3 gap-3">'
                                        .'<span class="ak-eyebrow">§ Baris — Debit · Kredit</span>'
                                        .'<div class="flex items-center gap-3">'
                                        .'  <button type="button" class="ak-ctx-toggle" '
                                        .'    @click="$store.akJournal.toggle()" '
                                        .'    :title="$store.akJournal.ctxOpen ? \'Sembunyikan kolom konteks\' : \'Tampilkan kolom konteks\'">'
                                        .'    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" '
                                        .'      :style="$store.akJournal.ctxOpen ? \'\' : \'transform: rotate(180deg);\'">'
                                        .'      <path d="M3 6h18M3 12h12M3 18h18"/>'
                                        .'      <path d="M16 9l3 3-3 3"/>'
                                        .'    </svg>'
                                        .'    <span class="ak-ctx-toggle-label" x-text="$store.akJournal.ctxOpen ? \'Sembunyikan konteks\' : \'Tampilkan konteks\'"></span>'
                                        .'  </button>'
                                        .'  <span class="ak-mono text-[0.65rem] tracking-[0.18em] uppercase text-gray-500">double-entry</span>'
                                        .'</div>'
                                        .'</div>'
                                    )),

                                Forms\Components\Repeater::make('entries')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('account_id')
                                            ->label('Akun')
                                            ->required()
                                            ->relationship(
                                                name: 'account',
                                                modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('code'),
                                            )
                                            ->getOptionLabelFromRecordUsing(fn (Account $r) => "{$r->code} — {$r->name}".($r->is_postable ? '' : '  [non-postable]'))
                                            ->disableOptionWhen(fn ($value) => optional(Account::find($value))->is_postable === false)
                                            ->searchable(['code', 'name'])
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, $state) {
                                                $set('account_balance', self::accountBalanceLabel($state));
                                            })
                                            ->helperText(fn (Get $get) => $get('account_balance') ?: null)
                                            ->columnSpan(['default' => 12, 'md' => 5]),

                                        Forms\Components\TextInput::make('amount')
                                            ->label('Jumlah')
                                            ->prefix('Rp')
                                            ->inputMode('decimal')
                                            ->mask($moneyMask)
                                            ->stripCharacters('.')
                                            ->numeric()
                                            ->default(0)
                                            ->minValue(0)
                                            ->extraInputAttributes(['class' => 'text-right tabular-nums font-mono'])
                                            ->live(debounce: 500)
                                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                                self::syncAmountSide($set, $get, (float) $state, $get('side') ?? 'debit');
                                            })
                                            ->columnSpan(['default' => 8, 'md' => 4]),

                                        Forms\Components\ToggleButtons::make('side')
                                            ->label('Sisi')
                                            ->options([
                                                'debit' => 'Debit',
                                                'credit' => 'Kredit',
                                            ])
                                            ->colors([
                                                'debit' => 'primary',
                                                'credit' => 'warning',
                                            ])
                                            ->icons([
                                                'debit' => 'heroicon-m-arrow-down-left',
                                                'credit' => 'heroicon-m-arrow-up-right',
                                            ])
                                            ->default('debit')
                                            ->grouped()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                                self::syncAmountSide($set, $get, (float) ($get('amount') ?? 0), $state ?? 'debit');
                                            })
                                            ->columnSpan(['default' => 4, 'md' => 3]),

                                        Forms\Components\Hidden::make('debit')->default(0),
                                        Forms\Components\Hidden::make('credit')->default(0),
                                        Forms\Components\Hidden::make('account_balance'),

                                        Forms\Components\TextInput::make('memo')
                                            ->label('Catatan baris')
                                            ->placeholder('opsional — keterangan baris ini')
                                            ->maxLength(255)
                                            ->columnSpan(12),
                                    ])
                                    ->columns(12)
                                    ->defaultItems(2)
                                    ->minItems(2)
                                    ->addActionLabel('+ Tambah Baris')
                                    ->itemLabel(function (array $state) {
                                        $amount = (float) ($state['amount'] ?? max((float) ($state['debit'] ?? 0), (float) ($state['credit'] ?? 0)));
                                        $side = $state['side'] ?? ((float) ($state['debit'] ?? 0) > 0 ? 'debit' : 'credit');

                                        $code = '—';
                                        $name = 'Akun belum dipilih';
                                        if (! empty($state['account_id'])) {
                                            $acct = Account::find($state['account_id']);
                                            if ($acct) {
                                                $code = $acct->code;
                                                $name = $acct->name;
                                            }
                                        }

                                        $sideBadge = $side === 'debit'
                                            ? '<span style="font-family:\'JetBrains Mono\',monospace;font-size:0.65rem;letter-spacing:0.18em;padding:1px 6px;border:1px solid #0D3B2E;color:#0D3B2E;border-radius:2px;">D</span>'
                                            : '<span style="font-family:\'JetBrains Mono\',monospace;font-size:0.65rem;letter-spacing:0.18em;padding:1px 6px;border:1px solid #B8654A;color:#B8654A;border-radius:2px;">K</span>';

                                        $amountStr = $amount > 0 ? 'Rp '.number_format($amount, 0, ',', '.') : '—';

                                        return new HtmlString(
                                            '<span style="display:inline-flex;align-items:center;gap:0.6rem;">'
                                            .$sideBadge
                                            .'<span style="font-family:\'JetBrains Mono\',monospace;font-size:0.78rem;color:#6B685F;">'.e($code).'</span>'
                                            .'<span style="font-family:\'Instrument Sans\',sans-serif;">'.e($name).'</span>'
                                            .'<span style="margin-left:auto;font-family:\'JetBrains Mono\',monospace;font-variant-numeric:tabular-nums;font-weight:500;">'.$amountStr.'</span>'
                                            .'</span>'
                                        );
                                    })
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->collapsed(false)
                                    ->cloneable()
                                    ->live()
                                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data) {
                                        static $counter = 0;
                                        $data['line_no'] = ++$counter;

                                        return self::normalizeEntryData($data);
                                    })
                                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::normalizeEntryData($data)),

                                Forms\Components\Placeholder::make('totals')
                                    ->hiddenLabel()
                                    ->extraAttributes(['class' => 'ak-journal-totals'])
                                    ->content(function (Get $get): HtmlString {
                                        $entries = $get('entries') ?? [];
                                        $debit = 0.0;
                                        $credit = 0.0;
                                        foreach ($entries as $row) {
                                            $amount = (float) ($row['amount'] ?? 0);
                                            $side = $row['side'] ?? null;
                                            if ($side === 'debit') {
                                                $debit += $amount;
                                            } elseif ($side === 'credit') {
                                                $credit += $amount;
                                            } else {
                                                $debit += (float) ($row['debit'] ?? 0);
                                                $credit += (float) ($row['credit'] ?? 0);
                                            }
                                        }
                                        $diff = $debit - $credit;
                                        $balanced = abs($diff) < 0.005;

                                        $fmt = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');

                                        $statusBlock = $balanced
                                            ? '<div style="display:inline-flex;align-items:center;gap:0.5rem;font-family:\'JetBrains Mono\',monospace;font-size:0.7rem;letter-spacing:0.2em;text-transform:uppercase;color:#0D3B2E;">'
                                                .'<span style="width:0.5rem;height:0.5rem;border-radius:999px;background:#0D3B2E;"></span>Seimbang</div>'
                                            : '<div style="display:inline-flex;align-items:center;gap:0.5rem;font-family:\'JetBrains Mono\',monospace;font-size:0.7rem;letter-spacing:0.2em;text-transform:uppercase;color:#C23B22;">'
                                                .'<span style="width:0.5rem;height:0.5rem;border-radius:999px;background:#C23B22;animation:pulse 1.6s ease-in-out infinite;"></span>Selisih '.$fmt(abs($diff)).'</div>';

                                        // visual scale bar
                                        $max = max($debit, $credit, 1);
                                        $dPct = ($debit / $max) * 100;
                                        $cPct = ($credit / $max) * 100;

                                        return new HtmlString(
                                            '<div class="ak-totals-card">'
                                            .'  <div class="ak-totals-grid">'
                                            .'    <div>'
                                            .'      <div class="ak-totals-label">Total Debit</div>'
                                            .'      <div class="ak-totals-value">'.$fmt($debit).'</div>'
                                            .'      <div class="ak-totals-bar"><span style="width:'.number_format($dPct, 1).'%;background:#0D3B2E;"></span></div>'
                                            .'    </div>'
                                            .'    <div>'
                                            .'      <div class="ak-totals-label">Total Kredit</div>'
                                            .'      <div class="ak-totals-value">'.$fmt($credit).'</div>'
                                            .'      <div class="ak-totals-bar"><span style="width:'.number_format($cPct, 1).'%;background:#B8654A;"></span></div>'
                                            .'    </div>'
                                            .'    <div>'
                                            .'      <div class="ak-totals-label">Status</div>'
                                            .'      <div class="ak-totals-status">'.$statusBlock.'</div>'
                                            .'      <div class="ak-totals-hint">'.e(count($entries)).' baris</div>'
                                            .'    </div>'
                                            .'  </div>'
                                            .'</div>'
                                        );
                                    }),
                            ]),
                    ]),
            ]);
    }

    /* ----------------- helpers ----------------- */

    protected static function syncAmountSide(Set $set, Get $get, float $amount, string $side): void
    {
        if ($side === 'debit') {
            $set('debit', $amount);
            $set('credit', 0);
        } else {
            $set('debit', 0);
            $set('credit', $amount);
        }
    }

    protected static function normalizeEntryData(array $data): array
    {
        $amount = (float) ($data['amount'] ?? max((float) ($data['debit'] ?? 0), (float) ($data['credit'] ?? 0)));
        $side = $data['side'] ?? ((float) ($data['debit'] ?? 0) > 0 ? 'debit' : 'credit');
        $data['debit'] = $side === 'debit' ? $amount : 0;
        $data['credit'] = $side === 'credit' ? $amount : 0;

        return $data;
    }

    protected static function autoBalance(Set $set, Get $get): void
    {
        $entries = $get('entries') ?? [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($entries as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if (($row['side'] ?? null) === 'debit') {
                $debit += $amount;
            } elseif (($row['side'] ?? null) === 'credit') {
                $credit += $amount;
            }
        }
        $diff = $debit - $credit;
        if (abs($diff) < 0.005) {
            Notification::make()->title('Sudah seimbang')->success()->send();

            return;
        }

        foreach ($entries as $i => $row) {
            $hasAccount = ! empty($row['account_id']);
            $rowAmount = (float) ($row['amount'] ?? 0);
            if ($hasAccount && $rowAmount === 0.0) {
                $entries[$i]['amount'] = abs($diff);
                $entries[$i]['side'] = $diff > 0 ? 'credit' : 'debit';
                $entries[$i]['debit'] = $diff > 0 ? 0 : abs($diff);
                $entries[$i]['credit'] = $diff > 0 ? abs($diff) : 0;
                $set('entries', $entries);
                Notification::make()->title('Selisih '.number_format(abs($diff), 0, ',', '.').' diisi otomatis')->success()->send();

                return;
            }
        }

        Notification::make()->title('Tidak ada baris kosong untuk auto-balance')->warning()->send();
    }

    protected static function addCounterEntry(Set $set, Get $get): void
    {
        $entries = $get('entries') ?? [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($entries as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if (($row['side'] ?? null) === 'debit') {
                $debit += $amount;
            } elseif (($row['side'] ?? null) === 'credit') {
                $credit += $amount;
            }
        }
        $diff = $debit - $credit;
        if (abs($diff) < 0.005) {
            Notification::make()->title('Sudah seimbang — tidak perlu lawan akun')->info()->send();

            return;
        }
        $side = $diff > 0 ? 'credit' : 'debit';
        $amount = abs($diff);

        $entries[] = [
            'account_id' => null,
            'amount' => $amount,
            'side' => $side,
            'memo' => null,
            'debit' => $side === 'debit' ? $amount : 0,
            'credit' => $side === 'credit' ? $amount : 0,
            'account_balance' => null,
        ];

        $set('entries', $entries);
        Notification::make()
            ->title('Baris lawan ditambah')
            ->body('Pilih akun untuk baris '.strtoupper($side).' Rp '.number_format($amount, 0, ',', '.'))
            ->success()
            ->send();
    }

    protected static function accountBalanceLabel(?string $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }
        $balance = (float) JournalEntry::query()
            ->where('account_id', $accountId)
            ->whereHas('journal', fn ($q) => $q->where('status', Journal::STATUS_POSTED))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal');

        $side = $balance >= 0 ? 'D' : 'K';
        $abs = abs($balance);

        return 'Saldo akun: Rp '.number_format($abs, 0, ',', '.').' ('.$side.')';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->striped()
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->description(fn (Journal $r) => $r->date ? Carbon::parse($r->date)->isoFormat('dddd') : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->label('No. Jurnal')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Journal::TYPE_GENERAL => 'gray',
                        Journal::TYPE_ADJUSTMENT => 'info',
                        Journal::TYPE_CLOSING => 'warning',
                        Journal::TYPE_REVERSING => 'danger',
                        Journal::TYPE_OPENING => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref.')
                    ->searchable()
                    ->limit(20)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('memo')
                    ->label('Keterangan')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($state) => $state)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Baris')
                    ->counts('entries')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('entries_sum_debit')
                    ->sum('entries', 'debit')
                    ->label('Total')
                    ->money('IDR')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->icon(fn (string $state) => match ($state) {
                        Journal::STATUS_DRAFT => 'heroicon-o-pencil',
                        Journal::STATUS_POSTED => 'heroicon-o-check-circle',
                        Journal::STATUS_REVERSED => 'heroicon-o-arrow-uturn-left',
                        default => null,
                    })
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Journal::STATUS_DRAFT => 'Draft',
                        Journal::STATUS_POSTED => 'Posted',
                        Journal::STATUS_REVERSED => 'Reversed',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Diposting')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source_app')
                    ->label('Sumber')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable()
                    ->formatStateUsing(function (Journal $r) {
                        if ($r->source_app === null || $r->source_app === '' || $r->source_app === 'accounting') {
                            return $r->source_app ?: '—';
                        }

                        return $r->source_id
                            ? $r->source_app.' · '.Str::limit($r->source_id, 16)
                            : $r->source_app;
                    })
                    ->url(function (Journal $r) {
                        if (empty($r->source_id) || $r->source_app === null || $r->source_app === 'accounting') {
                            return null;
                        }
                        $template = config("akunta.source_drill_urls.{$r->source_app}");
                        if (! is_string($template) || $template === '') {
                            return null;
                        }

                        return strtr($template, [
                            '{entity}' => $r->entity_id,
                            '{source_id}' => urlencode((string) $r->source_id),
                        ]);
                    }, shouldOpenInNewTab: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        Journal::STATUS_DRAFT => 'Draft',
                        Journal::STATUS_POSTED => 'Posted',
                        Journal::STATUS_REVERSED => 'Reversed',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->multiple()
                    ->options(self::TYPES),
                Tables\Filters\SelectFilter::make('period_id')
                    ->label('Periode')
                    ->relationship('period', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('date_range')
                    ->label('Rentang Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Dari')
                            ->native(false)
                            ->displayFormat('d M Y'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Sampai')
                            ->native(false)
                            ->displayFormat('d M Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'Dari '.Carbon::parse($data['from'])->format('d M Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Sampai '.Carbon::parse($data['until'])->format('d M Y');
                        }

                        return $indicators;
                    }),
                Tables\Filters\TernaryFilter::make('has_attachments')
                    ->label('Lampiran')
                    ->placeholder('Semua')
                    ->trueLabel('Dengan lampiran')
                    ->falseLabel('Tanpa lampiran')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('attachments'),
                        false: fn (Builder $query) => $query->whereDoesntHave('attachments'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Lihat Baris')
                        ->icon('heroicon-o-eye')
                        ->slideOver()
                        ->modalHeading(fn (Journal $r) => 'Jurnal '.($r->number ?? '—'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup'),
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
                    Tables\Actions\Action::make('replicate')
                        ->label('Duplikasi sebagai Draft')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (Journal $r) {
                            try {
                                $clone = $r->replicate([
                                    'number',
                                    'status',
                                    'posted_at',
                                    'posted_by',
                                    'reversed_by_journal_id',
                                    'idempotency_key',
                                ]);
                                $clone->status = Journal::STATUS_DRAFT;
                                $clone->date = now()->toDateString();
                                $clone->created_by = auth()->id();
                                $clone->source_app = 'accounting';
                                $clone->source_id = null;
                                $clone->save();

                                foreach ($r->entries as $entry) {
                                    $newEntry = $entry->replicate();
                                    $newEntry->journal_id = $clone->id;
                                    $newEntry->save();
                                }

                                Notification::make()
                                    ->title('Jurnal diduplikasi sebagai draft baru')
                                    ->body($clone->id)
                                    ->success()
                                    ->send();
                            } catch (Throwable $e) {
                                Notification::make()->title('Gagal duplikasi jurnal')->body($e->getMessage())->danger()->send();
                            }
                        }),
                ])
                    ->label('Aksi')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm')
                    ->color('gray')
                    ->button(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('post')
                        ->label('Post Draft Terpilih')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Hanya jurnal berstatus draft yang akan diposting.')
                        ->action(function ($records) {
                            $posted = 0;
                            $failed = 0;
                            foreach ($records as $r) {
                                if ($r->status !== Journal::STATUS_DRAFT) {
                                    continue;
                                }
                                try {
                                    app(PostJournalAction::class)->execute($r, auth()->user());
                                    $posted++;
                                } catch (Throwable) {
                                    $failed++;
                                }
                            }
                            Notification::make()
                                ->title('Bulk post selesai')
                                ->body("Berhasil: {$posted}".($failed ? "  ·  Gagal: {$failed}" : ''))
                                ->{$failed ? 'warning' : 'success'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Hapus Draft Terpilih')
                        ->modalDescription('Hanya jurnal berstatus draft yang dapat dihapus. Lainnya dilewati.')
                        ->before(function ($records, Tables\Actions\DeleteBulkAction $action) {
                            $drafts = $records->filter(fn (Journal $r) => $r->status === Journal::STATUS_DRAFT);
                            if ($drafts->isEmpty()) {
                                Notification::make()->title('Tidak ada draft untuk dihapus')->warning()->send();
                                $action->cancel();
                            }
                        })
                        ->action(function ($records) {
                            $deleted = 0;
                            foreach ($records as $r) {
                                if ($r->status === Journal::STATUS_DRAFT) {
                                    $r->delete();
                                    $deleted++;
                                }
                            }
                            Notification::make()->title("{$deleted} draft dihapus")->success()->send();
                        }),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('Belum ada jurnal')
            ->emptyStateDescription('Mulai catat transaksi pertama untuk entitas ini.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Buat Jurnal Pertama')
                    ->icon('heroicon-o-plus'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('number')
                            ->label('No. Jurnal')
                            ->fontFamily('mono')
                            ->weight('medium')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('date')
                            ->label('Tanggal')
                            ->date('d M Y'),
                        Infolists\Components\TextEntry::make('type')
                            ->label('Jenis')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray')
                            ->formatStateUsing(fn (string $state) => ucfirst($state)),
                        Infolists\Components\TextEntry::make('period.name')
                            ->label('Periode')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('reference')
                            ->label('Referensi')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('memo')
                            ->label('Keterangan')
                            ->columnSpan(2)
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make('Baris Jurnal')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('entries')
                            ->hiddenLabel()
                            ->columns(12)
                            ->schema([
                                Infolists\Components\TextEntry::make('account.code')
                                    ->label('Kode')
                                    ->fontFamily('mono')
                                    ->columnSpan(2),
                                Infolists\Components\TextEntry::make('account.name')
                                    ->label('Akun')
                                    ->columnSpan(4),
                                Infolists\Components\TextEntry::make('memo')
                                    ->label('Catatan')
                                    ->placeholder('—')
                                    ->columnSpan(2),
                                Infolists\Components\TextEntry::make('debit')
                                    ->label('Debit')
                                    ->money('IDR')
                                    ->alignEnd()
                                    ->fontFamily('mono')
                                    ->columnSpan(2),
                                Infolists\Components\TextEntry::make('credit')
                                    ->label('Kredit')
                                    ->money('IDR')
                                    ->alignEnd()
                                    ->fontFamily('mono')
                                    ->columnSpan(2),
                            ]),
                    ]),
                Infolists\Components\Section::make('Audit')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        Infolists\Components\TextEntry::make('createdBy.name')
                            ->label('Dibuat oleh')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('postedBy.name')
                            ->label('Diposting oleh')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('posted_at')
                            ->label('Diposting')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('source_app')
                            ->label('Sumber')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('source_id')
                            ->label('Source ID')
                            ->fontFamily('mono')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AttachmentsRelationManager::class,
        ];
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
