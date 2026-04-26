<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buku Besar (General Ledger) — drill-down per account.
 *
 * Returns:
 *   - account header (code, name, normal_balance)
 *   - opening balance (sum of postings strictly BEFORE period_start)
 *   - chronological line list within [period_start..period_end] with running balance
 *   - period totals (debit, credit, net change)
 *   - ending balance
 *
 * Optional partner_id / cost_center_id / project_id / branch_id filters.
 */
class GeneralLedgerService
{
    /**
     * @param  array<string, string|null>  $filters  partner_id, cost_center_id, project_id, branch_id
     * @return array{
     *   account: object,
     *   period_start: string,
     *   period_end: string,
     *   opening: string,
     *   ending: string,
     *   total_debit: string,
     *   total_credit: string,
     *   lines: Collection<int, object>
     * }
     */
    public function compute(
        string $entityId,
        string $accountId,
        string $periodStart,
        string $periodEnd,
        array $filters = [],
    ): array {
        $account = Account::query()
            ->where('entity_id', $entityId)
            ->where('id', $accountId)
            ->firstOrFail();

        $applyFilters = function ($q) use ($filters) {
            foreach (['partner_id', 'cost_center_id', 'project_id', 'branch_id'] as $f) {
                if (! empty($filters[$f])) {
                    $q->where("journal_entries.{$f}", $filters[$f]);
                }
            }
        };

        // Opening = sum of postings strictly BEFORE period_start
        $opening = $this->balance($account, '<', $periodStart, $applyFilters);

        // Lines within range
        $lines = DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->where('journals.entity_id', $entityId)
            ->where('journals.status', Journal::STATUS_POSTED)
            ->where('journal_entries.account_id', $account->id)
            ->whereBetween('journals.date', [$periodStart, $periodEnd])
            ->tap($applyFilters)
            ->orderBy('journals.date')
            ->orderBy('journals.number')
            ->orderBy('journal_entries.line_no')
            ->select(
                'journals.id as journal_id',
                'journals.number',
                'journals.date',
                'journals.memo as journal_memo',
                'journals.reference',
                'journal_entries.id as line_id',
                'journal_entries.debit',
                'journal_entries.credit',
                'journal_entries.memo as line_memo',
                'journal_entries.partner_id',
            )
            ->get();

        $running = $opening;
        $totalDebit  = '0.00';
        $totalCredit = '0.00';

        $lines = $lines->map(function ($l) use (&$running, &$totalDebit, &$totalCredit, $account) {
            $debit  = (string) $l->debit;
            $credit = (string) $l->credit;
            $delta  = $account->normal_balance === 'debit'
                ? bcsub($debit, $credit, 2)
                : bcsub($credit, $debit, 2);
            $running = bcadd($running, $delta, 2);
            $totalDebit  = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
            $l->balance = $running;

            return $l;
        });

        return [
            'account'      => $account,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'opening'      => $opening,
            'ending'       => $running,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'lines'        => $lines,
        ];
    }

    private function balance(Account $account, string $op, string $cutoff, callable $applyFilters): string
    {
        $row = DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->where('journals.entity_id', $account->entity_id)
            ->where('journals.status', Journal::STATUS_POSTED)
            ->where('journal_entries.account_id', $account->id)
            ->where('journals.date', $op, $cutoff)
            ->tap($applyFilters)
            ->selectRaw('COALESCE(SUM(journal_entries.debit), 0) as td, COALESCE(SUM(journal_entries.credit), 0) as tc')
            ->first();

        $td = (string) ($row->td ?? '0');
        $tc = (string) ($row->tc ?? '0');

        return $account->normal_balance === 'debit'
            ? bcsub($td, $tc, 2)
            : bcsub($tc, $td, 2);
    }
}
