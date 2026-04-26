<?php

declare(strict_types=1);

namespace App\Filament\Pages\Subsidiary;

use App\Services\Reporting\AgingService;
use App\Services\Reporting\SubLedgerService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class ReceivableSubLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $activeNavigationIcon = 'heroicon-s-banknotes';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Buku Pembantu Piutang';

    protected static ?string $navigationLabel = 'Buku Pembantu Piutang';

    protected static string $view = 'filament.pages.receivable-sub-ledger';

    public ?string $as_of = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** @var array<string, mixed>|null */
    public ?array $aging = null;

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
        $state  = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null) {
            $this->report = null;
            $this->aging  = null;

            return;
        }

        $asOf = $state['as_of'] ?? $this->as_of;
        $this->report = app(SubLedgerService::class)->arSubLedger($entity->id, $asOf);
        $this->aging  = app(AgingService::class)->arAging($entity->id, $asOf);
    }
}
