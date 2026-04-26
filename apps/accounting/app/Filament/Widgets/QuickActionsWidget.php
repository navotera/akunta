<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\BalanceSheet;
use App\Filament\Pages\IncomeStatement;
use App\Filament\Pages\Subsidiary\PayableSubLedger;
use App\Filament\Pages\Subsidiary\ReceivableSubLedger;
use App\Filament\Pages\TrialBalance;
use App\Filament\Resources\JournalResource;
use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PeriodResource;
use Filament\Widgets\Widget;

class QuickActionsWidget extends Widget
{
    protected static ?int $sort = 4;

    protected static string $view = 'filament.widgets.quick-actions';

    protected int|string|array $columnSpan = 'full';

    /** @return array<int, array{label: string, url: string, icon: string, color: string}> */
    public function getViewData(): array
    {
        return [
            'actions' => [
                ['label' => 'Lihat Jurnal',    'url' => JournalResource::getUrl(),         'icon' => 'heroicon-o-document-text',   'color' => 'gray'],
                ['label' => 'Mitra',           'url' => PartnerResource::getUrl(),         'icon' => 'heroicon-o-identification',  'color' => 'gray'],
                ['label' => 'Periode',         'url' => PeriodResource::getUrl(),          'icon' => 'heroicon-o-calendar',        'color' => 'gray'],
                ['label' => 'Neraca Saldo',    'url' => TrialBalance::getUrl(),            'icon' => 'heroicon-o-calculator',      'color' => 'info'],
                ['label' => 'Neraca',          'url' => BalanceSheet::getUrl(),            'icon' => 'heroicon-o-scale',           'color' => 'info'],
                ['label' => 'Laba Rugi',       'url' => IncomeStatement::getUrl(),         'icon' => 'heroicon-o-chart-bar',       'color' => 'info'],
                ['label' => 'BB Piutang',      'url' => ReceivableSubLedger::getUrl(),     'icon' => 'heroicon-o-banknotes',       'color' => 'info'],
                ['label' => 'BB Hutang',       'url' => PayableSubLedger::getUrl(),        'icon' => 'heroicon-o-credit-card',     'color' => 'info'],
            ],
        ];
    }
}
