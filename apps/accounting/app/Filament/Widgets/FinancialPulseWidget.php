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

        // 6-month trend (net income per month, oldest first)
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m   = Carbon::now()->startOfMonth()->subMonths($i);
            $mEnd = (clone $m)->endOfMonth();
            $r   = $svc->compute($entity->id, $m->toDateString(), $mEnd->toDateString());
            $months[] = [
                'label' => $m->isoFormat('MMM'),
                'net'   => (float) ($r['net_income'] ?? 0),
            ];
        }

        // Donut segments — share of P&L composition
        $totalAct = max($revenue + $cogs + $expense, 1);
        $segments = [
            ['label' => 'Pendapatan',  'value' => $revenue, 'color' => '#17C653', 'pct' => $revenue / $totalAct * 100],
            ['label' => 'HPP',         'value' => $cogs,    'color' => '#F6C000', 'pct' => $cogs / $totalAct * 100],
            ['label' => 'Beban',       'value' => $expense, 'color' => '#F8285A', 'pct' => $expense / $totalAct * 100],
        ];

        // Spark path — normalize to 0..1
        $netVals = array_column($months, 'net');
        $maxAbs = max(array_map('abs', $netVals)) ?: 1;
        $points = [];
        $w = 100;
        $h = 30;
        $pad = 2;
        $count = count($netVals);
        foreach ($netVals as $i => $v) {
            $x = $pad + ($i / max($count - 1, 1)) * ($w - 2 * $pad);
            $y = ($h / 2) - ($v / $maxAbs) * ($h / 2 - $pad);
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $sparkPath = 'M ' . implode(' L ', $points);
        $areaPath  = 'M ' . $points[0] . ' L ' . implode(' L ', array_slice($points, 1)) . " L {$w},{$h} L 0,{$h} Z";

        return [
            'empty' => false,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'expense' => $expense,
            'net' => $net,
            'cash' => $cash,
            'draftCount' => $draftCount,
            'segments' => $segments,
            'months' => $months,
            'sparkPath' => $sparkPath,
            'areaPath' => $areaPath,
            'sparkW' => $w,
            'sparkH' => $h,
            'lastNet' => end($netVals) ?: 0,
            'prevNet' => $netVals[count($netVals) - 2] ?? 0,
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
