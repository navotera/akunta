<?php

namespace App\Filament\Pages;

use App\Actions\RecordSalesAction;
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
use Illuminate\Support\HtmlString;
use Throwable;

class SalesEntry extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.sales-entry';

    protected static ?string $title = 'Penjualan Baru';

    protected static ?string $slug = 'penjualan/baru';

    public ?array $data = [];

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenLarge;
    }

    public function getSubheading(): ?string
    {
        return 'Catat transaksi penjualan secara cepat. Jurnal double-entry tercatat otomatis.';
    }

    public function mount(): void
    {
        $entity = Filament::getTenant();
        $entityId = $entity?->id;

        $defaultTaxCode = $entityId
            ? TaxCode::query()
                ->where('entity_id', $entityId)
                ->where('kind', TaxCode::KIND_OUTPUT_VAT)
                ->where('is_active', true)
                ->orderBy('code')
                ->first()
            : null;

        $defaultTarget = $entityId
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

        $defaultRevenue = $entityId
            ? Account::query()
                ->where('entity_id', $entityId)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->where('type', 'revenue')
                ->orderBy('code')
                ->value('id')
            : null;

        $this->form->fill([
            'date' => now()->toDateString(),
            'target_account_id' => $defaultTarget,
            'revenue_account_id' => $defaultRevenue,
            'tax_code_id' => $defaultTaxCode?->id,
            'with_tax' => $defaultTaxCode !== null,
            'subtotal' => 0,
        ]);
    }

    protected function getFormSchema(): array
    {
        $entity = Filament::getTenant();
        $entityId = $entity?->id;
        $moneyMask = RawJs::make("\$money(\$input, ',', '.', 0)");

        return [
            Forms\Components\Section::make('Transaksi')
                ->columns(12)
                ->schema([
                    Forms\Components\DatePicker::make('date')
                        ->label('Tanggal')
                        ->required()
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->columnSpan(['default' => 12, 'md' => 4]),

                    Forms\Components\Select::make('partner_id')
                        ->label('Pelanggan')
                        ->placeholder('Tunai (tanpa pelanggan)')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn () => $entityId ? Partner::query()
                            ->where('entity_id', $entityId)
                            ->where('type', Partner::TYPE_CUSTOMER)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all() : [])
                        ->columnSpan(['default' => 12, 'md' => 5]),

                    Forms\Components\TextInput::make('reference')
                        ->label('No. Invoice')
                        ->maxLength(120)
                        ->placeholder('opsional')
                        ->columnSpan(['default' => 12, 'md' => 3]),
                ]),

            Forms\Components\Section::make('Akun')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('target_account_id')
                        ->label('Diterima Di')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn () => $entityId ? Account::query()
                            ->where('entity_id', $entityId)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->where('type', 'asset')
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all() : [])
                        ->helperText('Kas, Bank, atau Piutang Usaha.')
                        ->columnSpan(['default' => 12, 'md' => 6]),

                    Forms\Components\Select::make('revenue_account_id')
                        ->label('Akun Pendapatan')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn () => $entityId ? Account::query()
                            ->where('entity_id', $entityId)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->where('type', 'revenue')
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all() : [])
                        ->columnSpan(['default' => 12, 'md' => 6]),
                ]),

            Forms\Components\Section::make('Nilai')
                ->columns(12)
                ->schema([
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
                        ->label('Kena PPN Keluaran')
                        ->live()
                        ->columnSpan(['default' => 6, 'md' => 3]),

                    Forms\Components\Select::make('tax_code_id')
                        ->label('Kode Pajak')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->options(fn () => $entityId ? TaxCode::query()
                            ->where('entity_id', $entityId)
                            ->where('kind', TaxCode::KIND_OUTPUT_VAT)
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
                        ->columnSpanFull()
                        ->content(function (Get $get) use ($entityId) {
                            $sub = (float) ($get('subtotal') ?? 0);
                            $taxAmt = 0.0;
                            $taxLabel = null;
                            if ($get('with_tax') && $get('tax_code_id') && $entityId) {
                                $tc = TaxCode::query()->where('entity_id', $entityId)->find($get('tax_code_id'));
                                if ($tc) {
                                    $taxAmt = (float) $tc->computeOn((string) $sub);
                                    $taxLabel = "{$tc->code} · {$tc->rate}%";
                                }
                            }
                            $total = $sub + $taxAmt;
                            $fmt = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');

                            return new HtmlString(
                                '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;padding:0.75rem 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">'
                                .'<div><div style="font-size:0.7rem;letter-spacing:0.18em;text-transform:uppercase;color:#6B7280;">Subtotal</div>'
                                .'<div style="font-family:\'JetBrains Mono\',monospace;font-variant-numeric:tabular-nums;font-size:1rem;margin-top:0.2rem;">'.$fmt($sub).'</div></div>'
                                .'<div><div style="font-size:0.7rem;letter-spacing:0.18em;text-transform:uppercase;color:#6B7280;">PPN'
                                .($taxLabel ? ' <span style="font-weight:400;color:#9CA3AF;">· '.e($taxLabel).'</span>' : '')
                                .'</div>'
                                .'<div style="font-family:\'JetBrains Mono\',monospace;font-variant-numeric:tabular-nums;font-size:1rem;margin-top:0.2rem;">'.$fmt($taxAmt).'</div></div>'
                                .'<div><div style="font-size:0.7rem;letter-spacing:0.18em;text-transform:uppercase;color:#0D3B2E;font-weight:600;">Total</div>'
                                .'<div style="font-family:\'JetBrains Mono\',monospace;font-variant-numeric:tabular-nums;font-size:1.15rem;margin-top:0.2rem;font-weight:600;">'.$fmt($total).'</div></div>'
                                .'</div>'
                            );
                        }),
                ]),

            Forms\Components\Section::make('Catatan')
                ->collapsed()
                ->schema([
                    Forms\Components\Textarea::make('memo')
                        ->label('Memo')
                        ->placeholder('Deskripsi singkat transaksi (opsional).')
                        ->rows(2),
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
            $journal = app(RecordSalesAction::class)->execute([
                'entity_id' => $entity->id,
                'date' => $state['date'],
                'target_account_id' => $state['target_account_id'],
                'revenue_account_id' => $state['revenue_account_id'],
                'subtotal' => $state['subtotal'],
                'tax_code_id' => ! empty($state['with_tax']) ? ($state['tax_code_id'] ?? null) : null,
                'partner_id' => $state['partner_id'] ?? null,
                'reference' => $state['reference'] ?? null,
                'memo' => $state['memo'] ?? null,
                'created_by' => auth()->id(),
            ]);

            Notification::make()
                ->title('Penjualan tercatat')
                ->body('Jurnal '.$journal->number.' (draft) berhasil dibuat. Lanjut review & post.')
                ->success()
                ->send();

            $this->redirect(JournalResource::getUrl('edit', [
                'record' => $journal->id,
                'tenant' => $entity,
            ]));
        } catch (Throwable $e) {
            Notification::make()->title('Gagal mencatat penjualan')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
