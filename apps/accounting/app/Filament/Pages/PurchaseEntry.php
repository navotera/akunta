<?php

namespace App\Filament\Pages;

use App\Actions\RecordPurchaseAction;
use App\Filament\Resources\JournalResource;
use App\Models\Account;
use App\Models\Partner;
use App\Models\TaxCode;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\RawJs;
use Throwable;

class PurchaseEntry extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.purchase-entry';

    protected static ?string $title = 'Pembelian Baru';

    protected static ?string $slug = 'pembelian/baru';

    public ?array $data = [];

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenLarge;
    }

    public function getSubheading(): ?string
    {
        return 'Catat transaksi pembelian secara cepat. Jurnal double-entry tercatat otomatis.';
    }

    public function mount(): void
    {
        $entity = Filament::getTenant();
        $entityId = $entity?->id;

        $defaultTaxCode = $entityId
            ? TaxCode::query()
                ->where('entity_id', $entityId)
                ->where('kind', TaxCode::KIND_INPUT_VAT)
                ->where('is_active', true)
                ->orderBy('code')
                ->first()
            : null;

        $defaultPayment = $entityId
            ? Account::query()
                ->where('entity_id', $entityId)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->where('type', 'asset')
                ->where(function ($q) {
                    $q->where('name', 'like', 'Kas%')->orWhere('name', 'like', 'Bank%');
                })
                ->orderBy('code')
                ->value('id')
            : null;

        $defaultPurchase = $entityId
            ? Account::query()
                ->where('entity_id', $entityId)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->whereIn('type', ['expense', 'asset'])
                ->where(function ($q) {
                    $q->where('name', 'like', 'Persediaan%')
                        ->orWhere('name', 'like', 'Beban%')
                        ->orWhere('name', 'like', 'Pembelian%');
                })
                ->orderBy('code')
                ->value('id')
            : null;

        $this->form->fill([
            'date' => now()->toDateString(),
            'purchase_account_id' => $defaultPurchase,
            'payment_account_id' => $defaultPayment,
            'tax_code_id' => $defaultTaxCode?->id,
            'with_tax' => $defaultTaxCode !== null,
            'subtotal' => 0,
            'attachments' => [],
        ]);
    }

    protected function getFormSchema(): array
    {
        $entity = Filament::getTenant();
        $entityId = $entity?->id;
        $moneyMask = RawJs::make("\$money(\$input, ',', '.', 0)");

        return [
            Forms\Components\Tabs::make('PurchaseEntryTabs')
                ->extraAttributes(['class' => 'ak-vtabs'])
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Transaksi Info')
                        ->icon('heroicon-o-document-text')
                        ->columns(12)
                        ->schema([
                            Forms\Components\DatePicker::make('date')
                                ->label('Tanggal')
                                ->required()
                                ->native(false)
                                ->displayFormat('d M Y')
                                ->columnSpan(['default' => 12, 'md' => 4]),

                            Forms\Components\Select::make('partner_id')
                                ->label('Vendor / Pemasok')
                                ->placeholder('Tunai (tanpa vendor)')
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->options(fn () => $entityId ? Partner::query()
                                    ->where('entity_id', $entityId)
                                    ->where('type', Partner::TYPE_VENDOR)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all() : [])
                                ->columnSpan(['default' => 12, 'md' => 5]),

                            Forms\Components\TextInput::make('reference')
                                ->label('No. Faktur')
                                ->maxLength(120)
                                ->placeholder('opsional')
                                ->columnSpan(['default' => 12, 'md' => 3]),

                            Forms\Components\Textarea::make('memo')
                                ->label('Memo')
                                ->placeholder('Deskripsi singkat transaksi (opsional).')
                                ->rows(2)
                                ->columnSpan(12),
                        ]),

                    Forms\Components\Tabs\Tab::make('Detail')
                        ->icon('heroicon-o-banknotes')
                        ->columns(12)
                        ->schema([
                            Forms\Components\Select::make('purchase_account_id')
                                ->label('Akun Pembelian')
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->options(fn () => $entityId ? Account::query()
                                    ->where('entity_id', $entityId)
                                    ->where('is_postable', true)
                                    ->where('is_active', true)
                                    ->whereIn('type', ['expense', 'asset'])
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                                    ->all() : [])
                                ->helperText('Persediaan, Beban, atau Aset (di-debit).')
                                ->columnSpan(['default' => 12, 'md' => 6]),

                            Forms\Components\Select::make('payment_account_id')
                                ->label('Dibayar Dari / Hutang')
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->options(fn () => $entityId ? Account::query()
                                    ->where('entity_id', $entityId)
                                    ->where('is_postable', true)
                                    ->where('is_active', true)
                                    ->whereIn('type', ['asset', 'liability'])
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                                    ->all() : [])
                                ->helperText('Kas/Bank (cash) atau Hutang Usaha (kredit).')
                                ->columnSpan(['default' => 12, 'md' => 6]),

                            Forms\Components\TextInput::make('subtotal')
                                ->label('Subtotal (sebelum PPN)')
                                ->prefix('Rp')
                                ->required()
                                ->inputMode('decimal')
                                ->mask($moneyMask)
                                ->stripCharacters('.')
                                ->numeric()
                                ->minValue(1)
                                ->live(debounce: 400)
                                ->extraInputAttributes(['class' => 'text-right tabular-nums font-mono'])
                                ->columnSpan(['default' => 12, 'md' => 6]),

                            Forms\Components\Toggle::make('with_tax')
                                ->label('Kena PPN Masukan')
                                ->live()
                                ->columnSpan(['default' => 6, 'md' => 3]),

                            Forms\Components\Select::make('tax_code_id')
                                ->label('Kode Pajak')
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->options(fn () => $entityId ? TaxCode::query()
                                    ->where('entity_id', $entityId)
                                    ->where('kind', TaxCode::KIND_INPUT_VAT)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (TaxCode $t) => [$t->id => "{$t->code} ({$t->rate}%)"])
                                    ->all() : [])
                                ->visible(fn (Get $get) => (bool) $get('with_tax'))
                                ->live()
                                ->columnSpan(['default' => 12, 'md' => 3]),

                            Forms\Components\Placeholder::make('preview')
                                ->hiddenLabel()
                                ->columnSpan(12)
                                ->content(fn (Get $get) => SalesEntry::previewBlock($get, $entityId, TaxCode::KIND_INPUT_VAT, 'PPN Masukan')),
                        ]),

                    Forms\Components\Tabs\Tab::make('Lampiran')
                        ->icon('heroicon-o-paper-clip')
                        ->schema([
                            Forms\Components\FileUpload::make('attachments')
                                ->hiddenLabel()
                                ->multiple()
                                ->disk(config('filesystems.default'))
                                ->directory(fn () => 'attachments/'.($entityId ?? 'unknown'))
                                ->visibility('private')
                                ->preserveFilenames()
                                ->maxSize(10 * 1024)
                                ->reorderable()
                                ->openable()
                                ->downloadable()
                                ->helperText('Faktur pajak, surat jalan, kuitansi (maks 10 MB per file).'),
                        ]),
                ]),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null) {
            Notification::make()->title('Pilih entity dulu.')->danger()->send();

            return;
        }

        try {
            $journal = app(RecordPurchaseAction::class)->execute([
                'entity_id' => $entity->id,
                'date' => $state['date'],
                'purchase_account_id' => $state['purchase_account_id'],
                'payment_account_id' => $state['payment_account_id'],
                'subtotal' => $state['subtotal'],
                'tax_code_id' => ! empty($state['with_tax']) ? ($state['tax_code_id'] ?? null) : null,
                'partner_id' => $state['partner_id'] ?? null,
                'reference' => $state['reference'] ?? null,
                'memo' => $state['memo'] ?? null,
                'created_by' => auth()->id(),
                'attachments' => array_values($state['attachments'] ?? []),
            ]);

            Notification::make()
                ->title('Pembelian tercatat')
                ->body('Jurnal '.$journal->number.' (draft) berhasil dibuat. Lanjut review & post.')
                ->success()
                ->send();

            $this->redirect(JournalResource::getUrl('edit', [
                'record' => $journal->id,
                'tenant' => $entity,
            ]));
        } catch (Throwable $e) {
            Notification::make()->title('Gagal mencatat pembelian')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
