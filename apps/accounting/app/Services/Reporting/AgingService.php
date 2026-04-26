<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Journal;
use App\Models\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aging Report — bucketize open AR/AP per partner by age of unpaid debits.
 *
 * Algorithm (per partner, single side):
 *   1. Pull all posted journal_entries for that partner on the AR/AP account-type,
 *      ordered by date.
 *   2. Walk chronologically, maintaining a FIFO queue of open *debits* (for AR)
 *      or open *credits* (for AP). Opposite-side entries consume queue head.
 *   3. Remaining queue at end = open balance, each item carrying its origination
 *      date → bucketed by age (today − origination).
 *
 * Default buckets: current (≤0d), 1–30, 31–60, 61–90, >90.
 */
class AgingService
{
    /** @var array<int, array{label: string, max: ?int}> */
    public const DEFAULT_BUCKETS = [
        ['label' => 'Current',  'max' => 0],
        ['label' => '1–30',     'max' => 30],
        ['label' => '31–60',    'max' => 60],
        ['label' => '61–90',    'max' => 90],
        ['label' => '>90',      'max' => null],
    ];

    /** @return array{rows: Collection<int, object>, totals: array<string, string>, type: string, as_of: string, entity_id: string} */
    public function arAging(string $entityId, string $asOf): array
    {
        return $this->build($entityId, Partner::TYPE_CUSTOMER, 'asset', 'debit', $asOf);
    }

    /** @return array{rows: Collection<int, object>, totals: array<string, string>, type: string, as_of: string, entity_id: string} */
    public function apAging(string $entityId, string $asOf): array
    {
        return $this->build($entityId, Partner::TYPE_VENDOR, 'liability', 'credit', $asOf);
    }

    /**
     * @param  string  $openSide  'debit' for AR (customer invoices increase AR via debit),
     *                            'credit' for AP (vendor bills increase AP via credit).
     * @return array{rows: Collection<int, object>, totals: array<string, string>, type: string, as_of: string, entity_id: string}
     */
    private function build(string $entityId, string $partnerType, string $accountType, string $openSide, string $asOf): array
    {
        $partners = DB::table('partners')
            ->where('entity_id', $entityId)
            ->where('type', $partnerType)
            ->select('id', 'code', 'name')
            ->orderBy('name')
            ->get();

        $today = Carbon::parse($asOf);
        $bucketLabels = array_column(self::DEFAULT_BUCKETS, 'label');
        $totals = array_fill_keys($bucketLabels, '0.00');
        $totals['total'] = '0.00';

        $rows = $partners->map(function ($partner) use ($entityId, $accountType, $openSide, $asOf, $today, &$totals) {
            $entries = DB::table('journal_entries as je')
                ->join('journals as j', 'j.id', '=', 'je.journal_id')
                ->join('accounts as a', 'a.id', '=', 'je.account_id')
                ->where('j.entity_id', $entityId)
                ->where('je.partner_id', $partner->id)
                ->where('a.type', $accountType)
                ->where('j.status', Journal::STATUS_POSTED)
                ->where('j.date', '<=', $asOf)
                ->orderBy('j.date')
                ->orderBy('j.number')
                ->orderBy('je.line_no')
                ->select('j.date', 'je.debit', 'je.credit', 'j.number')
                ->get();

            // FIFO match: queue holds open items on the open side; opposite side consumes head.
            $queue = []; // [['date' => ..., 'amount' => string, 'number' => ...]]
            foreach ($entries as $e) {
                $debit  = (string) $e->debit;
                $credit = (string) $e->credit;

                if ($openSide === 'debit') {
                    $openAmt   = $debit;
                    $closeAmt  = $credit;
                } else {
                    $openAmt   = $credit;
                    $closeAmt  = $debit;
                }

                if (bccomp($openAmt, '0', 2) > 0) {
                    $queue[] = [
                        'date'   => $e->date,
                        'amount' => $openAmt,
                        'number' => $e->number,
                    ];
                }

                $remaining = $closeAmt;
                while (bccomp($remaining, '0', 2) > 0 && ! empty($queue)) {
                    $head = &$queue[0];
                    if (bccomp($head['amount'], $remaining, 2) <= 0) {
                        $remaining = bcsub($remaining, $head['amount'], 2);
                        array_shift($queue);
                    } else {
                        $head['amount'] = bcsub($head['amount'], $remaining, 2);
                        $remaining = '0.00';
                    }
                    unset($head);
                }
            }

            $buckets = array_fill_keys(array_column(self::DEFAULT_BUCKETS, 'label'), '0.00');
            $rowTotal = '0.00';

            foreach ($queue as $open) {
                $age = (int) $today->diffInDays(Carbon::parse($open['date']), absolute: false);
                // diffInDays(absolute=false) returns positive when arg is in the past relative to $today
                // — but signs vary by Carbon version; normalize:
                $age = (int) abs($age);
                // If origination is in future, treat as current (negative age):
                if (Carbon::parse($open['date'])->greaterThan($today)) {
                    $age = 0;
                }

                $label = $this->bucketFor($age);
                $buckets[$label] = bcadd($buckets[$label], $open['amount'], 2);
                $rowTotal        = bcadd($rowTotal, $open['amount'], 2);
            }

            $row = (object) [
                'partner_id'   => $partner->id,
                'partner_code' => $partner->code,
                'partner_name' => $partner->name,
                'buckets'      => $buckets,
                'total'        => $rowTotal,
            ];

            foreach ($buckets as $label => $amount) {
                $totals[$label] = bcadd($totals[$label], $amount, 2);
            }
            $totals['total'] = bcadd($totals['total'], $rowTotal, 2);

            return $row;
        })
            ->filter(fn ($r) => bccomp($r->total, '0', 2) !== 0)
            ->values();

        return [
            'rows'      => $rows,
            'totals'    => $totals,
            'buckets'   => array_column(self::DEFAULT_BUCKETS, 'label'),
            'type'      => $partnerType,
            'as_of'     => $asOf,
            'entity_id' => $entityId,
        ];
    }

    private function bucketFor(int $age): string
    {
        foreach (self::DEFAULT_BUCKETS as $b) {
            if ($b['max'] === null) {
                return $b['label'];
            }
            if ($age <= $b['max']) {
                return $b['label'];
            }
        }

        return self::DEFAULT_BUCKETS[count(self::DEFAULT_BUCKETS) - 1]['label'];
    }
}
