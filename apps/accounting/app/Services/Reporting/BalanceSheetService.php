<?php

namespace App\Services\Reporting;

use Illuminate\Support\Collection;

/**
 * Neraca (Balance Sheet) as of a cutoff date.
 *
 * Groups postable accounts by type into Assets / Liabilities / Equity. Net
 * income (Revenue - COGS - Expense) accumulated up to the same cutoff date
 * is added as "Laba Tahun Berjalan" synthetic equity line, keeping
 * Assets = Liabilities + Equity invariant without requiring manual closing
 * entries every period.
 */
class BalanceSheetService
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
    ) {}

    /**
     * @return array{
     *     entity_id: string,
     *     as_of: string,
     *     assets: array{lines: Collection<int, object>, total: string},
     *     liabilities: array{lines: Collection<int, object>, total: string},
     *     equity: array{lines: Collection<int, object>, total: string, net_income_ytd: string},
     *     balanced: bool
     * }
     */
    public function compute(string $entityId, string $asOfDate, ?string $periodStart = null): array
    {
        $trial = $this->trialBalance->compute($entityId, $asOfDate);
        $rows = $trial['rows'];

        $assetLines = $rows->where('type', 'asset')->values();
        $liabilityLines = $rows->where('type', 'liability')->values();
        $equityLines = $rows->where('type', 'equity')->values();

        $sumBalance = fn (Collection $c) => $c->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->balance, 2), '0.00');

        $assetTotal = $sumBalance($assetLines);
        $liabilityTotal = $sumBalance($liabilityLines);
        $equityTotal = $sumBalance($equityLines);

        $is = $this->incomeStatement->compute(
            $entityId,
            $periodStart ?? $this->defaultPeriodStart($asOfDate),
            $asOfDate,
        );
        $netIncome = $is['net_income'];

        $equityTotalWithNetIncome = bcadd($equityTotal, $netIncome, 2);

        $totalLiabEquity = bcadd($liabilityTotal, $equityTotalWithNetIncome, 2);
        $balanced = bccomp($assetTotal, $totalLiabEquity, 2) === 0;

        return [
            'entity_id' => $entityId,
            'as_of' => $asOfDate,
            'assets' => [
                'lines' => $assetLines,
                'total' => $assetTotal,
            ],
            'liabilities' => [
                'lines' => $liabilityLines,
                'total' => $liabilityTotal,
            ],
            'equity' => [
                'lines' => $equityLines,
                'total' => $equityTotalWithNetIncome,
                'net_income_ytd' => $netIncome,
            ],
            'balanced' => $balanced,
        ];
    }

    private function defaultPeriodStart(string $asOfDate): string
    {
        // Fiscal year typically Jan 1 — use same-year Jan 1 as default period start.
        return substr($asOfDate, 0, 4).'-01-01';
    }
}
