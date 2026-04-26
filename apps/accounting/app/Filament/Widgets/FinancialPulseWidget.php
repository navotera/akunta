<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\Journal;
use App\Services\Reporting\IncomeStatementService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class FinancialPulseWidget extends Widget
{
    protected static ?int $sort = 1;

    protected static string $view = 'filament.widgets.financial-pulse';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return ['empty' => true];
        }

        $svc = app(IncomeStatementService::class);

        $start = Carbon::now()->startOfYear()->toDateString();
        $end   = Carbon::now()->endOfYear()->toDateString();
        $today = Carbon::now()->toDateString();

        $income = $svc->compute($entity->id, $start, $end);

        $revenue = (float) ($income['revenue']['total']  ?? 0);
        $cogs    = (float) ($income['cogs']['total']     ?? 0);
        $expense = (float) ($income['expenses']['total'] ?? 0);
        $net     = (float) ($income['net_income']        ?? 0);

        $cash = (float) $this->cashBalance($entity->id, $today);
        $draftCount = Journal::query()
            ->where('entity_id', $entity->id)
            ->where('status', 'draft')
            ->count();

        // Anatomy bar — revenue split into cogs / expense / net (or loss overlay)
        $denom = max($revenue, 1);
        $cogsPct    = $revenue > 0 ? min(100, $cogs    / $denom * 100) : 0;
        $expensePct = $revenue > 0 ? min(100, $expense / $denom * 100) : 0;
        $profitPct  = $net > 0 && $revenue > 0 ? max(0, min(100, $net / $denom * 100)) : 0;
        $lossPct    = $net < 0 && $revenue > 0
            ? max(0, min(100 - $cogsPct - $expensePct, abs($net) / $denom * 100))
            : 0;

        // 6-month net income trend
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m    = Carbon::now()->startOfMonth()->subMonths($i);
            $mEnd = (clone $m)->endOfMonth();
            $r    = $svc->compute($entity->id, $m->toDateString(), $mEnd->toDateString());
            $months[] = [
                'label' => $m->isoFormat('MMM'),
                'net'   => (float) ($r['net_income'] ?? 0),
            ];
        }

        $netVals  = array_column($months, 'net');
        $maxAbs   = max(array_map('abs', $netVals)) ?: 1;
        $lastNet  = end($netVals) ?: 0;
        $prevNet  = $netVals[count($netVals) - 2] ?? 0;
        $delta    = $lastNet - $prevNet;
        $deltaPct = $prevNet != 0 ? ($delta / abs($prevNet)) * 100 : 0;

        // Runway = cash / avg monthly burn YTD (cogs + expense / months elapsed)
        $monthsElapsed = max(1, (int) Carbon::now()->month);
        $monthlyBurn   = ($cogs + $expense) / $monthsElapsed;
        $runway        = $monthlyBurn > 0 ? $cash / $monthlyBurn : null;

        return [
            'empty'      => false,
            'revenue'    => $revenue,
            'cogs'       => $cogs,
            'expense'    => $expense,
            'net'        => $net,
            'cogsPct'    => $cogsPct,
            'expensePct' => $expensePct,
            'profitPct'  => $profitPct,
            'lossPct'    => $lossPct,
            'cash'       => $cash,
            'runway'     => $runway,
            'draftCount' => $draftCount,
            'months'     => $months,
            'maxAbs'     => $maxAbs,
            'delta'      => $delta,
            'deltaPct'   => $deltaPct,
            'lastNet'    => $lastNet,
            'prevNet'    => $prevNet,
            'hasRevenue' => $revenue > 0,
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
}
