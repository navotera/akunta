<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\SourceRefRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SourceRefController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'source_app' => 'nullable|string|max:40',
            'ref_type'   => 'nullable|string|max:40',
            'q'          => 'nullable|string|max:120',
        ]);

        $query = SourceRefRegistry::query()
            ->where('entity_id', $entity->id);

        if ($app = $data['source_app'] ?? null) {
            $query->where('source_app', $app);
        }
        if ($type = $data['ref_type'] ?? null) {
            $query->where('ref_type', $type);
        }
        if ($q = $data['q'] ?? null) {
            $query->where(function ($qb) use ($q) {
                $qb->where('last_label', 'like', "%{$q}%")
                    ->orWhere('last_code', 'like', "%{$q}%");
            });
        }

        $rows = $query->orderBy('last_label')
            ->limit(200)
            ->get(['id', 'source_app', 'ref_type', 'ref_id', 'last_code', 'last_label', 'entry_count', 'last_seen_at']);

        return response()->json([
            'data' => $rows->map(fn (SourceRefRegistry $r) => [
                'source_app'   => $r->source_app,
                'ref_type'     => $r->ref_type,
                'ref_id'       => $r->ref_id,
                'code'         => $r->last_code,
                'label'        => $r->last_label,
                'entry_count'  => $r->entry_count,
                'last_seen_at' => optional($r->last_seen_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Aggregate per source_ref over a period — generic replacement
     * for the old aging/sub-ledger reports. Group by ref_id, sum
     * debit/credit, derive balance per account-side preference.
     */
    public function bySourceRef(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'source_app'   => 'required|string|max:40',
            'ref_type'     => 'required|string|max:40',
            'period_start' => 'required|date_format:Y-m-d',
            'period_end'   => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'account_id'   => 'nullable|string|size:26',
        ]);

        $q = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->where('j.entity_id', $entity->id)
            ->where('j.status', Journal::STATUS_POSTED)
            ->whereBetween('j.date', [$data['period_start'], $data['period_end']])
            ->where('je.source_app', $data['source_app'])
            ->where('je.source_ref_type', $data['ref_type'])
            ->whereNotNull('je.source_ref_id');

        if (! empty($data['account_id'])) {
            $q->where('je.account_id', $data['account_id']);
        }

        $rows = $q->groupBy('je.source_ref_id')
            ->orderByRaw('SUM(je.debit) + SUM(je.credit) DESC')
            ->select(
                'je.source_ref_id',
                DB::raw('SUM(je.debit) as total_debit'),
                DB::raw('SUM(je.credit) as total_credit'),
                DB::raw('COUNT(*) as entry_count'),
            )
            ->get();

        // Backfill labels from registry — one batched query.
        $refIds = $rows->pluck('source_ref_id')->all();
        $registry = SourceRefRegistry::query()
            ->where('entity_id', $entity->id)
            ->where('source_app', $data['source_app'])
            ->where('ref_type', $data['ref_type'])
            ->whereIn('ref_id', $refIds)
            ->get(['ref_id', 'last_code', 'last_label'])
            ->keyBy('ref_id');

        $aggregated = $rows->map(function ($r) use ($registry) {
            $reg = $registry[$r->source_ref_id] ?? null;
            $debit  = bcadd((string) $r->total_debit, '0', 2);
            $credit = bcadd((string) $r->total_credit, '0', 2);

            return [
                'ref_id'       => $r->source_ref_id,
                'code'         => $reg?->last_code,
                'label'        => $reg?->last_label,
                'total_debit'  => $debit,
                'total_credit' => $credit,
                'net'          => bcsub($debit, $credit, 2),
                'entry_count'  => (int) $r->entry_count,
            ];
        });

        return response()->json([
            'data' => $aggregated->all(),
            'meta' => [
                'source_app'   => $data['source_app'],
                'ref_type'     => $data['ref_type'],
                'period_start' => $data['period_start'],
                'period_end'   => $data['period_end'],
                'totals' => [
                    'debit'  => $aggregated->reduce(fn ($c, $r) => bcadd($c, $r['total_debit'], 2), '0.00'),
                    'credit' => $aggregated->reduce(fn ($c, $r) => bcadd($c, $r['total_credit'], 2), '0.00'),
                ],
            ],
        ]);
    }
}
