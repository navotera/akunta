<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Collection;

/**
 * Laba Rugi (Income Statement) over a date range.
 *
 * Aggregates posted journal entries for revenue / cogs / expense accounts:
 *
 *   Gross Profit = Revenue - COGS
 *   Net Income   = Gross Profit - Operating Expenses
 *
 * Dates are inclusive on both ends.
 */
class IncomeStatementService
{
    /**
     * @return array{
     *     entity_id: string,
     *     period_start: string,
     *     period_end: string,
     *     revenue: array{lines: Collection<int, object>, total: string},
     *     cogs: array{lines: Collection<int, object>, total: string},
     *     gross_profit: string,
     *     expenses: array{lines: Collection<int, object>, total: string},
     *     net_income: string
     * }
     */
    public function compute(string $entityId, string $periodStart, string $periodEnd): array
    {
        $rows = Account::query()
            ->where('accounts.entity_id', $entityId)
            ->where('accounts.is_postable', true)
            ->whereIn('accounts.type', ['revenue', 'cogs', 'expense'])
            ->leftJoin('journal_entries', 'journal_entries.account_id', '=', 'accounts.id')
            ->leftJoin('journals', function ($join) use ($periodStart, $periodEnd) {
                $join->on('journals.id', '=', 'journal_entries.journal_id')
                    ->where('journals.status', Journal::STATUS_POSTED)
                    ->whereBetween('journals.date', [$periodStart, $periodEnd]);
            })
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, accounts.normal_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN journals.id IS NOT NULL THEN journal_entries.debit ELSE 0 END), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(CASE WHEN journals.id IS NOT NULL THEN journal_entries.credit ELSE 0 END), 0) as total_credit')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->get()
            ->map(function ($row) {
                $row->balance = $row->normal_balance === 'debit'
                    ? bcsub((string) $row->total_debit, (string) $row->total_credit, 2)
                    : bcsub((string) $row->total_credit, (string) $row->total_debit, 2);

                return $row;
            })
            ->filter(fn ($row) => bccomp($row->balance, '0', 2) !== 0);

        $revenueLines = $rows->where('type', 'revenue')->values();
        $cogsLines = $rows->where('type', 'cogs')->values();
        $expenseLines = $rows->where('type', 'expense')->values();

        $sumBalance = fn (Collection $c) => $c->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->balance, 2), '0.00');

        $revenueTotal = $sumBalance($revenueLines);
        $cogsTotal = $sumBalance($cogsLines);
        $expenseTotal = $sumBalance($expenseLines);

        $grossProfit = bcsub($revenueTotal, $cogsTotal, 2);
        $netIncome = bcsub($grossProfit, $expenseTotal, 2);

        return [
            'entity_id' => $entityId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'revenue' => ['lines' => $revenueLines, 'total' => $revenueTotal],
            'cogs' => ['lines' => $cogsLines, 'total' => $cogsTotal],
            'gross_profit' => $grossProfit,
            'expenses' => ['lines' => $expenseLines, 'total' => $expenseTotal],
            'net_income' => $netIncome,
        ];
    }
}
