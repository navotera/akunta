<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash Flow Statement — direct method (cash-account-driven).
 *
 * Algorithm:
 *   1. Identify cash & equivalent accounts (asset, code prefix 11 OR
 *      name contains kas|cash|bank).
 *   2. For each posted journal_entry on those accounts in [start..end],
 *      net effect = debit - credit (positive = inflow, negative = outflow).
 *   3. Classify by examining the OTHER lines of the same journal:
 *        - Investing  : counter-account type = 'asset' (non-cash) or
 *                       account.code prefix '15' (Aktiva Tetap)
 *        - Financing  : counter-account type = 'equity' OR liability/equity
 *                       prefix '21x'/'3x' (interpret loosely)
 *        - Operating  : everything else
 *      Heuristic kept simple — UMKM-friendly. Accountant can refine via
 *      manual reclassification (future).
 *
 * Output mirrors IncomeStatementService shape:
 *   { operating: { lines, total }, investing: {...}, financing: {...},
 *     net_change, opening_cash, ending_cash }
 */
class CashFlowService
{
    /** @return array<string, mixed> */
    public function compute(string $entityId, string $periodStart, string $periodEnd): array
    {
        $cashIds = Account::query()
            ->where('entity_id', $entityId)
            ->where('type', 'asset')
            ->where(function ($q) {
                $q->where('code', 'like', '11%')
                    ->orWhere('name', 'like', '%kas%')
                    ->orWhere('name', 'like', '%cash%')
                    ->orWhere('name', 'like', '%bank%');
            })
            ->pluck('id');

        if ($cashIds->isEmpty()) {
            return $this->empty($entityId, $periodStart, $periodEnd);
        }

        // Opening + ending cash balance (debit-side normal)
        $opening = $this->cashBalance($cashIds, $entityId, $periodStart, '<');
        $ending  = $this->cashBalance($cashIds, $entityId, $periodEnd, '<=');

        // Pull journals touching cash within range, group by journal
        $cashLines = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->whereIn('je.account_id', $cashIds)
            ->where('j.entity_id', $entityId)
            ->where('j.status', Journal::STATUS_POSTED)
            ->whereBetween('j.date', [$periodStart, $periodEnd])
            ->select('j.id as journal_id', 'j.number', 'j.date', 'j.memo',
                     DB::raw('SUM(je.debit - je.credit) as cash_delta'))
            ->groupBy('j.id', 'j.number', 'j.date', 'j.memo')
            ->get()
            ->keyBy('journal_id');

        // Pull counter-accounts for those journals (non-cash lines)
        $journalIds = $cashLines->keys()->all();
        $counters = collect();
        if (! empty($journalIds)) {
            $counters = DB::table('journal_entries as je')
                ->join('accounts as a', 'a.id', '=', 'je.account_id')
                ->whereIn('je.journal_id', $journalIds)
                ->whereNotIn('je.account_id', $cashIds)
                ->select('je.journal_id', 'a.id as account_id', 'a.code', 'a.name', 'a.type', 'je.debit', 'je.credit')
                ->get()
                ->groupBy('journal_id');
        }

        $buckets = [
            'operating' => ['lines' => collect(), 'total' => '0.00'],
            'investing' => ['lines' => collect(), 'total' => '0.00'],
            'financing' => ['lines' => collect(), 'total' => '0.00'],
        ];

        foreach ($cashLines as $jid => $cl) {
            $delta   = (string) $cl->cash_delta;
            $bucket  = 'operating';
            $counter = $counters->get($jid, collect());
            // Pick the counter line with largest absolute amount as classifier
            $primary = $counter->sortByDesc(fn ($r) => abs((float) $r->debit - (float) $r->credit))->first();
            if ($primary !== null) {
                $bucket = $this->classify($primary);
            }
            $buckets[$bucket]['lines']->push((object) [
                'journal_id' => $jid,
                'number'     => $cl->number,
                'date'       => $cl->date,
                'memo'       => $cl->memo,
                'amount'     => $delta,
                'counter'    => $primary,
            ]);
            $buckets[$bucket]['total'] = bcadd($buckets[$bucket]['total'], $delta, 2);
        }

        $netChange = bcadd(bcadd($buckets['operating']['total'], $buckets['investing']['total'], 2), $buckets['financing']['total'], 2);

        return [
            'entity_id'    => $entityId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'opening_cash' => $opening,
            'ending_cash'  => $ending,
            'net_change'   => $netChange,
            'operating'    => $buckets['operating'],
            'investing'    => $buckets['investing'],
            'financing'    => $buckets['financing'],
        ];
    }

    private function classify(object $counter): string
    {
        $type = (string) $counter->type;
        $code = (string) $counter->code;

        // Investing — fixed assets / long-term assets (15xx) or non-cash asset side
        if ($type === 'asset' && (str_starts_with($code, '15') || str_starts_with($code, '16'))) {
            return 'investing';
        }
        // Financing — equity (3xxx) or long-term liability (22xx, e.g. hutang bank long-term)
        if ($type === 'equity') {
            return 'financing';
        }
        if ($type === 'liability' && str_starts_with($code, '22')) {
            return 'financing';
        }

        return 'operating';
    }

    private function cashBalance(Collection $cashIds, string $entityId, string $cutoff, string $op): string
    {
        $row = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->whereIn('je.account_id', $cashIds)
            ->where('j.entity_id', $entityId)
            ->where('j.status', Journal::STATUS_POSTED)
            ->where('j.date', $op, $cutoff)
            ->selectRaw('COALESCE(SUM(je.debit), 0) as td, COALESCE(SUM(je.credit), 0) as tc')
            ->first();

        return bcsub((string) ($row->td ?? '0'), (string) ($row->tc ?? '0'), 2);
    }

    private function empty(string $entityId, string $start, string $end): array
    {
        return [
            'entity_id'    => $entityId,
            'period_start' => $start,
            'period_end'   => $end,
            'opening_cash' => '0.00',
            'ending_cash'  => '0.00',
            'net_change'   => '0.00',
            'operating'    => ['lines' => collect(), 'total' => '0.00'],
            'investing'    => ['lines' => collect(), 'total' => '0.00'],
            'financing'    => ['lines' => collect(), 'total' => '0.00'],
        ];
    }
}
