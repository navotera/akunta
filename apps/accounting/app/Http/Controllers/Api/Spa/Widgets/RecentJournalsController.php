<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentJournalsController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        abort_unless($entity->is_active, 422, 'Entitas yang dipilih sedang nonaktif.');
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'period_id' => ['nullable', 'string'],
            'journal_mode' => ['nullable', 'in:internal,fiscal'],
        ]);
        $limit = (int) ($data['limit'] ?? 10);
        $periodId = $data['period_id'] ?? null;
        if ($periodId && ! Period::query()->where('entity_id', $entity->id)->whereKey($periodId)->exists()) {
            abort(422, 'Periode aktif bukan milik entitas yang dipilih.');
        }
        $mode = $this->isInspector($request)
            ? Journal::MODE_FISCAL
            : ($data['journal_mode'] ?? Journal::MODE_INTERNAL);

        $items = Journal::query()
            ->where('entity_id', $entity->id)
            ->when($periodId, fn ($query) => $query->where('period_id', $periodId))
            ->where('journal_mode', $mode)
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
