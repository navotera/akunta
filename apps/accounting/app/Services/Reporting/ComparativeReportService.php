<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;

/**
 * Comparative wrapper — runs a base report twice over different period ranges
 * and aligns rows for delta/percentage display.
 *
 * Usage:
 *   $cmp = app(ComparativeReportService::class);
 *   $cmp->incomeStatement($entityId, $currStart, $currEnd, $prevStart, $prevEnd);
 *   $cmp->balanceSheet($entityId, $currAsOf, $prevAsOf);
 */
class ComparativeReportService
{
    public function __construct(
        private readonly IncomeStatementService $is,
        private readonly BalanceSheetService $bs,
    ) {}

    /** @return array<string, mixed> */
    public function incomeStatement(
        string $entityId,
        string $currStart,
        string $currEnd,
        string $prevStart,
        string $prevEnd,
    ): array {
        $curr = $this->is->compute($entityId, $currStart, $currEnd);
        $prev = $this->is->compute($entityId, $prevStart, $prevEnd);

        return [
            'kind'          => 'income_statement',
            'entity_id'     => $entityId,
            'curr_period'   => ['start' => $currStart, 'end' => $currEnd],
            'prev_period'   => ['start' => $prevStart, 'end' => $prevEnd],
            'sections'      => [
                'revenue'  => $this->mergeSections($curr['revenue'], $prev['revenue']),
                'cogs'     => $this->mergeSections($curr['cogs'], $prev['cogs']),
                'expenses' => $this->mergeSections($curr['expenses'], $prev['expenses']),
            ],
            'gross_profit_curr' => $curr['gross_profit'] ?? '0.00',
            'gross_profit_prev' => $prev['gross_profit'] ?? '0.00',
            'gross_profit_delta'=> bcsub((string) ($curr['gross_profit'] ?? '0'), (string) ($prev['gross_profit'] ?? '0'), 2),
            'net_income_curr'   => $curr['net_income'] ?? '0.00',
            'net_income_prev'   => $prev['net_income'] ?? '0.00',
            'net_income_delta'  => bcsub((string) ($curr['net_income'] ?? '0'), (string) ($prev['net_income'] ?? '0'), 2),
        ];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(string $entityId, string $currAsOf, string $prevAsOf): array
    {
        $curr = $this->bs->compute($entityId, $currAsOf);
        $prev = $this->bs->compute($entityId, $prevAsOf);

        return [
            'kind'         => 'balance_sheet',
            'entity_id'    => $entityId,
            'curr_as_of'   => $currAsOf,
            'prev_as_of'   => $prevAsOf,
            'sections'     => [
                'assets'      => $this->mergeSections($curr['assets'] ?? [], $prev['assets'] ?? []),
                'liabilities' => $this->mergeSections($curr['liabilities'] ?? [], $prev['liabilities'] ?? []),
                'equity'      => $this->mergeSections($curr['equity'] ?? [], $prev['equity'] ?? []),
            ],
            'totals' => [
                'assets'      => ['curr' => (string) ($curr['total_assets']      ?? '0'), 'prev' => (string) ($prev['total_assets']      ?? '0')],
                'liabilities' => ['curr' => (string) ($curr['total_liabilities'] ?? '0'), 'prev' => (string) ($prev['total_liabilities'] ?? '0')],
                'equity'      => ['curr' => (string) ($curr['total_equity']      ?? '0'), 'prev' => (string) ($prev['total_equity']      ?? '0')],
            ],
        ];
    }

    /** Convenience: prior-period dates (same length, immediately before). */
    public function priorPeriod(string $start, string $end): array
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        $days = $s->diffInDays($e);
        $prevEnd   = $s->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days);

        return ['start' => $prevStart->toDateString(), 'end' => $prevEnd->toDateString()];
    }

    /** Convenience: same period prior YEAR. */
    public function priorYear(string $start, string $end): array
    {
        return [
            'start' => Carbon::parse($start)->subYearNoOverflow()->toDateString(),
            'end'   => Carbon::parse($end)->subYearNoOverflow()->toDateString(),
        ];
    }

    /**
     * Merge two section payloads (each shape: { lines: Collection, total: string })
     * by account id. Lines unique to one period appear with 0 on the other side.
     */
    private function mergeSections(array $curr, array $prev): array
    {
        $currLines = collect($curr['lines'] ?? []);
        $prevLines = collect($prev['lines'] ?? []);

        $byId = [];
        foreach ($currLines as $l) {
            $byId[$l->id] = (object) [
                'id'           => $l->id,
                'code'         => $l->code,
                'name'         => $l->name,
                'curr_balance' => (string) ($l->balance ?? '0'),
                'prev_balance' => '0.00',
            ];
        }
        foreach ($prevLines as $l) {
            if (! isset($byId[$l->id])) {
                $byId[$l->id] = (object) [
                    'id'           => $l->id,
                    'code'         => $l->code,
                    'name'         => $l->name,
                    'curr_balance' => '0.00',
                    'prev_balance' => (string) ($l->balance ?? '0'),
                ];
            } else {
                $byId[$l->id]->prev_balance = (string) ($l->balance ?? '0');
            }
        }

        $rows = collect(array_values($byId))
            ->map(function ($r) {
                $r->delta = bcsub($r->curr_balance, $r->prev_balance, 2);
                $r->delta_pct = bccomp($r->prev_balance, '0', 2) !== 0
                    ? bcdiv(bcmul($r->delta, '100', 4), $r->prev_balance, 2)
                    : null;

                return $r;
            })
            ->sortBy('code')
            ->values();

        return [
            'lines'      => $rows,
            'curr_total' => (string) ($curr['total'] ?? '0'),
            'prev_total' => (string) ($prev['total'] ?? '0'),
            'total_delta'=> bcsub((string) ($curr['total'] ?? '0'), (string) ($prev['total'] ?? '0'), 2),
        ];
    }
}
