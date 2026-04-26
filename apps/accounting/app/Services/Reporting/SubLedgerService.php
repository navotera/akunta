<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Journal;
use App\Models\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buku Besar Pembantu — per-partner ledger.
 *
 * AR (Piutang) — partner.type = customer, sums entries on asset accounts.
 * AP (Hutang)  — partner.type = vendor,   sums entries on liability accounts.
 *
 * Each row aggregates debit/credit for the partner across all posted journals
 * up to `as_of`, then computes balance respecting the side (asset = debit-side
 * normal, liability = credit-side normal). Returned shape mirrors
 * TrialBalanceService for downstream consistency.
 */
class SubLedgerService
{
    /** @return array{rows: Collection<int, object>, total_balance: string, type: string, as_of: string, entity_id: string} */
    public function arSubLedger(string $entityId, string $asOf): array
    {
        return $this->build($entityId, Partner::TYPE_CUSTOMER, 'asset', $asOf);
    }

    /** @return array{rows: Collection<int, object>, total_balance: string, type: string, as_of: string, entity_id: string} */
    public function apSubLedger(string $entityId, string $asOf): array
    {
        return $this->build($entityId, Partner::TYPE_VENDOR, 'liability', $asOf);
    }

    /**
     * @return array{rows: Collection<int, object>, total_balance: string, type: string, as_of: string, entity_id: string}
     */
    private function build(string $entityId, string $partnerType, string $accountType, string $asOf): array
    {
        $rows = DB::table('partners as p')
            ->where('p.entity_id', $entityId)
            ->where('p.type', $partnerType)
            ->leftJoin('journal_entries as je', 'je.partner_id', '=', 'p.id')
            ->leftJoin('journals as j', function ($join) use ($asOf) {
                $join->on('j.id', '=', 'je.journal_id')
                    ->where('j.status', Journal::STATUS_POSTED)
                    ->where('j.date', '<=', $asOf);
            })
            ->leftJoin('accounts as a', 'a.id', '=', 'je.account_id')
            ->where(function ($q) use ($accountType) {
                $q->whereNull('je.id')
                    ->orWhere('a.type', $accountType);
            })
            ->selectRaw('p.id, p.code, p.name')
            ->selectRaw('COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN je.debit ELSE 0 END), 0) as td')
            ->selectRaw('COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN je.credit ELSE 0 END), 0) as tc')
            ->groupBy('p.id', 'p.code', 'p.name')
            ->orderBy('p.name')
            ->get()
            ->map(function ($r) use ($accountType) {
                $td = (string) $r->td;
                $tc = (string) $r->tc;
                $r->total_debit  = $td;
                $r->total_credit = $tc;
                $r->balance = $accountType === 'asset'
                    ? bcsub($td, $tc, 2)
                    : bcsub($tc, $td, 2);

                return $r;
            })
            ->filter(fn ($r) => bccomp($r->balance, '0', 2) !== 0)
            ->values();

        $total = $rows->reduce(fn ($carry, $r) => bcadd($carry, $r->balance, 2), '0.00');

        return [
            'rows'          => $rows,
            'total_balance' => $total,
            'type'          => $partnerType,
            'as_of'         => $asOf,
            'entity_id'     => $entityId,
        ];
    }

    /**
     * Per-partner journal_entry list (chronological) — for drill-down or PDF Statement of Account.
     *
     * @return Collection<int, object>
     */
    public function partnerTransactions(string $entityId, string $partnerId, string $accountType, string $asOf): Collection
    {
        return DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('j.entity_id', $entityId)
            ->where('je.partner_id', $partnerId)
            ->where('a.type', $accountType)
            ->where('j.status', Journal::STATUS_POSTED)
            ->where('j.date', '<=', $asOf)
            ->orderBy('j.date')
            ->orderBy('j.number')
            ->orderBy('je.line_no')
            ->select(
                'j.id as journal_id',
                'j.number',
                'j.date',
                'j.memo as journal_memo',
                'j.reference',
                'je.id as line_id',
                'je.debit',
                'je.credit',
                'je.memo as line_memo',
                'a.code as account_code',
                'a.name as account_name',
            )
            ->get();
    }
}
