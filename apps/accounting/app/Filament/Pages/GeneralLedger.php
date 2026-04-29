<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\Partner;
use App\Services\Reporting\Export\XlsxExporter;
use App\Services\Reporting\GeneralLedgerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class GeneralLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $activeNavigationIcon = 'heroicon-s-book-open';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Buku Besar';

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static string $view = 'filament.pages.general-ledger';

    public ?string $account_id = null;

    public ?string $period_start = null;

    public ?string $period_end = null;

    public ?string $partner_id = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end = now()->endOfMonth()->toDateString();
        $this->account_id = request()->query('account') ?: null;

        if (request()->query('from')) {
            $this->period_start = (string) request()->query('from');
        }
        if (request()->query('to')) {
            $this->period_end = (string) request()->query('to');
        }

        $this->form->fill([
            'account_id' => $this->account_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'partner_id' => $this->partner_id,
        ]);

        if ($this->account_id) {
            $this->run();
        }
    }

    protected function getFormSchema(): array
    {
        $entity = Filament::getTenant();
        $entityId = $entity?->id;

        return [
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\Select::make('account_id')
                    ->label('Akun')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->options(function () use ($entityId) {
                        if (! $entityId) {
                            return [];
                        }

                        return Account::query()
                            ->where('entity_id', $entityId)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all();
                    })
                    ->columnSpan(['default' => 12, 'md' => 5]),

                Forms\Components\DatePicker::make('period_start')
                    ->label('Mulai')
                    ->required()
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->columnSpan(['default' => 6, 'md' => 3]),

                Forms\Components\DatePicker::make('period_end')
                    ->label('Akhir')
                    ->required()
                    ->afterOrEqual('period_start')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->columnSpan(['default' => 6, 'md' => 3]),

                Forms\Components\Select::make('partner_id')
                    ->label('Mitra (opsional)')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->options(function () use ($entityId) {
                        if (! $entityId || ! class_exists(Partner::class)) {
                            return [];
                        }

                        return Partner::query()
                            ->where('entity_id', $entityId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->columnSpan(['default' => 12, 'md' => 4]),
            ]),
        ];
    }

    public function run(): void
    {
        $state = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null || empty($state['account_id'])) {
            $this->report = null;

            return;
        }

        $this->report = app(GeneralLedgerService::class)->compute(
            $entity->id,
            $state['account_id'],
            $state['period_start'] ?? $this->period_start,
            $state['period_end'] ?? $this->period_end,
            ['partner_id' => $state['partner_id'] ?? null],
        );

        $this->account_id = $state['account_id'];
        $this->period_start = $state['period_start'] ?? $this->period_start;
        $this->period_end = $state['period_end'] ?? $this->period_end;
        $this->partner_id = $state['partner_id'] ?? null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_xlsx')
                ->label('Export XLSX')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->report !== null)
                ->action(function () {
                    $entity = Filament::getTenant();

                    return app(XlsxExporter::class)->exportGeneralLedger(
                        $this->report,
                        $entity?->name ?? 'Entity',
                    );
                }),
            Action::make('print')
                ->label('Print / PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn () => $this->report !== null)
                ->extraAttributes(['onclick' => 'window.print(); return false;', 'type' => 'button']),
        ];
    }
}
