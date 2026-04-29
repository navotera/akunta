<?php

namespace App\Filament\Pages;

use App\Services\Reporting\ComparativeReportService;
use App\Services\Reporting\Export\XlsxExporter;
use App\Services\Reporting\IncomeStatementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class IncomeStatement extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $activeNavigationIcon = 'heroicon-s-chart-bar';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static string $view = 'filament.pages.income-statement';

    public ?string $period_start = null;

    public ?string $period_end = null;

    public bool $compare = false;

    public string $compare_mode = 'prior_period'; // prior_period | prior_year | custom

    public ?string $compare_start = null;

    public ?string $compare_end = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** @var array<string, mixed>|null */
    public ?array $comparative = null;

    public function mount(): void
    {
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end = now()->endOfMonth()->toDateString();

        $cmp = app(ComparativeReportService::class)->priorPeriod($this->period_start, $this->period_end);
        $this->compare_start = $cmp['start'];
        $this->compare_end = $cmp['end'];

        $this->form->fill([
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'compare' => false,
            'compare_mode' => $this->compare_mode,
            'compare_start' => $this->compare_start,
            'compare_end' => $this->compare_end,
        ]);
        $this->run();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\DatePicker::make('period_start')->label('Mulai')->required()->native(false)->displayFormat('d M Y')
                    ->columnSpan(['default' => 12, 'md' => 4]),
                Forms\Components\DatePicker::make('period_end')->label('Akhir')->required()->native(false)->displayFormat('d M Y')->afterOrEqual('period_start')
                    ->columnSpan(['default' => 12, 'md' => 4]),
                Forms\Components\Toggle::make('compare')->label('Bandingkan')->live()
                    ->columnSpan(['default' => 12, 'md' => 4]),

                Forms\Components\Select::make('compare_mode')
                    ->label('Mode Pembanding')
                    ->options([
                        'prior_period' => 'Periode Sebelumnya',
                        'prior_year' => 'Tahun Lalu (Periode Sama)',
                        'custom' => 'Kustom',
                    ])
                    ->default('prior_period')
                    ->live()
                    ->native(false)
                    ->visible(fn (Forms\Get $get) => (bool) $get('compare'))
                    ->columnSpan(['default' => 12, 'md' => 4]),

                Forms\Components\DatePicker::make('compare_start')->label('Pembanding Mulai')->native(false)->displayFormat('d M Y')
                    ->visible(fn (Forms\Get $get) => $get('compare') && $get('compare_mode') === 'custom')
                    ->columnSpan(['default' => 12, 'md' => 4]),
                Forms\Components\DatePicker::make('compare_end')->label('Pembanding Akhir')->native(false)->displayFormat('d M Y')
                    ->visible(fn (Forms\Get $get) => $get('compare') && $get('compare_mode') === 'custom')
                    ->columnSpan(['default' => 12, 'md' => 4]),
            ]),
        ];
    }

    public function run(): void
    {
        $state = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null) {
            $this->report = null;
            $this->comparative = null;

            return;
        }

        $start = $state['period_start'] ?? $this->period_start;
        $end = $state['period_end'] ?? $this->period_end;
        $this->period_start = $start;
        $this->period_end = $end;
        $this->compare = (bool) ($state['compare'] ?? false);
        $this->compare_mode = $state['compare_mode'] ?? 'prior_period';

        $this->report = app(IncomeStatementService::class)->compute($entity->id, $start, $end);

        if (! $this->compare) {
            $this->comparative = null;

            return;
        }

        $svc = app(ComparativeReportService::class);
        if ($this->compare_mode === 'prior_year') {
            $cmp = $svc->priorYear($start, $end);
        } elseif ($this->compare_mode === 'custom' && ! empty($state['compare_start']) && ! empty($state['compare_end'])) {
            $cmp = ['start' => $state['compare_start'], 'end' => $state['compare_end']];
        } else {
            $cmp = $svc->priorPeriod($start, $end);
        }

        $this->compare_start = $cmp['start'];
        $this->compare_end = $cmp['end'];

        $this->comparative = $svc->incomeStatement(
            $entity->id,
            $start,
            $end,
            $cmp['start'],
            $cmp['end'],
        );
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

                    return app(XlsxExporter::class)->exportIncomeStatement(
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
