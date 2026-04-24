<?php

namespace App\Filament\Pages;

use App\Services\Reporting\BalanceSheetService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class BalanceSheet extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $title = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca';

    protected static string $view = 'filament.pages.balance-sheet';

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

        $this->report = app(BalanceSheetService::class)->compute($entity->id, $state['as_of'] ?? $this->as_of);
    }
}
