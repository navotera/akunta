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

class PayableSubLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $activeNavigationIcon = 'heroicon-s-credit-card';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Buku Pembantu Hutang';

    protected static ?string $navigationLabel = 'Buku Pembantu Hutang';

    protected static string $view = 'filament.pages.payable-sub-ledger';

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
        $this->report = app(SubLedgerService::class)->apSubLedger($entity->id, $asOf);
        $this->aging  = app(AgingService::class)->apAging($entity->id, $asOf);
    }
}
