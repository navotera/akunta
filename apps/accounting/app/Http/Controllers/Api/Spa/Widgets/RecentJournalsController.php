<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentJournalsController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        $items = Journal::query()
            ->where('entity_id', $entity->id)
            ->when($this->isInspector($request), fn ($query) => $query->where('journal_mode', Journal::MODE_FISCAL))
            ->withSum('entries as total_debit', 'debit')
            ->latest('date')
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'number', 'reference', 'date', 'memo', 'status', 'type']);

        return response()->json([
            'data' => $items->map(fn (Journal $j) => [
                'id' => $j->id,
                'number' => $j->number,
                'reference' => $j->reference,
                'date' => optional($j->date)?->toDateString() ?? (string) $j->date,
                'memo' => $j->memo,
                'status' => $j->status,
                'type' => $j->type,
                'total' => (string) ($j->total_debit ?? 0),
            ])->all(),
        ]);
    }
}
