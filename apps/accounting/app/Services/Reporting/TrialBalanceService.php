<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Collection;

/**
 * Trial Balance (spec §10).
 *
 * For each postable account in an entity, sums debit + credit totals across
 * POSTED journals up to an as-of date (inclusive). Computes normal-balance
 * aware net balance. Grand totals across all rows must tie — debit total
 * equals credit total if every posted journal was itself balanced (invariant
 * preserved by `PostJournalAction`).
 */
class TrialBalanceService
{
    /**
     * @return array{rows: Collection<int, object>, total_debit: string, total_credit: string, as_of: string, entity_id: string}
     */
    public function compute(string $entityId, string $asOfDate): array
    {
        $rows = Account::query()
            ->where('accounts.entity_id', $entityId)
            ->where('accounts.is_postable', true)
            ->leftJoin('journal_entries', 'journal_entries.account_id', '=', 'accounts.id')
            ->leftJoin('journals', function ($join) use ($asOfDate) {
                $join->on('journals.id', '=', 'journal_entries.journal_id')
                    ->where('journals.status', Journal::STATUS_POSTED)
                    ->where('journals.date', '<=', $asOfDate);
            })
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, accounts.normal_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN journals.id IS NOT NULL THEN journal_entries.debit ELSE 0 END), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(CASE WHEN journals.id IS NOT NULL THEN journal_entries.credit ELSE 0 END), 0) as total_credit')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->get()
            ->map(function ($row) {
                $debit = (string) $row->total_debit;
                $credit = (string) $row->total_credit;
                $row->total_debit = $debit;
                $row->total_credit = $credit;
                $row->balance = $row->normal_balance === 'debit'
                    ? bcsub($debit, $credit, 2)
                    : bcsub($credit, $debit, 2);

                return $row;
            })
            ->filter(fn ($row) => bccomp($row->total_debit, '0', 2) !== 0 || bccomp($row->total_credit, '0', 2) !== 0)
            ->values();

        $totalDebit = $rows->reduce(fn ($carry, $r) => bcadd($carry, $r->total_debit, 2), '0.00');
        $totalCredit = $rows->reduce(fn ($carry, $r) => bcadd($carry, $r->total_credit, 2), '0.00');

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'as_of' => $asOfDate,
            'entity_id' => $entityId,
        ];
    }
}
