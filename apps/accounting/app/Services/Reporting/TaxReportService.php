<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Journal;
use App\Models\TaxCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * List + summarize tax-bearing journal_entries within a period.
 *
 *   - $kind: TaxCode::KIND_OUTPUT_VAT (default), KIND_INPUT_VAT, or null = all
 *   - Returns one row per journal_entry that carries a tax_code_id, with
 *     denormalized partner NPWP/name + tax_base (DPP) + tax_amount.
 *   - Used by both UI Tax Report page and e-Faktur CSV exporter.
 */
class TaxReportService
{
    /** @return array{rows: Collection<int, object>, totals: array{base: string, tax: string}, period_start: string, period_end: string, kind: ?string} */
    public function compute(string $entityId, string $periodStart, string $periodEnd, ?string $kind = null): array
    {
        $q = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('tax_codes as t', 't.id', '=', 'je.tax_code_id')
            ->leftJoin('partners as p', 'p.id', '=', 'je.partner_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('j.entity_id', $entityId)
            ->where('j.status', Journal::STATUS_POSTED)
            ->whereBetween('j.date', [$periodStart, $periodEnd])
            ->whereNotNull('je.tax_code_id');

        if ($kind !== null) {
            $q->where('t.kind', $kind);
        }

        $rows = $q->orderBy('j.date')
            ->orderBy('j.number')
            ->orderBy('je.line_no')
            ->select(
                'j.id as journal_id',
                'j.number',
                'j.date',
                'j.reference',
                'j.memo as journal_memo',
                'je.id as line_id',
                'je.line_no',
                'je.tax_base',
                'je.debit',
                'je.credit',
                'je.memo as line_memo',
                't.code as tax_code',
                't.name as tax_name',
                't.kind as tax_kind',
                't.rate as tax_rate',
                'p.id as partner_id',
                'p.name as partner_name',
                'p.npwp as partner_npwp',
                'p.address as partner_address',
                'a.code as account_code',
                'a.name as account_name',
            )
            ->get()
            ->map(function ($r) {
                $r->tax_amount = bcadd((string) $r->debit, (string) $r->credit, 2);
                $r->tax_base   = $r->tax_base !== null ? bcadd((string) $r->tax_base, '0', 2) : null;

                return $r;
            });

        $totalBase = $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) ($r->tax_base ?? '0'), 2), '0.00');
        $totalTax  = $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->tax_amount, 2), '0.00');

        return [
            'rows'        => $rows,
            'totals'      => ['base' => $totalBase, 'tax' => $totalTax],
            'period_start'=> $periodStart,
            'period_end'  => $periodEnd,
            'kind'        => $kind,
        ];
    }
}
