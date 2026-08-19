<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use Illuminate\Support\Collection;

class FiscalReconciliationService
{
    public function __construct(private readonly IncomeStatementService $incomeStatement) {}

    /** @return array<string, mixed> */
    public function compute(string $entityId, string $periodStart, string $periodEnd): array
    {
        $book = $this->incomeStatement->compute(
            $entityId,
            $periodStart,
            $periodEnd,
            Journal::MODE_FISCAL,
        );

        $adjustments = FiscalAdjustment::query()
            ->where('entity_id', $entityId)
            ->where('status', FiscalAdjustment::STATUS_APPROVED)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get()
            ->groupBy('account_id');

        $bookLines = collect()
            ->concat($book['revenue']['lines'])
            ->concat($book['cogs']['lines'])
            ->concat($book['expenses']['lines']);
        $missingAccounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $adjustments->keys())
            ->whereNotIn('id', $bookLines->pluck('id'))
            ->get(['id', 'code', 'name', 'type'])
            ->each(fn (Account $account) => $account->setAttribute('balance', '0.00'));

        $rows = $bookLines
            ->concat($missingAccounts)
            ->map(function (object $line) use ($adjustments): array {
                /** @var Collection<int, FiscalAdjustment> $accountAdjustments */
                $accountAdjustments = $adjustments->get($line->id, collect());
                $positive = $this->sum($accountAdjustments->where('direction', FiscalAdjustment::DIRECTION_POSITIVE));
                $negative = $this->sum($accountAdjustments->where('direction', FiscalAdjustment::DIRECTION_NEGATIVE));
                $bookAmount = (string) $line->balance;
                $finalAmount = $line->type === 'revenue'
                    ? bcsub(bcadd($bookAmount, $positive, 2), $negative, 2)
                    : bcadd(bcsub($bookAmount, $positive, 2), $negative, 2);

                return [
                    'account_id' => $line->id,
                    'code' => $line->code,
                    'name' => $line->name,
                    'type' => $line->type,
                    'book_amount' => $bookAmount,
                    'positive_adjustment' => $positive,
                    'negative_adjustment' => $negative,
                    'final_amount' => $finalAmount,
                ];
            });

        $positiveTotal = $this->sum($adjustments->flatten()->where('direction', FiscalAdjustment::DIRECTION_POSITIVE));
        $negativeTotal = $this->sum($adjustments->flatten()->where('direction', FiscalAdjustment::DIRECTION_NEGATIVE));
        $finalNetIncome = bcsub(bcadd((string) $book['net_income'], $positiveTotal, 2), $negativeTotal, 2);

        return [
            'entity_id' => $entityId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'book_net_income' => (string) $book['net_income'],
            'positive_adjustments' => $positiveTotal,
            'negative_adjustments' => $negativeTotal,
            'final_net_income' => $finalNetIncome,
            'rows' => $rows->filter(fn (array $row): bool => bccomp($row['book_amount'], '0', 2) !== 0
                || bccomp($row['positive_adjustment'], '0', 2) !== 0
                || bccomp($row['negative_adjustment'], '0', 2) !== 0
            )->values(),
        ];
    }

    /** @param Collection<int, FiscalAdjustment> $items */
    private function sum(Collection $items): string
    {
        return $items->reduce(
            fn (string $carry, FiscalAdjustment $item): string => bcadd($carry, (string) $item->amount, 2),
            '0.00',
        );
    }
}
