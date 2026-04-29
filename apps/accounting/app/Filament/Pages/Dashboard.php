<?php

namespace App\Filament\Pages;

use App\Filament\Resources\JournalResource;
use App\Models\Period;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Carbon;

class Dashboard extends BaseDashboard
{
    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return 'Dasbor';
    }

    public function getSubheading(): ?string
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return null;
        }

        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if ($period === null) {
            return null;
        }

        $start = Carbon::parse($period->start_date)->translatedFormat('d M Y');
        $end = Carbon::parse($period->end_date)->translatedFormat('d M Y');
        $days = (int) Carbon::today()->diffInDays(Carbon::parse($period->end_date), false);

        $tail = $days < 0
            ? abs($days).' hari telat'
            : $days.' hari sisa';

        return "Periode Aktif {$period->name} · {$start} — {$end} · {$tail}";
    }

    protected function getHeaderActions(): array
    {
        $newAt = fn (string $preset) => JournalResource::getUrl('create', ['preset' => $preset]);

        return [
            ActionGroup::make([
                Action::make('newJournalSales')
                    ->label('Penjualan')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->url(fn () => SalesEntry::getUrl(tenant: Filament::getTenant())),
                Action::make('newJournalPurchase')
                    ->label('Pembelian')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->url(fn () => PurchaseEntry::getUrl(tenant: Filament::getTenant())),
                Action::make('newJournalExpense')
                    ->label('Biaya')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->url(fn () => $newAt('expense')),
            ])
                ->label('New')
                ->icon('heroicon-m-plus')
                ->button()
                ->color('primary'),
        ];
    }
}
