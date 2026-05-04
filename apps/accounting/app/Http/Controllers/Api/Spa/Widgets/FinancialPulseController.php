<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Services\Reporting\IncomeStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialPulseController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly IncomeStatementService $incomeStatement) {}

    public function show(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $today = now();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $prevMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $prevMonthEnd = $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $current = $this->incomeStatement->compute($entity->id, $monthStart, $monthEnd);
        $previous = $this->incomeStatement->compute($entity->id, $prevMonthStart, $prevMonthEnd);

        $draftCount = Journal::where('entity_id', $entity->id)
            ->where('status', Journal::STATUS_DRAFT)
            ->count();
        $postedThisMonth = Journal::where('entity_id', $entity->id)
            ->where('status', Journal::STATUS_POSTED)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->count();

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'period_label' => $today->translatedFormat('F Y'),
                'revenue' => [
                    'current' => $current['revenue']['total'],
                    'previous' => $previous['revenue']['total'],
                ],
                'expenses' => [
                    'current' => bcadd($current['cogs']['total'], $current['expenses']['total'], 2),
                    'previous' => bcadd($previous['cogs']['total'], $previous['expenses']['total'], 2),
                ],
                'net_income' => [
                    'current' => $current['net_income'],
                    'previous' => $previous['net_income'],
                ],
                'journals' => [
                    'draft_count' => $draftCount,
                    'posted_this_month' => $postedThisMonth,
                ],
            ],
        ]);
    }
}
