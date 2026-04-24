<?php

namespace App\Filament\Pages;

use App\Services\Reporting\IncomeStatementService;
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

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end = now()->endOfMonth()->toDateString();
        $this->form->fill(['period_start' => $this->period_start, 'period_end' => $this->period_end]);
        $this->run();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('period_start')->label('Mulai')->required()->native(false),
            Forms\Components\DatePicker::make('period_end')->label('Akhir')->required()->native(false)->afterOrEqual('period_start'),
        ];
    }

    public function run(): void
    {
        $state = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null) {
            $this->report = null;

            return;
        }

        $this->report = app(IncomeStatementService::class)->compute(
            $entity->id,
            $state['period_start'] ?? $this->period_start,
            $state['period_end'] ?? $this->period_end,
        );
    }
}
