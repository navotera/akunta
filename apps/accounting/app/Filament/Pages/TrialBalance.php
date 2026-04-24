<?php

namespace App\Filament\Pages;

use App\Services\Reporting\TrialBalanceService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class TrialBalance extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $activeNavigationIcon = 'heroicon-s-calculator';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Neraca Saldo';

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static string $view = 'filament.pages.trial-balance';

    public ?string $as_of = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->as_of = now()->toDateString();
        $this->form->fill(['as_of' => $this->as_of]);
        $this->run();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('as_of')
                ->label('Per Tanggal')
                ->required()
                ->native(false),
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

        $this->report = app(TrialBalanceService::class)->compute($entity->id, $state['as_of'] ?? $this->as_of);
    }
}
