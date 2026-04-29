<?php

namespace App\Filament\Pages;

use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\ComparativeReportService;
use App\Services\Reporting\Export\XlsxExporter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class BalanceSheet extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $activeNavigationIcon = 'heroicon-s-scale';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca';

    protected static string $view = 'filament.pages.balance-sheet';

    public ?string $as_of = null;

    public ?string $compare_as_of = null;

    public bool $compare = false;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** @var array<string, mixed>|null */
    public ?array $comparative = null;

    public function mount(): void
    {
        $this->as_of = now()->toDateString();
        $this->compare_as_of = now()->subYear()->toDateString();
        $this->form->fill([
            'as_of' => $this->as_of,
            'compare' => false,
            'compare_as_of' => $this->compare_as_of,
        ]);
        $this->run();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\DatePicker::make('as_of')
                    ->label('Per Tanggal')
                    ->required()
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->columnSpan(['default' => 12, 'md' => 4]),
                Forms\Components\Toggle::make('compare')
                    ->label('Bandingkan')
                    ->live()
                    ->columnSpan(['default' => 12, 'md' => 3]),
                Forms\Components\DatePicker::make('compare_as_of')
                    ->label('Bandingkan Per')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->visible(fn (Forms\Get $get) => (bool) $get('compare'))
                    ->columnSpan(['default' => 12, 'md' => 5]),
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

        $asOf = $state['as_of'] ?? $this->as_of;
        $this->as_of = $asOf;
        $this->compare = (bool) ($state['compare'] ?? false);

        $this->report = app(BalanceSheetService::class)->compute($entity->id, $asOf);

        if ($this->compare && ! empty($state['compare_as_of'])) {
            $this->compare_as_of = $state['compare_as_of'];
            $this->comparative = app(ComparativeReportService::class)->balanceSheet(
                $entity->id,
                $asOf,
                $state['compare_as_of'],
            );
        } else {
            $this->comparative = null;
        }
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

                    return app(XlsxExporter::class)->exportBalanceSheet(
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
