<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialPulseController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function show(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $today = now();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $prevMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $prevMonthEnd = $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $journalMode = $this->isInspector($request) ? Journal::MODE_FISCAL : Journal::MODE_INTERNAL;

        $totals = $this->periodTotals(
            $entity->id,
            $prevMonthStart,
            $prevMonthEnd,
            $monthStart,
            $monthEnd,
            $journalMode,
        );
        $journalCounts = Journal::query()
            ->where('entity_id', $entity->id)
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft_count',
                [Journal::STATUS_DRAFT],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND journal_mode = ? AND date BETWEEN ? AND ? THEN 1 ELSE 0 END) as posted_this_month',
                [Journal::STATUS_POSTED, $journalMode, $monthStart, $monthEnd],
            )
            ->first();

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'period_label' => $today->translatedFormat('F Y'),
                'revenue' => [
                    'current' => $totals['current']['revenue'],
                    'previous' => $totals['previous']['revenue'],
                ],
                'expenses' => [
                    'current' => bcadd($totals['current']['cogs'], $totals['current']['expenses'], 2),
                    'previous' => bcadd($totals['previous']['cogs'], $totals['previous']['expenses'], 2),
                ],
                'net_income' => [
                    'current' => $totals['current']['net_income'],
                    'previous' => $totals['previous']['net_income'],
                ],
                'journals' => [
                    'draft_count' => (int) ($journalCounts->draft_count ?? 0),
                    'posted_this_month' => (int) ($journalCounts->posted_this_month ?? 0),
                ],
            ],
        ]);
    }

    /** @return array{current: array{revenue: string, cogs: string, expenses: string, net_income: string}, previous: array{revenue: string, cogs: string, expenses: string, net_income: string}} */
    private function periodTotals(
        string $entityId,
        string $previousStart,
        string $previousEnd,
        string $currentStart,
        string $currentEnd,
        string $journalMode,
    ): array {
        $rows = Account::query()
            ->where('accounts.entity_id', $entityId)
            ->where('accounts.is_postable', true)
            ->whereIn('accounts.type', ['revenue', 'cogs', 'expense'])
            ->leftJoin('journal_entries', 'journal_entries.account_id', '=', 'accounts.id')
            ->leftJoin('journals', function ($join) use ($entityId, $previousStart, $currentEnd, $journalMode) {
                $join->on('journals.id', '=', 'journal_entries.journal_id')
                    ->where('journals.entity_id', $entityId)
                    ->where('journals.status', Journal::STATUS_POSTED)
                    ->where('journals.journal_mode', $journalMode)
                    ->whereBetween('journals.date', [$previousStart, $currentEnd]);
            })
            ->select(['accounts.type', 'accounts.normal_balance'])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journals.date BETWEEN ? AND ? THEN journal_entries.debit ELSE 0 END), 0) as previous_debit',
                [$previousStart, $previousEnd],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journals.date BETWEEN ? AND ? THEN journal_entries.credit ELSE 0 END), 0) as previous_credit',
                [$previousStart, $previousEnd],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journals.date BETWEEN ? AND ? THEN journal_entries.debit ELSE 0 END), 0) as current_debit',
                [$currentStart, $currentEnd],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journals.date BETWEEN ? AND ? THEN journal_entries.credit ELSE 0 END), 0) as current_credit',
                [$currentStart, $currentEnd],
            )
            ->groupBy('accounts.id', 'accounts.type', 'accounts.normal_balance')
            ->get();

        $totals = [
            'previous' => ['revenue' => '0.00', 'cogs' => '0.00', 'expenses' => '0.00'],
            'current' => ['revenue' => '0.00', 'cogs' => '0.00', 'expenses' => '0.00'],
        ];

        foreach ($rows as $row) {
            $previous = $row->normal_balance === 'debit'
                ? bcsub((string) $row->previous_debit, (string) $row->previous_credit, 2)
                : bcsub((string) $row->previous_credit, (string) $row->previous_debit, 2);
            $current = $row->normal_balance === 'debit'
                ? bcsub((string) $row->current_debit, (string) $row->current_credit, 2)
                : bcsub((string) $row->current_credit, (string) $row->current_debit, 2);

            $bucket = $row->type === 'revenue' ? 'revenue' : ($row->type === 'cogs' ? 'cogs' : 'expenses');
            $totals['previous'][$bucket] = bcadd($totals['previous'][$bucket], $previous, 2);
            $totals['current'][$bucket] = bcadd($totals['current'][$bucket], $current, 2);
        }

        foreach (['previous', 'current'] as $period) {
            $totals[$period]['net_income'] = bcsub(
                bcsub($totals[$period]['revenue'], $totals[$period]['cogs'], 2),
                $totals[$period]['expenses'],
                2,
            );
        }

        return $totals;
    }
}
