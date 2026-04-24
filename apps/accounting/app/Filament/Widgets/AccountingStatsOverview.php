<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\Journal;
use App\Services\Reporting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AccountingStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return [];
        }

        $start = Carbon::now()->startOfYear()->toDateString();
        $end = Carbon::now()->endOfYear()->toDateString();
        $today = Carbon::now()->toDateString();

        $income = app(IncomeStatementService::class)->compute($entity->id, $start, $end);

        $revenueTotal = $income['revenue']['total'] ?? '0';
        $expenseTotal = bcadd((string) ($income['cogs']['total'] ?? '0'), (string) ($income['expenses']['total'] ?? '0'), 2);
        $netIncome = (string) ($income['net_income'] ?? '0');

        $cashBalance = $this->cashBalance($entity->id, $today);
        $draftCount = Journal::query()
            ->where('entity_id', $entity->id)
            ->where('status', 'draft')
            ->count();

        return [
            Stat::make('Pendapatan YTD', $this->fmt($revenueTotal))
                ->description('Tahun berjalan')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up'),
            Stat::make('Beban YTD', $this->fmt($expenseTotal))
                ->description('Tahun berjalan')
                ->color('warning')
                ->icon('heroicon-o-arrow-trending-down'),
            Stat::make('Laba/Rugi YTD', $this->fmt($netIncome))
                ->description(bccomp($netIncome, '0', 2) >= 0 ? 'Laba' : 'Rugi')
                ->color(bccomp($netIncome, '0', 2) >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-banknotes'),
            Stat::make('Saldo Kas', $this->fmt($cashBalance))
                ->description('Akun kas + setara kas')
                ->color('info')
                ->icon('heroicon-o-wallet'),
            Stat::make('Jurnal Draft', (string) $draftCount)
                ->description('Menunggu posting')
                ->color($draftCount > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-document-text'),
        ];
    }

    private function cashBalance(string $entityId, string $asOf): string
    {
        $cashAccountIds = Account::query()
            ->where('entity_id', $entityId)
            ->where('type', 'asset')
            ->where(function ($q) {
                $q->where('code', 'like', '11%')
                    ->orWhere('name', 'like', '%kas%')
                    ->orWhere('name', 'like', '%cash%')
                    ->orWhere('name', 'like', '%bank%');
            })
            ->pluck('id');

        if ($cashAccountIds->isEmpty()) {
            return '0';
        }

        $row = \DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->whereIn('journal_entries.account_id', $cashAccountIds)
            ->where('journals.status', 'posted')
            ->where('journals.date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(journal_entries.debit), 0) AS d, COALESCE(SUM(journal_entries.credit), 0) AS c')
            ->first();

        return bcsub((string) ($row->d ?? '0'), (string) ($row->c ?? '0'), 2);
    }

    private function fmt(string $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
