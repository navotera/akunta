<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\TaxCode;
use App\Services\EfakturCsvExporter;
use App\Services\Reporting\TaxReportService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $activeNavigationIcon = 'heroicon-s-receipt-percent';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Laporan Pajak';

    protected static ?string $navigationLabel = 'Laporan Pajak';

    protected static string $view = 'filament.pages.tax-report';

    public ?string $period_start = null;

    public ?string $period_end = null;

    public ?string $kind = TaxCode::KIND_OUTPUT_VAT;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->period_start = now()->startOfMonth()->toDateString();
        $this->period_end   = now()->endOfMonth()->toDateString();
        $this->form->fill([
            'period_start' => $this->period_start,
            'period_end'   => $this->period_end,
            'kind'         => $this->kind,
        ]);
        $this->run();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\DatePicker::make('period_start')->label('Mulai')->required()->native(false),
                Forms\Components\DatePicker::make('period_end')->label('Sampai')->required()->native(false)->after('period_start'),
                Forms\Components\Select::make('kind')
                    ->label('Jenis Pajak')
                    ->options([
                        ''                          => 'Semua',
                        TaxCode::KIND_OUTPUT_VAT    => 'PPN Keluaran',
                        TaxCode::KIND_INPUT_VAT     => 'PPN Masukan',
                        TaxCode::KIND_WHT_PPH_21    => 'PPh 21',
                        TaxCode::KIND_WHT_PPH_23    => 'PPh 23',
                        TaxCode::KIND_WHT_PPH_4_2   => 'PPh 4(2)',
                        TaxCode::KIND_WHT_PPH_26    => 'PPh 26',
                    ])
                    ->native(false),
            ]),
        ];
    }

    public function run(): void
    {
        $state  = $this->form->getState();
        $entity = Filament::getTenant();
        if ($entity === null) {
            $this->report = null;

            return;
        }

        $kind = $state['kind'] ?? null;
        if ($kind === '') {
            $kind = null;
        }

        $this->report = app(TaxReportService::class)->compute(
            $entity->id,
            $state['period_start'] ?? $this->period_start,
            $state['period_end']   ?? $this->period_end,
            $kind,
        );
    }

    public function exportEfaktur(): ?StreamedResponse
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            Notification::make()->title('Pilih entitas dahulu')->warning()->send();

            return null;
        }

        $state = $this->form->getState();
        $payload = app(EfakturCsvExporter::class)->exportOutputVat(
            $entity->id,
            $state['period_start'] ?? $this->period_start,
            $state['period_end']   ?? $this->period_end,
        );

        return response()->streamDownload(
            fn () => print($payload['content']),
            $payload['filename'],
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
