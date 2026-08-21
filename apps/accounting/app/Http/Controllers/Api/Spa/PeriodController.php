<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $query = Period::query()
            ->where('entity_id', $entity->id)
            ->orderByDesc('start_date');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Period $p) => $this->serialize($p))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        return response()->json(['data' => $this->serialize($period)]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $this->validatePayload($request);

        $this->assertNoOverlap($entity->id, $data['start_date'], $data['end_date'], null);

        $hasOpenPeriod = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->exists();

        $period = Period::create([
            ...$data,
            'entity_id' => $entity->id,
            'status' => $hasOpenPeriod ? Period::STATUS_CLOSED : Period::STATUS_OPEN,
            'closed_at' => $hasOpenPeriod ? now() : null,
            'closed_by' => $hasOpenPeriod ? Auth::id() : null,
        ]);

        return response()->json(['data' => $this->serialize($period)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        if ($period->status !== Period::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'status' => 'Only open periods can be edited.',
            ]);
        }

        $data = $this->validatePayload($request);
        $this->assertNoOverlap($entity->id, $data['start_date'], $data['end_date'], $period->id);

        $period->fill($data)->save();

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        if (Journal::where('period_id', $period->id)->exists()) {
            throw ValidationException::withMessages([
                'period' => 'Period contains journals; cannot delete.',
            ]);
        }

        $period->delete();

        return response()->json(null, 204);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        if ($period->status === Period::STATUS_CLOSED) {
            return response()->json(['data' => $this->serialize($period)]);
        }

        $draftCount = Journal::where('period_id', $period->id)
            ->where('status', Journal::STATUS_DRAFT)
            ->count();
        if ($draftCount > 0) {
            throw ValidationException::withMessages([
                'period' => "Period has {$draftCount} draft journal(s); post or delete them first.",
            ]);
        }

        $period->forceFill([
            'status' => Period::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ])->save();

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $period = Period::where('entity_id', $entity->id)->findOrFail($id);

        $this->assertAdminCanActivate();

        DB::transaction(function () use ($entity, $period): void {
            $current = Period::query()
                ->where('entity_id', $entity->id)
                ->where('status', Period::STATUS_OPEN)
                ->where('id', '!=', $period->id)
                ->first();

            if ($current) {
                // An explicit admin period switch may close the current
                // period with drafts; the drafts remain attached to that
                // period and can be handled when it is active again.
                $current->forceFill([
                    'status' => Period::STATUS_CLOSED,
                    'closed_at' => now(),
                    'closed_by' => Auth::id(),
                ])->save();
            }

            $period->forceFill([
                'status' => Period::STATUS_OPEN,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();
        });

        return response()->json(['data' => $this->serialize($period->refresh())]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);
    }

    private function assertNoOverlap(string $entityId, string $start, string $end, ?string $exceptId): void
    {
        $exists = Period::query()
            ->where('entity_id', $entityId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('end_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_date', '<=', $start)->where('end_date', '>=', $end);
                    });
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' => 'Period overlaps an existing period.',
            ]);
        }
    }

    private function assertAdminCanActivate(): void
    {
        $user = Auth::user();
        $isAdmin = $user !== null
            && ((method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin())
                || session('ecopa.app_role') === 'admin'
                || $user->assignments()
                    ->whereNull('revoked_at')
                    ->whereHas('role', fn ($query) => $query->whereIn('code', ['admin', 'super_admin']))
                    ->exists());

        if (! $isAdmin) {
            abort(403, 'Hanya admin yang dapat mengaktifkan periode.');
        }
    }

    private function serialize(Period $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'start_date' => optional($p->start_date)->toDateString() ?? (string) $p->start_date,
            'end_date' => optional($p->end_date)->toDateString() ?? (string) $p->end_date,
            'status' => $p->status,
            'closed_at' => optional($p->closed_at)?->toIso8601String(),
            'closed_by' => $p->closed_by,
        ];
    }
}
